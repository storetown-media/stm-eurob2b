<?php
/**
 * Decision table for the tax treatment.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Treatment;

use EuroB2B\Modules\Countries\CountryRegistry;
use EuroB2B\Modules\Countries\TaxArea;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;

/**
 * Pure, unit-tested decision (TAX-017, TAX-018, TAX-022). Exempt only when every condition holds:
 * business customer, VAT ID confirmed valid, ID from another member state than the shop, place of
 * supply in another member state, no self-collection, exemption enabled. Everything else is taxed,
 * and unresolved cases are flagged for review (EU-01..EU-05 in docs/country-matrix.md).
 *
 * Special cases (`SpecialTreatment`) are asked first. EuroB2B Pro adds two (PLT-015): with the
 * special territories setting (TAX-024) a place of supply in a verified territory outside the EU VAT
 * area is an export like a third country (`special_territory`); with the Northern Ireland setting
 * (TAX-034, § 30 UStG) goods to Northern Ireland are decided like goods to another member state. The
 * reason codes of both stay here, because orders keep them.
 */
final class TaxTreatmentDecider {

	public const REASON_SHOP_OUTSIDE_EU     = 'shop_outside_eu';
	public const REASON_COUNTRY_UNKNOWN     = 'country_unknown';
	public const REASON_DOMESTIC            = 'domestic';
	public const REASON_HOME_COUNTRY_VAT_ID = 'home_country_vat_id';
	public const REASON_CONSUMER            = 'consumer';
	public const REASON_NO_VAT_ID           = 'no_vat_id';
	public const REASON_VAT_ID_INVALID      = 'vat_id_invalid';
	public const REASON_VAT_ID_UNAVAILABLE  = 'vat_id_unavailable';
	public const REASON_VAT_ID_PENDING      = 'vat_id_pending';
	public const REASON_DELIVERY_IN_SHOP    = 'delivery_in_shop_country';
	public const REASON_EXPORT_DESTINATION  = 'export_destination';
	public const REASON_PICKUP              = 'pickup_needs_transport_evidence';
	public const REASON_EXEMPTION_DISABLED  = 'exemption_disabled';
	public const REASON_INTRA_EU_SUPPLY     = 'intra_eu_supply_b2b';
	public const REASON_INTRA_EU_SERVICE    = 'intra_eu_service_b2b';
	public const REASON_SWISS_AREA          = 'swiss_area';
	public const REASON_THIRD_COUNTRY       = 'third_country';
	public const REASON_RULES_NOT_REVIEWED  = 'rules_not_reviewed';
	public const REASON_SPECIAL_TERRITORY   = 'special_territory';
	public const REASON_SHOP_IN_TERRITORY   = 'shop_in_special_territory';
	public const REASON_NI_CONSUMER         = 'northern_ireland_consumer';
	public const REASON_NI_NEEDS_XI_VAT_ID  = 'northern_ireland_needs_xi_vat_id';
	public const REASON_NI_MIXED            = 'northern_ireland_mixed';

	/**
	 * Special cases, asked in this order before the standard rules.
	 *
	 * @var SpecialTreatment[]
	 */
	private array $special;

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $special Special cases (`SpecialTreatment`; anything else is ignored).
	 */
	public function __construct( array $special = array() ) {
		$this->special = array_values( array_filter( $special, static fn( $candidate ): bool => $candidate instanceof SpecialTreatment ) );
	}

	/**
	 * Decides.
	 *
	 * @param TreatmentInput $input Facts.
	 */
	public function decide( TreatmentInput $input ): TreatmentDecision {
		// Monaco counts as France (Art. 7 (1) VAT Directive) for the shop, the customer and the destination.
		$shop    = '' === $input->shop_country ? '' : CountryRegistry::vat_country( $input->shop_country );
		$billing = '' === $input->billing_country ? '' : CountryRegistry::vat_country( $input->billing_country );

		if ( '' === $shop || ! CountryRegistry::is_eu( $shop ) ) {
			return new TreatmentDecision( TaxTreatment::OUT_OF_SCOPE, self::REASON_SHOP_OUTSIDE_EU );
		}
		foreach ( $this->special as $case ) {
			$decision = $case->decide( $input, $shop, $billing );
			if ( null !== $decision ) {
				return $decision;
			}
		}
		if ( '' === $billing ) {
			return new TreatmentDecision( TaxTreatment::INTRA_EU_TAXED, self::REASON_COUNTRY_UNKNOWN );
		}
		if ( $billing === $shop ) {
			$has_valid_id = ValidationStatus::VALID === $input->vat_status;
			return new TreatmentDecision( TaxTreatment::DOMESTIC, $has_valid_id ? self::REASON_HOME_COUNTRY_VAT_ID : self::REASON_DOMESTIC );
		}

		$area = CountryRegistry::tax_area( $billing );
		if ( TaxArea::SWISS_VAT_AREA === $area ) {
			return new TreatmentDecision( TaxTreatment::SWISS_AREA, self::REASON_SWISS_AREA );
		}
		if ( TaxArea::EU_VAT_AREA !== $area ) {
			return new TreatmentDecision( TaxTreatment::EXPORT, self::REASON_THIRD_COUNTRY );
		}

		// EU customer in another member state.
		if ( ! $input->is_b2b ) {
			return new TreatmentDecision( TaxTreatment::INTRA_EU_TAXED, self::REASON_CONSUMER );
		}
		if ( null === $input->vat_status ) {
			return new TreatmentDecision( TaxTreatment::INTRA_EU_TAXED, self::REASON_NO_VAT_ID );
		}
		switch ( $input->vat_status ) {
			case ValidationStatus::INVALID:
				return new TreatmentDecision( TaxTreatment::INTRA_EU_TAXED, self::REASON_VAT_ID_INVALID );
			case ValidationStatus::UNAVAILABLE:
				return new TreatmentDecision( TaxTreatment::MANUAL, self::REASON_VAT_ID_UNAVAILABLE, true );
			case ValidationStatus::PENDING:
				return new TreatmentDecision( TaxTreatment::MANUAL, self::REASON_VAT_ID_PENDING, true );
			case ValidationStatus::VALID:
				break;
		}

		$vat_country = strtoupper( (string) $input->vat_country );
		if ( '' === $vat_country || $vat_country === $shop ) {
			// A VAT ID of the shop's own member state never exempts (EU-02), wherever the customer sits.
			return new TreatmentDecision( TaxTreatment::DOMESTIC, self::REASON_HOME_COUNTRY_VAT_ID );
		}

		$destination = '' === $input->destination() ? '' : CountryRegistry::vat_country( $input->destination() );
		if ( $destination === $shop ) {
			return new TreatmentDecision( TaxTreatment::INTRA_EU_TAXED, self::REASON_DELIVERY_IN_SHOP );
		}
		if ( ! CountryRegistry::is_eu( $destination ) ) {
			return new TreatmentDecision( TaxTreatment::EXPORT, self::REASON_EXPORT_DESTINATION );
		}
		if ( $input->is_pickup && ! $input->services_only ) {
			return new TreatmentDecision( TaxTreatment::MANUAL, self::REASON_PICKUP, true );
		}
		if ( ! $input->exemption_enabled ) {
			return new TreatmentDecision( TaxTreatment::INTRA_EU_TAXED, self::REASON_EXEMPTION_DISABLED );
		}
		if ( ! $input->rules_reviewed ) {
			// CTY-004: a country whose rules nobody signed off is taxed and flagged, never auto-exempted.
			return new TreatmentDecision( TaxTreatment::MANUAL, self::REASON_RULES_NOT_REVIEWED, true );
		}
		return new TreatmentDecision( TaxTreatment::INTRA_EU_REVERSE_CHARGE, $input->services_only ? self::REASON_INTRA_EU_SERVICE : self::REASON_INTRA_EU_SUPPLY );
	}

	/**
	 * Decision for an order with goods and services, from the decisions of each part. The goods decide
	 * the customer-wide exemption; when only the services are exempt the result is MIXED and the exempt
	 * service lines are untaxed on their own (CartTreatment). A special case may combine differently
	 * (EuroB2B Pro: goods to Northern Ireland, TAX-034, NI-05).
	 *
	 * @param TreatmentDecision   $goods       Decision for the goods.
	 * @param TreatmentDecision   $services    Decision for the services.
	 * @param TreatmentInput|null $goods_input Facts of the goods (the special cases need them).
	 */
	public function combine( TreatmentDecision $goods, TreatmentDecision $services, ?TreatmentInput $goods_input = null ): TreatmentDecision {
		if ( null !== $goods_input ) {
			foreach ( $this->special as $case ) {
				$decision = $case->combine( $goods, $services, $goods_input );
				if ( null !== $decision ) {
					return $decision;
				}
			}
		}
		if ( $services->is_exempt() && ! $goods->is_exempt() ) {
			return new TreatmentDecision( TaxTreatment::MIXED, self::REASON_INTRA_EU_SERVICE, $goods->needs_review, $goods->territory );
		}
		return $goods;
	}
}

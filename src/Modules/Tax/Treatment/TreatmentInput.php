<?php
/**
 * Facts the tax treatment decision is based on.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Treatment;

use EuroB2B\Modules\Countries\NorthernIreland;
use EuroB2B\Modules\Countries\Territory;
use EuroB2B\Modules\Countries\TerritoryResolver;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;

/**
 * Everything the decider needs, gathered by the WooCommerce layer (cart or order).
 */
final class TreatmentInput {

	/**
	 * Constructor.
	 *
	 * @param string                $shop_country      ISO country of the shop (WooCommerce base location).
	 * @param string                $billing_country   ISO billing country ("" when unknown).
	 * @param string                $shipping_country  ISO shipping country ("" = same as billing).
	 * @param bool                  $is_b2b            Business customer (audience).
	 * @param ValidationStatus|null $vat_status        Effective validation status of the customer's VAT ID, null without ID.
	 * @param string|null           $vat_country       ISO country of the VAT ID.
	 * @param bool                  $exemption_enabled Setting: apply the intra-EU exemption automatically.
	 * @param bool                  $services_only     Every line is a service (place of supply = customer, Art. 44).
	 * @param bool                  $is_pickup         Customer collects the goods (no transport evidence, Art. 45a).
	 * @param bool                  $rules_reviewed    The customer country's profile is professionally reviewed, or the shop opted in to unreviewed rules.
	 * @param bool                  $special_territories Setting: recognise special territories by state and postcode (TAX-024).
	 * @param string                $shop_state        WooCommerce state of the shop.
	 * @param string                $shop_postcode     Postcode of the shop.
	 * @param string                $billing_state     WooCommerce state of the billing address.
	 * @param string                $billing_postcode  Postcode of the billing address.
	 * @param string                $shipping_state    WooCommerce state of the shipping address.
	 * @param string                $shipping_postcode Postcode of the shipping address.
	 * @param bool                  $northern_ireland  Setting: goods to Northern Ireland under EU VAT rules (TAX-034).
	 */
	public function __construct(
		public readonly string $shop_country,
		public readonly string $billing_country,
		public readonly string $shipping_country,
		public readonly bool $is_b2b,
		public readonly ?ValidationStatus $vat_status,
		public readonly ?string $vat_country,
		public readonly bool $exemption_enabled = true,
		public readonly bool $services_only = false,
		public readonly bool $is_pickup = false,
		public readonly bool $rules_reviewed = true,
		public readonly bool $special_territories = false,
		public readonly string $shop_state = '',
		public readonly string $shop_postcode = '',
		public readonly string $billing_state = '',
		public readonly string $billing_postcode = '',
		public readonly string $shipping_state = '',
		public readonly string $shipping_postcode = '',
		public readonly bool $northern_ireland = false
	) {
	}

	/**
	 * The same facts with the switches of the special cases (special territories, Northern Ireland)
	 * and the rules review gate replaced. The core decides without the special cases; EuroB2B Pro
	 * switches them on by its settings through `eurob2b_tax_treatment_input` (PLT-015).
	 *
	 * @param bool $special_territories Recognise special territories (TAX-024).
	 * @param bool $northern_ireland    Goods to Northern Ireland under EU VAT rules (TAX-034).
	 * @param bool $rules_reviewed      The rules that apply to the customer are reviewed (CTY-004).
	 */
	public function with_special_cases( bool $special_territories, bool $northern_ireland, bool $rules_reviewed ): self {
		return new self(
			$this->shop_country,
			$this->billing_country,
			$this->shipping_country,
			$this->is_b2b,
			$this->vat_status,
			$this->vat_country,
			$this->exemption_enabled,
			$this->services_only,
			$this->is_pickup,
			$rules_reviewed,
			$special_territories,
			$this->shop_state,
			$this->shop_postcode,
			$this->billing_state,
			$this->billing_postcode,
			$this->shipping_state,
			$this->shipping_postcode,
			$northern_ireland
		);
	}

	/**
	 * Whether this is a supply of goods to Northern Ireland that the setting puts under EU VAT rules
	 * (§ 30 UStG, TAX-034): the setting is on, the lines are goods and the destination (shipping
	 * address, else billing address) is GB with a BT postcode, or XI. Services stay with UK rules.
	 */
	public function northern_ireland(): bool {
		return $this->northern_ireland
			&& ! $this->services_only
			&& NorthernIreland::is_destination( $this->billing_country, $this->billing_postcode, $this->shipping_country, $this->shipping_postcode );
	}

	/**
	 * Country that decides the place of supply: the establishment for services, the destination for goods.
	 */
	public function destination(): string {
		if ( $this->services_only ) {
			return strtoupper( $this->billing_country );
		}
		return strtoupper( '' !== $this->shipping_country ? $this->shipping_country : $this->billing_country );
	}

	/**
	 * Verified special territory outside the EU VAT area that decides the place of supply: the
	 * establishment's address for services, the delivery address for goods. Null when the setting
	 * is off or the address is a normal one (Monaco counts as France and is normal).
	 */
	public function territory(): ?Territory {
		if ( ! $this->special_territories ) {
			return null;
		}
		if ( $this->services_only || '' === $this->shipping_country ) {
			return self::outside( $this->billing_country, $this->billing_state, $this->billing_postcode );
		}
		return self::outside( $this->shipping_country, $this->shipping_state, $this->shipping_postcode );
	}

	/**
	 * Verified special territory outside the EU VAT area the shop itself is in (null when the
	 * setting is off or the shop is in the EU VAT area).
	 */
	public function shop_territory(): ?Territory {
		return $this->special_territories ? self::outside( $this->shop_country, $this->shop_state, $this->shop_postcode ) : null;
	}

	/**
	 * Verified territory outside the EU VAT area of an address.
	 *
	 * @param string $country  Country.
	 * @param string $state    State.
	 * @param string $postcode Postcode.
	 */
	private static function outside( string $country, string $state, string $postcode ): ?Territory {
		$territory = TerritoryResolver::resolve( $country, $state, $postcode );
		return null !== $territory && $territory->is_outside_vat_area() ? $territory : null;
	}
}

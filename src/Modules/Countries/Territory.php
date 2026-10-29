<?php
/**
 * Special territory of a member state.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Countries;

/**
 * A part of a member state's country code whose VAT status differs from the rest (TAX-024,
 * docs/country-matrix.md §8): outside the EU VAT area (Art. 6 VAT Directive, overseas countries
 * and territories) or treated as another member state (Art. 7). Detected by WooCommerce state code
 * or by postcode; every rule carries its sources and a review status, and only verified rules are
 * applied (`TerritoryResolver::resolve()`).
 */
final class Territory {

	public const OUTSIDE_EU_VAT_AREA     = 'outside_eu_vat_area';
	public const TREATED_AS_MEMBER_STATE = 'treated_as_member_state';

	public const REVIEW_VERIFIED   = 'verified';
	public const REVIEW_UNVERIFIED = 'UNVERIFIED';

	/**
	 * Constructor.
	 *
	 * @param string   $id                Stable id (stored on orders as `_eurob2b_tax_territory`).
	 * @param string   $name              English name.
	 * @param string   $member_state      ISO code the addresses carry (ES for the Canary Islands).
	 * @param string   $vat_status        OUTSIDE_EU_VAT_AREA or TREATED_AS_MEMBER_STATE.
	 * @param string   $treated_as        Member state the territory counts as (TREATED_AS_MEMBER_STATE only).
	 * @param string   $legal_basis       Provision that sets the status.
	 * @param string[] $states            WooCommerce state codes of the territory.
	 * @param string[] $postcodes         Whole postcodes of the territory (normalised).
	 * @param string[] $postcode_prefixes Postcode prefixes of the territory.
	 * @param string   $source            Source of the VAT status (URL).
	 * @param string   $detection_source  Source of the state codes or postcodes (URL).
	 * @param string   $checked_at        Y-m-d of the last source check.
	 * @param string   $review            REVIEW_VERIFIED or REVIEW_UNVERIFIED.
	 * @param string   $note              English note on the detection (empty when none).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $member_state,
		public readonly string $vat_status,
		public readonly string $treated_as,
		public readonly string $legal_basis,
		public readonly array $states,
		public readonly array $postcodes,
		public readonly array $postcode_prefixes,
		public readonly string $source,
		public readonly string $detection_source,
		public readonly string $checked_at,
		public readonly string $review,
		public readonly string $note = ''
	) {
	}

	/**
	 * Whether the rule is verified and may therefore change the tax treatment.
	 */
	public function is_verified(): bool {
		return self::REVIEW_VERIFIED === $this->review;
	}

	/**
	 * Whether sales into the territory are outside the EU VAT area.
	 */
	public function is_outside_vat_area(): bool {
		return self::OUTSIDE_EU_VAT_AREA === $this->vat_status;
	}
}

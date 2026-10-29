<?php
/**
 * Data profile of one country.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Countries;

/**
 * Versioned, sourced facts about a country (docs/country-matrix.md §6). Review states:
 * "reviewed" (signed off by a tax professional), "needs_review" (researched, sources listed),
 * "unverified" (gap known). Unreviewed profiles never auto-exempt unless the shop opts in.
 */
final class CountryProfile implements CountryProfileInterface {

	public const REVIEW_REVIEWED     = 'reviewed';
	public const REVIEW_NEEDS_REVIEW = 'needs_review';
	public const REVIEW_UNVERIFIED   = 'unverified';

	/**
	 * Constructor.
	 *
	 * @param string                                                                                      $code                 ISO code.
	 * @param string                                                                                      $name                 English name.
	 * @param string                                                                                      $review               Review state.
	 * @param string                                                                                      $rules_version        Rule set version.
	 * @param string                                                                                      $rules_checked_at     Y-m-d of the last source check.
	 * @param string|null                                                                                 $vat_number_pattern   Regex for the national VAT number part.
	 * @param string|null                                                                                 $company_identifier   Company identifier the registration may ask for (label + format).
	 * @param string|null                                                                                 $identifier_pattern   Regex for that identifier, null when unknown.
	 * @param string|null                                                                                 $lookup               Official lookup / API.
	 * @param string|null                                                                                 $einvoicing           Domestic B2B e-invoicing status (Oct 2026).
	 * @param string[]                                                                                    $routing_identifiers  Routing identifiers invoicing plugins may need.
	 * @param string[]                                                                                    $sources              Source URLs or document references.
	 * @param array<int, array{type: string, rate: string, valid_from: string, source: string}>           $vat_rates VAT rates in percent as exact decimal strings (non-EU profiles, docs/country-matrix.md §7).
	 * @param array<int, array{id: string, amount: int, currency: string, basis: string, source: string}> $thresholds Amounts in whole currency units that make a foreign shop liable for VAT there.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $name,
		public readonly string $review,
		public readonly string $rules_version,
		public readonly string $rules_checked_at,
		public readonly ?string $vat_number_pattern,
		public readonly ?string $company_identifier,
		public readonly ?string $identifier_pattern,
		public readonly ?string $lookup,
		public readonly ?string $einvoicing,
		public readonly array $routing_identifiers,
		public readonly array $sources,
		public readonly array $vat_rates = array(),
		public readonly array $thresholds = array()
	) {
	}

	/**
	 * VAT rates with their sources (empty for the EU data profiles, whose rates WooCommerce holds).
	 *
	 * @return array<int, array{type: string, rate: string, valid_from: string, source: string}>
	 */
	public function vat_rates(): array {
		return $this->vat_rates;
	}

	/**
	 * Rate of one type ("standard", "reduced", ...), null when the profile has none.
	 *
	 * @param string $type Rate type.
	 */
	public function rate( string $type ): ?string {
		foreach ( $this->vat_rates as $rate ) {
			if ( $type === $rate['type'] ) {
				return $rate['rate'];
			}
		}
		return null;
	}

	/**
	 * Registration thresholds with their sources.
	 *
	 * @return array<int, array{id: string, amount: int, currency: string, basis: string, source: string}>
	 */
	public function thresholds(): array {
		return $this->thresholds;
	}

	/**
	 * ISO code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Tax area from the registry.
	 */
	public function tax_area(): TaxArea {
		return CountryRegistry::tax_area( $this->code );
	}

	/**
	 * Rule set version.
	 */
	public function rules_version(): string {
		return $this->rules_version;
	}

	/**
	 * Last check date.
	 */
	public function rules_checked_at(): string {
		return $this->rules_checked_at;
	}

	/**
	 * Signed off by a tax professional.
	 */
	public function is_professionally_reviewed(): bool {
		return self::REVIEW_REVIEWED === $this->review;
	}

	/**
	 * National VAT number pattern.
	 */
	public function vat_number_pattern(): ?string {
		return $this->vat_number_pattern;
	}

	/**
	 * Sources.
	 *
	 * @return string[]
	 */
	public function sources(): array {
		return $this->sources;
	}

	/**
	 * Whether a company identifier matches the profile's format (true when no format is known).
	 *
	 * @param string $value Entered identifier.
	 */
	public function identifier_matches( string $value ): bool {
		if ( null === $this->identifier_pattern ) {
			return true;
		}
		return 1 === preg_match( $this->identifier_pattern, strtoupper( trim( $value ) ) );
	}
}

<?php
/**
 * Country profile contract.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Countries;

/**
 * Versioned, sourced rule set for one country.
 *
 * Profiles carry no tax law logic of their own in this version; they describe
 * identifiers, validation services and the documented rule state. Tax
 * decisions are made by the Tax module using the profile as input.
 */
interface CountryProfileInterface {

	/**
	 * ISO 3166-1 alpha-2 code.
	 */
	public function code(): string;

	/**
	 * Tax area the country belongs to.
	 */
	public function tax_area(): TaxArea;

	/**
	 * Version of the rule set, bumped whenever a rule or source changes.
	 */
	public function rules_version(): string;

	/**
	 * Date (Y-m-d) the rule set was last checked against its sources.
	 */
	public function rules_checked_at(): string;

	/**
	 * Whether the rule set was signed off by a tax professional.
	 */
	public function is_professionally_reviewed(): bool;

	/**
	 * Regular expression for the national part of the VAT ID (without prefix), or null when unknown.
	 */
	public function vat_number_pattern(): ?string;

	/**
	 * Source URLs for the rules in this profile.
	 *
	 * @return string[]
	 */
	public function sources(): array;
}

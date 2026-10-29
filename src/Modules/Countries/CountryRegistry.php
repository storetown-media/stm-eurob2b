<?php
/**
 * Country registry.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Countries;

/**
 * Static knowledge about which country belongs to which tax area.
 *
 * Country profiles (rules, identifiers, sources) build on top of this and are
 * versioned individually, see docs/country-matrix.md.
 */
final class CountryRegistry {

	/**
	 * The 27 EU member states (ISO 3166-1 alpha-2).
	 *
	 * Source: https://european-union.europa.eu/principles-countries-history/eu-countries_en
	 */
	public const EU_MEMBER_STATES = array(
		'AT',
		'BE',
		'BG',
		'HR',
		'CY',
		'CZ',
		'DK',
		'EE',
		'FI',
		'FR',
		'DE',
		'GR',
		'HU',
		'IE',
		'IT',
		'LV',
		'LT',
		'LU',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SK',
		'SI',
		'ES',
		'SE',
	);

	/**
	 * EEA members that are not EU members.
	 *
	 * Liechtenstein is EEA but uses Swiss VAT; it is therefore in the Swiss VAT area.
	 */
	public const EEA_NON_EU = array( 'IS', 'LI', 'NO' );

	/**
	 * Swiss VAT territory.
	 */
	public const SWISS_VAT_TERRITORY = array( 'CH', 'LI' );

	/**
	 * Countries outside the EU that the VAT Directive treats as part of a member state (Art. 7 (1):
	 * Monaco as France), keyed by ISO code. Checked 2026-10-28 against the consolidated Directive
	 * of 2025-04-14, docs/country-matrix.md EU-11. The UK territories of Art. 7 no longer matter
	 * since Brexit; the sovereign base areas in Cyprus have no address code of their own.
	 */
	public const TREATED_AS_MEMBER_STATE = array( 'MC' => 'FR' );

	/**
	 * Country code used by VIES for Greece (ISO is GR, VAT prefix is EL).
	 */
	public const VIES_GREECE = 'EL';

	/**
	 * Northern Ireland VAT prefix for goods under the Windsor Framework (not an ISO code).
	 */
	public const VIES_NORTHERN_IRELAND = 'XI';

	/**
	 * Whether the country is an EU member state.
	 *
	 * @param string $country ISO code.
	 */
	public static function is_eu( string $country ): bool {
		return in_array( self::iso( $country ), self::EU_MEMBER_STATES, true );
	}

	/**
	 * Whether the country is in the European Economic Area (EU + IS, LI, NO).
	 *
	 * @param string $country ISO code.
	 */
	public static function is_eea( string $country ): bool {
		$iso = self::iso( $country );
		return self::is_eu( $iso ) || in_array( $iso, self::EEA_NON_EU, true );
	}

	/**
	 * Tax area for a country.
	 *
	 * @param string $country ISO code.
	 */
	public static function tax_area( string $country ): TaxArea {
		$iso = self::vat_country( $country );
		if ( self::is_eu( $iso ) ) {
			return TaxArea::EU_VAT_AREA;
		}
		if ( in_array( $iso, self::SWISS_VAT_TERRITORY, true ) ) {
			return TaxArea::SWISS_VAT_AREA;
		}
		if ( in_array( $iso, self::EEA_NON_EU, true ) ) {
			return TaxArea::EEA_OWN_VAT;
		}
		return TaxArea::THIRD_COUNTRY;
	}

	/**
	 * The country whose VAT rules apply to an address country: Monaco counts as France
	 * (Art. 7 (1) VAT Directive); every other code stays as it is.
	 *
	 * @param string $country ISO code.
	 */
	public static function vat_country( string $country ): string {
		$iso = self::iso( $country );
		return self::TREATED_AS_MEMBER_STATE[ $iso ] ?? $iso;
	}

	/**
	 * Maps a VAT ID prefix to the ISO country code (EL -> GR). XI stays XI.
	 *
	 * @param string $prefix VAT prefix.
	 */
	public static function prefix_to_iso( string $prefix ): string {
		$prefix = strtoupper( $prefix );
		return self::VIES_GREECE === $prefix ? 'GR' : $prefix;
	}

	/**
	 * Maps an ISO country code to the VAT ID prefix (GR -> EL).
	 *
	 * @param string $iso ISO code.
	 */
	public static function iso_to_prefix( string $iso ): string {
		$iso = strtoupper( $iso );
		return 'GR' === $iso ? self::VIES_GREECE : $iso;
	}

	/**
	 * Normalises to upper-case ISO alpha-2.
	 *
	 * @param string $country Country code in any case, or VAT prefix.
	 */
	private static function iso( string $country ): string {
		return self::prefix_to_iso( trim( $country ) );
	}
}

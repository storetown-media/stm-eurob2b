<?php
/**
 * Movement Reference Number of an export declaration.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Export;

/**
 * TAX-032: the MRN (Movement Reference Number, in German "Versendungsbezugsnummer") that the customs
 * system gives an export declaration (§ 10 (3) UStDV, Art. 226 Implementing Regulation (EU)
 * 2015/2447). Pure: no WordPress calls.
 *
 * Format (country-matrix §18, AN-06): 18 characters, the last two digits of the year (2 digits), the
 * ISO alpha-2 code of the declaring country (2 letters), an identifier (13 letters or digits) and one
 * check character (a letter or a digit). Field 4 is "a check digit for the whole MRN"; the algorithm
 * is not stated in the sources found, so it is UNVERIFIED and only the format is checked.
 */
final class Mrn {

	/**
	 * Length of an MRN.
	 */
	public const LENGTH = 18;

	/**
	 * Pattern of the format: year, country, identifier, check character.
	 */
	private const PATTERN = '/^[0-9]{2}[A-Z]{2}[A-Z0-9]{13}[A-Z0-9]$/';

	/**
	 * Upper case without spaces, dashes or dots (people copy the number from printed documents).
	 *
	 * @param string $mrn Entered value.
	 */
	public static function normalize( string $mrn ): string {
		return strtoupper( (string) preg_replace( '/[\s\-.]+/u', '', $mrn ) );
	}

	/**
	 * Whether a value has the format of an MRN (after normalize()). The check character is not
	 * verified (algorithm UNVERIFIED).
	 *
	 * @param string $mrn Value.
	 */
	public static function is_valid_format( string $mrn ): bool {
		return 1 === preg_match( self::PATTERN, self::normalize( $mrn ) );
	}

	/**
	 * Why a value is no MRN: '' when the format holds, else `length`, `year`, `country` or `characters`.
	 *
	 * @param string $mrn Value.
	 */
	public static function format_error( string $mrn ): string {
		$mrn = self::normalize( $mrn );
		if ( self::LENGTH !== strlen( $mrn ) ) {
			return 'length';
		}
		if ( 1 !== preg_match( '/^[0-9]{2}/', $mrn ) ) {
			return 'year';
		}
		if ( 1 !== preg_match( '/^[0-9]{2}[A-Z]{2}/', $mrn ) ) {
			return 'country';
		}
		return self::is_valid_format( $mrn ) ? '' : 'characters';
	}

	/**
	 * Country code of an MRN in the valid format ('' otherwise).
	 *
	 * @param string $mrn Value.
	 */
	public static function country( string $mrn ): string {
		$mrn = self::normalize( $mrn );
		return self::is_valid_format( $mrn ) ? substr( $mrn, 2, 2 ) : '';
	}
}

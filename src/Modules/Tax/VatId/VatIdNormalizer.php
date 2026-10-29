<?php
/**
 * VAT ID normaliser.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId;

/**
 * Turns user input into a VatId or a syntax error, without any network call.
 *
 * Syntax patterns per EU member state follow the structure table published by
 * the European Commission (VIES FAQ, question 11). They are a necessary
 * pre-check only: a syntactically valid number can still be unregistered.
 * Non-EU formats (CHE, NO) are handled here too, with their own prefixes.
 */
final class VatIdNormalizer {

	/**
	 * National-part patterns by VAT prefix.
	 *
	 * Source: https://ec.europa.eu/taxation_customs/vies/#/faq (structure per member state).
	 * XI (Northern Ireland) follows the UK structure: 9 or 12 digits, or GD/HA + 3 digits.
	 * CHE: Swiss UID, 8 digits + check digit (UIDV Art. 5); also used by Liechtenstein businesses
	 * that have a UID. LI: the five-digit Liechtenstein VAT number, which the UID did not replace
	 * (Liechtenstein National Administration, UID FAQ). NO: 9-digit organisation number, which is
	 * also the VAT number (Brønnøysund Register Centre). CHE and NO also pass check_digit().
	 */
	public const PATTERNS = array(
		'AT'  => '/^U\d{8}$/',
		'BE'  => '/^[01]\d{9}$/',
		'BG'  => '/^\d{9,10}$/',
		'CY'  => '/^\d{8}[A-Z]$/',
		'CZ'  => '/^\d{8,10}$/',
		'DE'  => '/^\d{9}$/',
		'DK'  => '/^\d{8}$/',
		'EE'  => '/^\d{9}$/',
		'EL'  => '/^\d{9}$/',
		'ES'  => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
		'FI'  => '/^\d{8}$/',
		'FR'  => '/^[A-HJ-NP-Z0-9]{2}\d{9}$/',
		'HR'  => '/^\d{11}$/',
		'HU'  => '/^\d{8}$/',
		'IE'  => '/^(\d{7}[A-W][A-I]?|\d[A-Z+*]\d{5}[A-W])$/',
		'IT'  => '/^\d{11}$/',
		'LT'  => '/^(\d{9}|\d{12})$/',
		'LU'  => '/^\d{8}$/',
		'LV'  => '/^\d{11}$/',
		'MT'  => '/^\d{8}$/',
		'NL'  => '/^\d{9}B\d{2}$/',
		'PL'  => '/^\d{10}$/',
		'PT'  => '/^\d{9}$/',
		'RO'  => '/^\d{2,10}$/',
		'SE'  => '/^\d{10}01$/',
		'SI'  => '/^\d{8}$/',
		'SK'  => '/^\d{10}$/',
		'XI'  => '/^(\d{9}|\d{12}|(GD|HA)\d{3})$/',
		'CHE' => '/^\d{9}$/',
		'LI'  => '/^\d{5}$/',
		'NO'  => '/^\d{9}$/',
	);

	/**
	 * Modulus 11 weights of the first eight digits; the ninth digit is the check digit.
	 *
	 * CHE: eCH-0097 version 5.2 §2.4.2 (the algorithm of the UID; the standard was withdrawn in 2021,
	 * the UID itself is unchanged, UIDV Art. 5); NO: brreg.no, "Organisasjonsnummeret". In both, the
	 * check digit is 11 minus the remainder, 0 when the remainder is 0, and no number is issued when
	 * the result would be 10.
	 */
	private const CHECK_WEIGHTS = array(
		'CHE' => array( 5, 4, 3, 2, 7, 6, 5, 4 ),
		'NO'  => array( 3, 2, 7, 6, 5, 4, 3, 2 ),
	);

	/**
	 * Suffixes that are part of how the number is commonly written but not part of the number.
	 */
	private const STRIP_SUFFIXES = array( 'MWST', 'TVA', 'IVA', 'MVA' );

	/**
	 * Normalises input. Returns a VatId on success or a reason code string on failure.
	 *
	 * Reason codes: "empty", "unknown_prefix", "syntax". The caller decides how to
	 * phrase them for the user, so this class stays free of translations.
	 *
	 * @param string      $input        What the customer typed.
	 * @param string|null $country_hint ISO country of the billing address, used when the input has no prefix.
	 */
	public function normalize( string $input, ?string $country_hint = null ): VatId|string {
		$raw     = $input;
		$compact = strtoupper( (string) preg_replace( '/[\s.\-\/]+/u', '', $input ) );

		if ( '' === $compact ) {
			return 'empty';
		}

		foreach ( self::STRIP_SUFFIXES as $suffix ) {
			if ( str_ends_with( $compact, $suffix ) && strlen( $compact ) > strlen( $suffix ) ) {
				$compact = substr( $compact, 0, -strlen( $suffix ) );
				break;
			}
		}

		$prefix = null;
		$number = $compact;

		if ( str_starts_with( $compact, 'CHE' ) ) {
			$prefix = 'CHE';
			$number = substr( $compact, 3 );
		} elseif ( preg_match( '/^([A-Z]{2})(.+)$/', $compact, $m ) ) {
			$candidate = 'GR' === $m[1] ? 'EL' : $m[1];
			if ( isset( self::PATTERNS[ $candidate ] ) ) {
				$prefix = $candidate;
				$number = $m[2];
			}
		}

		if ( null === $prefix ) {
			if ( null === $country_hint || '' === trim( $country_hint ) ) {
				return 'unknown_prefix';
			}
			$hint = strtoupper( trim( $country_hint ) );
			$hint = match ( $hint ) {
				'GR' => 'EL',
				// Monaco businesses are registered for French VAT (Art. 7 (1) VAT Directive).
				'MC' => 'FR',
				'CH' => 'CHE',
				// Liechtenstein businesses use their five-digit VAT number or, with a UID, a CHE number.
				'LI' => 1 === preg_match( self::PATTERNS['LI'], $compact ) ? 'LI' : 'CHE',
				default => $hint,
			};
			if ( ! isset( self::PATTERNS[ $hint ] ) ) {
				return 'unknown_prefix';
			}
			$prefix = $hint;
			$number = $compact;
		}

		if ( ! preg_match( self::PATTERNS[ $prefix ], $number ) || ! self::check_digit( $prefix, $number ) ) {
			return 'syntax';
		}

		return new VatId( $prefix, $number, $raw );
	}

	/**
	 * Whether the check digit of a CHE or NO number is right (true for prefixes without one).
	 *
	 * @param string $prefix VAT prefix.
	 * @param string $number National part, digits only for CHE and NO.
	 */
	public static function check_digit( string $prefix, string $number ): bool {
		$weights = self::CHECK_WEIGHTS[ strtoupper( $prefix ) ] ?? null;
		if ( null === $weights ) {
			return true;
		}
		if ( 1 !== preg_match( '/^\d{9}$/', $number ) ) {
			return false;
		}
		$sum = 0;
		foreach ( $weights as $i => $weight ) {
			$sum += $weight * (int) $number[ $i ];
		}
		$check = ( 11 - $sum % 11 ) % 11;
		return 10 !== $check && (int) $number[8] === $check;
	}

	/**
	 * Whether a prefix is known to this normaliser.
	 *
	 * @param string $prefix VAT prefix.
	 */
	public function supports_prefix( string $prefix ): bool {
		return isset( self::PATTERNS[ strtoupper( $prefix ) ] );
	}
}

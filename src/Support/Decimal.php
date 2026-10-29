<?php
/**
 * Exact comparison of decimal strings.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

/**
 * Prices travel through WooCommerce as decimal strings. Comparing them as floats collapses
 * distinct values beyond float precision, so every comparison of two price strings goes
 * through this class. Money handles arithmetic; this handles ordering of raw strings.
 */
final class Decimal {

	/**
	 * Compares two decimal strings exactly: -1, 0 or 1. Signs are supported; a malformed
	 * value counts as zero (callers validate input before comparing).
	 *
	 * @param string $a Decimal string.
	 * @param string $b Decimal string.
	 */
	public static function compare( string $a, string $b ): int {
		[ $a_negative, $ai, $af ] = self::split( $a );
		[ $b_negative, $bi, $bf ] = self::split( $b );

		$a_zero = '0' === $ai && '' === $af;
		$b_zero = '0' === $bi && '' === $bf;
		if ( $a_zero && $b_zero ) {
			return 0;
		}
		$a_negative = $a_negative && ! $a_zero;
		$b_negative = $b_negative && ! $b_zero;
		if ( $a_negative !== $b_negative ) {
			return $a_negative ? -1 : 1;
		}

		$magnitude = self::compare_magnitude( $ai, $af, $bi, $bf );
		return $a_negative ? -$magnitude : $magnitude;
	}

	/**
	 * Rounds a decimal string to $scale fraction digits, half away from zero, without floats.
	 * The result always carries exactly $scale fraction digits (none when $scale is 0).
	 *
	 * @param string $value Decimal string (optional sign).
	 * @param int    $scale Fraction digits (0-12).
	 */
	public static function round( string $value, int $scale ): string {
		$scale                             = max( 0, min( 12, $scale ) );
		[ $negative, $integer, $fraction ] = self::split( $value );
		if ( strlen( $fraction ) <= $scale ) {
			$fraction = str_pad( $fraction, $scale, '0' );
		} else {
			$round_up = (int) $fraction[ $scale ] >= 5;
			$fraction = substr( $fraction, 0, $scale );
			if ( $round_up ) {
				$digits   = self::increment( $integer . $fraction );
				$integer  = '' === $digits ? '0' : ( $scale > 0 ? substr( $digits, 0, -$scale ) : $digits );
				$fraction = $scale > 0 ? substr( $digits, -$scale ) : '';
				$integer  = '' === $integer ? '0' : $integer;
			}
		}
		$result = $integer . ( $scale > 0 ? '.' . $fraction : '' );
		$zero   = '' === ltrim( str_replace( '.', '', $result ), '0' );
		return ( $negative && ! $zero ? '-' : '' ) . $result;
	}

	/**
	 * Adds one to a string of digits (arbitrary length).
	 *
	 * @param string $digits Digits.
	 */
	private static function increment( string $digits ): string {
		$digits = '' === $digits ? '0' : $digits;
		$i      = strlen( $digits ) - 1;
		while ( $i >= 0 ) {
			if ( '9' !== $digits[ $i ] ) {
				$digits[ $i ] = (string) ( (int) $digits[ $i ] + 1 );
				return $digits;
			}
			$digits[ $i ] = '0';
			--$i;
		}
		return '1' . $digits;
	}

	/**
	 * Whether $a is lower than $b.
	 *
	 * @param string $a Decimal string.
	 * @param string $b Decimal string.
	 */
	public static function lt( string $a, string $b ): bool {
		return self::compare( $a, $b ) < 0;
	}

	/**
	 * Sign, integer digits (no leading zeros, "0" when empty) and fraction digits (no trailing zeros).
	 *
	 * @param string $value Decimal string.
	 * @return array{0: bool, 1: string, 2: string}
	 */
	private static function split( string $value ): array {
		$value    = trim( $value );
		$negative = str_starts_with( $value, '-' );
		$parts    = explode( '.', ltrim( $value, '+-' ), 2 );
		$integer  = ltrim( (string) preg_replace( '/\D/', '', $parts[0] ), '0' );
		$fraction = rtrim( (string) preg_replace( '/\D/', '', $parts[1] ?? '' ), '0' );
		return array( $negative, '' === $integer ? '0' : $integer, $fraction );
	}

	/**
	 * Compares absolute values.
	 *
	 * @param string $ai Integer digits of a.
	 * @param string $af Fraction digits of a.
	 * @param string $bi Integer digits of b.
	 * @param string $bf Fraction digits of b.
	 */
	private static function compare_magnitude( string $ai, string $af, string $bi, string $bf ): int {
		if ( strlen( $ai ) !== strlen( $bi ) ) {
			return strlen( $ai ) < strlen( $bi ) ? -1 : 1;
		}
		$cmp = strcmp( $ai, $bi );
		if ( 0 !== $cmp ) {
			return $cmp < 0 ? -1 : 1;
		}
		$length = max( strlen( $af ), strlen( $bf ) );
		$cmp    = strcmp( str_pad( $af, $length, '0' ), str_pad( $bf, $length, '0' ) );
		return 0 === $cmp ? 0 : ( $cmp < 0 ? -1 : 1 );
	}
}

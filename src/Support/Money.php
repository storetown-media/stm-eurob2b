<?php
/**
 * Money value object.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

use InvalidArgumentException;
use OverflowException;

/**
 * Immutable amount in minor units (e.g. cents) with an explicit currency.
 *
 * All B2B price rules calculate with this class and only convert to the float
 * WooCommerce expects at the very end (see docs/architecture.md, "Money").
 * Floats are deliberately not accepted as input: callers pass decimal strings
 * or integers, so rounding happens exactly once, where it is visible.
 *
 * Every arithmetic step is overflow-checked: PHP silently turns an integer
 * overflow into a float, which would corrupt an amount without any error.
 * Instead, an OverflowException is thrown and the amount stays unchanged.
 */
final class Money {

	public const ROUND_HALF_UP   = PHP_ROUND_HALF_UP;
	public const ROUND_HALF_EVEN = PHP_ROUND_HALF_EVEN;

	/**
	 * Maximum number of digits of a minor-unit amount (PHP_INT_MAX has 19).
	 */
	public const MAX_DIGITS = 18;

	/**
	 * Maximum significant decimals accepted for a percentage.
	 */
	public const MAX_PERCENT_DECIMALS = 8;

	/**
	 * Amount in minor units.
	 *
	 * @var int
	 */
	private int $minor;

	/**
	 * ISO 4217 currency code, upper case.
	 *
	 * @var string
	 */
	private string $currency;

	/**
	 * Number of decimal places of the currency (2 for EUR, 0 for HUF when configured so).
	 *
	 * @var int
	 */
	private int $scale;

	/**
	 * Constructor.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO 4217 code.
	 * @param int    $scale    Decimal places (0..4).
	 */
	private function __construct( int $minor, string $currency, int $scale ) {
		$this->minor    = $minor;
		$this->currency = $currency;
		$this->scale    = $scale;
	}

	/**
	 * Creates an amount from minor units.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO 4217 code.
	 * @param int    $scale    Decimal places.
	 */
	public static function of_minor( int $minor, string $currency, int $scale = 2 ): self {
		return new self( $minor, self::normalise_currency( $currency ), self::check_scale( $scale ) );
	}

	/**
	 * Creates an amount from a decimal string such as "19.99" or "-0.5".
	 *
	 * Extra decimals beyond the scale are rejected, not rounded, because a
	 * silently rounded price is the classic source of one-cent discrepancies.
	 *
	 * @param string|int $amount   Decimal string (dot as separator) or integer.
	 * @param string     $currency ISO 4217 code.
	 * @param int        $scale    Decimal places.
	 * @throws InvalidArgumentException On malformed input or too many decimals.
	 * @throws OverflowException When the amount does not fit into MAX_DIGITS minor units.
	 */
	public static function of_decimal( string|int $amount, string $currency, int $scale = 2 ): self {
		$scale  = self::check_scale( $scale );
		$amount = trim( (string) $amount );

		if ( ! preg_match( '/^(-)?(\d+)(?:\.(\d+))?$/', $amount, $m ) ) {
			throw new InvalidArgumentException( sprintf( 'Money: "%s" is not a decimal amount.', $amount ) );
		}

		$negative = '-' === $m[1];
		$integer  = $m[2];
		$fraction = $m[3] ?? '';

		if ( strlen( $fraction ) > $scale ) {
			throw new InvalidArgumentException( sprintf( 'Money: "%s" has more than %d decimals.', $amount, $scale ) );
		}

		$fraction = str_pad( $fraction, $scale, '0' );
		$digits   = ltrim( $integer . $fraction, '0' );

		if ( strlen( $digits ) > self::MAX_DIGITS ) {
			throw new OverflowException( sprintf( 'Money: "%s" exceeds %d digits in minor units.', $amount, self::MAX_DIGITS ) );
		}

		$minor = (int) ( $integer . $fraction );

		return new self( $negative ? -$minor : $minor, self::normalise_currency( $currency ), $scale );
	}

	/**
	 * Converts a float coming from WooCommerce. The only place where floats enter.
	 *
	 * @param float  $amount   Float amount.
	 * @param string $currency ISO 4217 code.
	 * @param int    $scale    Decimal places.
	 * @param int    $mode     Rounding mode.
	 * @throws OverflowException When the float is not finite or exceeds the supported range.
	 */
	public static function of_float( float $amount, string $currency, int $scale = 2, int $mode = self::ROUND_HALF_UP ): self {
		$scale    = self::check_scale( $scale );
		$php_mode = self::ROUND_HALF_EVEN === $mode ? PHP_ROUND_HALF_EVEN : PHP_ROUND_HALF_UP;
		$scaled   = round( $amount * ( 10 ** $scale ), 0, $php_mode );

		// Casting a float beyond the integer range is undefined; refuse it instead.
		if ( ! is_finite( $scaled ) || abs( $scaled ) >= 10 ** self::MAX_DIGITS ) {
			throw new OverflowException( sprintf( 'Money: float %s exceeds the supported range.', (string) $amount ) );
		}

		return new self( (int) $scaled, self::normalise_currency( $currency ), $scale );
	}

	/**
	 * Zero in the given currency.
	 *
	 * @param string $currency ISO 4217 code.
	 * @param int    $scale    Decimal places.
	 */
	public static function zero( string $currency, int $scale = 2 ): self {
		return self::of_minor( 0, $currency, $scale );
	}

	/**
	 * Minor units.
	 */
	public function minor(): int {
		return $this->minor;
	}

	/**
	 * Currency code.
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * Decimal places.
	 */
	public function scale(): int {
		return $this->scale;
	}

	/**
	 * Adds another amount of the same currency.
	 *
	 * @param Money $other Addend.
	 */
	public function add( Money $other ): self {
		$this->assert_same_currency( $other );
		return new self( self::checked_add( $this->minor, $other->minor ), $this->currency, $this->scale );
	}

	/**
	 * Subtracts another amount of the same currency.
	 *
	 * @param Money $other Subtrahend.
	 */
	public function subtract( Money $other ): self {
		$this->assert_same_currency( $other );
		return new self( self::checked_add( $this->minor, self::checked_negate( $other->minor ) ), $this->currency, $this->scale );
	}

	/**
	 * Multiplies by an integer quantity. Exact, no rounding.
	 *
	 * @param int $quantity Quantity.
	 */
	public function multiply( int $quantity ): self {
		return new self( self::checked_mul( $this->minor, $quantity ), $this->currency, $this->scale );
	}

	/**
	 * Applies a percentage given as decimal string, e.g. "19" or "7.5", rounding once.
	 *
	 * @param string|int $percent Percentage (may be negative).
	 * @param int        $mode    Rounding mode.
	 * @throws InvalidArgumentException On malformed percentage or too many significant decimals.
	 * @throws OverflowException When the percentage or the product leaves the integer range.
	 */
	public function percentage( string|int $percent, int $mode = self::ROUND_HALF_UP ): self {
		$percent = trim( (string) $percent );
		if ( ! preg_match( '/^(-)?(\d+)(?:\.(\d+))?$/', $percent, $m ) ) {
			throw new InvalidArgumentException( sprintf( 'Money: "%s" is not a percentage.', $percent ) );
		}
		$fraction = rtrim( $m[3] ?? '', '0' );
		$digits   = strlen( $fraction );

		if ( $digits > self::MAX_PERCENT_DECIMALS ) {
			throw new InvalidArgumentException( sprintf( 'Money: percentage "%s" has more than %d significant decimals.', $percent, self::MAX_PERCENT_DECIMALS ) );
		}
		if ( strlen( ltrim( $m[2], '0' ) ) + $digits > self::MAX_DIGITS ) {
			throw new OverflowException( sprintf( 'Money: percentage "%s" is too large.', $percent ) );
		}

		$factor = (int) ( $m[2] . $fraction );
		// percent scaled by 10^digits.
		$divisor = 100 * ( 10 ** $digits );
		// Back to a ratio; at most 10^10, always an int.
		$sign = '-' === $m[1] ? -1 : 1;

		$product = self::checked_mul( self::checked_mul( $this->minor, $factor ), $sign );

		return new self( self::div_round( $product, $divisor, $mode ), $this->currency, $this->scale );
	}

	/**
	 * Multiplies by the fraction numerator / denominator and rounds once, e.g. interest as
	 * amount × rate in basis points × days / (10000 × 365) (PAY-021). An OverflowException is
	 * thrown when the product leaves the integer range.
	 *
	 * @param int $numerator   Numerator (may be negative).
	 * @param int $denominator Denominator (> 0).
	 * @param int $mode        Rounding mode.
	 * @throws InvalidArgumentException When the denominator is not positive.
	 */
	public function multiply_fraction( int $numerator, int $denominator, int $mode = self::ROUND_HALF_UP ): self {
		if ( $denominator < 1 ) {
			throw new InvalidArgumentException( 'Money: the denominator must be positive.' );
		}
		return new self( self::div_round( self::checked_mul( $this->minor, $numerator ), $denominator, $mode ), $this->currency, $this->scale );
	}

	/**
	 * Splits the amount into n parts whose sum equals the original (largest remainder).
	 *
	 * @param int $parts Number of parts (>= 1).
	 * @return Money[]
	 * @throws InvalidArgumentException When parts < 1.
	 */
	public function allocate( int $parts ): array {
		if ( $parts < 1 ) {
			throw new InvalidArgumentException( 'Money: cannot allocate into less than one part.' );
		}
		$base      = intdiv( $this->minor, $parts );
		$remainder = $this->minor - ( $base * $parts );
		$result    = array();
		for ( $i = 0; $i < $parts; $i++ ) {
			$extra    = $i < abs( $remainder ) ? ( $remainder < 0 ? -1 : 1 ) : 0;
			$result[] = new self( $base + $extra, $this->currency, $this->scale );
		}
		return $result;
	}

	/**
	 * Negates the amount.
	 */
	public function negate(): self {
		return new self( self::checked_negate( $this->minor ), $this->currency, $this->scale );
	}

	/**
	 * Whether the amount is zero.
	 */
	public function is_zero(): bool {
		return 0 === $this->minor;
	}

	/**
	 * Whether the amount is below zero.
	 */
	public function is_negative(): bool {
		return $this->minor < 0;
	}

	/**
	 * Compares two amounts: -1, 0 or 1.
	 *
	 * @param Money $other Other amount.
	 */
	public function compare( Money $other ): int {
		$this->assert_same_currency( $other );
		return $this->minor <=> $other->minor;
	}

	/**
	 * Whether both amounts are equal (same currency and minor units).
	 *
	 * @param Money $other Other amount.
	 */
	public function equals( Money $other ): bool {
		return $this->currency === $other->currency && $this->scale === $other->scale && $this->minor === $other->minor;
	}

	/**
	 * Decimal string with a dot separator and exactly `scale` decimals, e.g. "-19.90".
	 */
	public function to_decimal(): string {
		$abs      = abs( $this->minor );
		$integer  = intdiv( $abs, 10 ** $this->scale );
		$fraction = $abs - ( $integer * ( 10 ** $this->scale ) );
		$out      = (string) $integer;
		if ( $this->scale > 0 ) {
			$out .= '.' . str_pad( (string) $fraction, $this->scale, '0', STR_PAD_LEFT );
		}
		return ( $this->minor < 0 ? '-' : '' ) . $out;
	}

	/**
	 * Float for hand-over to WooCommerce. Use only at the boundary.
	 */
	public function to_float(): float {
		return (float) $this->to_decimal();
	}

	/**
	 * String representation, e.g. "EUR 19.90".
	 */
	public function __toString(): string {
		return $this->currency . ' ' . $this->to_decimal();
	}

	/**
	 * Integer division with explicit rounding, safe for negative values.
	 *
	 * @param int $dividend Dividend.
	 * @param int $divisor  Divisor (> 0).
	 * @param int $mode     Rounding mode.
	 */
	private static function div_round( int $dividend, int $divisor, int $mode ): int {
		$quotient  = intdiv( $dividend, $divisor );
		$remainder = $dividend % $divisor;
		if ( 0 === $remainder ) {
			return $quotient;
		}
		$twice = abs( $remainder ) * 2;
		$sign  = $dividend < 0 ? -1 : 1;

		if ( $twice > $divisor ) {
			return $quotient + $sign;
		}
		if ( $twice < $divisor ) {
			return $quotient;
		}
		// Exactly half.
		if ( self::ROUND_HALF_EVEN === $mode ) {
			return 0 === $quotient % 2 ? $quotient : $quotient + $sign;
		}
		return $quotient + $sign;
	}

	/**
	 * Addition that refuses to overflow (PHP would silently return a float).
	 *
	 * @param int $a First operand.
	 * @param int $b Second operand.
	 * @throws OverflowException When the result leaves the integer range.
	 */
	private static function checked_add( int $a, int $b ): int {
		if ( ( $b > 0 && $a > PHP_INT_MAX - $b ) || ( $b < 0 && $a < PHP_INT_MIN - $b ) ) {
			throw new OverflowException( 'Money: addition overflow.' );
		}
		return $a + $b;
	}

	/**
	 * Multiplication that refuses to overflow.
	 *
	 * @param int $a First operand.
	 * @param int $b Second operand.
	 * @throws OverflowException When the result leaves the integer range.
	 */
	private static function checked_mul( int $a, int $b ): int {
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}
		if ( 1 === $a ) {
			return $b;
		}
		if ( 1 === $b ) {
			return $a;
		}
		// With |other operand| >= 2, PHP_INT_MIN can never be multiplied safely.
		if ( PHP_INT_MIN === $a || PHP_INT_MIN === $b ) {
			throw new OverflowException( 'Money: multiplication overflow.' );
		}
		// Conservative bound: rejects exactly PHP_INT_MIN as a product too, which is acceptable.
		if ( abs( $a ) > intdiv( PHP_INT_MAX, abs( $b ) ) ) {
			throw new OverflowException( 'Money: multiplication overflow.' );
		}
		return $a * $b;
	}

	/**
	 * Negation that refuses PHP_INT_MIN (its negation does not fit).
	 *
	 * @param int $a Operand.
	 * @throws OverflowException When the operand is PHP_INT_MIN.
	 */
	private static function checked_negate( int $a ): int {
		if ( PHP_INT_MIN === $a ) {
			throw new OverflowException( 'Money: negation overflow.' );
		}
		return -$a;
	}

	/**
	 * Guards against mixing currencies.
	 *
	 * @param Money $other Other amount.
	 * @throws InvalidArgumentException On mismatch.
	 */
	private function assert_same_currency( Money $other ): void {
		if ( $this->currency !== $other->currency || $this->scale !== $other->scale ) {
			throw new InvalidArgumentException( sprintf( 'Money: cannot combine %s with %s.', $this->currency, $other->currency ) );
		}
	}

	/**
	 * Upper-cases and validates the currency code.
	 *
	 * @param string $currency ISO 4217 code.
	 * @throws InvalidArgumentException When not three letters.
	 */
	private static function normalise_currency( string $currency ): string {
		$currency = strtoupper( trim( $currency ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new InvalidArgumentException( sprintf( 'Money: "%s" is not an ISO 4217 currency code.', $currency ) );
		}
		return $currency;
	}

	/**
	 * Validates the scale.
	 *
	 * @param int $scale Decimal places.
	 * @throws InvalidArgumentException When outside 0..4.
	 */
	private static function check_scale( int $scale ): int {
		if ( $scale < 0 || $scale > 4 ) {
			throw new InvalidArgumentException( 'Money: scale must be between 0 and 4.' );
		}
		return $scale;
	}
}

<?php
/**
 * Price adjustment (discount or markup).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Support\Decimal;
use EuroB2B\Support\Money;
use InvalidArgumentException;

/**
 * One adjustment of step 4 of the pipeline. Negative amounts reduce, positive raise the price.
 */
final class Adjustment {

	public const TYPE_PERCENT = 'percent';
	public const TYPE_FIXED   = 'fixed';

	/**
	 * Constructor.
	 *
	 * @param string $type     percent or fixed.
	 * @param string $amount   Percent ("-10" = 10 % off) or fixed amount in major units ("-2.50").
	 * @param int    $priority Priority; higher is applied first (or alone, without stacking).
	 * @param string $label    Human label (shown next to discounts later).
	 * @param bool   $in_cart  PRC-018: the discount is shown as a discount line in the cart instead of
	 *                         lowering the price shown with the product. It counts in the decision
	 *                         (priority, stacking) like every adjustment.
	 * @param string $key      Stable key of the source (e.g. `rule:12`); names the cart discount line.
	 * @throws InvalidArgumentException On unknown type or malformed amount.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $amount,
		public readonly int $priority = 10,
		public readonly string $label = '',
		public readonly bool $in_cart = false,
		public readonly string $key = ''
	) {
		if ( ! in_array( $type, array( self::TYPE_PERCENT, self::TYPE_FIXED ), true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown adjustment type "%s".', $type ) );
		}
		if ( 1 !== preg_match( '/^-?\d+(\.(\d+))?$/', $amount, $m ) ) {
			throw new InvalidArgumentException( sprintf( 'Malformed adjustment amount "%s".', $amount ) );
		}
		// A percentage beyond Money's precision would fail at resolution time for every affected
		// product; refuse it when the provider builds the adjustment instead.
		if ( self::TYPE_PERCENT === $type && strlen( rtrim( $m[2] ?? '', '0' ) ) > Money::MAX_PERCENT_DECIMALS ) {
			throw new InvalidArgumentException( sprintf( 'Percentage "%s" has more than %d decimals.', $amount, Money::MAX_PERCENT_DECIMALS ) );
		}
	}

	/**
	 * Applies the adjustment. Prices never drop below zero. A fixed amount with more decimals than
	 * the price scale is rounded half away from zero to that scale (the documented single rounding).
	 *
	 * @param Money $price Price before.
	 */
	public function apply( Money $price ): Money {
		if ( self::TYPE_PERCENT === $this->type ) {
			$result = $price->add( $price->percentage( $this->amount ) );
		} else {
			$result = $price->add( Money::of_decimal( Decimal::round( $this->amount, $price->scale() ), $price->currency(), $price->scale() ) );
		}
		return $result->is_negative() ? Money::zero( $price->currency(), $price->scale() ) : $result;
	}
}

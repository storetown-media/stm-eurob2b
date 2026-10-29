<?php
/**
 * Pure pipeline steps (no WordPress or WooCommerce calls), unit-tested.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Support\Money;

/**
 * Deterministic selection and adjustment logic shared by the resolver and its tests.
 */
final class PricePipeline {

	/**
	 * Picks the replacement price: highest specificity wins; on a tie the earlier candidate wins.
	 *
	 * @param ResolvedPrice[] $candidates Candidates (at least the base price).
	 * @return ResolvedPrice|null Null when no candidates were given.
	 */
	public static function select( array $candidates ): ?ResolvedPrice {
		$winner = null;
		foreach ( $candidates as $candidate ) {
			if ( null === $winner || $candidate->specificity > $winner->specificity ) {
				$winner = $candidate;
			}
		}
		return $winner;
	}

	/**
	 * Applies adjustments. Without stacking only the highest priority adjustment applies (ties:
	 * the first given). With stacking every adjustment applies in descending priority order.
	 *
	 * @param Money        $price       Price before adjustments.
	 * @param Adjustment[] $adjustments Adjustments.
	 * @param bool         $stack       Whether adjustments stack.
	 */
	public static function adjust( Money $price, array $adjustments, bool $stack ): Money {
		foreach ( self::applied( $adjustments, $stack ) as $adjustment ) {
			$price = $adjustment->apply( $price );
		}
		return $price;
	}

	/**
	 * PRC-018: the same decision as adjust(), split into what the product shows and what the cart
	 * takes off. `final` is exactly adjust()'s result. `shown` applies only the adjustments that are
	 * not shown in the cart, in the same order. `cart` holds, per adjustment key, the amount per unit
	 * that the cart discount line takes off: the difference each cart adjustment makes when the cart
	 * adjustments are added one by one in the applied order, so the amounts add up to shown − final
	 * exactly. Every step of the pipeline is monotone (percentages, fixed amounts, the floor at zero),
	 * so no amount is negative.
	 *
	 * @param Money        $price       Price before adjustments.
	 * @param Adjustment[] $adjustments Adjustments.
	 * @param bool         $stack       Whether adjustments stack.
	 * @return array{shown: Money, final: Money, cart: array<string, Money>}
	 */
	public static function split( Money $price, array $adjustments, bool $stack ): array {
		$applied = self::applied( $adjustments, $stack );
		$shown   = self::apply_subset( $price, $applied, array() );
		$cart    = array();
		$taken   = array();
		$before  = $shown;
		foreach ( $applied as $index => $adjustment ) {
			if ( ! $adjustment->in_cart ) {
				continue;
			}
			$taken[ $index ] = true;
			$after           = self::apply_subset( $price, $applied, $taken );
			$difference      = $before->subtract( $after );
			$before          = $after;
			if ( $difference->is_zero() || $difference->is_negative() ) {
				continue;
			}
			$key          = '' !== $adjustment->key ? $adjustment->key : 'adjustment:' . $index;
			$cart[ $key ] = isset( $cart[ $key ] ) ? $cart[ $key ]->add( $difference ) : $difference;
		}
		return array(
			'shown' => $shown,
			'final' => $before,
			'cart'  => $cart,
		);
	}

	/**
	 * The adjustments that apply, in the order they apply: by priority (descending, stable: the
	 * given order breaks ties); without stacking only the first.
	 *
	 * @param Adjustment[] $adjustments Adjustments.
	 * @param bool         $stack       Whether adjustments stack.
	 * @return Adjustment[]
	 */
	private static function applied( array $adjustments, bool $stack ): array {
		if ( empty( $adjustments ) ) {
			return array();
		}
		$ordered = array_values( $adjustments );
		// Stable sort by priority (descending); array index breaks ties.
		$keys = array_keys( $ordered );
		usort(
			$keys,
			static function ( int $a, int $b ) use ( $ordered ): int {
				return $ordered[ $b ]->priority <=> $ordered[ $a ]->priority ?: $a <=> $b;
			}
		);
		$applied = array();
		foreach ( $keys as $key ) {
			$applied[] = $ordered[ $key ];
			if ( ! $stack ) {
				break;
			}
		}
		return $applied;
	}

	/**
	 * Applies the adjustments that are not shown in the cart plus the cart adjustments named in
	 * `$cart_taken`, in the given order.
	 *
	 * @param Money            $price      Price before adjustments.
	 * @param Adjustment[]     $applied    Applied adjustments in order.
	 * @param array<int, true> $cart_taken Indexes of the cart adjustments to include.
	 */
	private static function apply_subset( Money $price, array $applied, array $cart_taken ): Money {
		foreach ( $applied as $index => $adjustment ) {
			if ( ! $adjustment->in_cart || isset( $cart_taken[ $index ] ) ) {
				$price = $adjustment->apply( $price );
			}
		}
		return $price;
	}
}

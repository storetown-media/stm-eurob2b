<?php
/**
 * Quantity tier.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use InvalidArgumentException;

/**
 * "From quantity N the unit price is P". A tier set is a list of tiers with distinct minimums; the
 * tier with the highest minimum not above the quantity applies.
 */
final class Tier {

	/**
	 * Constructor.
	 *
	 * @param int    $min   Minimum quantity (>= 1).
	 * @param string $price Unit price (decimal string, >= 0).
	 * @throws InvalidArgumentException Malformed tier.
	 */
	public function __construct(
		public readonly int $min,
		public readonly string $price
	) {
		if ( $min < 1 ) {
			throw new InvalidArgumentException( 'A tier starts at quantity 1 or more.' );
		}
		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $price ) ) {
			throw new InvalidArgumentException( sprintf( 'Malformed tier price "%s".', $price ) );
		}
	}

	/**
	 * Sorts tiers by minimum and drops duplicates (the later entry wins).
	 *
	 * @param Tier[] $tiers Tiers.
	 * @return Tier[]
	 */
	public static function normalise( array $tiers ): array {
		$by_min = array();
		foreach ( $tiers as $tier ) {
			if ( $tier instanceof Tier ) {
				$by_min[ $tier->min ] = $tier;
			}
		}
		ksort( $by_min );
		return array_values( $by_min );
	}

	/**
	 * The tier that applies to a quantity, or null when the quantity is below every tier.
	 *
	 * @param Tier[] $tiers    Normalised tiers.
	 * @param int    $quantity Quantity.
	 */
	public static function for_quantity( array $tiers, int $quantity ): ?Tier {
		$match = null;
		foreach ( $tiers as $tier ) {
			if ( $tier->min <= $quantity ) {
				$match = $tier;
			} else {
				break;
			}
		}
		return $match;
	}

	/**
	 * Storage form.
	 *
	 * @param Tier[] $tiers Tiers.
	 * @return array<int, array{min: int, price: string}>
	 */
	public static function to_array( array $tiers ): array {
		return array_map(
			static fn( Tier $t ): array => array(
				'min'   => $t->min,
				'price' => $t->price,
			),
			self::normalise( $tiers )
		);
	}

	/**
	 * From storage or a submitted form; malformed rows are dropped.
	 *
	 * @param mixed $data Decoded JSON or rows.
	 * @return Tier[]
	 */
	public static function from_array( $data ): array {
		$tiers = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['min'], $row['price'] ) || ! is_scalar( $row['min'] ) || ! is_scalar( $row['price'] ) ) {
				continue;
			}
			try {
				$tiers[] = new Tier( (int) $row['min'], (string) $row['price'] );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
		}
		return self::normalise( $tiers );
	}
}

<?php
/**
 * How the import stores customer prices and tier sets.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

/**
 * Customer prices and tier prices are features of EuroB2B Pro. Its pricing module registers this
 * target in the container; without it the import leaves customer prices, tier sets and the tier
 * table switch out and says so (PLT-015). Group prices are imported either way.
 */
interface PriceTarget {

	/**
	 * Meta key of a customer's own regular or sale price on a product or variation.
	 *
	 * @param int  $user_id User id.
	 * @param bool $sale    Whether the sale price is meant.
	 */
	public function customer_price_key( int $user_id, bool $sale ): string;

	/**
	 * Meta key of the tier set of a group, or of everyone else (null).
	 *
	 * @param int|null $group_id Group id; null for the regular set.
	 */
	public function tiers_key( ?int $group_id ): string;

	/**
	 * Meta key of a customer's own tier set.
	 *
	 * @param int $user_id User id.
	 */
	public function customer_tiers_key( int $user_id ): string;

	/**
	 * Stored value of a tier set (rows that are no valid tier are left out).
	 *
	 * @param array<int, array{min: int, price: string}> $rows Rows (minimum quantity, unit price).
	 */
	public function tiers_value( array $rows ): string;

	/**
	 * Meta key and value that hide the tier table of a product.
	 *
	 * @return array{0: string, 1: string}
	 */
	public function hidden_table(): array;
}

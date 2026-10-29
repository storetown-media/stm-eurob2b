<?php
/**
 * How the import stores quantity limits.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

/**
 * Quantity rules per product are a feature of EuroB2B Pro. Its ordering module registers this
 * target in the container; without it the import leaves quantity limits out and says so (PLT-015).
 */
interface QuantityTarget {

	/**
	 * Meta key of the limits of a product for a group, or for everyone else (null).
	 *
	 * @param int|null $group_id Group id; null for the default row.
	 */
	public function meta_key( ?int $group_id ): string;

	/**
	 * Stored value of the limits, null when none of them limits anything.
	 *
	 * @param int|null $min  Minimum quantity.
	 * @param int|null $max  Maximum quantity.
	 * @param int|null $step Quantity step.
	 */
	public function value( ?int $min, ?int $max, ?int $step ): ?string;
}

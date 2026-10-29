<?php
/**
 * How the import stores product and category visibility.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

/**
 * Product and category visibility is a feature of EuroB2B Pro. Its access module registers this
 * target in the container; without it the import leaves visibility out and says so (PLT-015).
 */
interface VisibilityTarget {

	/**
	 * Meta key of a visibility rule on a product, variation or product category.
	 */
	public function meta_key(): string;

	/**
	 * Stored value of the rule "visible only to these audiences".
	 *
	 * @param string[] $tokens Audience tokens (b2c, guest, group:<id>, user:<id>).
	 */
	public function audiences_value( array $tokens ): string;
}

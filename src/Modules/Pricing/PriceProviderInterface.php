<?php
/**
 * Price replacement source.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Modules\Customers\AudienceContext;
use WC_Product;

/**
 * A source of replacement prices (step 2 of the pipeline). Exactly one source wins per
 * product and audience: the one with the highest specificity that returns a price.
 */
interface PriceProviderInterface {

	public const SPECIFICITY_BASE       = 0;
	public const SPECIFICITY_FIXED_RULE = 100;
	public const SPECIFICITY_GROUP      = 200;
	public const SPECIFICITY_COMPANY    = 300;
	public const SPECIFICITY_CUSTOMER   = 400;

	/**
	 * Specificity (see constants). Higher wins.
	 */
	public function specificity(): int;

	/**
	 * Price for the product and audience, or null when this source has none.
	 *
	 * Implementations must read product data in the "edit" context so WooCommerce's
	 * price filters (which call the pipeline) are not re-entered.
	 *
	 * @param WC_Product      $product  Product or variation.
	 * @param AudienceContext $audience Audience.
	 * @param int             $quantity Quantity being priced (1 on catalog pages).
	 */
	public function resolve( WC_Product $product, AudienceContext $audience, int $quantity = 1 ): ?ResolvedPrice;
}

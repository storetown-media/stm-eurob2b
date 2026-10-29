<?php
/**
 * Source of price adjustments.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Modules\Customers\AudienceContext;
use WC_Product;

/**
 * Supplies adjustments (step 4 of the pipeline), e.g. discount and markup rules.
 */
interface AdjustmentProviderInterface {

	/**
	 * Adjustments that apply to the product for the audience and quantity.
	 *
	 * @param WC_Product      $product  Product or variation.
	 * @param AudienceContext $audience Audience.
	 * @param int             $quantity Quantity in the cart (1 for display).
	 * @return Adjustment[]
	 */
	public function adjustments( WC_Product $product, AudienceContext $audience, int $quantity ): array;
}

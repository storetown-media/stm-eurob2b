<?php
/**
 * Tier provider contract.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Modules\Customers\AudienceContext;
use WC_Product;

/**
 * Supplies a quantity-dependent unit price. Runs after price replacement and before adjustments;
 * registered through the `eurob2b_price_tier_providers` filter.
 */
interface TierProviderInterface {

	/**
	 * The tier price for the quantity, or null when no tier applies.
	 *
	 * @param WC_Product      $product       Product or variation.
	 * @param AudienceContext $audience      Audience.
	 * @param int             $quantity      Tier quantity (already resolved per the quantity basis setting).
	 * @param string          $current_price Price after replacement (decimal string, '' when unknown); percentage tiers derive from it.
	 */
	public function tier( WC_Product $product, AudienceContext $audience, int $quantity, string $current_price ): ?TierPrice;
}

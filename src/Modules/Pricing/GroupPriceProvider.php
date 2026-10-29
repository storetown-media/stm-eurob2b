<?php
/**
 * Group price source.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Modules\Customers\AudienceContext;
use WC_Product;

/**
 * Step 2 source "group": the audience's group price on the product or variation.
 */
final class GroupPriceProvider implements PriceProviderInterface {

	/**
	 * Group prices.
	 *
	 * @var GroupPrices
	 */
	private GroupPrices $prices;

	/**
	 * Constructor.
	 *
	 * @param GroupPrices $prices Group prices.
	 */
	public function __construct( GroupPrices $prices ) {
		$this->prices = $prices;
	}

	/**
	 * Specificity.
	 */
	public function specificity(): int {
		return self::SPECIFICITY_GROUP;
	}

	/**
	 * Group price in the currency the audience pays in, or null (guests, customers without a group,
	 * products without a group price in that currency).
	 *
	 * @param WC_Product      $product  Product or variation.
	 * @param AudienceContext $audience Audience.
	 * @param int             $quantity Quantity being priced (1 on catalog pages).
	 */
	public function resolve( WC_Product $product, AudienceContext $audience, int $quantity = 1 ): ?ResolvedPrice {
		$group_id = $audience->group_id();
		if ( null === $group_id ) {
			return null;
		}
		$prices = $this->prices->get( $product, $group_id, $audience->currency() );
		if ( '' === $prices['regular'] ) {
			return null;
		}
		return new ResolvedPrice( $prices['regular'], $prices['sale'], 'group:' . $group_id, self::SPECIFICITY_GROUP );
	}
}

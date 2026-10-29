<?php
/**
 * WooCommerce's own prices as the pipeline's base.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Modules\Customers\AudienceContext;
use WC_Product;

/**
 * Step 1: the regular and (scheduled) sale price as entered on the product. These are prices in the
 * shop currency, so an audience that pays in its group's own currency (CUR-001) has no base price:
 * it buys only what carries a price in its currency.
 */
final class BasePriceProvider implements PriceProviderInterface {

	/**
	 * Specificity.
	 */
	public function specificity(): int {
		return self::SPECIFICITY_BASE;
	}

	/**
	 * Base price (possibly empty for variable parents); null for an audience with its own currency.
	 *
	 * @param WC_Product      $product  Product.
	 * @param AudienceContext $audience Audience (only its currency matters: the base price is the same for everyone).
	 * @param int             $quantity Quantity being priced (1 on catalog pages).
	 */
	public function resolve( WC_Product $product, AudienceContext $audience, int $quantity = 1 ): ?ResolvedPrice {
		if ( '' !== $audience->currency() ) {
			return null;
		}
		$regular = (string) $product->get_regular_price( 'edit' );
		$sale    = $product->is_on_sale( 'edit' ) ? (string) $product->get_sale_price( 'edit' ) : '';
		return new ResolvedPrice( $regular, $sale, ResolvedPrice::SOURCE_BASE, self::SPECIFICITY_BASE );
	}
}

<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Compatibility;

/**
 * Declares support for WooCommerce features.
 *
 * Rule for every module: anything that touches orders must work with HPOS
 * (use the CRUD API, never post meta directly) and anything that touches
 * cart or checkout must support both the classic shortcode checkout and the
 * Cart/Checkout Blocks (Store API). Deviations are documented in
 * docs/architecture.md, section "WooCommerce compatibility".
 */
final class WooCommerceFeatures {

	/**
	 * Hooked to before_woocommerce_init.
	 */
	public static function declare_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EUROB2B_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EUROB2B_PLUGIN_FILE, true );
	}
}

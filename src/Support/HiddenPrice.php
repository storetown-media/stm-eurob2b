<?php
/**
 * Hidden prices in the variation data of variable products.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

/**
 * Replacing the price HTML does not hide the price of a variation: WooCommerce also puts the
 * variation's numbers (`display_price`, `display_regular_price`) into the JSON of the variation form
 * on the product page (`data-product_variations`, printed without a purchasable check) and into the
 * answer of `?wc-ajax=get_variation`. Every rule that hides a price therefore also filters
 * `woocommerce_available_variation` and passes the data through here.
 */
final class HiddenPrice {

	/**
	 * The variation data without its prices; `price_html` already carries the replacement text,
	 * because WooCommerce builds it through `woocommerce_get_price_html`.
	 *
	 * @param array<string, mixed> $data Variation data (woocommerce_available_variation).
	 * @return array<string, mixed>
	 */
	public static function variation_data( array $data ): array {
		$data['display_price']         = '';
		$data['display_regular_price'] = '';
		$data['is_purchasable']        = false;
		return $data;
	}
}

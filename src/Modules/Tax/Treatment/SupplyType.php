<?php
/**
 * Goods or services per product.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Treatment;

use EuroB2B\Support\ProductMeta;
use WC_Order_Item_Product;
use WC_Product;

/**
 * TAX-021: a product is goods (place of supply = destination) or a service (place of supply = the
 * customer's establishment, Art. 44). Stored as `_eurob2b_supply_type`; variations inherit the parent.
 * Since RPT-006 the value at the time of the order is also kept on each order line (same key, order
 * item meta), so a later change or deletion of the product does not change how the sale is booked.
 */
final class SupplyType {

	public const META     = '_eurob2b_supply_type';
	public const GOODS    = 'goods';
	public const SERVICES = 'services';

	/**
	 * Supply type of a product (goods unless marked as a service).
	 *
	 * @param WC_Product $product Product or variation.
	 */
	public static function of( WC_Product $product ): string {
		$value = ProductMeta::get( $product, self::META );
		if ( '' === (string) $value && $product->get_parent_id() > 0 ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$value  = $parent instanceof WC_Product ? ProductMeta::get( $parent, self::META ) : '';
		}
		return self::SERVICES === $value ? self::SERVICES : self::GOODS;
	}

	/**
	 * Keeps the supply type on a new order line (hook `woocommerce_checkout_create_order_line_item`,
	 * fired by the classic checkout and by the Store API of the Checkout block).
	 *
	 * @param mixed $item Order item.
	 */
	public static function record( $item ): void {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$product = $item->get_product();
		if ( $product instanceof WC_Product ) {
			$item->update_meta_data( self::META, self::of( $product ) );
		}
	}

	/**
	 * Supply type of an order line: the value kept at the time of the order, else the product's
	 * current setting (orders from before RPT-006 or created without the checkout), null when neither
	 * is known (the product was deleted).
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 */
	public static function of_item( WC_Order_Item_Product $item ): ?string {
		$kept = (string) $item->get_meta( self::META, true );
		if ( self::GOODS === $kept || self::SERVICES === $kept ) {
			return $kept;
		}
		$product = $item->get_product();
		return $product instanceof WC_Product ? self::of( $product ) : null;
	}

	/**
	 * Whether every product in a list is a service.
	 *
	 * @param WC_Product[] $products Products.
	 */
	public static function all_services( array $products ): bool {
		if ( empty( $products ) ) {
			return false;
		}
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product || self::SERVICES !== self::of( $product ) ) {
				return false;
			}
		}
		return true;
	}
}

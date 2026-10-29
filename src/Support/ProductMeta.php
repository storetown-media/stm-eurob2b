<?php
/**
 * Product meta reads without a query per product.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

use ReflectionProperty;
use WC_Data;
use WC_Product;

/**
 * `WC_Product::get_meta()` loads all meta of a product with its own query the first time it is
 * called, even when WordPress already holds the values: WP_Query primes the post meta cache for
 * every product of a list. Prices, tiers and quantity limits read in a product loop would add one
 * query per product (PLT-011).
 *
 * Reads therefore go through the post meta cache, except when the product object has loaded its
 * meta already: then `get_meta()` costs nothing and also sees changes that are not saved yet.
 * A product without id is always read through the CRUD API. Products are posts, so this is not
 * order access (HPOS, PLT-003).
 */
final class ProductMeta {

	/**
	 * Accessor for `WC_Data::$meta_data`, which stays null until the object reads its meta.
	 *
	 * @var ReflectionProperty|null
	 */
	private static ?ReflectionProperty $meta_data = null;

	/**
	 * Single meta value of a product or variation, '' when missing (like `get_meta()` in the edit
	 * context).
	 *
	 * @param WC_Product $product Product.
	 * @param string     $key     Meta key.
	 * @return mixed
	 */
	public static function get( WC_Product $product, string $key ) {
		$id = $product->get_id();
		if ( $id < 1 || self::loaded( $product ) ) {
			return $product->get_meta( $key, true, 'edit' );
		}
		return get_post_meta( $id, $key, true );
	}

	/**
	 * Deletes meta keys from every product and variation (for example the rows of a deleted
	 * group) and clears the product transients, such as variation price ranges, built with them.
	 *
	 * @param string[] $keys Meta keys.
	 * @return int Number of products and variations that had any of the keys.
	 */
	public static function delete_from_all_products( array $keys ): int {
		global $wpdb;
		$ids = array();
		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed meta key; the ids are needed before the delete.
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", $key ) ) as $id ) {
				$ids[ (int) $id ] = true;
			}
			delete_post_meta_by_key( $key );
		}
		foreach ( array_keys( $ids ) as $id ) {
			wc_delete_product_transients( $id );
			$parent = wp_get_post_parent_id( $id );
			if ( $parent > 0 ) {
				wc_delete_product_transients( $parent );
			}
		}
		return count( $ids );
	}

	/**
	 * Distinct meta keys of any post that start with the prefix (for keys with a variable end,
	 * such as prices in other currencies: `_eurob2b_group_price_7_` finds `_eurob2b_group_price_7_chf`).
	 *
	 * @param string $prefix Literal prefix.
	 * @return string[]
	 */
	public static function keys_with_prefix( string $prefix ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- admin clean-up of a deleted group or user; the meta_key index serves the prefix.
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		return array_values( array_map( 'strval', is_array( $keys ) ? $keys : array() ) );
	}

	/**
	 * Whether the product object read its meta already (or this cannot be told).
	 *
	 * @param WC_Product $product Product.
	 */
	private static function loaded( WC_Product $product ): bool {
		if ( null === self::$meta_data ) {
			if ( ! property_exists( WC_Data::class, 'meta_data' ) ) {
				// A WooCommerce without the property: read through the CRUD API.
				return true;
			}
			self::$meta_data = new ReflectionProperty( WC_Data::class, 'meta_data' );
		}
		return null !== self::$meta_data->getValue( $product );
	}
}

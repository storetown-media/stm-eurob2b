<?php
/**
 * Group prices stored on products and variations.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Support\Currency;
use EuroB2B\Support\Decimal;
use EuroB2B\Support\ProductMeta;
use WC_Product;

/**
 * Reads and writes the per-group regular and sale price of a product or variation.
 *
 * Meta keys (documented in docs/pricing.md, usable in WooCommerce's CSV import/export as
 * "Meta: <key>" columns):
 *   _eurob2b_group_price_<group id>       regular price for the group
 *   _eurob2b_group_sale_price_<group id>  sale price for the group ("" = none)
 *
 * Values are decimal strings in WooCommerce's format (wc_format_decimal), never floats.
 * A variation only has its own group prices; the parent's values are not inherited.
 *
 * A group with its own currency (CUR-001) keeps its prices under keys with the currency appended
 * (`_eurob2b_group_price_7_chf`); the keys without a suffix hold prices in the shop currency. A
 * group that changes its currency therefore starts without prices instead of reading the old
 * numbers in the new currency.
 */
final class GroupPrices {

	public const META_REGULAR_PREFIX = '_eurob2b_group_price_';
	public const META_SALE_PREFIX    = '_eurob2b_group_sale_price_';

	/**
	 * Meta key of the regular price for a group.
	 *
	 * @param int    $group_id Group id.
	 * @param string $currency Currency of the price ('' = the shop currency).
	 */
	public static function regular_key( int $group_id, string $currency = '' ): string {
		return self::META_REGULAR_PREFIX . $group_id . Currency::suffix( $currency );
	}

	/**
	 * Meta key of the sale price for a group.
	 *
	 * @param int    $group_id Group id.
	 * @param string $currency Currency of the price ('' = the shop currency).
	 */
	public static function sale_key( int $group_id, string $currency = '' ): string {
		return self::META_SALE_PREFIX . $group_id . Currency::suffix( $currency );
	}

	/**
	 * Group prices of a product (read in the edit context: no price filters involved).
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int        $group_id Group id.
	 * @param string     $currency Currency of the prices ('' = the shop currency).
	 * @return array{regular: string, sale: string}
	 */
	public function get( WC_Product $product, int $group_id, string $currency = '' ): array {
		$regular = ProductMeta::get( $product, self::regular_key( $group_id, $currency ) );
		$sale    = ProductMeta::get( $product, self::sale_key( $group_id, $currency ) );
		$regular = is_scalar( $regular ) ? self::normalise( (string) $regular ) : '';
		$sale    = is_scalar( $sale ) ? self::normalise( (string) $sale ) : '';
		if ( '' === $regular || ( '' !== $sale && Decimal::compare( $sale, $regular ) >= 0 ) ) {
			$sale = '';
		}
		return array(
			'regular' => $regular,
			'sale'    => $sale,
		);
	}

	/**
	 * Sets the group prices on the product object (caller saves). Empty strings remove the meta;
	 * a sale price that is not below the regular price is dropped, like WooCommerce does.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int        $group_id Group id.
	 * @param string     $regular  Regular price as entered (locale decimal allowed) or "".
	 * @param string     $sale     Sale price as entered or "".
	 * @param string     $currency Currency of the prices ('' = the shop currency).
	 */
	public function set( WC_Product $product, int $group_id, string $regular, string $sale, string $currency = '' ): void {
		$regular = self::normalise( $regular );
		$sale    = self::normalise( $sale );
		if ( '' === $regular || ( '' !== $sale && Decimal::compare( $sale, $regular ) >= 0 ) ) {
			$sale = '';
		}
		self::write( $product, self::regular_key( $group_id, $currency ), $regular );
		self::write( $product, self::sale_key( $group_id, $currency ), $sale );
	}

	/**
	 * Removes the prices and the tier sets of a deleted group, in every currency, from every
	 * product and variation; the product screen has no field for them any more.
	 *
	 * @param int $group_id Group id.
	 * @return int Number of products and variations that had any.
	 */
	public function delete_for_group( int $group_id ): int {
		$keys = array();
		foreach ( array( self::regular_key( $group_id ), self::sale_key( $group_id ), TierStorage::key( $group_id ) ) as $key ) {
			$keys[] = $key;
			// The same key in other currencies: "<key>_<currency>".
			$keys = array_merge( $keys, ProductMeta::keys_with_prefix( $key . '_' ) );
		}
		return ProductMeta::delete_from_all_products( array_values( array_unique( $keys ) ) );
	}

	/**
	 * Whether the product carries a group price for any group.
	 *
	 * @param WC_Product $product Product.
	 */
	public function has_any( WC_Product $product ): bool {
		foreach ( $product->get_meta_data() as $meta ) {
			if ( str_starts_with( (string) $meta->key, self::META_REGULAR_PREFIX ) && '' !== (string) $meta->value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalises an entered price to WooCommerce's decimal string ("" when empty or invalid).
	 *
	 * @param string $value Entered value.
	 */
	public static function normalise( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$clean = wc_format_decimal( $value );
		if ( ! is_string( $clean ) || '' === $clean || ! is_numeric( $clean ) || (float) $clean < 0 ) {
			return '';
		}
		return $clean;
	}

	/**
	 * Updates or removes one meta value on the object.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $key     Meta key.
	 * @param string     $value   Value ("" removes).
	 */
	private static function write( WC_Product $product, string $key, string $value ): void {
		if ( '' === $value ) {
			$product->delete_meta_data( $key );
			return;
		}
		$product->update_meta_data( $key, $value );
	}
}

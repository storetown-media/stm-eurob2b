<?php
/**
 * Customer price storage.
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
 * PRC-007: a regular and a sale price per customer, stored as product (or variation) meta exactly
 * like the group prices: `_eurob2b_customer_price_<user id>` and
 * `_eurob2b_customer_sale_price_<user id>`. WooCommerce's CSV exporter and importer carry them as
 * `Meta:` columns; ERP and PIM tools may write the keys directly. A variation only has its own
 * customer prices. The customer's tier set lives in TierStorage (`_eurob2b_tiers_user_<user id>`).
 *
 * Prices are in the currency the customer pays in (CUR-001): the shop currency under the keys
 * above, another currency (the currency of the customer's group) under the same keys with
 * `_<currency>` appended. A customer who moves to a group with another currency therefore keeps
 * the old prices stored, but they no longer apply.
 */
final class CustomerPrices {

	public const META_REGULAR_PREFIX = '_eurob2b_customer_price_';
	public const META_SALE_PREFIX    = '_eurob2b_customer_sale_price_';

	/**
	 * Meta key of a customer's regular price.
	 *
	 * @param int    $user_id  User id.
	 * @param string $currency Currency of the price ('' = the shop currency).
	 */
	public static function regular_key( int $user_id, string $currency = '' ): string {
		return self::META_REGULAR_PREFIX . $user_id . Currency::suffix( $currency );
	}

	/**
	 * Meta key of a customer's sale price.
	 *
	 * @param int    $user_id  User id.
	 * @param string $currency Currency of the price ('' = the shop currency).
	 */
	public static function sale_key( int $user_id, string $currency = '' ): string {
		return self::META_SALE_PREFIX . $user_id . Currency::suffix( $currency );
	}

	/**
	 * A customer's prices on a product (edit context; a sale price only below the regular price).
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int        $user_id  User id.
	 * @param string     $currency Currency of the prices ('' = the shop currency).
	 * @return array{regular: string, sale: string}
	 */
	public function get( WC_Product $product, int $user_id, string $currency = '' ): array {
		$regular = ProductMeta::get( $product, self::regular_key( $user_id, $currency ) );
		$sale    = ProductMeta::get( $product, self::sale_key( $user_id, $currency ) );
		$regular = is_scalar( $regular ) ? GroupPrices::normalise( (string) $regular ) : '';
		$sale    = is_scalar( $sale ) ? GroupPrices::normalise( (string) $sale ) : '';
		if ( '' === $regular || ( '' !== $sale && Decimal::compare( $sale, $regular ) >= 0 ) ) {
			$sale = '';
		}
		return array(
			'regular' => $regular,
			'sale'    => $sale,
		);
	}

	/**
	 * Stores a customer's prices (an empty regular price removes both). The caller saves the product.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int        $user_id  User id.
	 * @param string     $regular  Regular price (decimal string, may be localised).
	 * @param string     $sale     Sale price.
	 * @param string     $currency Currency of the prices ('' = the shop currency).
	 */
	public function set( WC_Product $product, int $user_id, string $regular, string $sale, string $currency = '' ): void {
		$regular = GroupPrices::normalise( $regular );
		$sale    = GroupPrices::normalise( $sale );
		if ( '' === $regular || ( '' !== $sale && Decimal::compare( $sale, $regular ) >= 0 ) ) {
			$sale = '';
		}
		foreach ( array(
			self::regular_key( $user_id, $currency ) => $regular,
			self::sale_key( $user_id, $currency )    => '' === $regular ? '' : $sale,
		) as $key => $value ) {
			if ( '' === $value ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, $value );
			}
		}
	}

	/**
	 * Products and variations with prices for a customer in one currency.
	 *
	 * @param int    $user_id  User id.
	 * @param string $currency Currency ('' = the shop currency).
	 * @return array<int, array{regular: string, sale: string}> Product or variation id => prices.
	 */
	public function for_user( int $user_id, string $currency = '' ): array {
		$out = array();
		foreach ( $this->stored_for_user( $user_id, $currency ) as $id => $stored ) {
			if ( '' !== $stored['regular'] ) {
				$out[ $id ] = array(
					'regular' => $stored['regular'],
					'sale'    => $stored['sale'],
				);
			}
		}
		return $out;
	}

	/**
	 * Everything stored for a customer in one currency, including a tier set without an own price
	 * (privacy export, erasure): product or variation id => regular and sale price and the raw tier
	 * set JSON.
	 *
	 * @param int    $user_id  User id.
	 * @param string $currency Currency ('' = the shop currency).
	 * @return array<int, array{regular: string, sale: string, tiers: string}>
	 */
	public function stored_for_user( int $user_id, string $currency = '' ): array {
		return $this->stored_with_suffix( $user_id, Currency::suffix( $currency ) );
	}

	/**
	 * Everything stored for a customer in every currency, by key suffix: '' for the keys without a
	 * currency, "_chf" and the like for the others (CUR-001). Read from the keys themselves, so
	 * nothing is missed even after the shop changed its own currency (privacy export).
	 *
	 * @param int $user_id User id.
	 * @return array<string, array<int, array{regular: string, sale: string, tiers: string}>>
	 */
	public function stored_by_suffix( int $user_id ): array {
		$suffixes = array( '' => true );
		foreach ( self::keys( $user_id ) as $key ) {
			foreach ( ProductMeta::keys_with_prefix( $key . '_' ) as $stored ) {
				$suffix = substr( $stored, strlen( $key ) );
				if ( 1 === preg_match( '/^_[a-z]{3}$/', $suffix ) ) {
					$suffixes[ $suffix ] = true;
				}
			}
		}
		$out = array();
		foreach ( array_keys( $suffixes ) as $suffix ) {
			$rows = $this->stored_with_suffix( $user_id, (string) $suffix );
			if ( ! empty( $rows ) ) {
				$out[ (string) $suffix ] = $rows;
			}
		}
		return $out;
	}

	/**
	 * Removes every price and tier set of a customer in every currency (account deletion, privacy
	 * erasure).
	 *
	 * @param int $user_id User id.
	 * @return int Number of products and variations that held something.
	 */
	public function delete_for_user( int $user_id ): int {
		$keys = array();
		foreach ( self::keys( $user_id ) as $key ) {
			$keys[] = $key;
			// The same key in other currencies: "<key>_<currency>". Product transients such as variation
			// price ranges were built with these prices and are cleared with them.
			$keys = array_merge( $keys, ProductMeta::keys_with_prefix( $key . '_' ) );
		}
		return ProductMeta::delete_from_all_products( array_values( array_unique( $keys ) ) );
	}

	/**
	 * The three keys of a customer in the shop currency.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	private static function keys( int $user_id ): array {
		return array( self::regular_key( $user_id ), self::sale_key( $user_id ), TierStorage::user_key( $user_id ) );
	}

	/**
	 * Stored values of the three keys with one suffix.
	 *
	 * @param int    $user_id User id.
	 * @param string $suffix  '' or "_<currency>".
	 * @return array<int, array{regular: string, sale: string, tiers: string}>
	 */
	private function stored_with_suffix( int $user_id, string $suffix ): array {
		global $wpdb;
		$fields = array(
			self::regular_key( $user_id ) . $suffix     => 'regular',
			self::sale_key( $user_id ) . $suffix        => 'sale',
			TierStorage::user_key( $user_id ) . $suffix => 'tiers',
		);
		$keys   = array_keys( $fields );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s, %s ) ORDER BY post_id ASC", $keys[0], $keys[1], $keys[2] ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) $row['post_id'];
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'regular' => '',
					'sale'    => '',
					'tiers'   => '',
				);
			}
			$field                = $fields[ (string) $row['meta_key'] ] ?? 'tiers';
			$value                = (string) $row['meta_value'];
			$out[ $id ][ $field ] = 'tiers' === $field ? $value : GroupPrices::normalise( $value );
		}
		return $out;
	}
}

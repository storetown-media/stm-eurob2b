<?php
/**
 * Product tier storage.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Modules\Customers\AudienceContext;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Support\Currency;
use EuroB2B\Support\ProductMeta;
use WC_Product;

/**
 * Tiers live in product (and variation) meta: `_eurob2b_tiers` for everyone and
 * `_eurob2b_tiers_group_<id>` per group, each a JSON list of {min, price}. Importers may write the
 * keys directly (docs/pricing.md). Audience logic (PRC-012): a member of a group with its own tier
 * set gets that set; everyone else, and members of groups without tiers, get the regular set.
 */
final class TierStorage {

	public const META_ALL          = '_eurob2b_tiers';
	public const META_GROUP_PREFIX = '_eurob2b_tiers_group_';
	public const META_USER_PREFIX  = '_eurob2b_tiers_user_';
	public const META_TABLE        = '_eurob2b_tier_table';

	/**
	 * Meta key of a tier set: null for everyone, a group id for that group's set. A group with its
	 * own currency (CUR-001) keeps its set under the key with `_<currency>` appended; the set for
	 * everyone is in the shop currency only.
	 *
	 * @param int|null $group_id Group id or null.
	 * @param string   $currency Currency of the tier prices ('' = the shop currency).
	 */
	public static function key( ?int $group_id, string $currency = '' ): string {
		return null === $group_id ? self::META_ALL : self::META_GROUP_PREFIX . $group_id . Currency::suffix( $currency );
	}

	/**
	 * Tier set of a product for everyone or a group.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int|null   $group_id Group id, null for everyone.
	 * @param string     $currency Currency of the tier prices ('' = the shop currency).
	 * @return Tier[]
	 */
	public function get( WC_Product $product, ?int $group_id, string $currency = '' ): array {
		$raw = ProductMeta::get( $product, self::key( $group_id, $currency ) );
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( $raw, true );
		}
		return Tier::from_array( $raw );
	}

	/**
	 * Stores a tier set (empty list deletes the key). The caller saves the product.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int|null   $group_id Group id, null for everyone.
	 * @param Tier[]     $tiers    Tiers.
	 * @param string     $currency Currency of the tier prices ('' = the shop currency).
	 */
	public function set( WC_Product $product, ?int $group_id, array $tiers, string $currency = '' ): void {
		$rows = Tier::to_array( $tiers );
		if ( empty( $rows ) ) {
			$product->delete_meta_data( self::key( $group_id, $currency ) );
			return;
		}
		$product->update_meta_data( self::key( $group_id, $currency ), (string) wp_json_encode( $rows ) );
	}

	/**
	 * Meta key of a customer's own tier set (PRC-007), with `_<currency>` appended for a currency
	 * other than the shop currency.
	 *
	 * @param int    $user_id  User id.
	 * @param string $currency Currency of the tier prices ('' = the shop currency).
	 */
	public static function user_key( int $user_id, string $currency = '' ): string {
		return self::META_USER_PREFIX . $user_id . Currency::suffix( $currency );
	}

	/**
	 * A customer's own tier set.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int        $user_id  User id.
	 * @param string     $currency Currency of the tier prices ('' = the shop currency).
	 * @return Tier[]
	 */
	public function get_for_user( WC_Product $product, int $user_id, string $currency = '' ): array {
		$raw = ProductMeta::get( $product, self::user_key( $user_id, $currency ) );
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( $raw, true );
		}
		return Tier::from_array( $raw );
	}

	/**
	 * Stores a customer's tier set (empty list deletes the key). The caller saves the product.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int        $user_id  User id.
	 * @param Tier[]     $tiers    Tiers.
	 * @param string     $currency Currency of the tier prices ('' = the shop currency).
	 */
	public function set_for_user( WC_Product $product, int $user_id, array $tiers, string $currency = '' ): void {
		$rows = Tier::to_array( $tiers );
		if ( empty( $rows ) ) {
			$product->delete_meta_data( self::user_key( $user_id, $currency ) );
			return;
		}
		$product->update_meta_data( self::user_key( $user_id, $currency ), (string) wp_json_encode( $rows ) );
	}

	/**
	 * The tier set that applies to an audience, with its source label.
	 *
	 * @param WC_Product      $product  Product or variation.
	 * @param AudienceContext $audience Audience.
	 * @return array{tiers: Tier[], source: string}
	 */
	public function for_audience( WC_Product $product, AudienceContext $audience ): array {
		// An approved customer's own tier set comes first (PRC-007), then the group's, then everyone's.
		// All in the currency the audience pays in; the set for everyone exists in the shop currency only.
		$currency = $audience->currency();
		if ( null !== $audience->user_id && $audience->user_id > 0 && CustomerType::GUEST !== $audience->customer_type ) {
			$tiers = $this->get_for_user( $product, $audience->user_id, $currency );
			if ( ! empty( $tiers ) ) {
				return array(
					'tiers'  => $tiers,
					'source' => 'tier:customer:' . $audience->user_id,
				);
			}
		}
		$group_id = $audience->group_id();
		if ( null !== $group_id ) {
			$tiers = $this->get( $product, $group_id, $currency );
			if ( ! empty( $tiers ) ) {
				return array(
					'tiers'  => $tiers,
					'source' => 'tier:group:' . $group_id,
				);
			}
		}
		return array(
			'tiers'  => Currency::is_shop( $currency ) ? $this->get( $product, null ) : array(),
			'source' => 'tier:product',
		);
	}

	/**
	 * Whether any tier set exists on the product.
	 *
	 * @param WC_Product $product Product or variation.
	 */
	public function has_any( WC_Product $product ): bool {
		foreach ( $product->get_meta_data() as $meta ) {
			$key = (string) $meta->key;
			if ( ( self::META_ALL === $key || str_starts_with( $key, self::META_GROUP_PREFIX ) || str_starts_with( $key, self::META_USER_PREFIX ) ) && '' !== (string) $meta->value && '[]' !== (string) $meta->value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the tier table is shown for a product (per-product toggle, default on).
	 *
	 * @param WC_Product $product Product (the parent for variations).
	 */
	public function table_enabled( WC_Product $product ): bool {
		return 'no' !== (string) ProductMeta::get( $product, self::META_TABLE );
	}

	/**
	 * Sets the per-product table toggle.
	 *
	 * @param WC_Product $product Product.
	 * @param bool       $enabled Enabled.
	 */
	public function set_table_enabled( WC_Product $product, bool $enabled ): void {
		if ( $enabled ) {
			$product->delete_meta_data( self::META_TABLE );
		} else {
			$product->update_meta_data( self::META_TABLE, 'no' );
		}
	}
}

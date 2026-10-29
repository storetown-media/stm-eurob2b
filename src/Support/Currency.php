<?php
/**
 * Currency codes of the shop and its customer groups.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

/**
 * Small helpers around ISO 4217 codes (CUR-001).
 *
 * The shop currency is WooCommerce's stored setting (`woocommerce_currency`), read without the
 * `woocommerce_currency` filter: that filter answers with the currency the visitor pays in, which
 * may be a group's own currency or a switcher's choice. An empty code always means the shop
 * currency.
 *
 * Amounts stored per group or customer carry their currency in the meta key when it is not the
 * shop currency (`_eurob2b_group_price_7_chf`), so a price entered in one currency is never read
 * as a price in another one after a group changes its currency or a customer changes the group.
 */
final class Currency {

	/**
	 * Whether groups may have their own currency (CUR-001). Only EuroB2B Pro switches the storefront
	 * to a group's currency (module Currency), so without it every group pays in the shop currency and
	 * a stored group currency stays without effect (it is kept for a later activation of Pro).
	 */
	public static function group_currencies_enabled(): bool {
		/**
		 * Whether groups may have their own currency. EuroB2B Pro returns true.
		 *
		 * @param bool $enabled Default false.
		 */
		return true === apply_filters( 'eurob2b_group_currencies_enabled', false );
	}

	/**
	 * The currency a group's customers pay in: its own (CUR-001) while group currencies are enabled,
	 * else '' for the shop currency.
	 *
	 * @param \EuroB2B\Modules\Customers\Group $group Group.
	 */
	public static function of_group( \EuroB2B\Modules\Customers\Group $group ): string {
		return self::group_currencies_enabled() ? $group->currency() : '';
	}

	/**
	 * The shop currency (upper case, '' when WooCommerce has none stored).
	 */
	public static function shop(): string {
		return strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
	}

	/**
	 * The code itself, or the shop currency for ''.
	 *
	 * @param string $currency Code or ''.
	 */
	public static function code( string $currency ): string {
		$currency = strtoupper( trim( $currency ) );
		return '' === $currency ? self::shop() : $currency;
	}

	/**
	 * Whether the code means the shop currency ('' or the shop's own code).
	 *
	 * @param string $currency Code or ''.
	 */
	public static function is_shop( string $currency ): bool {
		return self::code( $currency ) === self::shop();
	}

	/**
	 * Whether two codes mean the same currency ('' = the shop currency).
	 *
	 * @param string $a Code or ''.
	 * @param string $b Code or ''.
	 */
	public static function same( string $a, string $b ): bool {
		return self::code( $a ) === self::code( $b );
	}

	/**
	 * Meta key suffix for amounts in this currency: '' for the shop currency, else "_<code>".
	 *
	 * @param string $currency Code or ''.
	 */
	public static function suffix( string $currency ): string {
		return self::is_shop( $currency ) ? '' : '_' . strtolower( self::code( $currency ) );
	}

	/**
	 * A stored or submitted code in its canonical form: upper case when it is three letters,
	 * otherwise ''. Whether WooCommerce knows the code is checked by `known()`.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function normalise( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtoupper( trim( $value ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $value ) ? $value : '';
	}

	/**
	 * Whether WooCommerce knows the code (its currency list, filterable by other plugins).
	 *
	 * @param string $currency Code.
	 */
	public static function known( string $currency ): bool {
		return function_exists( 'get_woocommerce_currencies' ) && array_key_exists( strtoupper( $currency ), get_woocommerce_currencies() );
	}
}

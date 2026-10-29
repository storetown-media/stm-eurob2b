<?php
/**
 * Decides whether B2B pricing applies to the current request.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

/**
 * Non-frontend context guard (PRC-006).
 *
 * B2B prices apply to the storefront (including WooCommerce's own wc-ajax endpoints, which run
 * as front-end requests), to the known storefront actions that still go through admin-ajax.php
 * (add to cart, cart fragments, checkout review) and to the Store API (Cart and Checkout blocks).
 * They never apply in the admin, to back-end AJAX (manual orders, product quick edit), in cron,
 * under WP-CLI or in other REST requests (the WooCommerce products REST API as used by shop
 * managers, feeds, ERP connectors), because those must see the catalogue's base prices.
 */
final class PricingContext {

	/**
	 * Storefront actions that reach admin-ajax.php (legacy themes and plugins; WooCommerce itself uses
	 * its front-end wc-ajax endpoint for these). Filterable via eurob2b_pricing_storefront_ajax_actions.
	 */
	public const STOREFRONT_AJAX_ACTIONS = array(
		'woocommerce_add_to_cart',
		'woocommerce_remove_from_cart',
		'woocommerce_get_refreshed_fragments',
		'woocommerce_update_order_review',
		'woocommerce_checkout',
		'woocommerce_apply_coupon',
		'woocommerce_remove_coupon',
		'woocommerce_update_shipping_method',
		'woocommerce_get_variation',
	);

	/**
	 * Request-level result.
	 *
	 * @var bool|null
	 */
	private ?bool $applies = null;

	/**
	 * Whether pricing applies to the current request (cached per request, filterable).
	 */
	public function applies(): bool {
		if ( null === $this->applies ) {
			$default = self::applies_for(
				is_admin(),
				wp_doing_ajax(),
				wp_doing_cron(),
				self::rest_route(),
				defined( 'WP_CLI' ) && WP_CLI,
				self::ajax_action(),
				self::storefront_ajax_actions()
			);
			/**
			 * Filters whether EuroB2B pricing applies to the current request.
			 *
			 * @param bool $applies Default decision.
			 */
			$this->applies = (bool) apply_filters( 'eurob2b_pricing_applies', $default );
		}
		return $this->applies;
	}

	/**
	 * Drops the cached decision (tests, long-running processes).
	 */
	public function flush(): void {
		$this->applies = null;
	}

	/**
	 * Pure decision.
	 *
	 * @param bool        $is_admin    Admin request (is_admin(); also true for admin-ajax.php).
	 * @param bool        $doing_ajax  AJAX request.
	 * @param bool        $doing_cron  Cron request.
	 * @param string|null $rest_route  REST route when this is a REST request, null otherwise.
	 * @param bool        $is_cli      WP-CLI.
	 * @param string|null $ajax_action The AJAX "action" parameter, null when none.
	 * @param string[]    $storefront  admin-ajax.php actions that count as storefront requests.
	 */
	public static function applies_for( bool $is_admin, bool $doing_ajax, bool $doing_cron, ?string $rest_route, bool $is_cli, ?string $ajax_action = null, array $storefront = self::STOREFRONT_AJAX_ACTIONS ): bool {
		if ( $is_cli || $doing_cron ) {
			return false;
		}
		if ( null !== $rest_route ) {
			// The Store API namespace exactly: "wc/store" or below "wc/store/", not "wc/storefront/v1".
			$route = ltrim( $rest_route, '/' );
			return 'wc/store' === $route || str_starts_with( $route, 'wc/store/' );
		}
		if ( $is_admin ) {
			// admin-ajax.php is an admin request for every action, including manual orders: only the
			// known storefront actions that still use it get B2B prices.
			return $doing_ajax && null !== $ajax_action && in_array( $ajax_action, $storefront, true );
		}
		return true;
	}

	/**
	 * Storefront actions that reach admin-ajax.php (legacy themes and plugins; WooCommerce itself
	 * uses the front-end wc-ajax endpoint for these).
	 *
	 * @return string[]
	 */
	public static function storefront_ajax_actions(): array {
		$actions = self::STOREFRONT_AJAX_ACTIONS;
		/**
		 * Filters the admin-ajax.php actions that count as storefront requests for pricing.
		 *
		 * @param string[] $actions Action names.
		 */
		$filtered = apply_filters( 'eurob2b_pricing_storefront_ajax_actions', $actions );
		return array_values( array_filter( is_array( $filtered ) ? $filtered : $actions, 'is_string' ) );
	}

	/**
	 * The AJAX action of this request, null when none is posted.
	 */
	private static function ajax_action(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- read-only context detection; the handlers verify their own nonces.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ) : '';
		return '' === $action ? null : $action;
	}

	/**
	 * The REST route of this request, "" for a REST request whose route is unknown, null when not REST.
	 */
	private static function rest_route(): ?string {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return null;
		}
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
			$path   = (string) wp_parse_url( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
			$prefix = '/' . rest_get_url_prefix() . '/';
			$pos    = strpos( $path, $prefix );
			$route  = false !== $pos ? substr( $path, $pos + strlen( $prefix ) - 1 ) : '';
		}
		return $route;
	}
}

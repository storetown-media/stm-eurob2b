<?php
/**
 * Storefront markup: class names and the shared stylesheet.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

use WP_Post;

/**
 * Class names for everything EuroB2B shows customers (forms and tables in My Account, on pages, on the
 * product page and in the cart), and the stylesheet behind them.
 *
 * Theme first: EuroB2B gives its controls the classes WooCommerce and WordPress give their own
 * (`woocommerce` scope, `button` plus the theme's button class, `input-text`, `shop_table`), so the
 * theme styles them like the rest of the shop. assets/css/frontend.css only fills in what a theme
 * leaves open, with selectors of zero specificity, and adds the layout (docs/frontend.md).
 */
final class Ui {

	/**
	 * Stylesheet handle.
	 */
	public const STYLE = 'eurob2b-frontend';

	/**
	 * Class of every EuroB2B storefront root element (carries the design tokens).
	 */
	public const ROOT = 'eurob2b-ui';

	/**
	 * Hooks: registers the stylesheet and loads it early where EuroB2B controls are usual.
	 */
	public static function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register' ) );
	}

	/**
	 * Registers the stylesheet and loads it in the head where EuroB2B controls are usual or present: My
	 * Account, cart, checkout, product pages, and pages whose content holds an EuroB2B shortcode or
	 * block. Elsewhere the component that renders loads it (enqueue()); WordPress then prints it late,
	 * in the footer.
	 */
	public static function register(): void {
		wp_register_style( self::STYLE, EUROB2B_PLUGIN_URL . 'assets/css/frontend.css', array(), Assets::version( 'assets/css/frontend.css' ) );
		$woocommerce = function_exists( 'is_account_page' ) && ( is_account_page() || is_cart() || is_checkout() || is_product() );
		if ( $woocommerce || self::page_has_components() ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/**
	 * Whether the current singular post holds an EuroB2B shortcode (`[eurob2b_…]`) or block.
	 */
	public static function page_has_components(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		return str_contains( $post->post_content, '[eurob2b_' ) || str_contains( $post->post_content, '<!-- wp:eurob2b/' );
	}

	/**
	 * Loads the stylesheet; components call it while they render.
	 */
	public static function enqueue(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style( self::STYLE, EUROB2B_PLUGIN_URL . 'assets/css/frontend.css', array(), Assets::version( 'assets/css/frontend.css' ) );
		}
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * Root element classes of a component: the WooCommerce scope (WooCommerce's and the theme's
	 * `.woocommerce` rules then apply on any page), the design-token root and the component.
	 *
	 * @param string ...$classes Component classes.
	 */
	public static function root( string ...$classes ): string {
		return self::join( 'woocommerce', self::ROOT, ...$classes );
	}

	/**
	 * Primary action: WooCommerce's primary button (`button alt`, as on "Add to cart" and "Proceed to
	 * checkout") plus the theme's button class. Block themes style `wp-element-button`; classic themes
	 * and WooCommerce's own stylesheet draw `.button.alt` as the shop's main button and a plain
	 * `.button` like every other button.
	 *
	 * @param string ...$classes Extra classes.
	 */
	public static function button( string ...$classes ): string {
		return self::join( 'button', 'alt', self::element_button(), ...$classes );
	}

	/**
	 * Secondary action: WooCommerce's button without the theme's primary style. Classic themes draw it
	 * as their plain button; elsewhere EuroB2B draws a quiet outline.
	 *
	 * @param string ...$classes Extra classes.
	 */
	public static function secondary( string ...$classes ): string {
		return self::join( 'button', 'eurob2b-button-secondary', ...$classes );
	}

	/**
	 * Small icon action, e.g. removing a row.
	 *
	 * @param string ...$classes Extra classes.
	 */
	public static function icon( string ...$classes ): string {
		return self::join( 'eurob2b-icon-button', ...$classes );
	}

	/**
	 * Text field, number field, select or textarea.
	 *
	 * @param string ...$classes Extra classes.
	 */
	public static function field( string ...$classes ): string {
		return self::join( 'input-text', 'eurob2b-field', ...$classes );
	}

	/**
	 * Data table. Stacked tables turn into labelled rows on small screens; their cells carry
	 * `data-title` like WooCommerce's own account tables.
	 *
	 * @param bool   $stacked    Whether the table stacks on small screens.
	 * @param string ...$classes Extra classes.
	 */
	public static function table( bool $stacked, string ...$classes ): string {
		return self::join( 'woocommerce-table', 'shop_table', $stacked ? 'shop_table_responsive' : '', 'eurob2b-table', ...$classes );
	}

	/**
	 * Escaped attribute that asks for confirmation before a button deletes data (WCAG 3.3.4). Without
	 * JavaScript the action runs as before.
	 *
	 * @param string $message Question.
	 */
	public static function confirm( string $message ): string {
		return ' onclick="' . esc_attr( 'return window.confirm(' . (string) wp_json_encode( $message ) . ');' ) . '"';
	}

	/**
	 * The theme's class for buttons ("wp-element-button" since WordPress 6.1).
	 */
	private static function element_button(): string {
		if ( function_exists( 'wc_wp_theme_get_element_class_name' ) ) {
			return (string) wc_wp_theme_get_element_class_name( 'button' );
		}
		return function_exists( 'wp_theme_get_element_class_name' ) ? (string) wp_theme_get_element_class_name( 'button' ) : '';
	}

	/**
	 * Joins class names, dropping empty and repeated ones.
	 *
	 * @param string ...$classes Class names.
	 */
	private static function join( string ...$classes ): string {
		return implode( ' ', array_values( array_unique( array_filter( array_map( 'trim', $classes ) ) ) ) );
	}
}

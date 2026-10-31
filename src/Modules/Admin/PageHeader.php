<?php
/**
 * Shared header of the EuroB2B admin pages.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use EuroB2B\Support\Assets;

/**
 * Opens an EuroB2B admin page: the `wrap` element, a white header band with the product name and
 * area, the page title and optional actions, the pages of the area as tabs (PLT-017), the page's own
 * tabs below them, and the `wp-header-end` marker so WordPress places admin notices below the band.
 * The page closes the `wrap` element itself, as before.
 *
 * The stylesheet assets/css/admin.css is loaded on every EuroB2B page (`?page=eurob2b-…`) and only
 * there; everything in it is scoped to `.eurob2b-admin`.
 */
final class PageHeader {

	public const STYLE = 'eurob2b-admin';

	/**
	 * Prints the opening markup of a page.
	 *
	 * @param string                                                       $css_class  Extra class of the wrap element, e.g. "eurob2b-rules".
	 * @param string                                                       $title      Page title (plain text, escaped here).
	 * @param string                                                       $actions    Already escaped HTML of buttons next to the title (links with class "button").
	 * @param array<int, array{url: string, label: string, current: bool}> $tabs       The page's own tabs, shown below the area tabs.
	 * @param string                                                       $tabs_label Accessible name of the page's tab navigation.
	 */
	public static function open( string $css_class, string $title, string $actions = '', array $tabs = array(), string $tabs_label = '' ): void {
		$slug = self::current_slug();
		$area = '' === $slug ? null : Menu::area_of( $slug );
		$nav  = null !== $area && count( $area['pages'] ) > 1;

		echo '<div class="' . esc_attr( trim( 'wrap eurob2b-admin ' . $css_class ) ) . '">';
		echo '<div class="eurob2b-head' . ( $nav || array() !== $tabs ? ' eurob2b-head--tabs' : '' ) . '"><p class="eurob2b-head__brand">EuroB2B';
		if ( null !== $area && ! str_starts_with( $area['key'], 'page:' ) ) {
			echo ' <span aria-hidden="true">&rsaquo;</span> <span class="eurob2b-head__area">' . esc_html( $area['label'] ) . '</span>';
		}
		echo '</p><div class="eurob2b-head__row">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $actions ) {
			echo '<div class="eurob2b-head__actions">' . $actions . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller (documented).
		}
		echo '</div>';
		if ( $nav ) {
			echo '<nav class="eurob2b-area" aria-label="' . esc_attr( $area['label'] ) . '">';
			foreach ( $area['pages'] as $page => $info ) {
				echo '<a href="' . esc_url( Menu::url( $page ) ) . '"' . ( $page === $slug ? ' aria-current="page"' : '' ) . '>' . esc_html( $info['label'] );
				if ( $info['count'] > 0 ) {
					echo ' <span class="eurob2b-count">' . (int) $info['count'] . '</span>';
				}
				echo '</a>';
			}
			echo '</nav>';
		}
		if ( array() !== $tabs ) {
			echo '<nav class="eurob2b-tabs"' . ( '' === $tabs_label ? '' : ' aria-label="' . esc_attr( $tabs_label ) . '"' ) . '>';
			foreach ( $tabs as $tab ) {
				echo '<a href="' . esc_url( $tab['url'] ) . '"' . ( $tab['current'] ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab['label'] ) . '</a>';
			}
			echo '</nav>';
		}
		echo '</div><hr class="wp-header-end" />';
	}

	/**
	 * Slug of the EuroB2B page of the current request without the "eurob2b-" prefix, or ''.
	 */
	public static function current_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing argument.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$prefix = Menu::SLUG . '-';
		return str_starts_with( $page, $prefix ) ? substr( $page, strlen( $prefix ) ) : '';
	}

	/**
	 * Whether the current admin request shows an EuroB2B page.
	 */
	public static function is_eurob2b_page(): bool {
		return '' !== self::current_slug();
	}

	/**
	 * Hooked to admin_enqueue_scripts: the stylesheet on EuroB2B pages only.
	 */
	public static function enqueue(): void {
		if ( ! self::is_eurob2b_page() ) {
			return;
		}
		wp_enqueue_style( self::STYLE, EUROB2B_PLUGIN_URL . 'assets/css/admin.css', array(), Assets::version( 'assets/css/admin.css' ) );
	}
}

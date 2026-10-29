<?php
/**
 * Admin hint about the differences of the Cart and Checkout blocks.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use EuroB2B\Infrastructure\Capabilities;

/**
 * Every feature that touches the cart or the checkout works with the classic pages and with the
 * Cart/Checkout blocks; where a block behaves differently, docs/architecture.md §11 says so and
 * this notice on the EuroB2B settings page tells the shop manager (PLT-005). It appears only when
 * the shop's cart or checkout page contains the block and a difference concerns that block, and
 * lists only the differences of the block that is in use.
 *
 * The differences come through the filter `eurob2b_blocks_differences`. The free plugin adds none:
 * its cart and checkout features (prices, percentage discounts, payment and shipping methods, the
 * VAT ID field and the tax treatment, registration fields) work alike in both checkouts. EuroB2B
 * Pro adds the differences of its features (PLT-015).
 */
final class BlocksCompatibilityNotice {

	/**
	 * The blocks a difference can concern: "cart", "checkout", or "any" for what the Store API does
	 * in both, so a shop with only one of the blocks sees it too.
	 */
	private const BLOCKS = array( 'cart', 'checkout', 'any' );

	/**
	 * Hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Known differences, keyed by id: the block concerned and the text. Entries of another shape
	 * are dropped.
	 *
	 * @return array<string, array{block: string, text: string}>
	 */
	public static function differences(): array {
		/**
		 * Differences of the Cart and Checkout blocks listed on the EuroB2B settings page (PLT-005),
		 * keyed by id. Each entry has "block" ("cart", "checkout", or "any" for both) and "text"
		 * (plain text, escaped on output); other entries are dropped. EuroB2B adds none of its own;
		 * EuroB2B Pro adds the differences of its features. A module that adds a difference also
		 * adds it to the compatibility table in docs/architecture.md §11.
		 *
		 * @param array<string, array{block: string, text: string}> $differences Differences.
		 */
		$filtered = apply_filters( 'eurob2b_blocks_differences', array() );
		$out      = array();
		if ( ! is_array( $filtered ) ) {
			return $out;
		}
		foreach ( $filtered as $id => $difference ) {
			if ( ! is_array( $difference ) || ! in_array( $difference['block'] ?? null, self::BLOCKS, true ) || ! is_string( $difference['text'] ?? null ) || '' === $difference['text'] ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'block' => (string) $difference['block'],
				'text'  => $difference['text'],
			);
		}
		return $out;
	}

	/**
	 * Whether the shop's cart or checkout page contains the WooCommerce block.
	 *
	 * @param string $page "cart" or "checkout".
	 */
	public static function uses_block( string $page ): bool {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		$id = wc_get_page_id( $page );
		return $id > 0 && has_block( 'woocommerce/' . $page, $id );
	}

	/**
	 * Differences of the blocks the shop uses.
	 *
	 * @return string[]
	 */
	public static function active(): array {
		$differences = self::differences();
		if ( empty( $differences ) ) {
			return array();
		}
		$used        = array(
			'cart'     => self::uses_block( 'cart' ),
			'checkout' => self::uses_block( 'checkout' ),
		);
		$used['any'] = $used['cart'] || $used['checkout'];
		$out         = array();
		foreach ( $differences as $difference ) {
			if ( ! empty( $used[ $difference['block'] ] ) ) {
				$out[] = $difference['text'];
			}
		}
		return $out;
	}

	/**
	 * The notice on the EuroB2B settings page.
	 */
	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! str_ends_with( (string) $screen->id, Menu::page_slug( SettingsPage::SLUG ) ) || ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}
		$differences = self::active();
		if ( empty( $differences ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'EuroB2B with the Cart and Checkout blocks', 'eurob2b' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Your shop uses the WooCommerce blocks for the cart or the checkout. These EuroB2B features work differently there:', 'eurob2b' ) . '</p>';
		echo '<ul style="list-style:disc;padding-left:2em;">';
		foreach ( $differences as $text ) {
			echo '<li>' . esc_html( $text ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'The classic cart and checkout pages support every EuroB2B feature.', 'eurob2b' ) . '</p></div>';
	}
}

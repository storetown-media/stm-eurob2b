<?php
/**
 * EuroB2B tab in the WooCommerce product data box.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use WC_Product;

/**
 * Collects the EuroB2B product fields in one tab of the product data box (PLT-017) instead of
 * spreading them over General, Inventory, Advanced and Tax. Each section is a collapsible block;
 * modules print their fields on the action `eurob2b_product_panel_<section>`. The fields stay in the
 * product form, so their names and the save handlers do not change. A section whose action prints
 * nothing (feature off, Pro missing) is left out. Variation fields keep their WooCommerce hooks.
 * Next to each section title a short summary of the saved values (filter
 * `eurob2b_product_panel_summary`) shows what is set without opening the section.
 */
final class ProductDataTab {

	public const TARGET = 'eurob2b_product_data';

	/**
	 * Hooks.
	 */
	public function hooks(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render' ) );
	}

	/**
	 * Sections in display order: key => label, open by default, extra classes of the block.
	 *
	 * @return array<string, array{label: string, open: bool, class: string}>
	 */
	public static function sections(): array {
		return array(
			'prices'     => array(
				'label' => __( 'Prices', 'eurob2b' ),
				'open'  => true,
				'class' => 'show_if_simple show_if_external',
			),
			'tiers'      => array(
				'label' => __( 'Quantity tiers', 'eurob2b' ),
				'open'  => true,
				'class' => '',
			),
			'quantities' => array(
				'label' => __( 'Order quantities', 'eurob2b' ),
				'open'  => false,
				'class' => '',
			),
			'stock'      => array(
				'label' => __( 'Stock for business customers', 'eurob2b' ),
				'open'  => false,
				'class' => '',
			),
			'visibility' => array(
				'label' => __( 'Visibility', 'eurob2b' ),
				'open'  => false,
				'class' => '',
			),
			'variations' => array(
				'label' => __( 'Variations table', 'eurob2b' ),
				'open'  => false,
				'class' => 'show_if_variable',
			),
			'tax'        => array(
				'label' => __( 'Tax', 'eurob2b' ),
				'open'  => false,
				'class' => '',
			),
		);
	}

	/**
	 * Adds the tab.
	 *
	 * @param mixed $tabs Tabs.
	 * @return mixed
	 */
	public function tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}
		$tabs['eurob2b'] = array(
			'label'    => __( 'EuroB2B', 'eurob2b' ),
			'target'   => self::TARGET,
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	/**
	 * Prints the panel with its sections.
	 */
	public function render(): void {
		global $product_object;
		$product = $product_object instanceof WC_Product ? $product_object : null;
		echo '<div id="' . esc_attr( self::TARGET ) . '" class="panel woocommerce_options_panel hidden eurob2b-product-panel">';
		echo '<style>.eurob2b-product-panel details{border-bottom:1px solid #eee}.eurob2b-product-panel summary{cursor:pointer;padding:12px;font-weight:600;list-style-position:inside}.eurob2b-product-panel summary:hover{background:#f6f7f7}.eurob2b-product-section__summary{float:right;margin-left:12px;font-weight:400;font-size:12px;color:#646970}.eurob2b-product-panel details>.options_group:last-child{border-bottom:0}.eurob2b-product-variation-note{margin:0;padding:12px!important;border-bottom:1px solid #eee;color:#646970}</style>';
		// Variable products keep these values per variation (WooCommerce's Variations tab).
		echo '<p class="form-field show_if_variable eurob2b-product-variation-note">' . esc_html__( 'Variable products: group prices, customer prices, quantity tiers and order quantities are set on each variation in the Variations tab.', 'eurob2b' ) . '</p>';
		$printed = 0;
		foreach ( self::sections() as $key => $section ) {
			ob_start();
			self::fire( $key );
			$html = (string) ob_get_clean();
			if ( '' === trim( $html ) ) {
				continue;
			}
			++$printed;
			$summary = null !== $product ? self::summary( $key, $product ) : '';
			echo '<details class="' . esc_attr( trim( 'eurob2b-product-section ' . $section['class'] ) ) . '"' . ( $section['open'] ? ' open' : '' ) . '><summary>' . esc_html( $section['label'] );
			if ( '' !== $summary ) {
				echo '<span class="eurob2b-product-section__summary">' . esc_html( $summary ) . '</span>';
			}
			echo '</summary>';
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printed by the field classes, which escape their output.
			echo '</details>';
		}
		if ( 0 === $printed ) {
			echo '<p class="form-field">' . esc_html__( 'No EuroB2B settings for this product.', 'eurob2b' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Short summary of a section's saved values, the parts joined by a middle dot ('' = none).
	 *
	 * @param string     $key     Section key.
	 * @param WC_Product $product Product being edited.
	 */
	public static function summary( string $key, WC_Product $product ): string {
		/**
		 * Filters the parts of the summary next to a section title of the EuroB2B product tab, e.g.
		 * "2 of 4 groups with their own price". Each module adds plain-text parts for its own fields
		 * of that section and only for users who may see those fields.
		 *
		 * @param string[]   $parts   Plain-text parts.
		 * @param string     $key     Section key (prices, tiers, quantities, stock, visibility, variations, tax).
		 * @param WC_Product $product Product being edited.
		 */
		$parts = apply_filters( 'eurob2b_product_panel_summary', array(), $key, $product );
		$parts = is_array( $parts ) ? array_filter( array_map( static fn( $p ): string => is_string( $p ) ? trim( $p ) : '', $parts ) ) : array();
		return implode( ' · ', $parts );
	}

	/**
	 * Fires the action of a section (one literal hook name per section, for the hook reference).
	 *
	 * @param string $key Section key.
	 */
	private static function fire( string $key ): void {
		switch ( $key ) {
			case 'prices':
				/**
				 * Fields of the section "prices" of the EuroB2B product tab: recommended retail price, group prices, customer prices. Print them as `options_group` blocks.
				 */
				do_action( 'eurob2b_product_panel_prices' );
				break;
			case 'tiers':
				/**
				 * Fields of the section "tiers" of the EuroB2B product tab: quantity tiers and the tier table switch. Print them as `options_group` blocks.
				 */
				do_action( 'eurob2b_product_panel_tiers' );
				break;
			case 'quantities':
				/**
				 * Fields of the section "quantities" of the EuroB2B product tab: minimum, maximum and step quantities. Print them as `options_group` blocks.
				 */
				do_action( 'eurob2b_product_panel_quantities' );
				break;
			case 'stock':
				/**
				 * Fields of the section "stock" of the EuroB2B product tab: the separate stock for business customers. Print them as `options_group` blocks.
				 */
				do_action( 'eurob2b_product_panel_stock' );
				break;
			case 'visibility':
				/**
				 * Fields of the section "visibility" of the EuroB2B product tab: visibility per audience. Print them as `options_group` blocks.
				 */
				do_action( 'eurob2b_product_panel_visibility' );
				break;
			case 'variations':
				/**
				 * Fields of the section "variations" of the EuroB2B product tab: the variations table (variable products). Print them as `options_group` blocks.
				 */
				do_action( 'eurob2b_product_panel_variations' );
				break;
			case 'tax':
				/**
				 * Fields of the section "tax" of the EuroB2B product tab: the supply type (goods or service) for the tax decision. Print them as `options_group` blocks.
				 */
				do_action( 'eurob2b_product_panel_tax' );
				break;
		}//end switch
	}
}

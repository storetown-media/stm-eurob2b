<?php
/**
 * EuroB2B tab in the WooCommerce product data box.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

/**
 * Collects the EuroB2B product fields in one tab of the product data box (PLT-017) instead of
 * spreading them over General, Inventory, Advanced and Tax. Each section is a collapsible block;
 * modules print their fields on the action `eurob2b_product_panel_<section>`. The fields stay in the
 * product form, so their names and the save handlers do not change. A section whose action prints
 * nothing (feature off, Pro missing) is left out. Variation fields keep their WooCommerce hooks.
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
		echo '<div id="' . esc_attr( self::TARGET ) . '" class="panel woocommerce_options_panel hidden eurob2b-product-panel">';
		echo '<style>.eurob2b-product-panel details{border-bottom:1px solid #eee}.eurob2b-product-panel summary{cursor:pointer;padding:12px;font-weight:600;list-style-position:inside}.eurob2b-product-panel summary:hover{background:#f6f7f7}.eurob2b-product-panel details>.options_group:last-child{border-bottom:0}.eurob2b-product-variation-note{margin:0;padding:12px!important;border-bottom:1px solid #eee;color:#646970}</style>';
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
			echo '<details class="' . esc_attr( trim( 'eurob2b-product-section ' . $section['class'] ) ) . '"' . ( $section['open'] ? ' open' : '' ) . '><summary>' . esc_html( $section['label'] ) . '</summary>';
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printed by the field classes, which escape their output.
			echo '</details>';
		}
		if ( 0 === $printed ) {
			echo '<p class="form-field">' . esc_html__( 'No EuroB2B settings for this product.', 'eurob2b' ) . '</p>';
		}
		echo '</div>';
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

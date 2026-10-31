<?php
/**
 * Goods/services setting on the product edit screen.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Admin;

use EuroB2B\Modules\Tax\Treatment\SupplyType;
use WC_Product;

/**
 * TAX-021: a select in the product's tax section. WooCommerce verifies its nonce before the save hook.
 */
final class ProductSupplyTypeField {

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		add_action( 'eurob2b_product_panel_tax', array( $this, 'render' ) );
		add_filter( 'eurob2b_product_panel_summary', array( $this, 'summary' ), 10, 3 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
	}

	/**
	 * Summary of the section "tax" of the EuroB2B product tab: goods or service.
	 *
	 * @param mixed  $parts   Parts.
	 * @param string $key     Section key.
	 * @param mixed  $product Product.
	 * @return mixed
	 */
	public function summary( $parts, $key, $product ) {
		if ( 'tax' !== $key || ! is_array( $parts ) || ! $product instanceof WC_Product ) {
			return $parts;
		}
		$parts[] = SupplyType::SERVICES === SupplyType::of( $product ) ? __( 'Service', 'eurob2b' ) : __( 'Goods', 'eurob2b' );
		return $parts;
	}

	/**
	 * Renders the select.
	 */
	public function render(): void {
		global $product_object;
		$value = $product_object instanceof WC_Product ? SupplyType::of( $product_object ) : SupplyType::GOODS;
		echo '<div class="options_group eurob2b-supply-type">';
		woocommerce_wp_select(
			array(
				'id'          => SupplyType::META,
				'label'       => __( 'Supply type (EuroB2B)', 'eurob2b' ),
				'description' => __( 'Services are taxed where the business customer is established (Art. 44); goods where they are delivered.', 'eurob2b' ),
				'desc_tip'    => true,
				'value'       => $value,
				'options'     => array(
					SupplyType::GOODS    => __( 'Goods', 'eurob2b' ),
					SupplyType::SERVICES => __( 'Service', 'eurob2b' ),
				),
			)
		);
		echo '</div>';
	}

	/**
	 * Saves the select.
	 *
	 * @param mixed $product Product.
	 */
	public function save( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce_meta_nonce.
		$value = isset( $_POST[ SupplyType::META ] ) ? sanitize_key( wp_unslash( (string) $_POST[ SupplyType::META ] ) ) : '';
		if ( SupplyType::SERVICES === $value ) {
			$product->update_meta_data( SupplyType::META, SupplyType::SERVICES );
		} else {
			$product->delete_meta_data( SupplyType::META );
		}
	}
}

<?php
/**
 * Group price fields on the product edit screen.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Pricing\GroupPrices;
use EuroB2B\Support\Currency;
use WC_Product;


/**
 * PRC-002: regular and sale price per group for simple products and for every variation. Shown to
 * and saved for users with `eurob2b_manage_groups` only; WooCommerce checks its product capability
 * and nonce before the save hooks fire. A group whose fields were not posted keeps its prices.
 */
final class ProductPriceFields {

	/**
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Group prices.
	 *
	 * @var GroupPrices
	 */
	private GroupPrices $prices;

	/**
	 * Constructor.
	 *
	 * @param GroupRepository $groups Groups.
	 * @param GroupPrices     $prices Group prices.
	 */
	public function __construct( GroupRepository $groups, GroupPrices $prices ) {
		$this->groups = $groups;
		$this->prices = $prices;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
	}

	/**
	 * Fields in the "General" tab, after WooCommerce's own prices.
	 */
	public function render_product_fields(): void {
		if ( ! current_user_can( Capabilities::MANAGE_GROUPS ) ) {
			return;
		}
		global $product_object;
		$product = $product_object instanceof WC_Product ? $product_object : null;
		$groups  = $this->sorted_groups();
		if ( empty( $groups ) ) {
			return;
		}
		echo '<div class="options_group eurob2b-group-prices show_if_simple show_if_external">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Group prices (EuroB2B)', 'eurob2b' ) . '</strong><br /><span class="description">' . esc_html__( 'Members of a group pay the group price instead of the regular price. Leave empty to use the regular price.', 'eurob2b' ) . '</span></p>';
		foreach ( $groups as $group ) {
			$current = null !== $product ? $this->prices->get( $product, $group->id, Currency::of_group( $group ) ) : array(
				'regular' => '',
				'sale'    => '',
			);
			$this->price_input( GroupPrices::regular_key( $group->id, Currency::of_group( $group ) ), $current['regular'], self::label( $group, false ) );
			$this->price_input( GroupPrices::sale_key( $group->id, Currency::of_group( $group ) ), $current['sale'], self::label( $group, true ) );
		}
		echo '</div>';
	}

	/**
	 * Saves the product fields.
	 *
	 * @param mixed $product Product being saved.
	 */
	public function save_product( $product ): void {
		if ( ! $product instanceof WC_Product || ! current_user_can( Capabilities::MANAGE_GROUPS ) ) {
			return;
		}
		foreach ( $this->groups->all() as $group ) {
			$regular_key = GroupPrices::regular_key( $group->id, Currency::of_group( $group ) );
			$sale_key    = GroupPrices::sale_key( $group->id, Currency::of_group( $group ) );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce_meta_nonce.
			if ( ! isset( $_POST[ $regular_key ] ) || ! is_scalar( $_POST[ $regular_key ] ) ) {
				continue;
			}
			$regular = sanitize_text_field( wp_unslash( (string) $_POST[ $regular_key ] ) );
			$sale    = isset( $_POST[ $sale_key ] ) && is_scalar( $_POST[ $sale_key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $sale_key ] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$this->prices->set( $product, $group->id, $regular, $sale, Currency::of_group( $group ) );
		}
	}

	/**
	 * Fields per variation (pricing row).
	 *
	 * @param mixed $loop           Variation index in the form.
	 * @param mixed $variation_data Unused legacy data.
	 * @param mixed $variation      WP_Post of the variation.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ): void {
		if ( ! current_user_can( Capabilities::MANAGE_GROUPS ) ) {
			return;
		}
		$groups = $this->sorted_groups();
		if ( empty( $groups ) || ! is_object( $variation ) || ! isset( $variation->ID ) ) {
			return;
		}
		$product = wc_get_product( (int) $variation->ID );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$loop = (int) $loop;
		echo '<div class="eurob2b-group-prices eurob2b-variation-group-prices" style="clear:both;">';
		echo '<p class="form-row form-row-full"><strong>' . esc_html__( 'Group prices (EuroB2B)', 'eurob2b' ) . '</strong></p>';
		foreach ( $groups as $group ) {
			$current = $this->prices->get( $product, $group->id, Currency::of_group( $group ) );
			$this->variation_price_input( GroupPrices::regular_key( $group->id, Currency::of_group( $group ) ), $loop, $current['regular'], self::label( $group, false ), 'form-row-first' );
			$this->variation_price_input( GroupPrices::sale_key( $group->id, Currency::of_group( $group ) ), $loop, $current['sale'], self::label( $group, true ), 'form-row-last' );
		}
		echo '</div>';
	}

	/**
	 * Saves the variation fields (WooCommerce already saved the variation itself).
	 *
	 * @param mixed $variation_id Variation id.
	 * @param mixed $loop         Index in the form.
	 */
	public function save_variation( $variation_id, $loop ): void {
		$variation = wc_get_product( (int) $variation_id );
		if ( ! $variation instanceof WC_Product || ! current_user_can( Capabilities::MANAGE_GROUPS ) ) {
			return;
		}
		$loop    = (int) $loop;
		$changed = false;
		foreach ( $this->groups->all() as $group ) {
			$regular_key = GroupPrices::regular_key( $group->id, Currency::of_group( $group ) );
			$sale_key    = GroupPrices::sale_key( $group->id, Currency::of_group( $group ) );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified its nonce (save_variations / AJAX).
			if ( ! isset( $_POST[ $regular_key ][ $loop ] ) || ! is_array( $_POST[ $regular_key ] ) || ! is_scalar( $_POST[ $regular_key ][ $loop ] ) ) {
				continue;
			}
			$regular = sanitize_text_field( wp_unslash( (string) $_POST[ $regular_key ][ $loop ] ) );
			$sale    = isset( $_POST[ $sale_key ][ $loop ] ) && is_array( $_POST[ $sale_key ] ) && is_scalar( $_POST[ $sale_key ][ $loop ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $sale_key ][ $loop ] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$this->prices->set( $variation, $group->id, $regular, $sale, Currency::of_group( $group ) );
			$changed = true;
		}
		if ( $changed ) {
			$variation->save();
		}
	}

	/**
	 * B2B groups first, then B2C groups, each by priority and name.
	 *
	 * @return Group[]
	 */
	private function sorted_groups(): array {
		$groups = $this->groups->all();
		usort(
			$groups,
			static function ( Group $a, Group $b ): int {
				if ( $a->customer_type !== $b->customer_type ) {
					return CustomerType::B2B === $a->customer_type ? -1 : 1;
				}
				return $a->priority <=> $b->priority ?: strcasecmp( $a->name, $b->name );
			}
		);
		return $groups;
	}

	/**
	 * Field label with the group's currency (CUR-001: a group with its own currency enters its prices
	 * in that currency, stored under keys with the currency appended).
	 *
	 * @param Group $group Group.
	 * @param bool  $sale  Sale price.
	 */
	private static function label( Group $group, bool $sale ): string {
		/* translators: 1: group name, 2: currency symbol */
		$pattern = $sale ? __( '%1$s – sale price (%2$s)', 'eurob2b' ) : __( '%1$s – price (%2$s)', 'eurob2b' );
		$code    = Currency::of_group( $group );
		return sprintf( $pattern, $group->name, '' === $code ? get_woocommerce_currency_symbol() : $code );
	}

	/**
	 * Simple product input (WooCommerce's helper handles escaping and the decimal separator).
	 *
	 * @param string $id    Field id/name.
	 * @param string $value Current value.
	 * @param string $label Label.
	 */
	private function price_input( string $id, string $value, string $label ): void {
		woocommerce_wp_text_input(
			array(
				'id'        => $id,
				'value'     => '' === $value ? '' : wc_format_localized_price( $value ),
				'label'     => $label,
				'data_type' => 'price',
			)
		);
	}

	/**
	 * Variation input (array-indexed by loop).
	 *
	 * @param string $key     Meta key.
	 * @param int    $loop    Index.
	 * @param string $value   Current value.
	 * @param string $label   Label.
	 * @param string $wrapper Wrapper class.
	 */
	private function variation_price_input( string $key, int $loop, string $value, string $label, string $wrapper ): void {
		woocommerce_wp_text_input(
			array(
				'id'            => $key . '_' . $loop,
				'name'          => $key . '[' . $loop . ']',
				'value'         => '' === $value ? '' : wc_format_localized_price( $value ),
				'label'         => $label,
				'data_type'     => 'price',
				'wrapper_class' => 'form-row ' . $wrapper,
			)
		);
	}
}

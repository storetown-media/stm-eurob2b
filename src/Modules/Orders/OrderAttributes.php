<?php
/**
 * Order attributes recorded at purchase.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Orders;

use EuroB2B\Modules\Admin\PageHeader;
use EuroB2B\Modules\Admin\StatusLabel;
use EuroB2B\Modules\Customers\AudienceContext;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Support\Assets;
use WC_Order;

/**
 * PLT-004: every order records who bought it in B2B terms (customer type, group, company) at the
 * time of purchase, as HPOS-safe order meta written through the CRUD API. Later modules add
 * their own keys through the filter (tax treatment, VAT validation id, placed_by).
 */
final class OrderAttributes {

	public const META_CUSTOMER_TYPE = '_eurob2b_customer_type';
	public const META_GROUP_ID      = '_eurob2b_group_id';
	public const META_GROUP_NAME    = '_eurob2b_group_name';
	public const META_COMPANY_ID    = '_eurob2b_company_id';

	/**
	 * The user who placed an order for a company account (written by the company module of EuroB2B
	 * Pro; read by the proof of supply, which asks only the ordering person to confirm the receipt).
	 */
	public const META_PLACED_BY = '_eurob2b_placed_by';

	/**
	 * Audience resolver.
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $audiences;

	/**
	 * Constructor.
	 *
	 * @param AudienceResolver $audiences Audience resolver.
	 */
	public function __construct( AudienceResolver $audiences ) {
		$this->audiences = $audiences;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		// Classic checkout: before the order is saved.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'record_on_checkout' ), 10, 1 );
		// Store API checkout: the order is saved right after this action.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'record_on_store_api' ), 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_admin_box' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_style' ) );
	}

	/**
	 * Writes the attributes of the buying customer onto the order (no save; the checkout saves).
	 *
	 * @param mixed $order Order.
	 */
	public function record_on_checkout( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$customer_id = $order->get_customer_id();
		$audience    = $customer_id > 0 ? $this->audiences->for_user( $customer_id ) : $this->audiences->current();
		$this->record( $order, $audience );
	}

	/**
	 * Store API: the same action also runs when an existing order is paid (`/checkout/{id}`); the
	 * attributes describe the purchase, so only the draft of a new order gets them. Otherwise paying
	 * an order would replace who placed it with who pays it.
	 *
	 * @param mixed $order Order.
	 */
	public function record_on_store_api( $order ): void {
		if ( $order instanceof WC_Order && $order->has_status( 'checkout-draft' ) ) {
			$this->record_on_checkout( $order );
		}
	}

	/**
	 * Writes the attributes for an audience onto the order object.
	 *
	 * @param WC_Order        $order    Order.
	 * @param AudienceContext $audience Audience of the buyer.
	 */
	public function record( WC_Order $order, AudienceContext $audience ): void {
		foreach ( self::attributes_for( $audience, $order ) as $key => $value ) {
			if ( '' === $value ) {
				$order->delete_meta_data( $key );
			} else {
				$order->update_meta_data( $key, $value );
			}
		}
	}

	/**
	 * Attribute values for an audience (filterable so other modules add theirs).
	 *
	 * @param AudienceContext $audience Audience.
	 * @param WC_Order        $order    Order.
	 * @return array<string, string> meta key => value ("" removes the key)
	 */
	public static function attributes_for( AudienceContext $audience, WC_Order $order ): array {
		// The order contract knows b2b and b2c only: a guest buys as a consumer.
		$type       = CustomerType::GUEST === $audience->customer_type ? CustomerType::B2C : $audience->customer_type;
		$attributes = array(
			self::META_CUSTOMER_TYPE => $type->value,
			self::META_GROUP_ID      => null !== $audience->group ? (string) $audience->group->id : '',
			self::META_GROUP_NAME    => null !== $audience->group ? $audience->group->name : '',
			self::META_COMPANY_ID    => null !== $audience->company_id ? (string) $audience->company_id : '',
		);

		/**
		 * Filters the order attributes recorded at purchase.
		 *
		 * @param array<string, string> $attributes Meta key => value.
		 * @param AudienceContext       $audience   Audience of the buyer.
		 * @param WC_Order              $order      Order.
		 */
		$filtered = apply_filters( 'eurob2b_order_attributes', $attributes, $audience, $order );
		$result   = array();
		foreach ( is_array( $filtered ) ? $filtered : $attributes as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, '_eurob2b_' ) && is_scalar( $value ) ) {
				$result[ $key ] = (string) $value;
			}
		}
		return $result;
	}

	/**
	 * Read-only block on the order edit screen (classic and HPOS screens both fire this action).
	 *
	 * @param mixed $order Order.
	 */
	public function render_admin_box( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// Orders without the attributes (placed before EuroB2B or created in the admin) get the box
		// only when another module has a row for them, e.g. days overdue (PAY-021).
		$type = (string) $order->get_meta( self::META_CUSTOMER_TYPE, true );
		$rows = array();
		if ( '' !== $type ) {
			$rows    = array(
				__( 'Customer type', 'eurob2b' ) => 'b2b' === $type ? __( 'Business (B2B)', 'eurob2b' ) : __( 'Private (B2C)', 'eurob2b' ),
				__( 'Group', 'eurob2b' )         => (string) $order->get_meta( self::META_GROUP_NAME, true ),
			);
			$company = (string) $order->get_meta( self::META_COMPANY_ID, true );
			if ( '' !== $company ) {
				$rows[ __( 'Company', 'eurob2b' ) ] = '#' . $company;
			}
		}
		/**
		 * Filters the rows shown in the EuroB2B box on the order screen.
		 *
		 * @param array<string, string> $rows  Label => value.
		 * @param WC_Order              $order Order.
		 */
		$rows = apply_filters( 'eurob2b_order_attribute_rows', $rows, $order );
		$rows = array_filter( is_array( $rows ) ? $rows : array(), static fn( $value ): bool => '' !== (string) $value );
		if ( array() === $rows ) {
			return;
		}

		/**
		 * Colours of rows in the EuroB2B box (PLT-017): label => tone of `StatusLabel` (ok, warn, bad,
		 * info). A coloured row shows its text as a label, e.g. a missing proof of supply in red.
		 *
		 * @param array<string, string> $tones Label => tone.
		 * @param array<string, string> $rows  Rows of the box.
		 * @param WC_Order              $order Order.
		 */
		$tones = apply_filters( 'eurob2b_order_attribute_tones', array(), $rows, $order );
		$tones = is_array( $tones ) ? $tones : array();

		echo '<div class="eurob2b-order-attributes" style="clear:both;padding-top:12px;">';
		echo '<h3 style="margin-bottom:4px;">' . esc_html__( 'EuroB2B at purchase', 'eurob2b' ) . '</h3>';
		foreach ( $rows as $label => $value ) {
			$tone = isset( $tones[ $label ] ) ? (string) $tones[ $label ] : '';
			echo '<p class="form-field form-field-wide" style="margin:0 0 4px;"><strong>' . esc_html( (string) $label ) . ':</strong> ' . ( '' === $tone ? esc_html( (string) $value ) : StatusLabel::html( (string) $value, $tone ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here or in StatusLabel::html().
		}
		echo '</div>';
	}

	/**
	 * Hooked to admin_enqueue_scripts: the EuroB2B admin stylesheet (status labels) on the order screens.
	 */
	public static function enqueue_style(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// WooCommerce's own id of the order screen: the HPOS page or the legacy post screen.
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : '';
		if ( null === $screen || '' === $order_screen || $order_screen !== (string) $screen->id ) {
			return;
		}
		wp_enqueue_style( PageHeader::STYLE, EUROB2B_PLUGIN_URL . 'assets/css/admin.css', array(), Assets::version( 'assets/css/admin.css' ) );
	}
}

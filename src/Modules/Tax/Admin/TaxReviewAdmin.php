<?php
/**
 * Confirming the review of orders whose tax treatment EuroB2B could not decide.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\StatusLabel;
use EuroB2B\Modules\Tax\Proof\ProofRecords;
use EuroB2B\Modules\Tax\Treatment\CartTreatment;
use EuroB2B\Support\Hpos;
use WC_Order;
use WP_Query;

/**
 * Orders flagged with `CartTreatment::META_NEEDS_REVIEW` (VAT ID check unavailable or pending,
 * pickup, a country rule not reviewed yet) wait for a person. Staff confirm the review on the order
 * screen (product owner decision of 2026-10-31); EuroB2B stores who and when and writes the audit
 * log, the flag itself stays as evidence. The overview counts flagged orders without a review and
 * links to the order list filtered to them.
 */
final class TaxReviewAdmin {

	public const META_REVIEWED_AT = '_eurob2b_tax_reviewed_at';
	public const META_REVIEWED_BY = '_eurob2b_tax_reviewed_by';
	public const ACTION           = 'eurob2b_tax_reviewed';
	public const NONCE_PREFIX     = 'eurob2b_tax_reviewed_';
	public const QUERY_VAR        = 'eurob2b_tax_review';

	/**
	 * Order statuses that need no tax review.
	 */
	private const SKIPPED_STATUSES = array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft' );

	/**
	 * Hooks (admin requests).
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order' ), 15 );
		add_filter( 'eurob2b_overview_items', array( $this, 'overview_items' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_list_args' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_legacy_list' ) );
	}

	/**
	 * Whether the order is flagged for a tax review.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function flagged( WC_Order $order ): bool {
		return 'yes' === (string) $order->get_meta( CartTreatment::META_NEEDS_REVIEW, true );
	}

	/**
	 * Whether a person confirmed the review.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function reviewed( WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::META_REVIEWED_AT, true );
	}

	/**
	 * Meta conditions of flagged orders without a review.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function open_meta_query(): array {
		return array(
			'relation' => 'AND',
			array(
				'key'   => CartTreatment::META_NEEDS_REVIEW,
				'value' => 'yes',
			),
			array(
				'key'     => self::META_REVIEWED_AT,
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Number of flagged orders without a review (not cancelled or failed).
	 */
	public static function open_count(): int {
		// ProofRecords::orders() applies the meta query on both order stores (the posts store refuses
		// meta_query in wc_get_orders() since WooCommerce 9.2).
		$result = ProofRecords::orders(
			array(
				'status'   => self::statuses(),
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			),
			self::open_meta_query()
		);
		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Order statuses that can need a tax review, narrowed to a status the list already shows.
	 *
	 * @param mixed $current Status of the list request ('' or 'all' for every status).
	 * @return string[]
	 */
	public static function statuses( $current = '' ): array {
		$allowed = array_values( array_diff( array_keys( wc_get_order_statuses() ), self::SKIPPED_STATUSES ) );
		if ( '' === $current || 'all' === $current || 'any' === $current || null === $current ) {
			return $allowed;
		}
		$wanted = array_map( static fn( $s ): string => str_starts_with( (string) $s, 'wc-' ) ? (string) $s : 'wc-' . $s, (array) $current );
		$kept   = array_values( array_intersect( $allowed, $wanted ) );
		// A status tab without reviewable orders (e.g. Cancelled) shows nothing instead of everything.
		return array() === $kept ? array( 'eurob2b-none' ) : $kept;
	}

	/**
	 * The order list filtered to flagged orders without a review.
	 */
	public static function list_url(): string {
		// On the legacy screen the screen id is the order post type.
		$base = Hpos::enabled() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=' . wc_get_page_screen_id( 'shop-order' ) );
		return add_query_arg( self::QUERY_VAR, 'open', $base );
	}

	/**
	 * Whether the current order list request asks for the filter.
	 */
	private static function filter_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter.
		return isset( $_GET[ self::QUERY_VAR ] ) && 'open' === sanitize_key( (string) wp_unslash( $_GET[ self::QUERY_VAR ] ) ) && current_user_can( Capabilities::DECIDE_VALIDATION );
	}

	/**
	 * HPOS order list: only flagged orders without a review.
	 *
	 * @param mixed $args Query arguments of the list.
	 * @return mixed
	 */
	public function filter_list_args( $args ) {
		if ( ! is_array( $args ) || ! self::filter_requested() ) {
			return $args;
		}
		$args['meta_query'] = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? array( $args['meta_query'], self::open_meta_query() ) : self::open_meta_query(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a filter staff choose.
		$args['status']     = self::statuses( $args['status'] ?? '' );
		return $args;
	}

	/**
	 * Legacy order list (orders as posts): the same filter.
	 *
	 * @param mixed $query Query.
	 */
	public function filter_legacy_list( $query ): void {
		if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() || ! in_array( $query->get( 'post_type' ), wc_get_order_types( 'view-orders' ), true ) || ! self::filter_requested() ) {
			return;
		}
		$query->set( 'meta_query', self::open_meta_query() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a filter staff choose.
		$query->set( 'post_status', self::statuses( $query->get( 'post_status' ) ) );
	}

	/**
	 * Overview line.
	 *
	 * @param mixed $items Items.
	 * @return mixed
	 */
	public function overview_items( $items ) {
		// Count only for users who may confirm the review; the overview hides the line for others anyway.
		if ( ! is_array( $items ) || ! current_user_can( Capabilities::DECIDE_VALIDATION ) ) {
			return $items;
		}
		$items[] = array(
			'count'      => self::open_count(),
			'title'      => __( 'Orders whose tax treatment needs a review', 'eurob2b' ),
			'text'       => __( 'EuroB2B could not decide the tax itself: the VAT ID check was unavailable or pending, the customer picks the goods up, or the country rule is not reviewed yet.', 'eurob2b' ),
			'url'        => self::list_url(),
			'action'     => _x( 'Review', 'button on the overview page', 'eurob2b' ),
			'capability' => Capabilities::DECIDE_VALIDATION,
		);
		return $items;
	}

	/**
	 * The review state on the order screen, below the EuroB2B box.
	 *
	 * @param mixed $order Order.
	 */
	public function render_order( $order ): void {
		if ( ! $order instanceof WC_Order || ! self::flagged( $order ) ) {
			return;
		}
		echo '<div class="eurob2b-tax-review" style="clear:both;padding-top:8px;">';
		if ( self::reviewed( $order ) ) {
			$by   = get_userdata( (int) $order->get_meta( self::META_REVIEWED_BY, true ) );
			$when = (int) strtotime( (string) $order->get_meta( self::META_REVIEWED_AT, true ) . ' UTC' );
			/* translators: 1: date and time, 2: name of the staff member */
			$text = sprintf( __( 'Tax treatment reviewed on %1$s by %2$s.', 'eurob2b' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ), $by ? $by->display_name : '#' . (int) $order->get_meta( self::META_REVIEWED_BY, true ) );
			echo '<p class="form-field form-field-wide" style="margin:0;">' . StatusLabel::html( $text, StatusLabel::OK ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in StatusLabel::html().
		} elseif ( current_user_can( Capabilities::DECIDE_VALIDATION ) ) {
			$args = array(
				'action'   => self::ACTION,
				'order_id' => $order->get_id(),
			);
			$url  = wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE_PREFIX . $order->get_id() );
			echo '<p class="form-field form-field-wide" style="margin:0 0 6px;"><span class="description">' . esc_html__( 'EuroB2B could not decide the tax of this order itself. Check the VAT ID, the delivery and the taxes of the order and correct them if needed, then confirm the review.', 'eurob2b' ) . '</span></p>';
			echo '<p class="form-field form-field-wide" style="margin:0;"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Mark the tax treatment as reviewed', 'eurob2b' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Confirms the review and returns to the order.
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::DECIDE_VALIDATION ) ) {
			wp_die( esc_html__( 'You are not allowed to review tax treatments.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified right below with the order id.
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
		check_admin_referer( self::NONCE_PREFIX . $order_id );
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || ! self::flagged( $order ) ) {
			wp_die( esc_html__( 'This order is not flagged for a tax review.', 'eurob2b' ), '', array( 'response' => 400 ) );
		}
		if ( ! self::reviewed( $order ) ) {
			self::confirm( $order, get_current_user_id() );
		}
		if ( wp_safe_redirect( $order->get_edit_order_url() ) ) {
			exit;
		}
	}

	/**
	 * Stores the review and tells the audit log.
	 *
	 * @param WC_Order $order    Order.
	 * @param int      $actor_id Staff member.
	 */
	public static function confirm( WC_Order $order, int $actor_id ): void {
		$order->update_meta_data( self::META_REVIEWED_AT, gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( self::META_REVIEWED_BY, (string) $actor_id );
		$order->save();
		/**
		 * A staff member confirmed the review of an order whose tax treatment EuroB2B flagged (the
		 * audit log records it).
		 *
		 * @param WC_Order $order    Order.
		 * @param int      $actor_id Staff member.
		 */
		do_action( 'eurob2b_tax_review_confirmed', $order, $actor_id );
	}

	/**
	 * WooCommerce order anonymisation (privacy tools, scheduled cleanup): the staff member who
	 * confirmed the review goes; the time of the review carries no personal data and stays.
	 *
	 * @param mixed $order Order.
	 */
	public static function anonymise_order( $order ): void {
		if ( $order instanceof WC_Order && '' !== (string) $order->get_meta( self::META_REVIEWED_BY, true ) ) {
			$order->delete_meta_data( self::META_REVIEWED_BY );
			$order->save();
		}
	}
}

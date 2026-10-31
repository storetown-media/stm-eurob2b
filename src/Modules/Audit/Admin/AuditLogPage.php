<?php
/**
 * Admin page: audit log.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Audit\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Admin\PageHeader;
use EuroB2B\Modules\Audit\AuditEntry;
use EuroB2B\Modules\Audit\AuditFormatter;
use EuroB2B\Modules\Audit\AuditLog;
use EuroB2B\Modules\Audit\AuditRetention;
use WC_Order;

/**
 * EuroB2B → Audit log (PRV-011): read-only list of administrative decisions with filters by action
 * and user. Capability `eurob2b_manage_settings`. There is no way to edit or delete entries here;
 * they go with the retention period, and an erasure request removes a person's references.
 */
final class AuditLogPage {

	public const SLUG     = 'audit-log';
	public const PER_PAGE = 50;

	/**
	 * Log.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Labels.
	 *
	 * @var AuditFormatter
	 */
	private AuditFormatter $format;

	/**
	 * Retention.
	 *
	 * @var AuditRetention
	 */
	private AuditRetention $retention;

	/**
	 * Constructor.
	 *
	 * @param AuditLog       $log       Log.
	 * @param AuditFormatter $format    Labels.
	 * @param AuditRetention $retention Retention.
	 */
	public function __construct( AuditLog $log, AuditFormatter $format, AuditRetention $retention ) {
		$this->log       = $log;
		$this->format    = $format;
		$this->retention = $retention;
	}

	/**
	 * Menu entry.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'Audit log', 'eurob2b' ), __( 'Audit log', 'eurob2b' ), Capabilities::MANAGE_SETTINGS, array( $this, 'render' ), 95 );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to view the audit log.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$action = isset( $_GET['audit_action'] ) ? sanitize_key( (string) wp_unslash( $_GET['audit_action'] ) ) : '';
		$user   = isset( $_GET['audit_user'] ) ? absint( $_GET['audit_user'] ) : 0;
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$action  = in_array( $action, AuditLog::actions(), true ) ? $action : '';
		$filters = array(
			'action'  => $action,
			'user_id' => $user,
		);
		$total   = $this->log->count( $filters );
		$entries = $this->log->search( $filters, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		PageHeader::open( 'eurob2b-audit-log', __( 'Audit log', 'eurob2b' ) );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of days */
				_n( 'Decisions on registrations, customer status, type and group, VAT ID checks and orders waiting for approval, with the deciding user. Entries are deleted %d day after they were written (setting under Settings → Privacy and data); an erasure request removes the person from them.', 'Decisions on registrations, customer status, type and group, VAT ID checks and orders waiting for approval, with the deciding user. Entries are deleted %d days after they were written (setting under Settings → Privacy and data); an erasure request removes the person from them.', $this->retention->days(), 'eurob2b' ),
				$this->retention->days()
			)
		) . '</p>';

		echo '<form method="get" class="eurob2b-audit-log__filters"><input type="hidden" name="page" value="' . esc_attr( Menu::page_slug( self::SLUG ) ) . '" />';
		echo '<label for="eurob2b-audit-action">' . esc_html__( 'Action', 'eurob2b' ) . '</label> <select id="eurob2b-audit-action" name="audit_action"><option value="">' . esc_html__( 'All actions', 'eurob2b' ) . '</option>';
		foreach ( AuditFormatter::action_labels() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $action, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <label for="eurob2b-audit-user">' . esc_html__( 'User ID', 'eurob2b' ) . '</label> <input type="number" min="1" class="small-text" id="eurob2b-audit-user" name="audit_user" value="' . esc_attr( $user > 0 ? (string) $user : '' ) . '" /> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'eurob2b' ) . '</button></form>';

		// On narrow screens each entry becomes a block of labelled lines instead of a wide row.
		echo '<style>@media (max-width:782px){.eurob2b-audit-log__table thead{display:none}.eurob2b-audit-log__table tr{display:block}.eurob2b-audit-log__table td{display:block;padding:.3em .75em}.eurob2b-audit-log__table td[data-label]::before{content:attr(data-label);display:block;font-weight:600}}</style>';
		$headings = array(
			'time'    => __( 'Time', 'eurob2b' ),
			'action'  => __( 'Action', 'eurob2b' ),
			'actor'   => __( 'Decided by', 'eurob2b' ),
			'subject' => __( 'Concerns', 'eurob2b' ),
			'change'  => __( 'Change', 'eurob2b' ),
		);
		echo '<table class="widefat striped eurob2b-audit-log__table"><thead><tr>';
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $entries ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No entries.', 'eurob2b' ) . '</td></tr>';
		}
		foreach ( $entries as $entry ) {
			$cells = array(
				'time'    => esc_html( self::time( $entry ) ),
				'action'  => esc_html( $this->format->action( $entry->action ) ),
				'actor'   => self::user( $entry->actor_id ),
				'subject' => self::concerns( $entry ),
				'change'  => esc_html( $this->format->change( $entry ) ),
			);
			echo '<tr>';
			foreach ( $cells as $key => $html ) {
				echo '<td data-label="' . esc_attr( $headings[ $key ] ) . '">' . $html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every cell is escaped above.
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			$links = paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			);
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) $links ) . '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * Local time of an entry.
	 *
	 * @param AuditEntry $entry Entry.
	 */
	private static function time( AuditEntry $entry ): string {
		$timestamp = strtotime( $entry->created_at . ' UTC' );
		return false !== $timestamp ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : $entry->created_at;
	}

	/**
	 * A user as escaped HTML: name with a link to the profile, or a note when removed or deleted.
	 *
	 * @param int $user_id User.
	 */
	private static function user( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '<em>' . esc_html__( 'removed', 'eurob2b' ) . '</em>';
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			/* translators: %d: user id */
			return esc_html( sprintf( __( 'Deleted user #%d', 'eurob2b' ), $user_id ) );
		}
		$label = esc_html( $user->display_name . ' (#' . $user_id . ')' );
		return current_user_can( 'edit_user', $user_id ) ? '<a href="' . esc_url( get_edit_user_link( $user_id ) ) . '">' . $label . '</a>' : $label;
	}

	/**
	 * The concerned customer and, for orders and VAT ID checks, the object.
	 *
	 * @param AuditEntry $entry Entry.
	 */
	private static function concerns( AuditEntry $entry ): string {
		$html = self::user( $entry->subject_id );
		if ( AuditLog::OBJECT_ORDER === $entry->object_type && $entry->object_id > 0 ) {
			$order = wc_get_order( $entry->object_id );
			/* translators: %s: order number */
			$label = esc_html( sprintf( __( 'Order #%s', 'eurob2b' ), $order instanceof WC_Order ? $order->get_order_number() : (string) $entry->object_id ) );
			$html .= '<br>' . ( $order instanceof WC_Order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . $label . '</a>' : $label );
		} elseif ( AuditLog::OBJECT_VAT === $entry->object_type && $entry->object_id > 0 ) {
			/* translators: %d: VAT ID check id */
			$html .= '<br>' . esc_html( sprintf( __( 'VAT ID check #%d', 'eurob2b' ), $entry->object_id ) );
		}
		return $html;
	}
}

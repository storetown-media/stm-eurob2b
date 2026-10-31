<?php
/**
 * Approvals admin page.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Admin\PageHeader;
use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Privacy\PrivateFiles;
use EuroB2B\Modules\Registration\FieldStorage;
use EuroB2B\Modules\Registration\RegistrationService;
use WP_User;
use WP_User_Query;

/**
 * Lists pending registrations with their details; approve into a group or reject with a note.
 */
final class ApprovalsPage {

	public const SLUG           = 'approvals';
	public const ACTION_APPROVE = 'eurob2b_approve_registration';
	public const ACTION_REJECT  = 'eurob2b_reject_registration';
	public const NONCE          = 'eurob2b_approval';

	/**
	 * Service.
	 *
	 * @var RegistrationService
	 */
	private RegistrationService $service;

	/**
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Storage.
	 *
	 * @var FieldStorage
	 */
	private FieldStorage $storage;

	/**
	 * Audience resolver (stored customer type of a user).
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $audiences;

	/**
	 * Constructor.
	 *
	 * @param RegistrationService $service Service.
	 * @param GroupRepository     $groups  Groups.
	 * @param FieldStorage        $storage   Storage (labelled values incl. deleted fields).
	 * @param AudienceResolver    $audiences Audience resolver.
	 */
	public function __construct( RegistrationService $service, GroupRepository $groups, FieldStorage $storage, AudienceResolver $audiences ) {
		$this->service   = $service;
		$this->groups    = $groups;
		$this->storage   = $storage;
		$this->audiences = $audiences;
	}

	/**
	 * Line of the overview page (PLT-017).
	 *
	 * @param array<int, array<string, mixed>> $items Lines.
	 * @return array<int, array<string, mixed>>
	 */
	public function overview_items( array $items ): array {
		// Count only for users who may open the page; the overview hides the line for others anyway.
		if ( ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			return $items;
		}
		$items[] = array(
			'count'      => $this->pending_count(),
			'title'      => __( 'Registrations waiting for approval', 'eurob2b' ),
			'text'       => __( 'Check the new business customers and assign them to a customer group.', 'eurob2b' ),
			'url'        => Menu::url( self::SLUG ),
			'action'     => _x( 'Review', 'button on the overview page', 'eurob2b' ),
			'capability' => Capabilities::MANAGE_CUSTOMERS,
		);
		return $items;
	}

	/**
	 * Registers the page.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$count = $this->pending_count();
		$label = __( 'Approvals', 'eurob2b' );
		if ( $count > 0 ) {
			$label .= ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>';
		}
		$menu->add_page( self::SLUG, __( 'Registration approvals', 'eurob2b' ), $label, Capabilities::MANAGE_CUSTOMERS, array( $this, 'render' ), 10 );
	}

	/**
	 * Number of pending customers.
	 */
	public function pending_count(): int {
		$query = new WP_User_Query(
			array(
				'meta_key'    => CustomersModule::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => CustomerStatus::PENDING->value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'count_total' => true,
				'number'      => 1,
				'fields'      => 'ID',
			)
		);
		return (int) $query->get_total();
	}

	/**
	 * Pending users, newest application first (like WooCommerce's order list).
	 *
	 * @return WP_User[]
	 */
	public function pending_users(): array {
		$query = new WP_User_Query(
			array(
				'meta_key'   => CustomersModule::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => CustomerStatus::PENDING->value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => array(
					'registered' => 'DESC',
					'ID'         => 'DESC',
				),
				'number'     => 200,
			)
		);
		return array_values( array_filter( $query->get_results(), static fn( $u ) => $u instanceof WP_User ) );
	}

	/**
	 * Approve handler.
	 */
	public function handle_approve(): void {
		$user_id = $this->authorise();
		if ( null === $user_id ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
		try {
			$this->service->approve( $user_id, $group_id > 0 ? $group_id : null, get_current_user_id() );
			$this->redirect( array( 'approved' => $user_id ) );
		} catch ( \Throwable $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Reject handler.
	 */
	public function handle_reject(): void {
		$user_id = $this->authorise();
		if ( null === $user_id ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['note'] ) ) : '';
		try {
			$this->service->reject( $user_id, $note, get_current_user_id() );
			$this->redirect( array( 'rejected' => $user_id ) );
		} catch ( \Throwable $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Renders the list.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			wp_die( esc_html__( 'You are not allowed to approve customers.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notices.
		$approved = isset( $_GET['approved'] ) ? absint( $_GET['approved'] ) : 0;
		$rejected = isset( $_GET['rejected'] ) ? absint( $_GET['rejected'] ) : 0;
		$error    = isset( $_GET['error'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		PageHeader::open( 'eurob2b-approvals', __( 'Registration approvals', 'eurob2b' ) );
		if ( $approved > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Customer approved and notified.', 'eurob2b' ) . '</p></div>';
		}
		if ( $rejected > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Registration rejected.', 'eurob2b' ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		$users = $this->pending_users();
		if ( empty( $users ) ) {
			echo '<p>' . esc_html__( 'No registrations are waiting for approval.', 'eurob2b' ) . '</p></div>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Approve into a group, or reject with an optional note for your records.', 'eurob2b' ) . '</p>';
		foreach ( $users as $user ) {
			$this->render_card( $user );
		}
		echo '</div>';
	}

	/**
	 * Renders one pending user with details and actions.
	 *
	 * @param WP_User $user User.
	 */
	private function render_card( WP_User $user ): void {
		$type = $this->service->registration_type_of( $user->ID );
		// The registration type may have been deleted meanwhile: fall back to what is stored on the user.
		$ctype  = null !== $type ? $type->customer_type : $this->audiences->stored_type( $user->ID );
		$ctype  = CustomerType::GUEST === $ctype ? CustomerType::B2C : $ctype;
		$groups = $this->groups->of_type( $ctype );
		$target = null !== $type ? $this->service->target_group_id( $type ) : null;
		$target = $target ?? ( $this->groups->default_for( $ctype )->id ?? 0 );

		echo '<div class="card" style="max-width:none;margin-top:16px;">';
		echo '<h2 style="margin-top:0;">' . esc_html( trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name ) . ' <small>&lt;' . esc_html( $user->user_email ) . '&gt;</small></h2>';
		echo '<table class="widefat striped" style="margin-bottom:12px;"><tbody>';
		echo '<tr><th scope="row" style="width:220px;">' . esc_html__( 'Registered', 'eurob2b' ) . '</th><td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $user->user_registered ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Registration type', 'eurob2b' ) . '</th><td>' . esc_html( null !== $type ? $type->label : '—' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Username', 'eurob2b' ) . '</th><td>' . esc_html( $user->user_login ) . ' · <a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html__( 'Open profile', 'eurob2b' ) . '</a></td></tr>';
		foreach ( $this->storage->labelled_entries( $user->ID ) as $entry ) {
			$link = '';
			if ( $entry['file_id'] > 0 && current_user_can( PrivateFiles::capability( PrivateFiles::CONTEXT_REGISTRATION ) ) ) {
				$link = ' · <a href="' . esc_url( PrivateFiles::download_link( $entry['file_id'] ) ) . '">' . esc_html__( 'Download', 'eurob2b' ) . '</a>';
			}
			echo '<tr><th scope="row">' . esc_html( $entry['label'] ) . '</th><td>' . nl2br( esc_html( $entry['value'] ) ) . $link . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from escaped parts.
		}
		echo '</tbody></table>';

		echo '<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-end;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_APPROVE ) . '" /><input type="hidden" name="user_id" value="' . esc_attr( (string) $user->ID ) . '" />';
		echo '<label for="eurob2b-approve-group-' . (int) $user->ID . '">' . esc_html__( 'Approve into group', 'eurob2b' ) . '</label> ';
		echo '<select id="eurob2b-approve-group-' . (int) $user->ID . '" name="group_id">';
		foreach ( $groups as $group ) {
			echo '<option value="' . esc_attr( (string) $group->id ) . '" ' . selected( $target, $group->id, false ) . '>' . esc_html( $group->name ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Approve', 'eurob2b' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REJECT ) . '" /><input type="hidden" name="user_id" value="' . esc_attr( (string) $user->ID ) . '" />';
		echo '<label for="eurob2b-reject-note-' . (int) $user->ID . '">' . esc_html__( 'Note (internal, optionally e-mailed)', 'eurob2b' ) . '</label> ';
		echo '<input type="text" class="regular-text" id="eurob2b-reject-note-' . (int) $user->ID . '" name="note" /> ';
		submit_button( __( 'Reject', 'eurob2b' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div></div>';
	}

	/**
	 * Capability + nonce + user id for POST handlers. The actor must be allowed to edit the target
	 * user (a shop manager cannot decide on an administrator's account), and the application must
	 * still be pending: a form left open while a colleague decided redirects with an error instead
	 * of reversing that decision.
	 *
	 * @return int|null User id, or null when the request was answered with a redirect.
	 */
	private function authorise(): ?int {
		if ( ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			wp_die( esc_html__( 'You are not allowed to approve customers.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'Unknown user.', 'eurob2b' ), '', array( 'response' => 400 ) );
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You are not allowed to decide on this user.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		if ( CustomerStatus::PENDING !== $this->audiences->stored_status( $user_id ) ) {
			$this->redirect( array( 'error' => __( 'This application was already decided. Please reload the list.', 'eurob2b' ) ) );
			return null;
		}
		return $user_id;
	}

	/**
	 * Redirects back to the page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function redirect( array $args ): void {
		if ( wp_safe_redirect( Menu::url( self::SLUG, $args ) ) ) {
			exit;
		}
	}
}

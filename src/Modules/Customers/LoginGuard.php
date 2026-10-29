<?php
/**
 * Login guard.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

use WP_Error;
use WP_User;

/**
 * Blocks pending and rejected customers from logging in, with a clear message.
 *
 * Hooked to the "authenticate" filter at priority 30, i.e. after WordPress'
 * username/password and e-mail/password handlers (priority 20) verified the
 * password. A wrong password therefore still yields the normal error and the
 * message never reveals whether a password was right for a blocked account.
 * Staff accounts (edit_posts or manage_woocommerce) are never locked out.
 */
final class LoginGuard {

	/**
	 * Resolver.
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param AudienceResolver $resolver Resolver.
	 */
	public function __construct( AudienceResolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Filter callback for "authenticate" (runs after the password was verified).
	 *
	 * @param WP_User|WP_Error|null $user User or error from earlier handlers.
	 * @return WP_User|WP_Error|null
	 */
	public function check( $user ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}
		if ( self::is_staff( $user ) ) {
			return $user;
		}
		$status = $this->resolver->stored_status( $user->ID );
		if ( CustomerStatus::PENDING === $status ) {
			return new WP_Error( 'eurob2b_pending', self::pending_message() );
		}
		if ( CustomerStatus::REJECTED === $status ) {
			return new WP_Error( 'eurob2b_rejected', self::rejected_message() );
		}
		return $user;
	}

	/**
	 * Filter callback for "determine_current_user" (priority 30, after WordPress validated an
	 * application password at 20). Application-password authentication bypasses the "authenticate"
	 * filter, so blocked customers are refused here; the error reaches the REST response through
	 * WordPress' own rest_authentication_errors handling of the application password status.
	 *
	 * @param mixed $user_id User id determined so far (int|false).
	 * @return mixed
	 */
	public function check_api_user( $user_id ) {
		if ( empty( $user_id ) || ! function_exists( 'rest_get_authenticated_app_password' ) || null === rest_get_authenticated_app_password() ) {
			return $user_id;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof WP_User ) {
			return $user_id;
		}
		$result = $this->check( $user );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 401 ) );
			$GLOBALS['wp_rest_application_password_status'] = $result; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core global, read by rest_application_password_check_errors().
			return 0;
		}
		return $user_id;
	}

	/**
	 * Message for pending accounts.
	 */
	public static function pending_message(): string {
		/**
		 * Filters the message shown to pending customers.
		 *
		 * @param string $message Message.
		 */
		return (string) apply_filters( 'eurob2b_pending_login_message', __( 'Your account is waiting for approval. We will e-mail you as soon as it is activated.', 'eurob2b' ) );
	}

	/**
	 * Message for rejected accounts.
	 */
	public static function rejected_message(): string {
		/**
		 * Filters the message shown to rejected customers.
		 *
		 * @param string $message Message.
		 */
		return (string) apply_filters( 'eurob2b_rejected_login_message', __( 'This account has not been activated. Please contact us if you think this is a mistake.', 'eurob2b' ) );
	}

	/**
	 * Staff (edit_posts or manage_woocommerce) is never locked out and keeps its sessions; the
	 * approval status only governs customers.
	 *
	 * @param WP_User|int $user User or user id.
	 */
	public static function is_staff( $user ): bool {
		return user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' );
	}
}

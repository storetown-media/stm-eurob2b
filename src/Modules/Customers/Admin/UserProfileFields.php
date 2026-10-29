<?php
/**
 * Customer fields on the user profile.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerService;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\GroupRepository;

/**
 * Shows and saves customer type, status and group on the WordPress user profile.
 *
 * Only users with eurob2b_manage_customers see the fields; everyone else
 * (including the customer editing their own profile) gets nothing, and the
 * save handler ignores the request.
 */
final class UserProfileFields {

	/**
	 * Transient prefix for a failure notice shown to the acting admin on the next screen.
	 */
	public const NOTICE_TRANSIENT = 'eurob2b_profile_notice_';

	public const NONCE = 'eurob2b_customer_profile';

	/**
	 * Repository.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Resolver.
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $resolver;

	/**
	 * Service.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * Constructor.
	 *
	 * @param GroupRepository  $groups    Repository.
	 * @param AudienceResolver $resolver  Resolver.
	 * @param CustomerService  $customers Service.
	 */
	public function __construct( GroupRepository $groups, AudienceResolver $resolver, CustomerService $customers ) {
		$this->groups    = $groups;
		$this->resolver  = $resolver;
		$this->customers = $customers;
	}

	/**
	 * Renders the section.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public function render( \WP_User $user ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			return;
		}

		$type   = $this->resolver->stored_type( $user->ID );
		$status = $this->resolver->stored_status( $user->ID );
		$group  = $this->resolver->stored_group( $user->ID );

		echo '<h2>' . esc_html__( 'EuroB2B customer', 'eurob2b' ) . '</h2>';
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th><label for="eurob2b-customer-type">' . esc_html__( 'Customer type', 'eurob2b' ) . '</label></th><td>';
		echo '<select id="eurob2b-customer-type" name="eurob2b_customer_type">';
		foreach ( array( CustomerType::B2C, CustomerType::B2B ) as $option ) {
			echo '<option value="' . esc_attr( $option->value ) . '" ' . selected( $type->value, $option->value, false ) . '>' . esc_html( GroupsPage::type_label( $option ) ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="eurob2b-customer-status">' . esc_html__( 'Status', 'eurob2b' ) . '</label></th><td>';
		echo '<select id="eurob2b-customer-status" name="eurob2b_status">';
		foreach ( CustomerStatus::cases() as $option ) {
			echo '<option value="' . esc_attr( $option->value ) . '" ' . selected( $status->value, $option->value, false ) . '>' . esc_html( $option->label() ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Pending and rejected customers cannot log in and are treated like guests.', 'eurob2b' ) . '</p></td></tr>';

		echo '<tr><th><label for="eurob2b-customer-group">' . esc_html__( 'Group', 'eurob2b' ) . '</label></th><td>';
		echo '<select id="eurob2b-customer-group" name="eurob2b_group_id">';
		echo '<option value="0">' . esc_html__( 'Default group of the customer type', 'eurob2b' ) . '</option>';
		foreach ( $this->groups->all() as $option ) {
			$label = sprintf( '%s (%s)', $option->name, GroupsPage::type_label( $option->customer_type ) );
			echo '<option value="' . esc_attr( (string) $option->id ) . '" ' . selected( $group ? $group->id : 0, $option->id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The group must match the customer type.', 'eurob2b' ) . '</p></td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Hooked to user_profile_update_errors: validates the section against the shared collector so an
	 * invalid group or type stops the profile update like a core error. Nothing is written here.
	 *
	 * @param mixed $errors WP_Error collector shared with core.
	 * @param mixed $update True for an update (false for a new user).
	 * @param mixed $user   stdClass with the posted user data (ID set on updates).
	 */
	public function validate_before_update( $errors, $update, $user ): void {
		if ( ! $errors instanceof \WP_Error || ! $update || ! is_object( $user ) || empty( $user->ID ) ) {
			return;
		}
		$user_id = (int) $user->ID;
		$posted  = $this->posted( $user_id );
		if ( null === $posted ) {
			return;
		}
		try {
			$this->customers->validate_assignment( $user_id, $posted['type'], $posted['status'], $posted['group_id'] );
		} catch ( \InvalidArgumentException $e ) {
			$errors->add( 'eurob2b_customer', $e->getMessage() );
		}
	}

	/**
	 * Hooked to profile_update, which WordPress fires only after wp_update_user() succeeded, i.e.
	 * after every validator (ours and other plugins') accepted the profile. Only then the customer
	 * fields are written.
	 *
	 * @param mixed $user_id Updated user id.
	 */
	public function save_after_update( $user_id ): void {
		if ( ! is_numeric( $user_id ) ) {
			return;
		}
		$this->save( (int) $user_id );
	}

	/**
	 * Shows a stored failure notice of a previous save (database errors surface here instead of a fatal).
	 */
	public function render_notice(): void {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_string( $notice ) || '' === $notice ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-error"><p>' . esc_html( $notice ) . '</p></div>';
	}

	/**
	 * Posted section values when the request is authorised (capability, edit_user, nonce), else null.
	 *
	 * @param int $user_id User being edited.
	 * @return array{type: CustomerType, status: CustomerStatus, group_id: int|null}|null
	 */
	private function posted( int $user_id ): ?array {
		if ( ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return null;
		}
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_key( (string) wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return null;
		}
		$type     = isset( $_POST['eurob2b_customer_type'] ) ? CustomerType::tryFrom( sanitize_key( (string) wp_unslash( $_POST['eurob2b_customer_type'] ) ) ) : null;
		$status   = isset( $_POST['eurob2b_status'] ) ? CustomerStatus::tryFrom( sanitize_key( (string) wp_unslash( $_POST['eurob2b_status'] ) ) ) : null;
		$group_id = isset( $_POST['eurob2b_group_id'] ) ? absint( $_POST['eurob2b_group_id'] ) : 0;
		if ( null === $type || null === $status || CustomerType::GUEST === $type ) {
			return null;
		}
		return array(
			'type'     => $type,
			'status'   => $status,
			'group_id' => $group_id > 0 ? $group_id : null,
		);
	}

	/**
	 * Saves the section (called after a successful core update, or directly by tests).
	 *
	 * @param int            $user_id User being edited.
	 * @param \WP_Error|null $errors  Error collector of the profile form, when called from it.
	 */
	public function save( int $user_id, ?\WP_Error $errors = null ): void {
		$posted = $this->posted( $user_id );
		if ( null === $posted ) {
			return;
		}
		try {
			$this->customers->assign( $user_id, $posted['type'], $posted['status'], $posted['group_id'], get_current_user_id() );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			if ( null !== $errors ) {
				$errors->add( 'eurob2b_customer', $e->getMessage() );
				return;
			}
			set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $e->getMessage(), MINUTE_IN_SECONDS );
		}
	}
}

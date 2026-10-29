<?php
/**
 * Customer service.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

use InvalidArgumentException;
use RuntimeException;
use WP_Session_Tokens;

/**
 * The only place that writes customer type, status and group to user meta.
 *
 * Writes always go together so the three values stay consistent, and every
 * change fires an action with the actor, which the approval e-mails and the
 * audit log hook into.
 */
final class CustomerService {

	/**
	 * Group repository.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Audience resolver (for cache invalidation).
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param GroupRepository  $groups   Group repository.
	 * @param AudienceResolver $resolver Audience resolver.
	 */
	public function __construct( GroupRepository $groups, AudienceResolver $resolver ) {
		$this->groups   = $groups;
		$this->resolver = $resolver;
	}

	/**
	 * Assigns customer type, status and group.
	 *
	 * @param int            $user_id  User id.
	 * @param CustomerType   $type     B2B or B2C.
	 * @param CustomerStatus $status   Status.
	 * @param int|null       $group_id Group id; null = default group of the type.
	 * @param int            $actor_id Who made the change (0 = system).
	 * @throws InvalidArgumentException|RuntimeException Invalid input (see validate_assignment()) or a failed write (the previous assignment is restored).
	 */
	public function assign( int $user_id, CustomerType $type, CustomerStatus $status, ?int $group_id, int $actor_id = 0 ): void {
		$group = $this->validate_assignment( $user_id, $type, $status, $group_id );

		$previous_status = $this->resolver->stored_status( $user_id );
		$previous_type   = $this->resolver->stored_type( $user_id );
		$previous_group  = $this->resolver->stored_group( $user_id );

		// A plain customer without status meta counts as approved for the audience, but an
		// explicit approval still needs its audit trail; so look at the raw meta here.
		$was_explicitly_approved = CustomerStatus::APPROVED->value === get_user_meta( $user_id, CustomersModule::META_STATUS, true );

		// WordPress has no transactions for user meta: every write is verified by reading it back and
		// an incomplete transition is rolled back before anything observes it (no flush, no event).
		$snapshot = array();
		foreach ( array( CustomersModule::META_CUSTOMER_TYPE, CustomersModule::META_STATUS, CustomersModule::META_GROUP_ID ) as $key ) {
			$raw              = get_user_meta( $user_id, $key, true );
			$snapshot[ $key ] = is_string( $raw ) && '' !== $raw ? $raw : null;
		}
		$writes = array(
			CustomersModule::META_CUSTOMER_TYPE => $type->value,
			CustomersModule::META_STATUS        => $status->value,
			CustomersModule::META_GROUP_ID      => null !== $group ? (string) $group->id : null,
		);
		foreach ( $writes as $key => $value ) {
			if ( ! self::write_meta( $user_id, $key, $value ) ) {
				foreach ( $snapshot as $restore_key => $restore_value ) {
					self::write_meta( $user_id, $restore_key, $restore_value );
				}
				$this->resolver->flush( $user_id );
				throw new RuntimeException( sprintf( 'Could not store %s for user %d; the previous assignment was restored.', $key, $user_id ) );
			}
		}
		if ( CustomerStatus::APPROVED === $status && ! $was_explicitly_approved ) {
			update_user_meta( $user_id, CustomersModule::META_APPROVED_AT, current_time( 'mysql', true ) );
			update_user_meta( $user_id, CustomersModule::META_APPROVED_BY, (string) $actor_id );
		}

		// Blocking an approved customer ends their sessions: LoginGuard only acts on the next login,
		// while existing cookies would keep My Account open (ACC-008). Staff keeps its sessions.
		if ( CustomerStatus::APPROVED === $previous_status && CustomerStatus::APPROVED !== $status && ! LoginGuard::is_staff( $user_id ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			if ( class_exists( 'WP_Application_Passwords' ) ) {
				\WP_Application_Passwords::delete_all_application_passwords( $user_id );
			}
		}

		$this->resolver->flush( $user_id );

		/**
		 * Fires after a customer's type, status or group changed.
		 *
		 * @param int            $user_id         User id.
		 * @param CustomerType   $type            New type.
		 * @param CustomerStatus $status          New status.
		 * @param Group|null     $group           New group.
		 * @param int            $actor_id        Actor (0 = system).
		 * @param CustomerStatus $previous_status Previous status.
		 * @param CustomerType   $previous_type   Previous type.
		 * @param Group|null     $previous_group  Previous group.
		 */
		do_action( 'eurob2b_customer_assigned', $user_id, $type, $status, $group, $actor_id, $previous_status, $previous_type, $previous_group );
	}

	/**
	 * Checks an assignment without writing anything (used by forms to validate before core saves).
	 *
	 * @param int            $user_id  User id.
	 * @param CustomerType   $type     Customer type (never guest).
	 * @param CustomerStatus $status   Status.
	 * @param int|null       $group_id Group id, null for the type's default group.
	 * @return Group|null The group that would be assigned.
	 * @throws InvalidArgumentException On guest type, unknown user or group/type mismatch.
	 */
	public function validate_assignment( int $user_id, CustomerType $type, CustomerStatus $status, ?int $group_id ): ?Group {
		unset( $status );
		// Every status is assignable; kept in the signature for symmetry with assign().
		if ( CustomerType::GUEST === $type ) {
			throw new InvalidArgumentException( esc_html__( 'Customers cannot be assigned the guest type.', 'eurob2b' ) );
		}
		if ( ! get_userdata( $user_id ) ) {
			throw new InvalidArgumentException( esc_html__( 'Unknown user.', 'eurob2b' ) );
		}
		$group = null !== $group_id && $group_id > 0 ? $this->groups->find( $group_id ) : $this->groups->default_for( $type );
		if ( null !== $group_id && $group_id > 0 && null === $group ) {
			throw new InvalidArgumentException( esc_html__( 'Unknown group.', 'eurob2b' ) );
		}
		if ( null !== $group && $group->customer_type !== $type ) {
			throw new InvalidArgumentException( esc_html__( 'The group does not belong to the chosen customer type.', 'eurob2b' ) );
		}
		return $group;
	}

	/**
	 * Approves a user into a group (B2B unless the group is B2C).
	 *
	 * @param int      $user_id  User id.
	 * @param int|null $group_id Group id or null for the default B2B group.
	 * @param int      $actor_id Actor.
	 */
	public function approve( int $user_id, ?int $group_id, int $actor_id ): void {
		$group = null !== $group_id ? $this->groups->find( $group_id ) : null;
		$type  = null !== $group ? $group->customer_type : CustomerType::B2B;
		$this->assign( $user_id, $type, CustomerStatus::APPROVED, $group_id, $actor_id );
	}

	/**
	 * Stored approval status of a user (missing meta counts as approved, like the resolver).
	 *
	 * @param int $user_id User id.
	 */
	public function status_of( int $user_id ): CustomerStatus {
		return $this->resolver->stored_status( $user_id );
	}

	/**
	 * Stored customer type of a user (missing meta counts as B2C, guests never occur here).
	 *
	 * @param int $user_id User id.
	 */
	public function type_of( int $user_id ): CustomerType {
		$type = $this->resolver->stored_type( $user_id );
		return CustomerType::GUEST === $type ? CustomerType::B2C : $type;
	}

	/**
	 * Rejects (or deactivates) a user.
	 *
	 * @param int $user_id  User id.
	 * @param int $actor_id Actor.
	 */
	public function reject( int $user_id, int $actor_id ): void {
		$type = $this->resolver->stored_type( $user_id );
		$this->assign( $user_id, CustomerType::GUEST === $type ? CustomerType::B2B : $type, CustomerStatus::REJECTED, null, $actor_id );
		// Audit trail (REG-005): who rejected and when; exported and erased with the other keys.
		update_user_meta( $user_id, CustomersModule::META_REJECTED_AT, current_time( 'mysql', true ) );
		update_user_meta( $user_id, CustomersModule::META_REJECTED_BY, (string) $actor_id );
	}

	/**
	 * Writes (or removes, for null) one meta value and verifies it by reading it back.
	 *
	 * @param int         $user_id User id.
	 * @param string      $key     Meta key.
	 * @param string|null $value   Value; null removes the key.
	 * @return bool Whether the stored value now matches.
	 */
	private static function write_meta( int $user_id, string $key, ?string $value ): bool {
		if ( null === $value ) {
			delete_user_meta( $user_id, $key );
		} else {
			update_user_meta( $user_id, $key, $value );
		}
		wp_cache_delete( $user_id, 'user_meta' );
		$stored = get_user_meta( $user_id, $key, true );
		return null === $value ? '' === (string) $stored : (string) $stored === $value;
	}
}

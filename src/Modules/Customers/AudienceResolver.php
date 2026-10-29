<?php
/**
 * Audience resolver.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

/**
 * Derives the AudienceContext for a user from user meta and the group table.
 *
 * Rules:
 *  - No user: guest.
 *  - Status pending or rejected: guest (the account is not entitled to customer treatment).
 *  - Customer type B2B (approved): B2B with the assigned group, or the default B2B group.
 *  - Everything else (plain WooCommerce customers, type B2C): B2C with the assigned
 *    group, or the default B2C group.
 * The result is cached per user id for the request and can be changed through
 * the "eurob2b_audience_context" filter (used later by the company module).
 */
final class AudienceResolver {

	/**
	 * Group repository.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Per-request cache keyed by user id (0 = guest).
	 *
	 * @var array<int, AudienceContext>
	 */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param GroupRepository $groups Group repository.
	 */
	public function __construct( GroupRepository $groups ) {
		$this->groups = $groups;
	}

	/**
	 * Context of the current user.
	 */
	public function current(): AudienceContext {
		return $this->for_user( get_current_user_id() );
	}

	/**
	 * Context of a user id (0 = guest).
	 *
	 * @param int $user_id User id.
	 */
	public function for_user( int $user_id ): AudienceContext {
		if ( isset( $this->cache[ $user_id ] ) ) {
			return $this->cache[ $user_id ];
		}

		$context = $this->resolve( $user_id );

		/**
		 * Filters the resolved audience context (e.g. company membership).
		 *
		 * @param AudienceContext $context Context.
		 * @param int             $user_id User id (0 for guests).
		 */
		$filtered = apply_filters( 'eurob2b_audience_context', $context, $user_id );
		if ( $filtered instanceof AudienceContext ) {
			$context = $filtered;
		}

		$this->cache[ $user_id ] = $context;
		return $context;
	}

	/**
	 * Drops the cache for one user or for everyone.
	 *
	 * @param int|null $user_id User id, null for all.
	 */
	public function flush( ?int $user_id = null ): void {
		if ( null === $user_id ) {
			$this->cache = array();
			return;
		}
		unset( $this->cache[ $user_id ] );
	}

	/**
	 * Stored customer type of a user (approved or not).
	 *
	 * @param int $user_id User id.
	 */
	public function stored_type( int $user_id ): CustomerType {
		$type = get_user_meta( $user_id, CustomersModule::META_CUSTOMER_TYPE, true );
		return is_string( $type ) ? ( CustomerType::tryFrom( $type ) ?? CustomerType::B2C ) : CustomerType::B2C;
	}

	/**
	 * Stored status of a user.
	 *
	 * @param int $user_id User id.
	 */
	public function stored_status( int $user_id ): CustomerStatus {
		return CustomerStatus::from_meta( get_user_meta( $user_id, CustomersModule::META_STATUS, true ) );
	}

	/**
	 * Stored group of a user, or null when none/invalid.
	 *
	 * @param int $user_id User id.
	 */
	public function stored_group( int $user_id ): ?Group {
		$group_id = (int) get_user_meta( $user_id, CustomersModule::META_GROUP_ID, true );
		return $group_id > 0 ? $this->groups->find( $group_id ) : null;
	}

	/**
	 * Uncached resolution.
	 *
	 * @param int $user_id User id.
	 */
	private function resolve( int $user_id ): AudienceContext {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return AudienceContext::guest();
		}

		$status = $this->stored_status( $user_id );
		if ( CustomerStatus::APPROVED !== $status ) {
			return new AudienceContext( CustomerType::GUEST, $user_id );
		}

		$type  = $this->stored_type( $user_id );
		$type  = CustomerType::GUEST === $type ? CustomerType::B2C : $type;
		$group = $this->stored_group( $user_id );

		if ( null !== $group && $group->customer_type !== $type ) {
			// The stored group contradicts the stored type; the group wins because prices hang on it.
			$type = $group->customer_type;
		}
		if ( null === $group ) {
			$group = $this->groups->default_for( $type );
		}

		return new AudienceContext( $type, $user_id, $group );
	}
}

<?php
/**
 * Group repository.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

use EuroB2B\Infrastructure\Schema;
use RuntimeException;

/**
 * Database access for groups (table eurob2b_groups) and group membership (user meta).
 *
 * Results are cached per request; every write invalidates the cache.
 */
final class GroupRepository {

	/**
	 * Request cache of all groups keyed by id, null until loaded.
	 *
	 * @var array<int, Group>|null
	 */
	private ?array $cache = null;

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'eurob2b_groups' );
	}

	/**
	 * All groups ordered by customer type, priority (desc) and name.
	 *
	 * @return array<int, Group>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows        = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY customer_type ASC, priority DESC, name ASC", ARRAY_A );
		$this->cache = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$group                     = $this->hydrate( $row );
			$this->cache[ $group->id ] = $group;
		}
		return $this->cache;
	}

	/**
	 * Groups of one customer type.
	 *
	 * @param CustomerType $type Customer type.
	 * @return array<int, Group>
	 */
	public function of_type( CustomerType $type ): array {
		return array_filter( $this->all(), static fn( Group $g ): bool => $g->customer_type === $type );
	}

	/**
	 * Finds a group by id.
	 *
	 * @param int $id Group id.
	 */
	public function find( int $id ): ?Group {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Finds a group by slug.
	 *
	 * @param string $slug Slug.
	 */
	public function find_by_slug( string $slug ): ?Group {
		foreach ( $this->all() as $group ) {
			if ( $group->slug === $slug ) {
				return $group;
			}
		}
		return null;
	}

	/**
	 * Default group of a customer type, or null when none is flagged.
	 *
	 * @param CustomerType $type Customer type.
	 */
	public function default_for( CustomerType $type ): ?Group {
		foreach ( $this->of_type( $type ) as $group ) {
			if ( $group->is_default ) {
				return $group;
			}
		}
		return null;
	}

	/**
	 * Inserts or updates a group. Enforces slug uniqueness, exactly one default per type
	 * (a default can only be replaced, never unset) and an immutable customer type.
	 *
	 * @param Group $group Group.
	 * @return Group The persisted group (with id).
	 * The first group of a customer type always becomes its default, so a type never ends up without one.
	 *
	 * @throws RuntimeException On duplicate slug, unknown id, unsetting a default, type change or database error.
	 */
	public function save( Group $group ): Group {
		global $wpdb;

		$existing = $this->find_by_slug( $group->slug );
		if ( null !== $existing && $existing->id !== $group->id ) {
			throw new RuntimeException( sprintf( 'A group with the slug "%s" already exists.', $group->slug ) );
		}

		$current = $group->exists() ? $this->find( $group->id ) : null;
		if ( $group->exists() && null === $current ) {
			/* translators: %d: group id */
			throw new RuntimeException( esc_html( sprintf( __( 'Group %d does not exist.', 'eurob2b' ), $group->id ) ) );
		}
		if ( ! $group->exists() && ! $group->is_default && empty( $this->of_type( $group->customer_type ) ) ) {
			$group = $group->with( array( 'is_default' => true ) );
		}
		if ( null !== $current && $current->is_default && ! $group->is_default ) {
			throw new RuntimeException( esc_html__( 'The default group of a customer type cannot be unset; make another group the default instead.', 'eurob2b' ) );
		}
		if ( null !== $current && $current->customer_type !== $group->customer_type ) {
			throw new RuntimeException( esc_html__( 'The customer type of an existing group cannot be changed.', 'eurob2b' ) );
		}

		$now    = current_time( 'mysql', true );
		$data   = array(
			'slug'          => $group->slug,
			'name'          => $group->name,
			'customer_type' => $group->customer_type->value,
			'is_default'    => $group->is_default ? 1 : 0,
			'priority'      => $group->priority,
			'settings'      => wp_json_encode( $group->settings ),
			'updated_at'    => $now,
		);
		$format = array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' );

		if ( $group->exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $this->table(), $data, array( 'id' => $group->id ), $format, array( '%d' ) );
			if ( false === $result ) {
				throw new RuntimeException( esc_html__( 'Could not update the group:', 'eurob2b' ) . ' ' . esc_html( $wpdb->last_error ) );
			}
			$saved = $group;
		} else {
			$data['created_at'] = $now;
			$format[]           = '%s';
			// Inserted as non-default; the single UPDATE below makes it the default, so two defaults never coexist.
			$data['is_default'] = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert( $this->table(), $data, $format );
			if ( false === $result ) {
				throw new RuntimeException( esc_html__( 'Could not create the group:', 'eurob2b' ) . ' ' . esc_html( $wpdb->last_error ) );
			}
			$saved = $group->with_id( (int) $wpdb->insert_id );
		}

		if ( $saved->is_default ) {
			// One statement sets the new default and clears every other row of the type: concurrent
			// requests end with exactly one default (last writer wins), readers never see two or none.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table()} SET is_default = CASE WHEN id = %d THEN 1 ELSE 0 END WHERE customer_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$saved->id,
					$saved->customer_type->value
				)
			);
			if ( false === $result ) {
				throw new RuntimeException( esc_html__( 'Could not set the default group:', 'eurob2b' ) . ' ' . esc_html( $wpdb->last_error ) );
			}
		}

		$this->cache = null;

		/**
		 * Fires after a group was created or updated.
		 *
		 * @param Group $saved Group.
		 */
		do_action( 'eurob2b_group_saved', $saved );

		return $saved;
	}

	/**
	 * Deletes a group and moves its members to another group.
	 *
	 * @param int $id        Group to delete.
	 * @param int $target_id Group that receives the members (same customer type).
	 * @throws RuntimeException When the group is a default, the target is invalid or the delete fails.
	 */
	public function delete( int $id, int $target_id ): void {
		global $wpdb;

		$group  = $this->find( $id );
		$target = $this->find( $target_id );
		if ( null === $group ) {
			throw new RuntimeException( esc_html__( 'The group does not exist.', 'eurob2b' ) );
		}
		if ( $group->is_default ) {
			throw new RuntimeException( esc_html__( 'The default group of a customer type cannot be deleted. Make another group the default first.', 'eurob2b' ) );
		}
		if ( null === $target || $target->id === $group->id || $target->customer_type !== $group->customer_type ) {
			throw new RuntimeException( esc_html__( 'Members must be moved to another group of the same customer type.', 'eurob2b' ) );
		}

		// Members first, then the group: whichever statement fails, no user ends up pointing at a
		// deleted group (a failed move aborts; a failed delete leaves the moved members in the target).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE meta_key = %s AND meta_value = %s",
				(string) $target->id,
				CustomersModule::META_GROUP_ID,
				(string) $group->id
			)
		);
		wp_cache_flush_group( 'user_meta' );
		if ( false === $moved ) {
			throw new RuntimeException( esc_html__( 'Could not move the members, the group was not deleted:', 'eurob2b' ) . ' ' . esc_html( $wpdb->last_error ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $this->table(), array( 'id' => $group->id ), array( '%d' ) );
		if ( false === $result ) {
			throw new RuntimeException( esc_html__( 'Could not delete the group:', 'eurob2b' ) . ' ' . esc_html( $wpdb->last_error ) );
		}

		$this->cache = null;

		/**
		 * Fires after a group was deleted and its members moved.
		 *
		 * @param Group $group  Deleted group.
		 * @param Group $target Group that received the members.
		 */
		do_action( 'eurob2b_group_deleted', $group, $target );
	}

	/**
	 * Number of users in each group. Default groups also count their implicit members: users with
	 * the customer role or a stored customer type but no group meta (AudienceResolver and the users
	 * list filter place them in the default group of their type; missing type = B2C).
	 *
	 * @return array<int, int> group id => count
	 */
	public function member_counts(): array {
		global $wpdb;
		$counts = $this->explicit_member_counts();

		$capabilities = $wpdb->prefix . 'capabilities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE( t.meta_value, '' ) AS customer_type, COUNT(*) AS members
				FROM {$wpdb->users} u
				LEFT JOIN {$wpdb->usermeta} g ON g.user_id = u.ID AND g.meta_key = %s
				LEFT JOIN {$wpdb->usermeta} t ON t.user_id = u.ID AND t.meta_key = %s
				LEFT JOIN {$wpdb->usermeta} c ON c.user_id = u.ID AND c.meta_key = %s AND c.meta_value LIKE %s
				WHERE g.umeta_id IS NULL AND ( t.umeta_id IS NOT NULL OR c.umeta_id IS NOT NULL )
				GROUP BY customer_type",
				CustomersModule::META_GROUP_ID,
				CustomersModule::META_CUSTOMER_TYPE,
				$capabilities,
				'%' . $wpdb->esc_like( '"customer"' ) . '%'
			),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$type    = CustomerType::tryFrom( (string) $row['customer_type'] ) ?? CustomerType::B2C;
			$default = $this->default_for( CustomerType::GUEST === $type ? CustomerType::B2C : $type );
			if ( null !== $default && (int) $row['members'] > 0 ) {
				$counts[ $default->id ] = ( $counts[ $default->id ] ?? 0 ) + (int) $row['members'];
			}
		}
		return $counts;
	}

	/**
	 * Users with an explicit group meta, per group.
	 *
	 * @return array<int, int> group id => count
	 */
	private function explicit_member_counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS group_id, COUNT(*) AS members FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value",
				CustomersModule::META_GROUP_ID
			),
			ARRAY_A
		);
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (int) $row['group_id'] ] = (int) $row['members'];
		}
		return $counts;
	}

	/**
	 * Makes sure every customer type has a default group. Each type is checked on its own, so a
	 * half-finished earlier run (one default created, the other missing) is repaired on retry.
	 *
	 * @return bool True when a group was created or promoted.
	 */
	public function ensure_defaults(): bool {
		$changed = false;
		$seed    = array(
			CustomerType::B2B->value => array( 'business', __( 'Business customers', 'eurob2b' ) ),
			CustomerType::B2C->value => array( 'retail', __( 'Private customers', 'eurob2b' ) ),
		);
		foreach ( $seed as $type_value => [ $slug, $name ] ) {
			$type = CustomerType::from( $type_value );
			if ( null !== $this->default_for( $type ) ) {
				continue;
			}
			$existing = $this->find_by_slug( $slug );
			if ( null !== $existing && $existing->customer_type === $type ) {
				$this->save( $existing->with( array( 'is_default' => true ) ) );
			} else {
				$groups = $this->of_type( $type );
				if ( ! empty( $groups ) ) {
					// Groups exist but none is the default: promote the first one instead of adding another.
					$this->save( reset( $groups )->with( array( 'is_default' => true ) ) );
				} else {
					$this->save( new Group( 0, null === $existing ? $slug : $slug . '-' . $type_value, $name, $type, true, 10 ) );
				}
			}
			$changed = true;
		}
		return $changed;
	}

	/**
	 * Drops the request cache.
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Builds an entity from a row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): Group {
		$settings = json_decode( (string) ( $row['settings'] ?? '' ), true );
		return new Group(
			(int) $row['id'],
			(string) $row['slug'],
			(string) $row['name'],
			CustomerType::tryFrom( (string) $row['customer_type'] ) ?? CustomerType::B2B,
			(bool) $row['is_default'],
			(int) $row['priority'],
			is_array( $settings ) ? $settings : array()
		);
	}
}

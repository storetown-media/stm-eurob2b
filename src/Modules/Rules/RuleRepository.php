<?php
/**
 * Rule storage.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules;

use DateTimeImmutable;
use DateTimeZone;
use EuroB2B\Infrastructure\Schema;
use RuntimeException;

/**
 * CRUD for `eurob2b_rules`. JSON columns hold audience, scope, conditions and action; dates are UTC.
 */
final class RuleRepository {

	/**
	 * Per-request cache of enabled rules per type (ordered by priority desc, id asc), null until
	 * loaded. One query loads every type (PLT-011).
	 *
	 * @var array<string, Rule[]>|null
	 */
	private ?array $enabled = null;

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'eurob2b_rules' );
	}

	/**
	 * All rules, optionally of one type, ordered by priority (desc) then id (asc).
	 *
	 * @param string|null $type Type filter.
	 * @return Rule[]
	 */
	public function all( ?string $type = null ): array {
		global $wpdb;
		$table = $this->table();
		if ( null === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY priority DESC, id ASC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY priority DESC, id ASC", $type ), ARRAY_A );
		}
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Enabled rules of a type (cached for the request). The date window is checked by the evaluator.
	 *
	 * @param string $type Type.
	 * @return Rule[]
	 */
	public function enabled_of_type( string $type ): array {
		if ( null === $this->enabled ) {
			global $wpdb;
			$table = $this->table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows          = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY priority DESC, id ASC", ARRAY_A );
			$this->enabled = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$rule                           = $this->hydrate( $row );
				$this->enabled[ $rule->type ][] = $rule;
			}
		}
		return $this->enabled[ $type ] ?? array();
	}

	/**
	 * One rule.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Rule {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Inserts or updates.
	 *
	 * @param Rule $rule Rule.
	 * @return Rule Stored rule (with id).
	 * @throws RuntimeException Database error.
	 */
	public function save( Rule $rule ): Rule {
		global $wpdb;
		$now                = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
		$data               = self::columns( $rule );
		$data['updated_at'] = $now;
		$this->flush();
		/**
		 * Fires when a rule is created, updated or deleted (caches must be dropped).
		 */
		do_action( 'eurob2b_rules_changed' );
		if ( $rule->exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update( $this->table(), $data, array( 'id' => $rule->id ), null, array( '%d' ) );
			if ( false === $ok ) {
				throw new RuntimeException( 'Could not update the rule: ' . $wpdb->last_error );
			}
			return $rule;
		}
		$data['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( $this->table(), $data );
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not create the rule: ' . $wpdb->last_error );
		}
		return $rule->with_id( (int) $wpdb->insert_id );
	}

	/**
	 * The stored columns of a rule (without id and timestamps): what save() writes, and what an
	 * import compares and keeps to undo a change (MIG-001).
	 *
	 * @param Rule $rule Rule.
	 * @return array<string, mixed>
	 */
	public static function columns( Rule $rule ): array {
		return array(
			'type'       => $rule->type,
			'name'       => $rule->name,
			'enabled'    => $rule->enabled ? 1 : 0,
			'priority'   => $rule->priority,
			'audience'   => (string) wp_json_encode( array_values( $rule->audience ) ),
			'scope'      => (string) wp_json_encode( $rule->scope->to_array() ),
			'conditions' => (string) wp_json_encode(
				array(
					'any'   => $rule->any_condition,
					'items' => array_map( static fn( Condition $c ): array => $c->to_array(), $rule->conditions ),
				)
			),
			'action'     => (string) wp_json_encode( $rule->action ),
			'starts_at'  => null !== $rule->starts_at ? $rule->starts_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null,
			'ends_at'    => null !== $rule->ends_at ? $rule->ends_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null,
		);
	}

	/**
	 * Deletes a rule.
	 *
	 * @param int $id Id.
	 * @throws RuntimeException Database error.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$this->flush();
		do_action( 'eurob2b_rules_changed' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not delete the rule: ' . $wpdb->last_error );
		}
	}

	/**
	 * Removes a deleted user's audience token from every rule (privacy: rules must not keep
	 * references to erased accounts).
	 *
	 * @param int $user_id User id.
	 * @return int Number of rules changed.
	 */
	public function forget_user( int $user_id ): int {
		$token   = 'user:' . $user_id;
		$changed = 0;
		foreach ( $this->all() as $rule ) {
			if ( ! in_array( $token, $rule->audience, true ) ) {
				continue;
			}
			$audience = array_values( array_filter( $rule->audience, static fn( string $t ): bool => $t !== $token ) );
			$this->save( new Rule( $rule->id, $rule->type, $rule->name, $rule->enabled, $rule->priority, $audience, $rule->scope, $rule->conditions, $rule->any_condition, $rule->action, $rule->starts_at, $rule->ends_at ) );
			++$changed;
		}
		return $changed;
	}

	/**
	 * Drops the request cache.
	 */
	public function flush(): void {
		$this->enabled = null;
	}

	/**
	 * Row to entity. Malformed JSON degrades to "matches nothing" rather than failing. Public for the
	 * undo of an import, which restores a rule from the columns it kept (MIG-001).
	 *
	 * @param array<string, mixed> $row Row (columns() plus id).
	 */
	public function hydrate( array $row ): Rule {
		$audience   = json_decode( (string) ( $row['audience'] ?? '[]' ), true );
		$scope      = json_decode( (string) ( $row['scope'] ?? '{}' ), true );
		$conditions = json_decode( (string) ( $row['conditions'] ?? '{}' ), true );
		$action     = json_decode( (string) ( $row['action'] ?? '{}' ), true );
		$items      = array();
		foreach ( is_array( $conditions ) && isset( $conditions['items'] ) && is_array( $conditions['items'] ) ? $conditions['items'] : array() as $item ) {
			$condition = Condition::from_array( $item );
			if ( null !== $condition ) {
				$items[] = $condition;
			}
		}
		return new Rule(
			(int) $row['id'],
			(string) $row['type'],
			(string) $row['name'],
			(bool) (int) $row['enabled'],
			(int) $row['priority'],
			is_array( $audience ) ? array_values( array_filter( array_map( 'strval', $audience ), static fn( string $t ): bool => '' !== $t ) ) : array(),
			Scope::from_array( $scope ),
			$items,
			is_array( $conditions ) && ! empty( $conditions['any'] ),
			is_array( $action ) ? $action : array(),
			self::date( $row['starts_at'] ?? null ),
			self::date( $row['ends_at'] ?? null )
		);
	}

	/**
	 * UTC datetime column to object.
	 *
	 * @param mixed $value Column value.
	 */
	private static function date( $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return false === $date ? null : $date;
	}
}

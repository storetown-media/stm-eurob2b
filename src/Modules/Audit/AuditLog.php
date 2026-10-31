<?php
/**
 * Audit log storage.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Audit;

use EuroB2B\Infrastructure\Schema;

/**
 * Stores and reads the audit log of administrative decisions (PRV-011, data inventory D-20).
 *
 * An entry holds ids and value codes only: no IP address, no notes, no names. Entries are never
 * edited, except that an erasure request or the deletion of an account sets that user's references
 * to 0; they are deleted by the retention task (AuditRetention) or with the table on uninstall.
 */
final class AuditLog {

	public const ACTION_REGISTRATION_APPROVED = 'registration_approved';
	public const ACTION_REGISTRATION_REJECTED = 'registration_rejected';
	public const ACTION_STATUS_CHANGED        = 'status_changed';
	public const ACTION_TYPE_CHANGED          = 'type_changed';
	public const ACTION_GROUP_CHANGED         = 'group_changed';
	public const ACTION_VAT_DECIDED           = 'vat_decided';
	public const ACTION_ORDER_APPROVED        = 'order_approved';
	public const ACTION_ORDER_REJECTED        = 'order_rejected';
	public const ACTION_MEMBER_LOGIN          = 'member_login_started';
	public const ACTION_MEMBER_LOGOUT         = 'member_login_ended';
	public const ACTION_MEMBER_ORDER          = 'member_login_order';
	public const ACTION_EXPORT_EVIDENCE       = 'export_evidence_changed';
	public const ACTION_TAX_REVIEWED          = 'tax_review_confirmed';

	public const OBJECT_USER  = 'user';
	public const OBJECT_ORDER = 'order';
	public const OBJECT_VAT   = 'vat_validation';

	/**
	 * Longest stored value (column width).
	 */
	private const VALUE_LENGTH = 100;

	/**
	 * Every action key.
	 *
	 * @return string[]
	 */
	public static function actions(): array {
		return array(
			self::ACTION_REGISTRATION_APPROVED,
			self::ACTION_REGISTRATION_REJECTED,
			self::ACTION_STATUS_CHANGED,
			self::ACTION_TYPE_CHANGED,
			self::ACTION_GROUP_CHANGED,
			self::ACTION_VAT_DECIDED,
			self::ACTION_ORDER_APPROVED,
			self::ACTION_ORDER_REJECTED,
			self::ACTION_MEMBER_LOGIN,
			self::ACTION_MEMBER_LOGOUT,
			self::ACTION_MEMBER_ORDER,
			self::ACTION_EXPORT_EVIDENCE,
			self::ACTION_TAX_REVIEWED,
		);
	}

	/**
	 * Table name.
	 */
	private static function table(): string {
		return Schema::table( 'eurob2b_audit_log' );
	}

	/**
	 * Writes an entry; returns its id (0 when the action is unknown or the insert failed).
	 *
	 * @param string $action      Action key.
	 * @param int    $actor_id    Deciding user.
	 * @param int    $subject_id  Concerned user (0 = none).
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param string $old_value   Previous value (code).
	 * @param string $new_value   New value (code).
	 */
	public function record( string $action, int $actor_id, int $subject_id, string $object_type, int $object_id, string $old_value = '', string $new_value = '' ): int {
		global $wpdb;
		if ( ! in_array( $action, self::actions(), true ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table, nothing to cache.
		$ok = $wpdb->insert(
			self::table(),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'action'      => $action,
				'actor_id'    => max( 0, $actor_id ),
				'subject_id'  => max( 0, $subject_id ),
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => max( 0, $object_id ),
				'old_value'   => mb_substr( $old_value, 0, self::VALUE_LENGTH ),
				'new_value'   => mb_substr( $new_value, 0, self::VALUE_LENGTH ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' )
		);
		return false !== $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * WHERE clause for the screen filters.
	 *
	 * @param array{action?: string, user_id?: int} $filters Action key, user (as actor or concerned person).
	 * @return array{0: string, 1: array<int, string|int>}
	 */
	private static function where( array $filters ): array {
		$clauses = array( '1=1' );
		$args    = array();
		$action  = (string) ( $filters['action'] ?? '' );
		if ( '' !== $action ) {
			$clauses[] = 'action = %s';
			$args[]    = $action;
		}
		$user = (int) ( $filters['user_id'] ?? 0 );
		if ( $user > 0 ) {
			$clauses[] = '(actor_id = %d OR subject_id = %d)';
			$args[]    = $user;
			$args[]    = $user;
		}
		return array( implode( ' AND ', $clauses ), $args );
	}

	/**
	 * Entries matching the filters, newest first.
	 *
	 * @param array{action?: string, user_id?: int} $filters Filters.
	 * @param int                                   $limit   Page size.
	 * @param int                                   $offset  Offset.
	 * @return AuditEntry[]
	 */
	public function search( array $filters, int $limit, int $offset = 0 ): array {
		global $wpdb;
		$table                = self::table();
		list( $where, $args ) = self::where( $filters );
		$args[]               = max( 1, $limit );
		$args[]               = max( 0, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name and placeholders built above; $args holds one value per placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $args ) );
		return array_map( array( AuditEntry::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Number of entries matching the filters.
	 *
	 * @param array{action?: string, user_id?: int} $filters Filters.
	 */
	public function count( array $filters ): int {
		global $wpdb;
		$table                = self::table();
		list( $where, $args ) = self::where( $filters );
		$sql                  = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name and placeholders built above.
		return (int) $wpdb->get_var( array() !== $args ? $wpdb->prepare( $sql, $args ) : $sql );
	}

	/**
	 * Entries in which a user decided or was concerned, oldest first.
	 *
	 * @param int $user_id User.
	 * @return AuditEntry[]
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return array();
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE actor_id = %d OR subject_id = %d ORDER BY created_at ASC, id ASC", $user_id, $user_id ) );
		return array_map( array( AuditEntry::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Entries about some objects of one type, oldest first (audit file of an order, TAX-031).
	 *
	 * @param string $object_type Object type (AuditLog::OBJECT_*).
	 * @param int[]  $object_ids  Object ids.
	 * @param int    $limit       At most this many entries.
	 * @return AuditEntry[]
	 */
	public function for_objects( string $object_type, array $object_ids, int $limit = 500 ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $object_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( sanitize_key( $object_type ) ), $ids, array( max( 1, $limit ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name from Schema; one %d per id, passed as one array.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_type = %s AND object_id IN ({$placeholders}) ORDER BY created_at ASC, id ASC LIMIT %d", $args ) );
		return array_map( array( AuditEntry::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Removes a user's references (as deciding and as concerned person); the entries stay.
	 * Returns the number of entries changed.
	 *
	 * @param int $user_id User.
	 */
	public function forget_user( int $user_id ): int {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		$changed  = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET subject_id = 0, object_id = IF(object_type = %s AND object_id = %d, 0, object_id) WHERE subject_id = %d OR (object_type = %s AND object_id = %d)",
				self::OBJECT_USER,
				$user_id,
				$user_id,
				self::OBJECT_USER,
				$user_id
			)
		);
		$changed += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET actor_id = 0 WHERE actor_id = %d", $user_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $changed;
	}

	/**
	 * Deletes up to $limit entries written before $cutoff (UTC); returns the number deleted.
	 *
	 * @param string $cutoff Time (UTC, Y-m-d H:i:s).
	 * @param int    $limit  Maximum number of entries.
	 */
	public function delete_before( string $cutoff, int $limit ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT %d", $cutoff, max( 1, $limit ) ) );
	}
}

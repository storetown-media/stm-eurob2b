<?php
/**
 * One audit log entry.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Audit;

/**
 * Read model of a row in `eurob2b_audit_log`. A user reference of 0 was removed by an erasure
 * request or the deletion of the account (or never existed).
 */
final class AuditEntry {

	/**
	 * Constructor.
	 *
	 * @param int    $id          Id.
	 * @param string $created_at  Time (UTC, Y-m-d H:i:s).
	 * @param string $action      Action key (AuditLog::ACTION_*).
	 * @param int    $actor_id    Deciding user, 0 when removed.
	 * @param int    $subject_id  Concerned user, 0 when removed or none.
	 * @param string $object_type Object type (AuditLog::OBJECT_*).
	 * @param int    $object_id   Object id.
	 * @param string $old_value   Previous value (code).
	 * @param string $new_value   New value (code).
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $created_at,
		public readonly string $action,
		public readonly int $actor_id,
		public readonly int $subject_id,
		public readonly string $object_type,
		public readonly int $object_id,
		public readonly string $old_value,
		public readonly string $new_value,
	) {}

	/**
	 * From a database row.
	 *
	 * @param object $row Row.
	 */
	public static function from_row( object $row ): self {
		$get = static fn( string $key ): string => isset( $row->$key ) && is_scalar( $row->$key ) ? (string) $row->$key : '';
		return new self(
			(int) $get( 'id' ),
			$get( 'created_at' ),
			$get( 'action' ),
			(int) $get( 'actor_id' ),
			(int) $get( 'subject_id' ),
			$get( 'object_type' ),
			(int) $get( 'object_id' ),
			$get( 'old_value' ),
			$get( 'new_value' )
		);
	}
}

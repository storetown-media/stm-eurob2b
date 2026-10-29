<?php
/**
 * Migration 8: audit log table.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Creates `eurob2b_audit_log` (PRV-011, data inventory D-20). Reversible by dropping the table; no
 * other data refers to it. Version 8 because migration 7 belongs to the conversations (#83).
 */
final class M0008CreateAuditLogTable implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 8;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Create the audit log table.';
	}

	/**
	 * Creates the table.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = Schema::table( 'eurob2b_audit_log' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  action varchar(40) NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  old_value varchar(100) NOT NULL DEFAULT '',
  new_value varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY action (action),
  KEY actor_id (actor_id),
  KEY subject_id (subject_id)
) {$charset_collate};"
		);
	}

	/**
	 * The table exists.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		$table = Schema::table( 'eurob2b_audit_log' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}

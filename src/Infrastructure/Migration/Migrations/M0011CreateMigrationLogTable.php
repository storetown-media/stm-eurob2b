<?php
/**
 * Migration 11: journal of imports from other B2B plugins.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Creates `eurob2b_migration_log` (MIG-001, D-22): one row per value an import wrote, with the value
 * it replaced, so the import can be undone. Rows are deleted when the shop finishes the import or
 * after 30 days. Reversible by dropping the table; the uninstaller does that with the opt-in.
 */
final class M0011CreateMigrationLogTable implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 11;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Create the journal of imports from other plugins.';
	}

	/**
	 * Creates the table.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$log             = Schema::table( 'eurob2b_migration_log' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id varchar(32) NOT NULL DEFAULT '',
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ref varchar(190) NOT NULL DEFAULT '',
  old_value longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY object (object_type, object_id),
  KEY created_at (created_at)
) {$charset_collate};"
		);
	}

	/**
	 * The table exists.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		$log = Schema::table( 'eurob2b_migration_log' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log ) ) === $log;
	}
}

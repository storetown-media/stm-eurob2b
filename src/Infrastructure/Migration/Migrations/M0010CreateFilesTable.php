<?php
/**
 * Migration 10: private files.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Creates `eurob2b_files` (PRV-006, D-09): one row per uploaded file kept in the private directory,
 * with the owner, the purpose (`context`, e.g. `registration`), the field it belongs to, the
 * original name, the checked type and size, and the random name on disk. Reversible by dropping the
 * table and the private directory; the uninstaller does both.
 */
final class M0010CreateFilesTable implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 10;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Create the table of private files.';
	}

	/**
	 * Creates the table.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$files           = Schema::table( 'eurob2b_files' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
		dbDelta(
			"CREATE TABLE {$files} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  context varchar(40) NOT NULL DEFAULT '',
  field_key varchar(40) NOT NULL DEFAULT '',
  original_name varchar(190) NOT NULL DEFAULT '',
  mime varchar(100) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  stored_name varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY owner_context (owner_id, context),
  KEY stored_name (stored_name)
) {$charset_collate};"
		);
	}

	/**
	 * The table exists.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		$files = Schema::table( 'eurob2b_files' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $files ) ) === $files;
	}
}

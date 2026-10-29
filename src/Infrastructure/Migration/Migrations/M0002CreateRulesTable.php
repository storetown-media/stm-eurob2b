<?php
/**
 * Migration 2: rules table.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Creates `eurob2b_rules` (rules engine, docs/architecture.md §7). Reversible by dropping the table.
 */
final class M0002CreateRulesTable implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 2;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Create the rules table.';
	}

	/**
	 * Creates the table.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$rules           = Schema::table( 'eurob2b_rules' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
		$sql = "CREATE TABLE {$rules} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(32) NOT NULL,
  name varchar(190) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  priority int(11) NOT NULL DEFAULT 10,
  audience longtext NOT NULL,
  scope longtext NOT NULL,
  conditions longtext NOT NULL,
  action longtext NOT NULL,
  starts_at datetime NULL,
  ends_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_enabled (type, enabled),
  KEY priority (priority)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * The table exists.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		$table = Schema::table( 'eurob2b_rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}

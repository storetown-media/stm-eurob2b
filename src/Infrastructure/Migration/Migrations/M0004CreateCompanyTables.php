<?php
/**
 * Migration 4: company account tables.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Creates `eurob2b_companies` and `eurob2b_company_members` (docs/companies.md). Reversible by
 * dropping both tables; orders keep their `_eurob2b_company_id` meta as plain numbers.
 */
final class M0004CreateCompanyTables implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 4;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Create the company account tables.';
	}

	/**
	 * Creates the tables.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$companies       = Schema::table( 'eurob2b_companies' );
		$members         = Schema::table( 'eurob2b_company_members' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
		dbDelta(
			"CREATE TABLE {$companies} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  group_id bigint(20) unsigned NULL,
  vat_validation_id bigint(20) unsigned NULL,
  settings longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY owner_user_id (owner_user_id),
  KEY group_id (group_id)
) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$members} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  company_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  role varchar(20) NOT NULL DEFAULT 'member',
  permissions longtext NOT NULL,
  requires_approval tinyint(1) NOT NULL DEFAULT 0,
  approval_threshold decimal(19,4) NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  KEY company_id (company_id)
) {$charset_collate};"
		);
	}

	/**
	 * Both tables exist.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		foreach ( array( 'eurob2b_companies', 'eurob2b_company_members' ) as $suffix ) {
			$table = Schema::table( $suffix );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}
}

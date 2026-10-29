<?php
/**
 * Migration 6: custom request field values and offer audiences.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Adds two nullable/defaulted columns to `eurob2b_quotes` (docs/quotes.md):
 * `field_values` (JSON list of the custom request field values with their labels, QUO-011/012)
 * and `audience` (comma-separated audience tokens that may also see an offer, OFR-003).
 * Existing rows keep working unchanged (no value / no extra audience). Reversible by dropping
 * the two columns; nothing else references them.
 */
final class M0006AddQuoteFieldsAndAudience implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 6;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Add request field values and offer audiences to the quote table.';
	}

	/**
	 * Adds the columns (dbDelta compares the full definition and only adds what is missing).
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$quotes          = Schema::table( 'eurob2b_quotes' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
		dbDelta(
			"CREATE TABLE {$quotes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(10) NOT NULL DEFAULT 'request',
  status varchar(20) NOT NULL DEFAULT 'open',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  company_id bigint(20) unsigned NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(190) NOT NULL DEFAULT '',
  message longtext NULL,
  note longtext NULL,
  field_values longtext NULL,
  audience varchar(255) NOT NULL DEFAULT '',
  currency varchar(3) NOT NULL DEFAULT '',
  total_minor bigint(20) NOT NULL DEFAULT 0,
  expires_at datetime NULL,
  reusable tinyint(1) NOT NULL DEFAULT 0,
  accepted_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_status (type, status),
  KEY user_id (user_id),
  KEY company_id (company_id),
  KEY parent_id (parent_id)
) {$charset_collate};"
		);
	}

	/**
	 * Both columns exist.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		$quotes = Schema::table( 'eurob2b_quotes' );
		foreach ( array( 'field_values', 'audience' ) as $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quotes} LIKE %s", $column ) ) !== $column ) {
				return false;
			}
		}
		return true;
	}
}

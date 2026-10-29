<?php
/**
 * Migration 1: core tables.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Creates the customer group table and the VAT ID validation log.
 *
 * Data inventory: see docs/privacy-and-data-flows.md.
 *  - eurob2b_groups: configuration only, no personal data.
 *  - eurob2b_vat_validations: VAT IDs are personal data for sole traders.
 *    Rows store the normalised ID, the result and the evidence needed to
 *    prove due diligence (consultation number). Retention is configurable.
 */
final class M0001CreateCoreTables implements MigrationInterface {

	/**
	 * Schema version produced by this migration.
	 */
	public function version(): int {
		return 1;
	}

	/**
	 * Description for the migration log.
	 */
	public function description(): string {
		return 'Create customer groups and VAT validation log tables.';
	}

	/**
	 * Creates the tables with dbDelta (idempotent).
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$groups          = Schema::table( 'eurob2b_groups' );
		$validations     = Schema::table( 'eurob2b_vat_validations' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
		$sql = "CREATE TABLE {$groups} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(64) NOT NULL,
  name varchar(190) NOT NULL,
  customer_type varchar(8) NOT NULL DEFAULT 'b2b',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  priority int(11) NOT NULL DEFAULT 10,
  settings longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY customer_type (customer_type)
) {$charset_collate};
CREATE TABLE {$validations} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NULL,
  company_id bigint(20) unsigned NULL,
  order_id bigint(20) unsigned NULL,
  country_code char(2) NOT NULL,
  vat_number varchar(32) NOT NULL,
  status varchar(24) NOT NULL,
  reason_code varchar(64) NULL,
  service varchar(32) NOT NULL,
  consultation_number varchar(64) NULL,
  requester_vat_id varchar(32) NULL,
  response_name varchar(255) NULL,
  response_address text NULL,
  decided_by bigint(20) unsigned NULL,
  decision_note text NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 1,
  checked_at datetime NOT NULL,
  expires_at datetime NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY company_id (company_id),
  KEY order_id (order_id),
  KEY vat_lookup (country_code, vat_number),
  KEY status (status),
  KEY checked_at (checked_at)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Both tables must exist after up().
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	public function verify( \wpdb $wpdb ): bool {
		foreach ( array( 'eurob2b_groups', 'eurob2b_vat_validations' ) as $suffix ) {
			$table = Schema::table( $suffix );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}
}

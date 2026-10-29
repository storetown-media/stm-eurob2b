<?php
/**
 * Migration 5: quote and offer tables.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Creates `eurob2b_quotes` (requests and offers) and `eurob2b_quote_items` (docs/quotes.md).
 * Reversible by dropping both tables; orders keep `_eurob2b_offer_id` as a plain number.
 */
final class M0005CreateQuoteTables implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 5;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Create the quote and offer tables.';
	}

	/**
	 * Creates the tables.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$quotes          = Schema::table( 'eurob2b_quotes' );
		$items           = Schema::table( 'eurob2b_quote_items' );

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
		dbDelta(
			"CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  quote_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  description varchar(255) NOT NULL DEFAULT '',
  quantity int(11) NOT NULL DEFAULT 1,
  unit_price_minor bigint(20) NULL,
  tax_class varchar(40) NOT NULL DEFAULT '',
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY quote_id (quote_id)
) {$charset_collate};"
		);
	}

	/**
	 * Both tables exist.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		foreach ( array( 'eurob2b_quotes', 'eurob2b_quote_items' ) as $suffix ) {
			$table = Schema::table( $suffix );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}
}

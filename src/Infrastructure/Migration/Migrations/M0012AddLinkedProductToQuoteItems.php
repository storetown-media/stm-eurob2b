<?php
/**
 * Migration 12: free-text offer lines linked to a product for stock.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Adds the nullable column `linked_product_id` to `eurob2b_quote_items` (OFR-002, docs/quotes.md):
 * the product or variation whose stock a free-text offer line reduces when the offer is ordered.
 * NULL means "not linked", so every existing line keeps its meaning without a data update and the
 * column cannot be mistaken for a product line (`product_id` stays 0 on free-text lines).
 * Reversible by dropping the column; no other table or index references it.
 */
final class M0012AddLinkedProductToQuoteItems implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 12;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Add the linked product to quote lines.';
	}

	/**
	 * Adds the column (dbDelta compares the full definition and only adds what is missing).
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$items           = Schema::table( 'eurob2b_quote_items' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
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
  linked_product_id bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY quote_id (quote_id)
) {$charset_collate};"
		);
	}

	/**
	 * The column exists.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		$items = Schema::table( 'eurob2b_quote_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		return $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$items} LIKE %s", 'linked_product_id' ) ) === 'linked_product_id';
	}
}

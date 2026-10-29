<?php
/**
 * Migration 3: purchase list tables.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Creates `eurob2b_purchase_lists` and `eurob2b_purchase_list_items` (docs/purchase-lists.md).
 * Reversible by dropping both tables; no other data refers to them.
 */
final class M0003CreatePurchaseListTables implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 3;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Create the purchase list tables.';
	}

	/**
	 * Creates the tables.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$lists           = Schema::table( 'eurob2b_purchase_lists' );
		$items           = Schema::table( 'eurob2b_purchase_list_items' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
		dbDelta(
			"CREATE TABLE {$lists} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  company_id bigint(20) unsigned NULL,
  name varchar(190) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY company_id (company_id)
) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  list_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  quantity int(11) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY list_id (list_id),
  UNIQUE KEY list_product (list_id, product_id, variation_id)
) {$charset_collate};"
		);
	}

	/**
	 * Both tables exist.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		foreach ( array( 'eurob2b_purchase_lists', 'eurob2b_purchase_list_items' ) as $suffix ) {
			$table = Schema::table( $suffix );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}
}

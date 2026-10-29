<?php
/**
 * Migration 7: conversation tables.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration\Migrations;

use EuroB2B\Infrastructure\Migration\MigrationInterface;
use EuroB2B\Infrastructure\Schema;

/**
 * Creates `eurob2b_conversations` (one row per thread) and `eurob2b_messages` (the messages of a
 * thread), docs/conversations.md, data inventory D-07. Reversible by dropping both tables; nothing
 * else references them.
 */
final class M0007CreateConversationTables implements MigrationInterface {

	/**
	 * Version.
	 */
	public function version(): int {
		return 7;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return 'Create the conversation tables.';
	}

	/**
	 * Creates the tables.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$conversations   = Schema::table( 'eurob2b_conversations' );
		$messages        = Schema::table( 'eurob2b_messages' );

		// dbDelta needs two spaces after PRIMARY KEY and one key definition per line.
		dbDelta(
			"CREATE TABLE {$conversations} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL DEFAULT 'message',
  status varchar(20) NOT NULL DEFAULT 'open',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  company_id bigint(20) unsigned NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subject varchar(190) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  last_message_at datetime NOT NULL,
  last_author_role varchar(10) NOT NULL DEFAULT 'customer',
  customer_read_at datetime NULL,
  staff_read_at datetime NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY company_id (company_id),
  KEY status_last (status, last_message_at)
) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$messages} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_role varchar(10) NOT NULL DEFAULT 'customer',
  body longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation_id (conversation_id),
  KEY author_id (author_id)
) {$charset_collate};"
		);
	}

	/**
	 * Both tables exist.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function verify( \wpdb $wpdb ): bool {
		foreach ( array( 'eurob2b_conversations', 'eurob2b_messages' ) as $suffix ) {
			$table = Schema::table( $suffix );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}
}

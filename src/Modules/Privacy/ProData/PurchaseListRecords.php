<?php
/**
 * Stored purchase lists, for the privacy handling.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use EuroB2B\Infrastructure\Schema;

/**
 * Purchase lists of the shared schema (tables `eurob2b_purchase_lists` and
 * `eurob2b_purchase_list_items`, migration 3; D-08), read and deleted for the privacy export and
 * erasure and on user deletion. EuroB2B Pro writes them; this part of the core handles them also
 * while Pro is inactive or deleted (PLT-015).
 */
final class PurchaseListRecords {

	/**
	 * Tables (without the prefix).
	 */
	public const TABLE_LISTS = 'eurob2b_purchase_lists';
	public const TABLE_ITEMS = 'eurob2b_purchase_list_items';

	/**
	 * The lists of a user, newest change first.
	 *
	 * @param int $user_id User id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;
		$lists = Schema::table( self::TABLE_LISTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$lists} WHERE user_id = %d ORDER BY updated_at DESC, id DESC", $user_id ), ARRAY_A );
	}

	/**
	 * Rows of a list in their order.
	 *
	 * @param int $list_id List id.
	 * @return array<int, array<string, mixed>>
	 */
	public function items( int $list_id ): array {
		global $wpdb;
		$items = Schema::table( self::TABLE_ITEMS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items} WHERE list_id = %d ORDER BY sort_order ASC, id ASC", $list_id ), ARRAY_A );
	}

	/**
	 * Deletes every list of a user with its rows (privacy erasure, account deletion).
	 *
	 * @param int $user_id User id.
	 * @return int Number of lists removed.
	 */
	public function delete_for_user( int $user_id ): int {
		global $wpdb;
		$count = 0;
		foreach ( $this->for_user( $user_id ) as $list ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( Schema::table( self::TABLE_ITEMS ), array( 'list_id' => (int) $list['id'] ), array( '%d' ) );
			$wpdb->delete( Schema::table( self::TABLE_LISTS ), array( 'id' => (int) $list['id'] ), array( '%d' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			++$count;
		}
		return $count;
	}
}

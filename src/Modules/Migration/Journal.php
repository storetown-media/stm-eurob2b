<?php
/**
 * Journal of an import.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

use EuroB2B\Infrastructure\Schema;
use EuroB2B\Modules\Pricing\CustomerPrices;
use EuroB2B\Modules\Pricing\TierStorage;

/**
 * Table `eurob2b_migration_log` (migration 11, D-22): for every value an import wrote, the value it
 * replaced (NULL = there was none), so the import can be undone. Object types: `post_meta`,
 * `user_meta`, `term_meta` (ref = meta key), `customer` (user id, old value = JSON of the stored
 * type, status and group), `group`, `rule`, `company` (created objects, old value NULL; a rule that
 * was updated keeps its old row as JSON), `member` (user linked to the company in ref),
 * `registration_type`, `field` (ref = slug or key, old value
 * = JSON definition), `policy` (ref = payment or shipping, old value = JSON policy) and `map`
 * (ref = kind:source key, old value = the previous target id).
 */
final class Journal {

	public const TYPE_POST_META         = 'post_meta';
	public const TYPE_USER_META         = 'user_meta';
	public const TYPE_TERM_META         = 'term_meta';
	public const TYPE_CUSTOMER          = 'customer';
	public const TYPE_GROUP             = 'group';
	public const TYPE_RULE              = 'rule';
	public const TYPE_COMPANY           = 'company';
	public const TYPE_MEMBER            = 'member';
	public const TYPE_REGISTRATION_TYPE = 'registration_type';
	public const TYPE_FIELD             = 'field';
	public const TYPE_POLICY            = 'policy';
	public const TYPE_MAP               = 'map';

	/**
	 * Rows read per query when undoing.
	 */
	public const PAGE = 500;

	/**
	 * Table name.
	 */
	private static function table(): string {
		return Schema::table( 'eurob2b_migration_log' );
	}

	/**
	 * Writes a row; false when the database refused it (the caller must not change anything then).
	 *
	 * @param string      $run_id      Run.
	 * @param string      $object_type One of the TYPE_ constants.
	 * @param int         $object_id   Object id (0 when the ref identifies it).
	 * @param string      $ref         Meta key, slug or key.
	 * @param string|null $old_value   Value before the import; null when there was none.
	 */
	public function record( string $run_id, string $object_type, int $object_id, string $ref, ?string $old_value ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table, nothing to cache.
		return false !== $wpdb->insert(
			self::table(),
			array(
				'run_id'      => $run_id,
				'object_type' => $object_type,
				'object_id'   => max( 0, $object_id ),
				'ref'         => mb_substr( $ref, 0, 190 ),
				'old_value'   => $old_value,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			// wpdb writes a null value as NULL whatever its format.
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Rows of a run, newest first, below a row id (for paging backwards).
	 *
	 * @param string $run_id Run.
	 * @param int    $before Only rows with a smaller id (0 = from the newest).
	 * @return array<int, array{id: int, object_type: string, object_id: int, ref: string, old_value: string|null}>
	 */
	public function rows_backwards( string $run_id, int $before = 0 ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		$rows = $before > 0
			? $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, object_id, ref, old_value FROM {$table} WHERE run_id = %s AND id < %d ORDER BY id DESC LIMIT %d", $run_id, $before, self::PAGE ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, object_id, ref, old_value FROM {$table} WHERE run_id = %s ORDER BY id DESC LIMIT %d", $run_id, self::PAGE ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'          => (int) $row['id'],
				'object_type' => (string) $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'ref'         => (string) $row['ref'],
				'old_value'   => null === $row['old_value'] ? null : (string) $row['old_value'],
			);
		}
		return $out;
	}

	/**
	 * Number of rows of a run.
	 *
	 * @param string $run_id Run.
	 */
	public function count( string $run_id ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE run_id = %s", $run_id ) );
	}

	/**
	 * Deletes the rows of a run.
	 *
	 * @param string $run_id Run.
	 */
	public function delete_run( string $run_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		return (int) $wpdb->delete( self::table(), array( 'run_id' => $run_id ), array( '%s' ) );
	}

	/**
	 * Deletes up to $limit rows written before $cutoff (UTC); returns the number deleted.
	 *
	 * @param string $cutoff Time (UTC, Y-m-d H:i:s).
	 * @param int    $limit  Maximum number of rows.
	 */
	public function delete_before( string $cutoff, int $limit ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT %d", $cutoff, max( 1, $limit ) ) );
	}

	/**
	 * Rows that belong to a person: the previous values of the user's own meta and classification,
	 * of the company link, of the user's own prices and tier sets on products, and earlier rules,
	 * and visibility rules that named the user (`user:<id>`).
	 *
	 * @param int $user_id User id.
	 * @return array<int, array{object_type: string, object_id: int, ref: string, old_value: string|null, created_at: string}>
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return array();
		}
		$table                = self::table();
		list( $where, $args ) = self::person_filter( $user_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from Schema; $where is built from placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id, ref, old_value, created_at FROM {$table} WHERE {$where} ORDER BY id ASC", $args ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'object_type' => (string) $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'ref'         => (string) $row['ref'],
				'old_value'   => null === $row['old_value'] ? null : (string) $row['old_value'],
				'created_at'  => (string) $row['created_at'],
			);
		}
		return $out;
	}

	/**
	 * Deletes a person's rows (erasure, deleted account); an undo then leaves that person's data as
	 * the import left it, so it cannot bring back erased values.
	 *
	 * @param int $user_id User id.
	 */
	public function forget_user( int $user_id ): int {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$table                = self::table();
		list( $where, $args ) = self::person_filter( $user_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from Schema; $where is built from placeholders.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$where}", $args ) );
	}

	/**
	 * WHERE part selecting a person's rows, with its arguments: rows about the user (own meta,
	 * classification, company link), the user's prices and tier sets on products (meta keys
	 * `_eurob2b_customer_price_<id>`, `_eurob2b_customer_sale_price_<id>`, `_eurob2b_tiers_user_<id>`,
	 * also with a currency suffix), and earlier values that name the user as `user:<id>`.
	 *
	 * @param int $user_id User id.
	 * @return array{0: string, 1: array<int, int|string>}
	 */
	private static function person_filter( int $user_id ): array {
		global $wpdb;
		$where = '(object_type IN (%s, %s, %s) AND object_id = %d)';
		$args  = array( self::TYPE_USER_META, self::TYPE_CUSTOMER, self::TYPE_MEMBER, $user_id );
		foreach ( array( CustomerPrices::regular_key( $user_id ), CustomerPrices::sale_key( $user_id ), TierStorage::user_key( $user_id ) ) as $key ) {
			$where .= ' OR (object_type = %s AND (ref = %s OR ref LIKE %s))';
			array_push( $args, self::TYPE_POST_META, $key, $wpdb->esc_like( $key . '_' ) . '%' );
		}
		$where .= ' OR (object_type IN (%s, %s, %s) AND old_value LIKE %s)';
		array_push( $args, self::TYPE_RULE, self::TYPE_POST_META, self::TYPE_TERM_META, '%' . $wpdb->esc_like( '"user:' . $user_id . '"' ) . '%' );
		return array( $where, $args );
	}
}

<?php
/**
 * Storage for VAT validations.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Validation;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- repository for the plugin's own table; every statement is prepared, rows are evidence and not cached.

use DateTimeImmutable;
use DateTimeZone;
use EuroB2B\Infrastructure\Schema;
use EuroB2B\Modules\Countries\CountryRegistry;
use EuroB2B\Modules\Tax\VatId\ExternalServices;
use EuroB2B\Modules\Tax\VatId\ValidationResult;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;
use RuntimeException;

/**
 * All reads and writes of eurob2b_vat_validations go through here (prepared statements only).
 */
final class ValidationRepository {

	public const SERVICE_MANUAL = 'manual';

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'eurob2b_vat_validations' );
	}

	/**
	 * Stores a service result as a new row.
	 *
	 * @param ValidationResult       $result     Result.
	 * @param int|null               $user_id    Customer.
	 * @param int                    $attempts   Attempts so far.
	 * @param DateTimeImmutable|null $expires_at Retention end.
	 * @param ValidationStatus|null  $status     Stored status when it differs from the result (pending while retrying).
	 * @return int New row id.
	 * @throws RuntimeException On database error.
	 */
	public function insert( ValidationResult $result, ?int $user_id, int $attempts, ?DateTimeImmutable $expires_at, ?ValidationStatus $status = null ): int {
		global $wpdb;
		$data = array_merge(
			self::row_from_result( $result, $status ),
			array(
				'user_id'    => $user_id,
				'attempts'   => max( 1, $attempts ),
				'expires_at' => null !== $expires_at ? $expires_at->format( 'Y-m-d H:i:s' ) : null,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( $this->table(), $data );
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not store the VAT validation: ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Copies a row's service result into a new row for another customer (or a new order), so
	 * evidence rows never change hands: one row per customer and order.
	 *
	 * A qualified BZSt answer holds the company data one customer submitted; it is copied only to
	 * the same customer or when the caller confirmed identical company data, otherwise the copy
	 * keeps the validity answer without name, address and comparison codes.
	 *
	 * @param ValidationRecord $source      Row whose service answer is reused.
	 * @param int|null         $user_id     Customer of the new row.
	 * @param bool             $same_trader The new customer's company data equals the compared data.
	 * @return int New row id.
	 * @throws RuntimeException On database error.
	 */
	public function copy_for( ValidationRecord $source, ?int $user_id, bool $same_trader = false ): int {
		global $wpdb;
		$keep = ExternalServices::BZST !== $source->service || $same_trader || ( null !== $user_id && $source->user_id === $user_id );
		$data = array(
			'user_id'             => $user_id,
			'country_code'        => $source->country_code,
			'vat_number'          => $source->vat_number,
			'status'              => $source->status->value,
			'reason_code'         => $source->is_decided() ? 'manual_decision' : 'reused_result',
			'service'             => $source->service,
			'consultation_number' => $source->consultation_number,
			'requester_vat_id'    => $source->requester_vat_id,
			'response_name'       => $keep ? $source->response_name : null,
			'response_address'    => $keep ? $source->response_address : null,
			'decided_by'          => $source->decided_by,
			'decision_note'       => $source->decision_note,
			'attempts'            => 1,
			'checked_at'          => $source->checked_at->format( 'Y-m-d H:i:s' ),
			'expires_at'          => null !== $source->expires_at ? $source->expires_at->format( 'Y-m-d H:i:s' ) : null,
		);
		$ok   = $wpdb->insert( $this->table(), $data );
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not copy the VAT validation: ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Overwrites a row with a new service result (retry).
	 *
	 * @param int                   $id       Row id.
	 * @param ValidationResult      $result   Result.
	 * @param int                   $attempts Attempts so far.
	 * @param ValidationStatus|null $status   Stored status when it differs from the result.
	 * @throws RuntimeException On database error.
	 */
	public function update_result( int $id, ValidationResult $result, int $attempts, ?ValidationStatus $status = null ): void {
		global $wpdb;
		$data             = self::row_from_result( $result, $status );
		$data['attempts'] = max( 1, $attempts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update( $this->table(), $data, array( 'id' => $id ), null, array( '%d' ) );
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not update the VAT validation: ' . $wpdb->last_error );
		}
	}

	/**
	 * Writes the service answer of a background retry unless a person decided meanwhile, on this row
	 * or on a newer row of the same number. One statement, so a decision saved while the service was
	 * being asked always wins over the late answer.
	 *
	 * @param int                   $id       Row id.
	 * @param ValidationResult      $result   Result.
	 * @param int                   $attempts Attempts so far.
	 * @param ValidationStatus|null $status   Status override (pending).
	 * @return bool Whether the answer was written.
	 * @throws RuntimeException Database error.
	 */
	public function update_result_unless_decided( int $id, ValidationResult $result, int $attempts, ?ValidationStatus $status = null ): bool {
		global $wpdb;
		$data             = self::row_from_result( $result, $status );
		$data['attempts'] = max( 1, $attempts );
		$set              = array();
		$values           = array();
		foreach ( $data as $column => $value ) {
			if ( null === $value ) {
				$set[] = "r.{$column} = NULL";
				continue;
			}
			$set[]    = "r.{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
			$values[] = $value;
		}
		$values[] = $id;
		$table    = $this->table();
		// A self join, because MySQL cannot read the table it updates in a subquery. The column names
		// come from row_from_result(), the values are prepared.
		$sql = "UPDATE {$table} AS r LEFT JOIN {$table} AS d ON d.country_code = r.country_code AND d.vat_number = r.vat_number AND d.id > r.id AND d.decided_by IS NOT NULL SET " . implode( ', ', $set ) . ' WHERE r.id = %d AND r.decided_by IS NULL AND d.id IS NULL';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false === $affected ) {
			throw new RuntimeException( 'Could not update the VAT validation: ' . $wpdb->last_error );
		}
		return $affected > 0;
	}

	/**
	 * Finds a row.
	 *
	 * @param int $id Row id.
	 */
	public function find( int $id ): ?ValidationRecord {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Latest row for a VAT ID (any user).
	 *
	 * @param string $country_code ISO country.
	 * @param string $vat_number   Number without prefix.
	 */
	public function latest_for( string $country_code, string $vat_number ): ?ValidationRecord {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE country_code = %s AND vat_number = %s ORDER BY checked_at DESC, id DESC LIMIT 1", $country_code, $vat_number ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Whether a person decided on this VAT ID in a row newer than the given one (any customer),
	 * for example on the copy made for a later order.
	 *
	 * @param string $country_code ISO country.
	 * @param string $vat_number   Number without prefix.
	 * @param int    $after_id     Row id the decided row must be newer than.
	 */
	public function decided_after( string $country_code, string $vat_number, int $after_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE country_code = %s AND vat_number = %s AND decided_by IS NOT NULL AND id > %d LIMIT 1", $country_code, $vat_number, $after_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null !== $found;
	}

	/**
	 * Latest row of a user.
	 *
	 * @param int $user_id User id.
	 */
	public function latest_for_user( int $user_id ): ?ValidationRecord {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d ORDER BY checked_at DESC, id DESC LIMIT 1", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Latest valid row of a user for one number (for the provisional downtime policy: only an earlier
	 * valid result of the same country and number counts, product owner decision of 2026-10-27).
	 *
	 * @param int    $user_id      User id.
	 * @param string $country_code Country code of the number.
	 * @param string $vat_number   Number without prefix.
	 */
	public function latest_valid_for_user( int $user_id, string $country_code, string $vat_number ): ?ValidationRecord {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d AND country_code = %s AND vat_number = %s AND status = %s ORDER BY checked_at DESC, id DESC LIMIT 1", $user_id, $country_code, $vat_number, ValidationStatus::VALID->value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Rows a person should look at: pending, unavailable and invalid results that nobody decided yet
	 * and that no newer check of the same number for the same customer replaced.
	 *
	 * @param int $limit Max rows.
	 * @return ValidationRecord[]
	 */
	public function review_list( int $limit = 100 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// A row that a newer result for the same number and customer replaced is history, not a
				// task: it stays in the table as evidence and leaves the list. "Newer" is ordered like
				// latest_for(): check time, then id. A background retry that answers later updates the
				// check time of its own row and so brings that row back to the front.
				"SELECT v.* FROM {$this->table()} v WHERE v.status IN (%s, %s, %s) AND v.decided_by IS NULL AND NOT EXISTS ( SELECT 1 FROM {$this->table()} n WHERE n.country_code = v.country_code AND n.vat_number = v.vat_number AND n.user_id <=> v.user_id AND ( n.checked_at > v.checked_at OR ( n.checked_at = v.checked_at AND n.id > v.id ) ) ) ORDER BY v.checked_at DESC, v.id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ValidationStatus::PENDING->value,
				ValidationStatus::UNAVAILABLE->value,
				ValidationStatus::INVALID->value,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Recent rows (for the admin list).
	 *
	 * @param int $limit Max rows.
	 * @return ValidationRecord[]
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY checked_at DESC, id DESC LIMIT %d", max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Rows matching the filters, newest first (REST API, one page).
	 *
	 * @param array{user_id?: int, order_id?: int, status?: string, country_code?: string} $filters Filters.
	 * @param int                                                                          $limit   Max rows.
	 * @param int                                                                          $offset  Rows to skip.
	 * @return ValidationRecord[]
	 */
	public function search( array $filters, int $limit, int $offset ): array {
		global $wpdb;
		[ $where, $args ] = self::where( $filters );
		$args[]           = max( 1, $limit );
		$args[]           = max( 0, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY checked_at DESC, id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the placeholders of where() plus LIMIT and OFFSET, passed as one array.
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Number of rows matching the filters.
	 *
	 * @param array{user_id?: int, order_id?: int, status?: string, country_code?: string} $filters Filters.
	 */
	public function count_matching( array $filters ): int {
		global $wpdb;
		[ $where, $args ] = self::where( $filters );
		$sql              = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( empty( $args ) ? $sql : $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * WHERE clause with placeholders for the filters of search() and count_matching().
	 *
	 * @param array{user_id?: int, order_id?: int, status?: string, country_code?: string} $filters Filters.
	 * @return array{0: string, 1: array<int, int|string>}
	 */
	private static function where( array $filters ): array {
		$clauses = array( '1=1' );
		$args    = array();
		foreach ( array(
			'user_id'      => '%d',
			'order_id'     => '%d',
			'status'       => '%s',
			'country_code' => '%s',
		) as $column => $placeholder ) {
			if ( isset( $filters[ $column ] ) ) {
				$clauses[] = $column . ' = ' . $placeholder;
				$args[]    = $filters[ $column ];
			}
		}
		return array( implode( ' AND ', $clauses ), $args );
	}

	/**
	 * Rows of a user (privacy export).
	 *
	 * @param int $user_id User id.
	 * @return ValidationRecord[]
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d ORDER BY checked_at DESC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Records a manual decision; the status becomes the decided one.
	 *
	 * @param int              $id       Row id.
	 * @param ValidationStatus $status   Decided status (valid or invalid).
	 * @param int              $actor_id Actor.
	 * @param string           $note     Note.
	 * @throws RuntimeException On database error.
	 */
	public function decide( int $id, ValidationStatus $status, int $actor_id, string $note ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$this->table(),
			array(
				'status'        => $status->value,
				'reason_code'   => 'manual_decision',
				'decided_by'    => $actor_id,
				'decision_note' => $note,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not store the decision: ' . $wpdb->last_error );
		}
	}

	/**
	 * Links a row to an order and extends its retention.
	 *
	 * @param int                    $id         Row id.
	 * @param int                    $order_id   Order id.
	 * @param DateTimeImmutable|null $expires_at New retention end.
	 */
	public function attach_order( int $id, int $order_id, ?DateTimeImmutable $expires_at ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table(),
			array(
				'order_id'   => $order_id,
				'expires_at' => null !== $expires_at ? $expires_at->format( 'Y-m-d H:i:s' ) : null,
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Sets the user of a row (a check made before the account existed).
	 *
	 * @param int $id      Row id.
	 * @param int $user_id User id.
	 */
	public function set_user( int $id, int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->table(), array( 'user_id' => $user_id ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Deletes expired rows. Orderless rows expire after the orderless retention period; order-linked
	 * rows after the invoice retention period that attach_order() set (setting "Keep evidence linked
	 * to orders"), so tax evidence is kept as long as required and no longer (docs/privacy-and-data-flows.md).
	 *
	 * @param DateTimeImmutable $now Now (UTC).
	 * @return int Rows deleted.
	 */
	public function purge_expired( DateTimeImmutable $now ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE expires_at IS NOT NULL AND expires_at < %s", $now->format( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Removes the name and address a service returned for a number from every row of that service,
	 * copies included; status, times and decisions stay as evidence of the earlier checks. Used when
	 * the Brønnøysund register answers 410: it removed the entity for legal reasons and asks that
	 * copies remove it too (docs/country-matrix.md §5).
	 *
	 * @param string $country_code ISO country.
	 * @param string $vat_number   Number without prefix.
	 * @param string $service      Service id.
	 * @return int Rows changed.
	 */
	public function forget_returned_data( string $country_code, string $vat_number, string $service ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET response_name = NULL, response_address = NULL WHERE country_code = %s AND vat_number = %s AND service = %s AND ( response_name IS NOT NULL OR response_address IS NOT NULL )", $country_code, $vat_number, $service ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_int( $changed ) ? $changed : 0;
	}

	/**
	 * Erasure: orderless rows of a user are deleted, order-linked rows lose the user link (kept as
	 * tax evidence, D-03).
	 *
	 * @param int $user_id User id.
	 * @return array{deleted: int, retained: int}
	 */
	public function erase_user( int $user_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE user_id = %d AND order_id IS NULL", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$retained = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET user_id = NULL, response_name = NULL, response_address = NULL WHERE user_id = %d AND order_id IS NOT NULL", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'deleted'  => is_int( $deleted ) ? $deleted : 0,
			'retained' => is_int( $retained ) ? $retained : 0,
		);
	}

	/**
	 * Row data from a result.
	 *
	 * @param ValidationResult      $result Result.
	 * @param ValidationStatus|null $status Stored status override.
	 * @return array<string, mixed>
	 */
	private static function row_from_result( ValidationResult $result, ?ValidationStatus $status ): array {
		return array(
			'country_code'        => $result->vat_id->country_code(),
			'vat_number'          => $result->vat_id->number,
			'status'              => ( $status ?? $result->status )->value,
			'reason_code'         => $result->reason_code,
			'service'             => $result->service,
			'consultation_number' => $result->consultation_number,
			'requester_vat_id'    => $result->requester_vat_id,
			'response_name'       => $result->trader_name,
			'response_address'    => $result->trader_address,
			'checked_at'          => $result->checked_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Entity from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function hydrate( array $row ): ValidationRecord {
		$utc = new DateTimeZone( 'UTC' );
		return new ValidationRecord(
			(int) $row['id'],
			isset( $row['user_id'] ) ? (int) $row['user_id'] : null,
			isset( $row['company_id'] ) ? (int) $row['company_id'] : null,
			isset( $row['order_id'] ) ? (int) $row['order_id'] : null,
			(string) $row['country_code'],
			(string) $row['vat_number'],
			ValidationStatus::tryFrom( (string) $row['status'] ) ?? ValidationStatus::UNAVAILABLE,
			isset( $row['reason_code'] ) ? (string) $row['reason_code'] : null,
			(string) $row['service'],
			isset( $row['consultation_number'] ) ? (string) $row['consultation_number'] : null,
			isset( $row['requester_vat_id'] ) ? (string) $row['requester_vat_id'] : null,
			isset( $row['response_name'] ) ? (string) $row['response_name'] : null,
			isset( $row['response_address'] ) ? (string) $row['response_address'] : null,
			isset( $row['decided_by'] ) ? (int) $row['decided_by'] : null,
			isset( $row['decision_note'] ) ? (string) $row['decision_note'] : null,
			(int) $row['attempts'],
			new DateTimeImmutable( (string) $row['checked_at'], $utc ),
			isset( $row['expires_at'] ) ? new DateTimeImmutable( (string) $row['expires_at'], $utc ) : null
		);
	}

	/**
	 * ISO country for a VAT prefix (helper for callers holding only a prefix).
	 *
	 * @param string $prefix Prefix.
	 */
	public static function country_for_prefix( string $prefix ): string {
		return CountryRegistry::prefix_to_iso( $prefix );
	}
}

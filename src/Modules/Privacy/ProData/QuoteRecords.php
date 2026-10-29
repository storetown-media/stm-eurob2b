<?php
/**
 * Stored quote requests and offers, for the privacy handling.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use DateTimeImmutable;
use DateTimeZone;
use EuroB2B\Infrastructure\Schema;
use EuroB2B\Modules\Registration\FieldDefinition;

/**
 * Quote requests and offers of the shared schema (tables `eurob2b_quotes` and `eurob2b_quote_items`,
 * migrations 5, 6, 9 and 12) and the quote basket in user meta (D-06), read and changed for the
 * privacy export and erasure, user deletion and the retention jobs. EuroB2B Pro writes them; this
 * part of the core handles them also while Pro is inactive or deleted (PLT-015). Pro uses the same
 * keys: its classes alias the constants and labels below. Request fields hold no files (the quote
 * fields offer no file type), so nothing lies in the private file storage.
 */
final class QuoteRecords {

	/**
	 * Tables (without the prefix).
	 */
	public const TABLE_QUOTES = 'eurob2b_quotes';
	public const TABLE_ITEMS  = 'eurob2b_quote_items';

	/**
	 * User meta: the lines a customer collects before sending a request (JSON).
	 */
	public const META_BASKET = '_eurob2b_quote_basket';

	/**
	 * Types.
	 */
	public const TYPE_REQUEST = 'request';
	public const TYPE_OFFER   = 'offer';

	/**
	 * Statuses of a request.
	 */
	public const STATUS_OPEN     = 'open';
	public const STATUS_ANSWERED = 'answered';
	public const STATUS_OFFERED  = 'offered';
	public const STATUS_CLOSED   = 'closed';

	/**
	 * Statuses of an offer.
	 */
	public const STATUS_DRAFT    = 'draft';
	public const STATUS_SENT     = 'sent';
	public const STATUS_ACCEPTED = 'accepted';
	public const STATUS_EXPIRED  = 'expired';
	public const STATUS_DECLINED = 'declined';

	/**
	 * Statuses per type (id => label).
	 *
	 * @param string $type request or offer.
	 * @return array<string, string>
	 */
	public static function statuses( string $type ): array {
		if ( self::TYPE_OFFER === $type ) {
			return array(
				self::STATUS_DRAFT    => __( 'Draft', 'eurob2b' ),
				self::STATUS_SENT     => __( 'Sent', 'eurob2b' ),
				self::STATUS_ACCEPTED => __( 'Accepted', 'eurob2b' ),
				self::STATUS_EXPIRED  => __( 'Expired', 'eurob2b' ),
				self::STATUS_DECLINED => __( 'Declined', 'eurob2b' ),
			);
		}
		return array(
			self::STATUS_OPEN     => __( 'Open', 'eurob2b' ),
			self::STATUS_ANSWERED => __( 'Answered', 'eurob2b' ),
			self::STATUS_OFFERED  => __( 'Offer sent', 'eurob2b' ),
			self::STATUS_CLOSED   => __( 'Closed', 'eurob2b' ),
		);
	}

	/**
	 * Label of the status of a stored quote (a sent offer whose expiry has passed shows as expired).
	 *
	 * @param array<string, mixed> $quote Quote row.
	 */
	public static function status_label( array $quote ): string {
		$type   = (string) $quote['type'];
		$status = (string) $quote['status'];
		if ( self::TYPE_OFFER === $type && self::STATUS_SENT === $status && self::is_expired( $quote ) ) {
			return self::statuses( $type )[ self::STATUS_EXPIRED ];
		}
		return self::statuses( $type )[ $status ] ?? $status;
	}

	/**
	 * Whether the expiry of a stored quote has passed.
	 *
	 * @param array<string, mixed> $quote Quote row.
	 */
	private static function is_expired( array $quote ): bool {
		$raw = isset( $quote['expires_at'] ) ? (string) $quote['expires_at'] : '';
		if ( '' === $raw ) {
			return false;
		}
		$expires = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $raw, new DateTimeZone( 'UTC' ) );
		return false !== $expires && $expires < new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Whether a stored quote is a request sent without a customer account that no customer was assigned to.
	 *
	 * @param array<string, mixed> $quote Quote row.
	 */
	public static function is_guest( array $quote ): bool {
		return self::TYPE_OFFER !== (string) $quote['type'] && (int) $quote['user_id'] <= 0 && '' !== (string) ( $quote['guest_email'] ?? '' );
	}

	/**
	 * Stored field values (JSON) as a clean list; anything malformed is dropped.
	 *
	 * @param mixed $raw Column value.
	 * @return array<int, array{key: string, label: string, type: string, value: string}>
	 */
	public static function decode_fields( $raw ): array {
		$list = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['key'], $entry['label'], $entry['value'] ) && is_scalar( $entry['value'] ) ) {
				$out[] = array(
					'key'   => (string) $entry['key'],
					'label' => (string) $entry['label'],
					'type'  => (string) ( $entry['type'] ?? 'text' ),
					'value' => (string) $entry['value'],
				);
			}
		}
		return $out;
	}

	/**
	 * A stored field value as shown without its definition (a checkbox as Yes/No).
	 *
	 * @param array{key: string, label: string, type: string, value: string} $entry Stored value.
	 */
	public static function field_display( array $entry ): string {
		if ( FieldDefinition::TYPE_CHECKBOX === $entry['type'] ) {
			return '1' === $entry['value'] ? __( 'Yes', 'eurob2b' ) : __( 'No', 'eurob2b' );
		}
		return $entry['value'];
	}

	/**
	 * All quotes of a user, newest first.
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;
		$quotes = Schema::table( self::TABLE_QUOTES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$quotes} WHERE user_id = %d ORDER BY created_at DESC, id DESC", $user_id ), ARRAY_A );
	}

	/**
	 * Requests sent without a customer account from an e-mail address, newest first.
	 *
	 * @param string $email E-mail address.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_guest_email( string $email ): array {
		global $wpdb;
		$email = trim( $email );
		if ( '' === $email ) {
			return array();
		}
		$quotes = Schema::table( self::TABLE_QUOTES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$quotes} WHERE user_id = %d AND guest_email = %s AND type = %s ORDER BY created_at DESC, id DESC", 0, $email, self::TYPE_REQUEST ), ARRAY_A );
	}

	/**
	 * Lines of a quote in their order.
	 *
	 * @param int $quote_id Quote id.
	 * @return array<int, array<string, mixed>>
	 */
	public function items( int $quote_id ): array {
		global $wpdb;
		$items = Schema::table( self::TABLE_ITEMS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items} WHERE quote_id = %d ORDER BY sort_order ASC, id ASC", $quote_id ), ARRAY_A );
	}

	/**
	 * Erasure of a user (privacy tools, user deletion): quotes no order references are deleted; a
	 * quote accepted into an order, and a request whose offer was accepted, keeps its lines as the
	 * commercial record of the order and loses the user id, the message, the field values and the
	 * title. The quote basket goes too.
	 *
	 * @param int $user_id User.
	 * @return array{deleted: int, anonymised: int}
	 */
	public function erase_user( int $user_id ): array {
		$deleted    = 0;
		$anonymised = 0;
		foreach ( $this->for_user( $user_id ) as $quote ) {
			$id = (int) $quote['id'];
			if ( (int) $quote['accepted_order_id'] > 0 || ( self::TYPE_OFFER !== (string) $quote['type'] && $this->has_accepted_child( $id ) ) ) {
				$this->anonymise( $id );
				++$anonymised;
			} else {
				$this->delete( $id );
				++$deleted;
			}
		}
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::META_BASKET );
		}
		return array(
			'deleted'    => $deleted,
			'anonymised' => $anonymised,
		);
	}

	/**
	 * Deletes the requests sent without a customer account from an e-mail address.
	 *
	 * @param string $email E-mail address.
	 * @return int Deleted requests.
	 */
	public function erase_guest( string $email ): int {
		$deleted = 0;
		foreach ( $this->for_guest_email( $email ) as $quote ) {
			$this->delete( (int) $quote['id'] );
			++$deleted;
		}
		return $deleted;
	}

	/**
	 * Quotes the retention job may delete (QUO-014): last changed before $cutoff, not accepted into
	 * an order, and not the request of an offer that was accepted or changed after $cutoff. Requests
	 * from guests that no customer was assigned to follow their own period (stale_guest_ids()).
	 * Lowest id first.
	 *
	 * @param string $cutoff UTC time "Y-m-d H:i:s".
	 * @param int    $limit  Rows at most.
	 * @return int[]
	 */
	public function stale_ids( string $cutoff, int $limit ): array {
		global $wpdb;
		$quotes = Schema::table( self::TABLE_QUOTES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name comes from Schema.
				"SELECT q.id FROM {$quotes} q WHERE q.updated_at < %s AND q.accepted_order_id = 0 AND NOT ( q.type = 'request' AND q.user_id = 0 AND q.guest_email <> '' ) AND NOT EXISTS ( SELECT 1 FROM {$quotes} c WHERE c.parent_id = q.id AND ( c.accepted_order_id > 0 OR c.updated_at >= %s ) ) ORDER BY q.id ASC LIMIT %d",
				$cutoff,
				$cutoff,
				max( 1, $limit )
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Guest requests the guest retention job may delete: last changed before $cutoff and still
	 * without a customer. Lowest id first.
	 *
	 * @param string $cutoff UTC time "Y-m-d H:i:s".
	 * @param int    $limit  Rows at most.
	 * @return int[]
	 */
	public function stale_guest_ids( string $cutoff, int $limit ): array {
		global $wpdb;
		$quotes = Schema::table( self::TABLE_QUOTES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$quotes} WHERE type = %s AND user_id = 0 AND guest_email <> '' AND updated_at < %s ORDER BY id ASC LIMIT %d", self::TYPE_REQUEST, $cutoff, max( 1, $limit ) ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Deletes a quote and its lines.
	 *
	 * @param int $id Quote id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( self::TABLE_ITEMS ), array( 'quote_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( self::TABLE_QUOTES ), array( 'id' => $id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Whether an offer created from the request was accepted.
	 *
	 * @param int $request_id Request id.
	 */
	private function has_accepted_child( int $request_id ): bool {
		global $wpdb;
		$quotes = Schema::table( self::TABLE_QUOTES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$quotes} WHERE parent_id = %d AND type = %s AND accepted_order_id > 0", $request_id, self::TYPE_OFFER ) ) > 0;
	}

	/**
	 * Removes the personal data of a quote an order references; its lines stay.
	 *
	 * @param int $id Quote id.
	 */
	private function anonymise( int $id ): void {
		global $wpdb;
		$table = Schema::table( self::TABLE_QUOTES );
		// The field values are written as NULL, like an empty list.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET user_id = 0, message = '', field_values = NULL, title = %s, updated_at = %s WHERE id = %d", mb_substr( __( 'Anonymised', 'eurob2b' ), 0, 190 ), ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' ), $id ) );
	}
}

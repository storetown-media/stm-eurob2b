<?php
/**
 * Stored conversations, for the privacy handling.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use EuroB2B\Infrastructure\Schema;

/**
 * Conversations and messages of the shared schema (tables `eurob2b_conversations` and
 * `eurob2b_messages`, migration 7; D-07), read and changed for the privacy export and erasure, user
 * deletion and the retention job. EuroB2B Pro writes them; this part of the core handles them also
 * while Pro is inactive or deleted (PLT-015). Pro uses the same keys: its classes alias the
 * constants and labels below. Conversations have no attachments.
 */
final class ConversationRecords {

	/**
	 * Tables (without the prefix).
	 */
	public const TABLE_CONVERSATIONS = 'eurob2b_conversations';
	public const TABLE_MESSAGES      = 'eurob2b_messages';

	/**
	 * Types.
	 */
	public const TYPE_MESSAGE = 'message';
	public const TYPE_INQUIRY = 'inquiry';

	/**
	 * Statuses.
	 */
	public const STATUS_OPEN     = 'open';
	public const STATUS_ANSWERED = 'answered';
	public const STATUS_CLOSED   = 'closed';

	/**
	 * Sides of a conversation.
	 */
	public const ROLE_CUSTOMER = 'customer';
	public const ROLE_STAFF    = 'staff';

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			self::STATUS_OPEN     => __( 'Waiting for the shop', 'eurob2b' ),
			self::STATUS_ANSWERED => __( 'Answered', 'eurob2b' ),
			self::STATUS_CLOSED   => __( 'Closed', 'eurob2b' ),
		);
	}

	/**
	 * Type labels.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			self::TYPE_MESSAGE => __( 'Message', 'eurob2b' ),
			self::TYPE_INQUIRY => __( 'Inquiry', 'eurob2b' ),
		);
	}

	/**
	 * Name of a message's author as the customer sees it: the shop for staff messages.
	 *
	 * @param array<string, mixed> $message Message row.
	 */
	public static function author_name( array $message ): string {
		if ( self::ROLE_STAFF === (string) $message['author_role'] ) {
			return (string) get_bloginfo( 'name' );
		}
		$author = (int) $message['author_id'];
		$user   = $author > 0 ? get_userdata( $author ) : false;
		return $user ? $user->display_name : __( 'Deleted user', 'eurob2b' );
	}

	/**
	 * One conversation row, or null.
	 *
	 * @param int $id Conversation id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( self::TABLE_CONVERSATIONS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Messages of a conversation, oldest first.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return array<int, array<string, mixed>>
	 */
	public function messages_of( int $conversation_id ): array {
		global $wpdb;
		$table = Schema::table( self::TABLE_MESSAGES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY created_at ASC, id ASC", $conversation_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Ids of the conversations a user owns.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public function owned_ids( int $user_id ): array {
		global $wpdb;
		$table = Schema::table( self::TABLE_CONVERSATIONS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) ) );
	}

	/**
	 * Messages a user wrote in conversations they do not own (company conversations, or as staff).
	 *
	 * @param int $user_id User id.
	 * @return array<int, array<string, mixed>>
	 */
	public function messages_elsewhere( int $user_id ): array {
		global $wpdb;
		$messages      = Schema::table( self::TABLE_MESSAGES );
		$conversations = Schema::table( self::TABLE_CONVERSATIONS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.* FROM {$messages} m JOIN {$conversations} c ON c.id = m.conversation_id WHERE m.author_id = %d AND c.user_id <> %d ORDER BY m.id ASC", $user_id, $user_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Erasure of one person (privacy tools, user deletion): their own conversations are deleted; their
	 * customer messages in other conversations are deleted; messages they wrote as staff and
	 * conversations they started for a customer lose the user id (the customer's record stays
	 * readable). A conversation whose last message was one of the deleted ones takes its last message,
	 * author side and status from the message that is now the last one (so it is not shown as unread
	 * or open, and retention counts from that message); one with no message left is deleted.
	 *
	 * @param int $user_id User id.
	 * @return array{conversations: int, messages: int, anonymised: int}
	 */
	public function erase_user( int $user_id ): array {
		global $wpdb;
		$owned = $this->owned_ids( $user_id );
		foreach ( $owned as $id ) {
			$this->delete( $id );
		}
		$messages      = Schema::table( self::TABLE_MESSAGES );
		$conversations = Schema::table( self::TABLE_CONVERSATIONS );
		$stale         = $this->last_written_by( $user_id, self::ROLE_CUSTOMER );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted     = (int) $wpdb->delete(
			$messages,
			array(
				'author_id'   => $user_id,
				'author_role' => self::ROLE_CUSTOMER,
			),
			array( '%d', '%s' )
		);
		$anonymised  = (int) $wpdb->update( $messages, array( 'author_id' => 0 ), array( 'author_id' => $user_id ), array( '%d' ), array( '%d' ) );
		$anonymised += (int) $wpdb->update( $conversations, array( 'created_by' => 0 ), array( 'created_by' => $user_id ), array( '%d' ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$emptied = 0;
		foreach ( $stale as $id ) {
			$emptied += $this->follow_last_message( $id ) ? 0 : 1;
		}
		return array(
			'conversations' => count( $owned ) + $emptied,
			'messages'      => $deleted,
			'anonymised'    => $anonymised,
		);
	}

	/**
	 * Conversations whose last message is older than the cutoff (retention), lowest id first.
	 *
	 * @param string $cutoff UTC timestamp.
	 * @param int    $limit  Maximum ids.
	 * @return int[]
	 */
	public function stale_ids( string $cutoff, int $limit ): array {
		global $wpdb;
		$table = Schema::table( self::TABLE_CONVERSATIONS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE last_message_at < %s ORDER BY id ASC LIMIT %d", $cutoff, max( 1, $limit ) ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Deletes a conversation with its messages.
	 *
	 * @param int $id Conversation id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( self::TABLE_MESSAGES ), array( 'conversation_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( self::TABLE_CONVERSATIONS ), array( 'id' => $id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Conversations whose last message a user wrote on the given side.
	 *
	 * @param int    $user_id User id.
	 * @param string $role    Side.
	 * @return int[]
	 */
	private function last_written_by( int $user_id, string $role ): array {
		global $wpdb;
		$table = Schema::table( self::TABLE_MESSAGES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT conversation_id FROM {$table} WHERE author_id = %d AND author_role = %s", $user_id, $role ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$last = $this->last_message( $id );
			if ( null !== $last && (int) $last['author_id'] === $user_id && (string) $last['author_role'] === $role ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * The last message of a conversation, or null.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return array<string, mixed>|null
	 */
	private function last_message( int $conversation_id ): ?array {
		global $wpdb;
		$table = Schema::table( self::TABLE_MESSAGES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY created_at DESC, id DESC LIMIT 1", $conversation_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Takes last message time, author side and status from the conversation's last message, as a new
	 * message sets them (a closed conversation stays closed; the author side has read its own
	 * message, the other side's read time is kept). Deletes the conversation when no message is left.
	 *
	 * @param int $id Conversation id.
	 * @return bool False when the conversation was deleted.
	 */
	private function follow_last_message( int $id ): bool {
		global $wpdb;
		$conversation = $this->find( $id );
		if ( null === $conversation ) {
			return true;
		}
		$last = $this->last_message( $id );
		if ( null === $last ) {
			$this->delete( $id );
			return false;
		}
		$staff  = self::ROLE_STAFF === (string) $last['author_role'];
		$closed = self::STATUS_CLOSED === (string) $conversation['status'];
		$data   = array(
			'last_message_at'  => (string) $last['created_at'],
			'last_author_role' => (string) $last['author_role'],
			'status'           => $closed ? self::STATUS_CLOSED : ( $staff ? self::STATUS_ANSWERED : self::STATUS_OPEN ),
		);
		$read   = $staff ? 'staff_read_at' : 'customer_read_at';
		if ( null === $conversation[ $read ] ) {
			$data[ $read ] = (string) $last['created_at'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( self::TABLE_CONVERSATIONS ), $data, array( 'id' => $id ), array_fill( 0, count( $data ), '%s' ), array( '%d' ) );
		return true;
	}
}

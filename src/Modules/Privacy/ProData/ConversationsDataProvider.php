<?php
/**
 * Privacy provider for conversations.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;

/**
 * Exports a person's conversations (subject, type, status, order, times, read times per side, who
 * started it) with all their messages, and the messages they wrote in other conversations (D-07);
 * erases as `ConversationRecords::erase_user()` describes. Part of the core: it reads the shared
 * tables, also while EuroB2B Pro, which records the conversations, is inactive (PLT-015).
 */
final class ConversationsDataProvider implements PersonalDataProviderInterface {

	/**
	 * Stored conversations.
	 *
	 * @var ConversationRecords
	 */
	private ConversationRecords $records;

	/**
	 * Constructor.
	 *
	 * @param ConversationRecords $records Stored conversations.
	 */
	public function __construct( ConversationRecords $records ) {
		$this->records = $records;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'conversations';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B conversations', 'eurob2b' );
	}

	/**
	 * Export callback: one item per conversation, one per message written elsewhere.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		$data = array();
		if ( $user ) {
			foreach ( $this->records->owned_ids( $user->ID ) as $id ) {
				$conversation = $this->records->find( $id );
				if ( null === $conversation ) {
					continue;
				}
				$lines = array();
				foreach ( $this->records->messages_of( $id ) as $message ) {
					$lines[] = (string) $message['created_at'] . ' UTC, ' . ConversationRecords::author_name( $message ) . ":\n" . (string) $message['body'];
				}
				$type   = (string) $conversation['type'];
				$status = (string) $conversation['status'];
				$fields = array(
					array(
						'name'  => __( 'Subject', 'eurob2b' ),
						'value' => (string) $conversation['subject'],
					),
					array(
						'name'  => __( 'Type', 'eurob2b' ),
						'value' => ConversationRecords::type_labels()[ $type ] ?? $type,
					),
					array(
						'name'  => __( 'Status', 'eurob2b' ),
						'value' => ConversationRecords::status_labels()[ $status ] ?? $status,
					),
					array(
						'name'  => __( 'Created', 'eurob2b' ),
						'value' => (string) $conversation['created_at'] . ' UTC',
					),
					array(
						'name'  => __( 'Last message', 'eurob2b' ),
						'value' => (string) $conversation['last_message_at'] . ' UTC',
					),
					array(
						'name'  => __( 'Last read by the customer side', 'eurob2b' ),
						'value' => null !== $conversation['customer_read_at'] ? (string) $conversation['customer_read_at'] . ' UTC' : '—',
					),
					array(
						'name'  => __( 'Last read by the shop', 'eurob2b' ),
						'value' => null !== $conversation['staff_read_at'] ? (string) $conversation['staff_read_at'] . ' UTC' : '—',
					),
					array(
						'name'  => __( 'Started by', 'eurob2b' ),
						'value' => (int) $conversation['created_by'] === (int) $conversation['user_id'] ? __( 'You', 'eurob2b' ) : (string) get_bloginfo( 'name' ),
					),
					array(
						'name'  => __( 'Messages', 'eurob2b' ),
						'value' => implode( "\n\n", $lines ),
					),
				);
				if ( (int) $conversation['order_id'] > 0 ) {
					$fields[] = array(
						'name'  => _x( 'Order', 'shop order', 'eurob2b' ),
						'value' => (string) (int) $conversation['order_id'],
					);
				}
				$data[] = array(
					'group_id'    => 'eurob2b-conversations',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-conversation-' . (int) $conversation['id'],
					'data'        => $fields,
				);
			}//end foreach
			foreach ( $this->records->messages_elsewhere( $user->ID ) as $message ) {
				$data[] = array(
					'group_id'    => 'eurob2b-conversations',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-message-' . (int) $message['id'],
					'data'        => array(
						array(
							'name'  => __( 'Message', 'eurob2b' ),
							'value' => (string) $message['body'],
						),
						array(
							'name'  => __( 'Created', 'eurob2b' ),
							'value' => (string) $message['created_at'] . ' UTC',
						),
					),
				);
			}
		}//end if
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user   = get_user_by( 'email', $email_address );
		$result = $user ? $this->records->erase_user( $user->ID ) : array(
			'conversations' => 0,
			'messages'      => 0,
			'anonymised'    => 0,
		);
		return array(
			'items_removed'  => $result['conversations'] > 0 || $result['messages'] > 0 || $result['anonymised'] > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

<?php
/**
 * Personal data provider for the import journal.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;

/**
 * Exports and erases a person's rows of the import journal (inventory D-22): the values an import
 * replaced on the person's account (classification, registration field values, method lists).
 * After erasure an undo leaves that account as the import left it.
 */
final class MigrationDataProvider implements PersonalDataProviderInterface {

	/**
	 * Journal.
	 *
	 * @var Journal
	 */
	private Journal $journal;

	/**
	 * Constructor.
	 *
	 * @param Journal $journal Journal.
	 */
	public function __construct( Journal $journal ) {
		$this->journal = $journal;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'migration';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B import journal', 'eurob2b' );
	}

	/**
	 * Export callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		$data = array();
		if ( $user ) {
			foreach ( $this->journal->for_user( $user->ID ) as $i => $row ) {
				$data[] = array(
					'group_id'    => 'eurob2b-migration',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-migration-' . $user->ID . '-' . $i,
					'data'        => array(
						array(
							'name'  => __( 'Imported on', 'eurob2b' ),
							'value' => $row['created_at'] . ' UTC',
						),
						array(
							'name'  => __( 'Value', 'eurob2b' ),
							'value' => self::describe( $row ),
						),
						array(
							'name'  => __( 'Before the import', 'eurob2b' ),
							'value' => null === $row['old_value'] ? __( 'none', 'eurob2b' ) : $row['old_value'],
						),
					),
				);
			}//end foreach
		}//end if
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * What a journal row is about.
	 *
	 * @param array{object_type: string, object_id: int, ref: string} $row Row.
	 */
	private static function describe( array $row ): string {
		switch ( $row['object_type'] ) {
			case Journal::TYPE_CUSTOMER:
				return __( 'Customer type, approval status and group', 'eurob2b' );
			case Journal::TYPE_MEMBER:
				/* translators: %s: company id */
				return sprintf( __( 'Member of company #%s', 'eurob2b' ), $row['ref'] );
			case Journal::TYPE_POST_META:
				/* translators: 1: product id, 2: meta key */
				return sprintf( __( 'Product #%1$d: %2$s', 'eurob2b' ), $row['object_id'], $row['ref'] );
			case Journal::TYPE_TERM_META:
				/* translators: 1: category id, 2: meta key */
				return sprintf( __( 'Category #%1$d: %2$s', 'eurob2b' ), $row['object_id'], $row['ref'] );
			case Journal::TYPE_RULE:
				/* translators: %d: rule id */
				return sprintf( __( 'Rule #%d', 'eurob2b' ), $row['object_id'] );
		}
		return $row['ref'];
	}

	/**
	 * Erase callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		return array(
			'items_removed'  => $user && $this->journal->forget_user( $user->ID ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

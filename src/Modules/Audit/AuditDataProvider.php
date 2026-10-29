<?php
/**
 * Privacy provider for the audit log.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Audit;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;

/**
 * Exports the audit log entries of a person and removes the person from them on erasure (D-20).
 *
 * The export names neither the other person of an entry (the deciding staff member or approver for
 * a decision about the person; the concerned customer for a decision the person made) nor values
 * about someone else. On erasure the entries stay without the reference to the person (product
 * owner decision of 2026-10-24): what was decided remains traceable, by and about whom does not.
 */
final class AuditDataProvider implements PersonalDataProviderInterface {

	/**
	 * Log.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Labels.
	 *
	 * @var AuditFormatter
	 */
	private AuditFormatter $format;

	/**
	 * Constructor.
	 *
	 * @param AuditLog       $log    Log.
	 * @param AuditFormatter $format Labels.
	 */
	public function __construct( AuditLog $log, AuditFormatter $format ) {
		$this->log    = $log;
		$this->format = $format;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'audit_log';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B audit log', 'eurob2b' );
	}

	/**
	 * Export callback: one item per entry.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		$data = array();
		foreach ( $user ? $this->log->for_user( $user->ID ) : array() as $entry ) {
			$concerned = $entry->subject_id === $user->ID;
			$rows      = array(
				array(
					'name'  => __( 'Time (UTC)', 'eurob2b' ),
					'value' => $entry->created_at,
				),
				array(
					'name'  => __( 'Action', 'eurob2b' ),
					'value' => $this->format->action( $entry->action ),
				),
				array(
					'name'  => __( 'Your role', 'eurob2b' ),
					'value' => $concerned ? __( 'Concerned by the decision', 'eurob2b' ) : __( 'Made the decision', 'eurob2b' ),
				),
			);
			if ( $concerned ) {
				$change = $this->format->change( $entry );
				if ( '' !== $change ) {
					$rows[] = array(
						'name'  => __( 'Change', 'eurob2b' ),
						'value' => $change,
					);
				}
			}
			if ( AuditLog::OBJECT_ORDER === $entry->object_type && $entry->object_id > 0 ) {
				$rows[] = array(
					'name'  => __( 'Order', 'eurob2b' ),
					'value' => '#' . $entry->object_id,
				);
			}
			$data[] = array(
				'group_id'    => 'eurob2b-audit-log',
				'group_label' => $this->label(),
				'item_id'     => 'eurob2b-audit-' . $entry->id,
				'data'        => $rows,
			);
		}//end foreach
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase callback: the person's references are removed, the entries stay without them.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email_address );
		$changed = $user ? $this->log->forget_user( $user->ID ) : 0;
		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => false,
			'messages'       => $changed > 0 ? array( __( 'EuroB2B audit log: the entries stay without the reference to this person, so the decisions remain traceable.', 'eurob2b' ) ) : array(),
			'done'           => true,
		);
	}
}

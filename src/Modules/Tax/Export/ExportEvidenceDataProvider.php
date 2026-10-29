<?php
/**
 * Privacy export and erasure for the export evidence.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Export;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;

/**
 * D-29: the export lists, per order of the person (customer id or billing e-mail address), the status,
 * type, MRN and date of exit, the reason of a "not needed" and the document metadata. An erasure
 * request removes nothing while the retention period runs (tax evidence, Art. 17(3)(b) GDPR) and
 * reports the evidence as retained, like the proof of supply (D-23) and the purchase order documents
 * (D-25). Part of the core: it reads the stored evidence through `ExportEvidenceRecords`, also while
 * EuroB2B Pro, which records it, is inactive (PLT-015).
 */
final class ExportEvidenceDataProvider implements PersonalDataProviderInterface {

	/**
	 * Orders per page.
	 */
	private const PER_PAGE = 50;

	/**
	 * Stored export evidence.
	 *
	 * @var ExportEvidenceRecords
	 */
	private ExportEvidenceRecords $evidence;

	/**
	 * Constructor.
	 *
	 * @param ExportEvidenceRecords $evidence Stored export evidence.
	 */
	public function __construct( ExportEvidenceRecords $evidence ) {
		$this->evidence = $evidence;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'export_evidence';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B export evidence', 'eurob2b' );
	}

	/**
	 * Export.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user   = get_user_by( 'email', $email_address );
		$orders = $this->evidence->for_person( $email_address, $user ? (int) $user->ID : 0, $page, self::PER_PAGE );
		$groups = array();
		foreach ( $orders as $order ) {
			$state = ExportEvidenceRecords::state( $order );
			$items = array(
				array(
					'name'  => _x( 'Order', 'shop order', 'eurob2b' ),
					'value' => $order->get_order_number(),
				),
				array(
					'name'  => __( 'Export evidence', 'eurob2b' ),
					'value' => $state->status()->label(),
				),
			);
			if ( null !== $state->details ) {
				$type    = ExportEvidenceType::tryFrom( $state->details['type'] ) ?? ExportEvidenceType::OTHER;
				$items[] = array(
					'name'  => __( 'Type of evidence', 'eurob2b' ),
					'value' => $type->label(),
				);
				if ( '' !== $state->details['mrn'] ) {
					$items[] = array(
						'name'  => __( 'MRN', 'eurob2b' ),
						'value' => $state->details['mrn'],
					);
				}
				$items[] = array(
					'name'  => __( 'Date of exit', 'eurob2b' ),
					'value' => $state->details['exit_date'],
				);
			}
			if ( null !== $state->not_needed ) {
				$items[] = array(
					'name'  => __( 'Reason why no evidence is needed', 'eurob2b' ),
					'value' => $state->not_needed['reason'],
				);
			}
			foreach ( $state->documents as $document ) {
				$file    = $this->evidence->document_file( $order, $document['file_id'] );
				$type    = ExportEvidenceType::tryFrom( $document['type'] ) ?? ExportEvidenceType::OTHER;
				$items[] = array(
					'name'  => __( 'Document', 'eurob2b' ),
					'value' => $type->label() . ( null !== $file ? ': ' . $file['original_name'] . ' (' . size_format( $file['size'] ) . ')' : '' ) . ', ' . $document['uploaded_at'] . ' UTC',
				);
			}
			$groups[] = array(
				'group_id'    => 'eurob2b-export-evidence',
				'group_label' => __( 'Export evidence', 'eurob2b' ),
				'item_id'     => 'eurob2b-export-' . $order->get_id(),
				'data'        => $items,
			);
		}//end foreach
		return array(
			'data' => $groups,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * Erasure: nothing is deleted while the retention period runs; the evidence is reported as retained.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user     = get_user_by( 'email', $email_address );
		$orders   = $this->evidence->for_person( $email_address, $user ? (int) $user->ID : 0, $page, self::PER_PAGE );
		$retained = count( $orders );
		$messages = array();
		if ( $retained > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of orders */
				_n( 'The export evidence of %d order (details and documents) was kept as tax evidence until its retention period ends (Art. 17(3)(b) GDPR).', 'The export evidence of %d orders (details and documents) was kept as tax evidence until its retention period ends (Art. 17(3)(b) GDPR).', $retained, 'eurob2b' ),
				$retained
			);
		}
		return array(
			'items_removed'  => false,
			'items_retained' => $retained > 0,
			'messages'       => $messages,
			'done'           => $retained < self::PER_PAGE,
		);
	}
}

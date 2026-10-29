<?php
/**
 * Privacy export and erasure for the proof of supply.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Proof;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;

/**
 * D-23: the export lists, per order of the person (customer id or billing e-mail address), the proof
 * status, the confirmation snapshot and the document metadata. An erasure request removes nothing
 * while the retention period runs (tax evidence, Art. 17(3)(b) GDPR) and reports the proofs as
 * retained, like the order-linked VAT evidence of the tax provider. Part of the core: it reads the
 * stored proofs through `ProofRecords`, also while EuroB2B Pro, which records them, is inactive.
 */
final class ProofDataProvider implements PersonalDataProviderInterface {

	/**
	 * Orders per page.
	 */
	private const PER_PAGE = 50;

	/**
	 * Stored proofs.
	 *
	 * @var ProofRecords
	 */
	private ProofRecords $proof;

	/**
	 * Constructor.
	 *
	 * @param ProofRecords $proof Stored proofs.
	 */
	public function __construct( ProofRecords $proof ) {
		$this->proof = $proof;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'proof_of_supply';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B proof of intra-Community supply', 'eurob2b' );
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
		$orders = $this->proof->for_person( $email_address, $user ? (int) $user->ID : 0, $page, self::PER_PAGE );
		$groups = array();
		foreach ( $orders as $order ) {
			$state    = ProofRecords::state( $order );
			$items    = array(
				array(
					'name'  => _x( 'Order', 'shop order', 'eurob2b' ),
					'value' => $order->get_order_number(),
				),
				array(
					'name'  => __( 'Proof of supply', 'eurob2b' ),
					'value' => $state->status()->label(),
				),
			);
			$snapshot = $state->confirmation;
			if ( null !== $snapshot ) {
				$buyer = is_array( $snapshot['buyer'] ?? null ) ? $snapshot['buyer'] : array();
				$by    = is_array( $snapshot['confirmed_by'] ?? null ) ? $snapshot['confirmed_by'] : array();
				$goods = array();
				foreach ( is_array( $snapshot['goods'] ?? null ) ? $snapshot['goods'] : array() as $line ) {
					$goods[] = (string) ( $line['quantity'] ?? '' ) . ' × ' . (string) ( $line['name'] ?? '' );
				}
				foreach ( array(
					__( 'Customer in the confirmation', 'eurob2b' ) => implode( ', ', array_filter( array_map( 'strval', $buyer ) ) ),
					__( 'Goods', 'eurob2b' )              => implode( '; ', $goods ),
					__( 'Place of receipt', 'eurob2b' )   => ProofRecords::place_label( (string) ( $snapshot['place'] ?? '' ), (string) ( $snapshot['country'] ?? '' ), (string) ( $snapshot['country_name'] ?? '' ) ),
					__( 'Month of receipt', 'eurob2b' )   => (string) ( $snapshot['month_label'] ?? '' ),
					__( 'Confirmed at (UTC)', 'eurob2b' ) => (string) ( $snapshot['issued_at'] ?? '' ),
					__( 'Confirmed by', 'eurob2b' )       => (string) ( $by['name'] ?? '' ),
					__( 'Statement', 'eurob2b' )          => (string) ( $snapshot['statement'] ?? '' ),
				) as $name => $value ) {
					$items[] = array(
						'name'  => $name,
						'value' => $value,
					);
				}
			}//end if
			foreach ( $state->documents as $document ) {
				$file    = $this->proof->document_file( $order, $document['file_id'] );
				$type    = DocumentType::tryFrom( $document['type'] ) ?? DocumentType::OTHER;
				$items[] = array(
					'name'  => __( 'Document', 'eurob2b' ),
					'value' => $type->label() . ( null !== $file ? ': ' . $file['original_name'] . ' (' . size_format( $file['size'] ) . ')' : '' ) . ', ' . $document['uploaded_at'] . ' UTC',
				);
			}
			$groups[] = array(
				'group_id'    => 'eurob2b-proof-of-supply',
				'group_label' => __( 'Proof of intra-Community supply', 'eurob2b' ),
				'item_id'     => 'eurob2b-proof-' . $order->get_id(),
				'data'        => $items,
			);
		}//end foreach
		return array(
			'data' => $groups,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * Erasure: nothing is deleted while the retention period runs; the proofs are reported as retained.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user     = get_user_by( 'email', $email_address );
		$orders   = $this->proof->for_person( $email_address, $user ? (int) $user->ID : 0, $page, self::PER_PAGE );
		$retained = count( $orders );
		$messages = array();
		if ( $retained > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of orders */
				_n( 'The proof of supply of %d order (confirmation of receipt and documents) was kept as tax evidence until its retention period ends (Art. 17(3)(b) GDPR).', 'The proofs of supply of %d orders (confirmations of receipt and documents) were kept as tax evidence until their retention period ends (Art. 17(3)(b) GDPR).', $retained, 'eurob2b' ),
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

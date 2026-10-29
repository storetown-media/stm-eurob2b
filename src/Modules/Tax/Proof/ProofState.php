<?php
/**
 * Facts of the proof of one order.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Proof;

/**
 * TAX-030: what is known about the proof of an order (request, the customer's confirmation, the
 * documents, the acceptance) and which steps it allows. Pure: no WordPress calls.
 *
 * Steps: request (`missing`/`requested`/`documents` without a confirmation), the customer's
 * confirmation (once, not after acceptance), upload (until accepted), accept (with a confirmation or
 * a document), reopen (after acceptance). The status follows from the facts: accepted, else documents,
 * else confirmed, else requested, else missing.
 */
final class ProofState {

	/**
	 * Constructor.
	 *
	 * @param string                                                                               $requested_at Time of the last request (UTC, `Y-m-d H:i:s`) or ''.
	 * @param array<string, mixed>|null                                                            $confirmation The customer's confirmation snapshot.
	 * @param array<int, array{file_id: int, type: string, uploaded_by: int, uploaded_at: string}> $documents Documents.
	 * @param string                                                                               $accepted_at  Time of the acceptance or ''.
	 */
	public function __construct(
		public readonly string $requested_at = '',
		public readonly ?array $confirmation = null,
		public readonly array $documents = array(),
		public readonly string $accepted_at = ''
	) {
	}

	/**
	 * State from the stored meta values (JSON for the snapshot and the documents).
	 *
	 * @param string $requested_at      Request time.
	 * @param string $confirmation_json Snapshot.
	 * @param string $documents_json    Documents.
	 * @param string $accepted_at       Acceptance time.
	 */
	public static function from_meta( string $requested_at, string $confirmation_json, string $documents_json, string $accepted_at ): self {
		$confirmation = '' !== $confirmation_json ? json_decode( $confirmation_json, true ) : null;
		$documents    = '' !== $documents_json ? json_decode( $documents_json, true ) : array();
		$list         = array();
		foreach ( is_array( $documents ) ? $documents : array() as $document ) {
			if ( is_array( $document ) && isset( $document['file_id'] ) && (int) $document['file_id'] > 0 ) {
				$list[] = array(
					'file_id'     => (int) $document['file_id'],
					'type'        => (string) ( $document['type'] ?? DocumentType::OTHER->value ),
					'uploaded_by' => (int) ( $document['uploaded_by'] ?? 0 ),
					'uploaded_at' => (string) ( $document['uploaded_at'] ?? '' ),
				);
			}
		}
		return new self( $requested_at, is_array( $confirmation ) ? $confirmation : null, $list, $accepted_at );
	}

	/**
	 * Status.
	 */
	public function status(): ProofStatus {
		if ( '' !== $this->accepted_at ) {
			return ProofStatus::ACCEPTED;
		}
		if ( array() !== $this->documents ) {
			return ProofStatus::DOCUMENTS;
		}
		if ( null !== $this->confirmation ) {
			return ProofStatus::CONFIRMED;
		}
		return '' !== $this->requested_at ? ProofStatus::REQUESTED : ProofStatus::MISSING;
	}

	/**
	 * Whether a confirmation from the customer may still be asked for.
	 */
	public function may_request(): bool {
		return '' === $this->accepted_at && null === $this->confirmation;
	}

	/**
	 * Whether the customer may confirm (once, and not after the acceptance).
	 */
	public function may_confirm(): bool {
		return '' === $this->accepted_at && null === $this->confirmation;
	}

	/**
	 * Whether the shop may add a document.
	 */
	public function may_upload(): bool {
		return '' === $this->accepted_at;
	}

	/**
	 * Whether the evidence may be marked as complete.
	 */
	public function may_accept(): bool {
		return '' === $this->accepted_at && ( null !== $this->confirmation || array() !== $this->documents );
	}

	/**
	 * Whether an acceptance may be taken back.
	 */
	public function may_reopen(): bool {
		return '' !== $this->accepted_at;
	}

	/**
	 * Whether anything was stored for the proof.
	 */
	public function has_entries(): bool {
		return '' !== $this->requested_at || null !== $this->confirmation || array() !== $this->documents || '' !== $this->accepted_at;
	}

	/**
	 * Latest of the order date and the proof's own times (`Y-m-d H:i:s`, UTC); the retention period
	 * runs from the end of its year.
	 *
	 * @param string $order_created Order date (UTC).
	 */
	public function latest_entry( string $order_created ): string {
		$times = array( $order_created, $this->requested_at, $this->accepted_at, (string) ( $this->confirmation['issued_at'] ?? '' ) );
		foreach ( $this->documents as $document ) {
			$times[] = $document['uploaded_at'];
		}
		$times = array_filter( $times, static fn( string $time ): bool => 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time ) );
		return array() !== $times ? max( $times ) : '';
	}

	/**
	 * The later of two retention deadlines (`Y-m-d H:i:s`; '' or a malformed value counts as none).
	 * A stored deadline is only ever extended, never shortened.
	 *
	 * @param string $stored     Deadline stored so far.
	 * @param string $calculated Deadline from the current facts.
	 */
	public static function later_deadline( string $stored, string $calculated ): string {
		$valid = array_filter( array( $stored, $calculated ), static fn( string $time ): bool => 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time ) );
		return array() !== $valid ? max( $valid ) : '';
	}

	/**
	 * End of the retention period: 31 December, 23:59:59, of the year of the latest entry plus the
	 * period (§ 147 (4) AO counts from the end of the calendar year).
	 *
	 * @param string $order_created Order date (UTC).
	 * @param int    $years         Retention period in years.
	 */
	public function retain_until( string $order_created, int $years ): string {
		$latest = $this->latest_entry( $order_created );
		if ( '' === $latest ) {
			return '';
		}
		return sprintf( '%04d-12-31 23:59:59', (int) substr( $latest, 0, 4 ) + max( 1, $years ) );
	}
}

<?php
/**
 * Facts of the export evidence of one order.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Export;

use DateTimeImmutable;
use DateTimeZone;

/**
 * TAX-032: what the shop recorded about the export evidence of an order (type, MRN and date of exit,
 * the documents, the acceptance, or "not needed" with a reason) and which steps it allows. Pure: no
 * WordPress calls.
 *
 * Status: not needed (staff, with a reason), else accepted, else received (details or a document
 * recorded), else missing. Steps: record details and upload while the evidence is open (missing or
 * received); accept when received; "not needed" while open; reopen after an acceptance or a "not
 * needed", which keeps the details and the documents.
 */
final class ExportEvidenceState {

	/**
	 * Longest reason for "not needed".
	 */
	public const REASON_MAX = 200;

	/**
	 * Constructor.
	 *
	 * @param array{type: string, mrn: string, exit_date: string, saved_by: int, saved_at: string}|null $details    Type, MRN and date of exit.
	 * @param array<int, array{file_id: int, type: string, uploaded_by: int, uploaded_at: string}>      $documents  Documents.
	 * @param string                                                                                    $accepted_at Time of the acceptance (UTC) or ''.
	 * @param array{reason: string, by: int, at: string}|null                                           $not_needed Staff decision that no evidence is needed.
	 */
	public function __construct(
		public readonly ?array $details = null,
		public readonly array $documents = array(),
		public readonly string $accepted_at = '',
		public readonly ?array $not_needed = null
	) {
	}

	/**
	 * State from the stored meta values (JSON for details, documents and "not needed").
	 *
	 * @param string $details_json    Details.
	 * @param string $documents_json  Documents.
	 * @param string $accepted_at     Acceptance time.
	 * @param string $not_needed_json "Not needed".
	 */
	public static function from_meta( string $details_json, string $documents_json, string $accepted_at, string $not_needed_json ): self {
		$details    = '' !== $details_json ? json_decode( $details_json, true ) : null;
		$documents  = '' !== $documents_json ? json_decode( $documents_json, true ) : array();
		$not_needed = '' !== $not_needed_json ? json_decode( $not_needed_json, true ) : null;

		$list = array();
		foreach ( is_array( $documents ) ? $documents : array() as $document ) {
			if ( is_array( $document ) && isset( $document['file_id'] ) && (int) $document['file_id'] > 0 ) {
				$list[] = array(
					'file_id'     => (int) $document['file_id'],
					'type'        => (string) ( $document['type'] ?? ExportEvidenceType::OTHER->value ),
					'uploaded_by' => (int) ( $document['uploaded_by'] ?? 0 ),
					'uploaded_at' => (string) ( $document['uploaded_at'] ?? '' ),
				);
			}
		}
		$details    = is_array( $details ) && isset( $details['type'] ) ? array(
			'type'      => (string) $details['type'],
			'mrn'       => (string) ( $details['mrn'] ?? '' ),
			'exit_date' => (string) ( $details['exit_date'] ?? '' ),
			'saved_by'  => (int) ( $details['saved_by'] ?? 0 ),
			'saved_at'  => (string) ( $details['saved_at'] ?? '' ),
		) : null;
		$not_needed = is_array( $not_needed ) && '' !== (string) ( $not_needed['reason'] ?? '' ) ? array(
			'reason' => (string) $not_needed['reason'],
			'by'     => (int) ( $not_needed['by'] ?? 0 ),
			'at'     => (string) ( $not_needed['at'] ?? '' ),
		) : null;
		return new self( $details, $list, $accepted_at, $not_needed );
	}

	/**
	 * Status.
	 */
	public function status(): ExportEvidenceStatus {
		if ( null !== $this->not_needed ) {
			return ExportEvidenceStatus::NOT_NEEDED;
		}
		if ( '' !== $this->accepted_at ) {
			return ExportEvidenceStatus::ACCEPTED;
		}
		return $this->has_evidence() ? ExportEvidenceStatus::RECEIVED : ExportEvidenceStatus::MISSING;
	}

	/**
	 * Whether details or a document were recorded.
	 */
	public function has_evidence(): bool {
		return null !== $this->details || array() !== $this->documents;
	}

	/**
	 * Whether details may be recorded and documents uploaded (open evidence).
	 */
	public function may_edit(): bool {
		return '' === $this->accepted_at && null === $this->not_needed;
	}

	/**
	 * Whether the evidence may be marked as complete.
	 */
	public function may_accept(): bool {
		return ExportEvidenceStatus::RECEIVED === $this->status();
	}

	/**
	 * Whether staff may mark the evidence as not needed.
	 */
	public function may_mark_not_needed(): bool {
		return $this->may_edit();
	}

	/**
	 * Whether an acceptance or a "not needed" may be taken back.
	 */
	public function may_reopen(): bool {
		return ! $this->may_edit();
	}

	/**
	 * Whether anything was stored.
	 */
	public function has_entries(): bool {
		return $this->has_evidence() || '' !== $this->accepted_at || null !== $this->not_needed;
	}

	/**
	 * Latest of the order date and the evidence's own times (`Y-m-d H:i:s`, UTC).
	 *
	 * @param string $order_created Order date (UTC).
	 */
	public function latest_entry( string $order_created ): string {
		$times = array( $order_created, $this->accepted_at, (string) ( $this->details['saved_at'] ?? '' ), (string) ( $this->not_needed['at'] ?? '' ) );
		foreach ( $this->documents as $document ) {
			$times[] = $document['uploaded_at'];
		}
		$times = array_filter( $times, static fn( string $time ): bool => 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time ) );
		return array() !== $times ? max( $times ) : '';
	}

	/**
	 * End of the retention period: 31 December, 23:59:59, of the year of the latest entry plus the
	 * period (§ 147 (4) AO, as for the proof of supply, TAX-030).
	 *
	 * @param string $order_created Order date (UTC).
	 * @param int    $years         Retention period in years.
	 */
	public function retain_until( string $order_created, int $years ): string {
		$latest = $this->latest_entry( $order_created );
		return '' === $latest ? '' : sprintf( '%04d-12-31 23:59:59', (int) substr( $latest, 0, 4 ) + max( 1, $years ) );
	}

	/**
	 * Checks entered details: the type, the MRN (required for the exit notice, else optional; format
	 * only, see Mrn) and the date of exit (`YYYY-MM-DD`, not after tomorrow). Returns the cleaned values
	 * or an error code (`type`, `mrn_missing`, `mrn_format`, `date`).
	 *
	 * @param string            $type      Type value.
	 * @param string            $mrn       MRN as entered.
	 * @param string            $exit_date Date of exit as entered.
	 * @param DateTimeImmutable $today     Today (UTC).
	 * @return array{type: ExportEvidenceType, mrn: string, exit_date: string}|string
	 */
	public static function check_details( string $type, string $mrn, string $exit_date, DateTimeImmutable $today ) {
		$kind = ExportEvidenceType::tryFrom( $type );
		if ( null === $kind ) {
			return 'type';
		}
		$mrn = Mrn::normalize( $mrn );
		if ( '' === $mrn && $kind->needs_mrn() ) {
			return 'mrn_missing';
		}
		if ( '' !== $mrn && ! Mrn::is_valid_format( $mrn ) ) {
			return 'mrn_format';
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $exit_date, new DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $exit_date || (int) $date->format( 'Y' ) < 2000 || $date > $today->setTime( 0, 0 )->modify( '+1 day' ) ) {
			return 'date';
		}
		return array(
			'type'      => $kind,
			'mrn'       => $mrn,
			'exit_date' => $exit_date,
		);
	}

	/**
	 * Cleans the reason for "not needed": one line, at most REASON_MAX characters; '' when empty.
	 *
	 * @param string $reason Reason as entered (already sanitised as text).
	 */
	public static function clean_reason( string $reason ): string {
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $reason ) ), 0, self::REASON_MAX );
	}
}

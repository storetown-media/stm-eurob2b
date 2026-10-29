<?php
/**
 * Retention of the export evidence.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Export;

use DateTimeImmutable;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * TAX-032 (D-29): export evidence is kept for the setting *Keep validation evidence linked to orders*
 * (`tax_retention_years`, default 10, as the proof of supply) from the end of the calendar year of
 * its latest entry (`_eurob2b_export_retain_until`, § 147 (4) AO). Afterwards the meta and the files
 * of the order are deleted; files whose order was deleted for good go in every run. Runs also while
 * the feature is off: evidence recorded earlier still has to go, and while EuroB2B Pro, which records
 * it, is inactive (PLT-015).
 */
final class ExportEvidenceRetention implements RetentionTaskInterface {

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
	 * Deletes up to $limit expired evidence records (and files of deleted orders).
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of records.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		$removed = 0;
		$orders  = $this->evidence->expired( $now, $limit + 1 );
		$more    = count( $orders ) > $limit;
		foreach ( array_slice( $orders, 0, $limit ) as $order ) {
			$this->evidence->delete( $order );
			++$removed;
		}
		if ( $removed < $limit ) {
			$removed += $this->evidence->sweep_files_without_order( $limit - $removed );
		}
		return array(
			'removed' => $removed,
			'skipped' => 0,
			'more'    => $more,
		);
	}
}

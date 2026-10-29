<?php
/**
 * Retention of the proof of supply.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Proof;

use DateTimeImmutable;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * TAX-030 (D-23): proofs are kept for the setting *Keep validation evidence linked to orders*
 * (`tax_retention_years`, default 10) from the end of the calendar year of their latest entry
 * (`_eurob2b_proof_retain_until`, § 147 (4) AO). Afterwards the meta and the files of the order are
 * deleted; files whose order was deleted for good go in every run. Part of the core: it runs also
 * while EuroB2B Pro, which records the proofs, is inactive (PLT-015).
 */
final class ProofRetention implements RetentionTaskInterface {

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
	 * Deletes up to $limit expired proofs (and files of deleted orders).
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of records.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		$removed = 0;
		$orders  = $this->proof->expired( $now, $limit + 1 );
		$more    = count( $orders ) > $limit;
		foreach ( array_slice( $orders, 0, $limit ) as $order ) {
			$this->proof->delete( $order );
			++$removed;
		}
		if ( $removed < $limit ) {
			$removed += $this->proof->sweep_files_without_order( $limit - $removed );
		}
		return array(
			'removed' => $removed,
			'skipped' => 0,
			'more'    => $more,
		);
	}
}

<?php
/**
 * Retention task contract.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy;

use DateTimeImmutable;

/**
 * One category of stored data whose retention period the shop owner configures (PRV-004).
 *
 * Modules register tasks through the "eurob2b_retention_tasks" filter; the RetentionRunner
 * calls them once a day. A task must be idempotent (a second run removes nothing new), must
 * remove at most $limit records per call and must never log personal data: it returns counts.
 */
interface RetentionTaskInterface {

	/**
	 * Stable id (lower snake case), used in the log and the last-run summary.
	 */
	public function id(): string;

	/**
	 * Removes up to $limit records whose retention period ended before $now.
	 *
	 * A task whose period is set to 0 (keep) returns zero counts without touching anything.
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of records to remove in this call.
	 * @return array{removed: int, skipped: int, more: bool} Removed records, expired records that were
	 *         deliberately kept (with the reason documented per task) and whether another call is needed.
	 */
	public function run( DateTimeImmutable $now, int $limit ): array;
}

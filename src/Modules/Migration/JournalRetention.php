<?php
/**
 * Retention of the import journal.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

use DateTimeImmutable;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * D-22: the journal of an import is kept for the undo only, at most DAYS days; the daily retention
 * job deletes older rows (an import that old can no longer be undone).
 */
final class JournalRetention implements RetentionTaskInterface {

	/**
	 * Days a journal row is kept.
	 */
	public const DAYS = 30;

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
		return 'migration_journal';
	}

	/**
	 * Deletes rows older than DAYS days.
	 *
	 * @param DateTimeImmutable $now   Now.
	 * @param int               $limit Maximum number of rows.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		if ( $limit <= 0 ) {
			return array(
				'removed' => 0,
				'skipped' => 0,
				'more'    => false,
			);
		}
		$removed = $this->journal->delete_before( $now->modify( '-' . self::DAYS . ' days' )->format( 'Y-m-d H:i:s' ), $limit );
		return array(
			'removed' => $removed,
			'skipped' => 0,
			'more'    => $removed === $limit,
		);
	}
}

<?php
/**
 * Daily retention job.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Runs every registered retention task once a day (PRV-004, docs/privacy-and-data-flows.md §6).
 *
 * Each task removes at most BATCH records per run; when a task reports more, a single follow-up
 * run is scheduled a few minutes later until the backlog is gone. The log and the last-run
 * summary contain counts only, never personal data. VAT validation evidence keeps its own daily
 * purge in the tax module (since M2.2) and is not run here.
 */
final class RetentionRunner {

	public const HOOK            = 'eurob2b_privacy_retention';
	public const FOLLOW_UP_HOOK  = 'eurob2b_privacy_retention_more';
	public const OPTION_LAST_RUN = 'eurob2b_retention_last_run';
	public const BATCH           = 100;
	public const FOLLOW_UP_DELAY = 300;

	/**
	 * Registered tasks.
	 *
	 * @return RetentionTaskInterface[]
	 */
	public function tasks(): array {
		/**
		 * Collects the retention tasks of all modules.
		 *
		 * @param RetentionTaskInterface[] $tasks Tasks.
		 */
		$tasks = apply_filters( 'eurob2b_retention_tasks', array() );
		return array_values( array_filter( is_array( $tasks ) ? $tasks : array(), static fn( $t ) => $t instanceof RetentionTaskInterface ) );
	}

	/**
	 * Runs all tasks once.
	 *
	 * @param DateTimeImmutable|null $now Current time (UTC); tests pass a fixed time.
	 * @return array<string, array{removed: int, skipped: int, more: bool}> Results per task id.
	 */
	public function run( ?DateTimeImmutable $now = null ): array {
		$now     = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$results = array();
		$more    = false;
		foreach ( $this->tasks() as $task ) {
			$result                 = $task->run( $now, self::BATCH );
			$results[ $task->id() ] = array(
				'removed' => max( 0, (int) $result['removed'] ),
				'skipped' => max( 0, (int) $result['skipped'] ),
				'more'    => (bool) $result['more'],
			);

			$more = $more || $results[ $task->id() ]['more'];
		}

		update_option(
			self::OPTION_LAST_RUN,
			array(
				'time'    => $now->format( 'Y-m-d H:i:s' ),
				'results' => $results,
			),
			false
		);
		$this->log( $results );

		if ( $more && ! wp_next_scheduled( self::FOLLOW_UP_HOOK ) ) {
			wp_schedule_single_event( time() + self::FOLLOW_UP_DELAY, self::FOLLOW_UP_HOOK );
		}
		return $results;
	}

	/**
	 * Hook callback: the daily event and the follow-up event run the same way.
	 */
	public function run_scheduled(): void {
		$this->run();
	}

	/**
	 * Schedules the daily event once.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes the daily and the follow-up event (deactivation).
	 */
	public static function unschedule(): void {
		wp_unschedule_hook( self::HOOK );
		wp_unschedule_hook( self::FOLLOW_UP_HOOK );
	}

	/**
	 * Writes one counts-only line to the WooCommerce log when something happened.
	 *
	 * @param array<string, array{removed: int, skipped: int, more: bool}> $results Results.
	 */
	private function log( array $results ): void {
		$parts = array();
		foreach ( $results as $id => $result ) {
			if ( $result['removed'] > 0 || $result['skipped'] > 0 ) {
				$parts[] = sprintf( '%s: removed %d, kept %d%s', $id, $result['removed'], $result['skipped'], $result['more'] ? ', more to do' : '' );
			}
		}
		if ( array() === $parts || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->info( 'Retention run. ' . implode( '; ', $parts ), array( 'source' => 'eurob2b' ) );
	}
}

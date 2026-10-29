<?php
/**
 * Runs imports from other B2B plugins.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

use EuroB2B\Infrastructure\Schema;
use RuntimeException;
use Throwable;

/**
 * MIG-001: starts a test run, an import or the undo of an import, and works through it in batches,
 * each limited in time, so big shops do not hit the request time limit. Batches run in the
 * background through WooCommerce's Action Scheduler and whenever the admin page is open; a lock
 * keeps two batches from running at the same time. Only one run exists at a time, and an import
 * must be finished (journal deleted) or undone before the next run starts.
 */
final class Importer {

	public const OPTION_RUN  = 'eurob2b_migration_run';
	public const OPTION_LOCK = 'eurob2b_migration_lock';

	/**
	 * Action Scheduler hook of a background batch.
	 */
	public const STEP_ACTION = 'eurob2b_migration_step';

	/**
	 * Source items per page.
	 */
	public const PAGE = 25;

	/**
	 * A lock older than this (seconds) belongs to a batch that died.
	 */
	public const LOCK_SECONDS = 120;

	/**
	 * Sources.
	 *
	 * @var array<string, SourceInterface>
	 */
	private array $sources = array();

	/**
	 * Writer.
	 *
	 * @var ImportWriter
	 */
	private ImportWriter $writer;

	/**
	 * Reverter.
	 *
	 * @var Reverter
	 */
	private Reverter $reverter;

	/**
	 * Journal.
	 *
	 * @var Journal
	 */
	private Journal $journal;

	/**
	 * Constructor.
	 *
	 * @param SourceInterface[] $sources  Sources.
	 * @param ImportWriter      $writer   Writer.
	 * @param Reverter          $reverter Reverter.
	 * @param Journal           $journal  Journal.
	 */
	public function __construct( array $sources, ImportWriter $writer, Reverter $reverter, Journal $journal ) {
		foreach ( $sources as $source ) {
			$this->sources[ $source->id() ] = $source;
		}
		$this->writer   = $writer;
		$this->reverter = $reverter;
		$this->journal  = $journal;
	}

	/**
	 * Every source.
	 *
	 * @return array<string, SourceInterface>
	 */
	public function sources(): array {
		return $this->sources;
	}

	/**
	 * A source by id.
	 *
	 * @param string $id Id.
	 */
	public function source( string $id ): ?SourceInterface {
		return $this->sources[ $id ] ?? null;
	}

	/**
	 * The current run, or null.
	 */
	public function current(): ?Run {
		return Run::from_array( get_option( self::OPTION_RUN, null ) );
	}

	/**
	 * Whether a finished or failed import can still be undone (its journal exists).
	 *
	 * @param Run $run Run.
	 */
	public function can_undo( Run $run ): bool {
		return Run::MODE_IMPORT === $run->mode && ! $run->running() && $this->journal->count( $run->id ) > 0;
	}

	/**
	 * Starts a test run or an import.
	 *
	 * @param string   $source_id Source.
	 * @param string   $mode      Run::MODE_CHECK or Run::MODE_IMPORT.
	 * @param string[] $parts     Parts to import (unknown ones are dropped, order follows the source).
	 * @param int      $user_id   Who starts it.
	 * @param string   $related   Test run an import follows.
	 * @throws RuntimeException When the shop has no data of that plugin, another run is in the way or nothing was chosen.
	 */
	public function start( string $source_id, string $mode, array $parts, int $user_id, string $related = '' ): Run {
		$source = $this->source( $source_id );
		if ( null === $source || ! in_array( $mode, array( Run::MODE_CHECK, Run::MODE_IMPORT ), true ) ) {
			throw new RuntimeException( __( 'Unknown plugin to import from.', 'eurob2b' ) );
		}
		// Without data a run would only report the other plugin's defaults (and offer an import).
		if ( ! $source->detected() ) {
			/* translators: %s: plugin name */
			throw new RuntimeException( sprintf( __( 'No data of %s was found in this shop\'s database.', 'eurob2b' ), $source->label() ) );
		}
		$this->assert_free();
		$parts = array_values( array_intersect( array_keys( $source->parts() ), $parts ) );
		if ( empty( $parts ) ) {
			throw new RuntimeException( __( 'Choose at least one part to import.', 'eurob2b' ) );
		}
		$run          = new Run( wp_generate_password( 20, false ), $source_id, $mode, $parts, $user_id );
		$run->related = $related;
		foreach ( $source->notes() as $note ) {
			$run->message( $note['level'], $note['text'], '' );
		}
		$this->save( $run );
		$this->schedule();
		return $run;
	}

	/**
	 * Starts undoing the current import.
	 *
	 * @param int $user_id Who starts it.
	 * @throws RuntimeException When there is no import to undo.
	 */
	public function start_undo( int $user_id ): Run {
		$import = $this->current();
		if ( null === $import || ! $this->can_undo( $import ) ) {
			throw new RuntimeException( __( 'There is no import to undo.', 'eurob2b' ) );
		}
		$run          = new Run( wp_generate_password( 20, false ), $import->source, Run::MODE_UNDO, array( 'undo' ), $user_id );
		$run->related = $import->id;
		$this->save( $run );
		$this->schedule();
		return $run;
	}

	/**
	 * Ends an import for good: deletes its journal (no undo any more) and the report.
	 *
	 * @throws RuntimeException While a run is still working.
	 */
	public function finish(): void {
		$run = $this->current();
		if ( null === $run ) {
			return;
		}
		if ( $run->running() ) {
			throw new RuntimeException( __( 'The run is still working. Wait until it has finished.', 'eurob2b' ) );
		}
		if ( Run::MODE_IMPORT === $run->mode ) {
			$this->journal->delete_run( $run->id );
		}
		delete_option( self::OPTION_RUN );
	}

	/**
	 * Works on the current run for at most $budget seconds; returns it (null when there is none).
	 *
	 * @param float $budget Seconds.
	 */
	public function step( float $budget = 20.0 ): ?Run {
		if ( ! $this->lock() ) {
			return $this->current();
		}
		$run = $this->current();
		try {
			if ( null === $run || ! $run->running() ) {
				return $run;
			}
			$until = microtime( true ) + $budget;
			$this->writer->begin( $run );
			try {
				if ( Run::MODE_UNDO === $run->mode ) {
					$this->undo_batch( $run, $until );
				} else {
					$this->import_batch( $run, $until );
				}
			} catch ( Throwable $e ) {
				$run->status = Run::STATUS_FAILED;
				/* translators: %s: error message */
				$run->message( Run::LEVEL_ERROR, sprintf( __( 'The run stopped: %s', 'eurob2b' ), $e->getMessage() ) );
			}
			$this->clear_product_caches( $this->writer->touched_posts() );
			if ( ! $run->running() ) {
				$run->finished_at = gmdate( 'Y-m-d H:i:s' );
			}
			$this->save( $run );
			if ( $run->running() ) {
				$this->schedule();
			}
			return $run;
		} finally {
			$this->unlock();
		}//end try
	}

	/**
	 * Runs a whole run synchronously (WP-CLI, tests).
	 *
	 * @param float $budget Seconds per batch.
	 */
	public function complete( float $budget = 30.0 ): ?Run {
		$run   = $this->current();
		$guard = 0;
		while ( null !== $run && $run->running() && $guard < 100000 ) {
			$run = $this->step( $budget );
			++$guard;
		}
		return $run;
	}

	/**
	 * Pages of the current parts until done or out of time.
	 *
	 * @param Run   $run   Run.
	 * @param float $until Deadline (microtime).
	 * @throws RuntimeException When the source is gone.
	 */
	private function import_batch( Run $run, float $until ): void {
		$source = $this->source( $run->source );
		if ( null === $source ) {
			throw new RuntimeException( __( 'Unknown plugin to import from.', 'eurob2b' ) );
		}
		while ( microtime( true ) < $until ) {
			$part = $run->current_part();
			if ( '' === $part ) {
				$run->status = Run::STATUS_DONE;
				return;
			}
			$handled = $source->import_page( $part, $run->offset, self::PAGE, $this->writer );
			if ( $handled <= 0 ) {
				++$run->part;
				$run->offset = 0;
			} else {
				$run->offset += $handled;
			}
		}
	}

	/**
	 * Reverts journal rows newest first until done or out of time.
	 *
	 * @param Run   $run   Undo run.
	 * @param float $until Deadline (microtime).
	 */
	private function undo_batch( Run $run, float $until ): void {
		while ( microtime( true ) < $until ) {
			$rows = $this->journal->rows_backwards( $run->related, $run->cursor );
			if ( empty( $rows ) ) {
				$this->journal->delete_run( $run->related );
				$run->status = Run::STATUS_DONE;
				return;
			}
			foreach ( $rows as $row ) {
				$error = $this->reverter->revert( $run->source, $row );
				if ( '' !== $error ) {
					$run->count( 'skipped' );
					/* translators: 1: kind of object, 2: id or key, 3: error message */
					$run->message( Run::LEVEL_ERROR, sprintf( __( 'Could not undo %1$s %2$s: %3$s', 'eurob2b' ), $row['object_type'], 0 !== $row['object_id'] ? '#' . $row['object_id'] : $row['ref'], $error ) );
				} else {
					$run->count( 'changed' );
				}
				$run->cursor = $row['id'];
				if ( microtime( true ) >= $until ) {
					return;
				}
			}
		}//end while
	}

	/**
	 * Refuses a new run while one works or an import's journal waits for finish or undo.
	 *
	 * @throws RuntimeException When blocked.
	 */
	private function assert_free(): void {
		$run = $this->current();
		if ( null === $run ) {
			return;
		}
		if ( $run->running() ) {
			throw new RuntimeException( __( 'Another run is working. Wait until it has finished.', 'eurob2b' ) );
		}
		if ( $this->can_undo( $run ) ) {
			throw new RuntimeException( __( 'Finish or undo the last import first.', 'eurob2b' ) );
		}
	}

	/**
	 * Stores the run.
	 *
	 * @param Run $run Run.
	 */
	private function save( Run $run ): void {
		update_option( self::OPTION_RUN, $run->to_array(), false );
	}

	/**
	 * Queues a background batch (Action Scheduler ships with WooCommerce).
	 */
	private function schedule(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::STEP_ACTION, array(), Schema::ACTION_GROUP ) ) {
			as_enqueue_async_action( self::STEP_ACTION, array(), Schema::ACTION_GROUP );
		}
	}

	/**
	 * Takes the batch lock (an expired lock is taken over).
	 */
	private function lock(): bool {
		$now = time();
		if ( add_option( self::OPTION_LOCK, (string) $now, '', false ) ) {
			return true;
		}
		$held = (int) get_option( self::OPTION_LOCK, '0' );
		if ( $held > 0 && $now - $held < self::LOCK_SECONDS ) {
			return false;
		}
		update_option( self::OPTION_LOCK, (string) $now, false );
		return true;
	}

	/**
	 * Releases the batch lock.
	 */
	private function unlock(): void {
		delete_option( self::OPTION_LOCK );
	}

	/**
	 * Drops WooCommerce's caches of products whose meta changed (and of their parents).
	 *
	 * @param int[] $ids Products and variations.
	 */
	private function clear_product_caches( array $ids ): void {
		if ( ! function_exists( 'wc_delete_product_transients' ) ) {
			return;
		}
		$done = array();
		foreach ( $ids as $id ) {
			foreach ( array( $id, (int) wp_get_post_parent_id( $id ) ) as $product ) {
				if ( $product > 0 && ! isset( $done[ $product ] ) ) {
					wc_delete_product_transients( $product );
					$done[ $product ] = true;
				}
			}
		}
	}
}

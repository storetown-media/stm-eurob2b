<?php
/**
 * WP-CLI: import from another B2B plugin.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration\Cli;

use EuroB2B\Modules\Migration\Importer;
use EuroB2B\Modules\Migration\Run;
use RuntimeException;
use WP_CLI;

/**
 * Imports customer groups, customers, prices, rules and more of another B2B plugin into EuroB2B
 * (MIG-001), the same way as EuroB2B → Transfer settings, for shops too big for the browser. Run it
 * with `--user=<admin>` so the audit log names who classified the customers.
 */
final class ImportCommand {

	/**
	 * Importer.
	 *
	 * @var Importer
	 */
	private Importer $importer;

	/**
	 * Constructor.
	 *
	 * @param Importer $importer Importer.
	 */
	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	/**
	 * Shows what an import would change, without writing anything.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Plugin to import from: b2bking or wholesale-suite.
	 *
	 * [--parts=<parts>]
	 * : Comma-separated parts; default: all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eurob2b import check b2bking --user=admin
	 *
	 * @param string[]              $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function check( array $args, array $assoc ): void {
		$this->start( Run::MODE_CHECK, $args, $assoc );
	}

	/**
	 * Imports, keeping every replaced value for the undo.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Plugin to import from: b2bking or wholesale-suite.
	 *
	 * [--parts=<parts>]
	 * : Comma-separated parts; default: all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eurob2b import run b2bking --user=admin
	 *
	 * @param string[]              $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function run( array $args, array $assoc ): void {
		$this->start( Run::MODE_IMPORT, $args, $assoc );
	}

	/**
	 * Undoes the last import.
	 */
	public function undo(): void {
		try {
			$this->importer->start_undo( get_current_user_id() );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$this->report( $this->importer->complete() );
	}

	/**
	 * Finishes the last import: deletes the kept values (no undo any more) and the report.
	 */
	public function finish(): void {
		try {
			$this->importer->finish();
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		WP_CLI::success( __( 'The import is finished.', 'eurob2b' ) );
	}

	/**
	 * Shows the current run and its report.
	 */
	public function status(): void {
		$run = $this->importer->current();
		if ( null === $run ) {
			WP_CLI::log( __( 'There is no run.', 'eurob2b' ) );
			return;
		}
		$this->report( $run );
	}

	/**
	 * Starts and completes a test run or an import.
	 *
	 * @param string                $mode  Mode.
	 * @param string[]              $args  Arguments.
	 * @param array<string, string> $assoc Options.
	 */
	private function start( string $mode, array $args, array $assoc ): void {
		$source = $this->importer->source( (string) ( $args[0] ?? '' ) );
		if ( null === $source ) {
			WP_CLI::error( __( 'Unknown plugin to import from.', 'eurob2b' ) );
			return;
		}
		$parts = isset( $assoc['parts'] ) ? array_map( 'trim', explode( ',', (string) $assoc['parts'] ) ) : array_keys( $source->parts() );
		try {
			$this->importer->start( $source->id(), $mode, $parts, get_current_user_id() );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$this->report( $this->importer->complete() );
	}

	/**
	 * Prints counts and messages.
	 *
	 * @param Run|null $run Run.
	 */
	private function report( ?Run $run ): void {
		if ( null === $run ) {
			return;
		}
		foreach ( $run->counts as $part => $counts ) {
			/* translators: 1: part, 2: new, 3: changed, 4: unchanged, 5: not imported */
			WP_CLI::log( sprintf( __( '%1$s: %2$d new, %3$d changed, %4$d unchanged, %5$d not imported', 'eurob2b' ), $part, $counts['new'] ?? 0, $counts['changed'] ?? 0, $counts['unchanged'] ?? 0, $counts['skipped'] ?? 0 ) );
		}
		foreach ( $run->messages as $message ) {
			$line = ( '' !== $message['part'] ? $message['part'] . ': ' : '' ) . $message['text'];
			if ( Run::LEVEL_INFO === $message['level'] ) {
				WP_CLI::log( $line );
			} else {
				WP_CLI::warning( $line );
			}
		}
		if ( Run::STATUS_DONE === $run->status ) {
			WP_CLI::success( Run::MODE_CHECK === $run->mode ? __( 'Test run finished; nothing was written.', 'eurob2b' ) : __( 'Finished.', 'eurob2b' ) );
		} else {
			WP_CLI::error( __( 'The run stopped with an error.', 'eurob2b' ) );
		}
	}
}

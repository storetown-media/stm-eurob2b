<?php
/**
 * "Import from another plugin" on the transfer page.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Admin\SettingsTransferPage;
use EuroB2B\Modules\Migration\Importer;
use EuroB2B\Modules\Migration\JournalRetention;
use EuroB2B\Modules\Migration\Run;
use EuroB2B\Modules\Migration\SourceInterface;
use RuntimeException;

/**
 * MIG-001: section of *EuroB2B → Transfer settings*. Shows the plugins whose data was found, lets
 * the shop pick the parts, runs the test run, the import, the undo and the finish. Needs
 * `eurob2b_manage_settings` and `eurob2b_manage_customers` (the import classifies customers), and
 * every action carries a nonce. While a run works, each view of the page does a short batch and
 * the page reloads itself; the rest runs in the background.
 */
final class ImportSection {

	public const ACTION_CHECK  = 'eurob2b_migration_check';
	public const ACTION_IMPORT = 'eurob2b_migration_import';
	public const ACTION_UNDO   = 'eurob2b_migration_undo';
	public const ACTION_FINISH = 'eurob2b_migration_finish';

	/**
	 * A test run can be imported for this long (seconds).
	 */
	public const CHECK_VALID = HOUR_IN_SECONDS;

	/**
	 * Seconds of work per page view and per button.
	 */
	private const BUDGET = 8.0;

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
	 * Hooks.
	 */
	public function hooks(): void {
		add_action( 'eurob2b_settings_transfer_sections', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION_CHECK, array( $this, 'handle_check' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT, array( $this, 'handle_import' ) );
		add_action( 'admin_post_' . self::ACTION_UNDO, array( $this, 'handle_undo' ) );
		add_action( 'admin_post_' . self::ACTION_FINISH, array( $this, 'handle_finish' ) );
		add_action( 'admin_head', array( $this, 'reload_while_running' ) );
	}

	/**
	 * Whether the current user may import.
	 */
	public static function allowed(): bool {
		return current_user_can( Capabilities::MANAGE_SETTINGS ) && current_user_can( Capabilities::MANAGE_CUSTOMERS );
	}

	/**
	 * Starts a test run.
	 */
	public function handle_check(): void {
		$this->authorise( self::ACTION_CHECK );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( (string) $_POST['source'] ) ) : '';
		$parts  = isset( $_POST['parts'] ) && is_array( $_POST['parts'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['parts'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		try {
			$this->importer->start( $source, Run::MODE_CHECK, $parts, get_current_user_id() );
			$this->importer->step( self::BUDGET );
		} catch ( RuntimeException $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
			return;
		}
		$this->redirect( array() );
	}

	/**
	 * Starts the import of the checked parts.
	 */
	public function handle_import(): void {
		$this->authorise( self::ACTION_IMPORT );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$token = isset( $_POST['run'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['run'] ) ) : '';
		$check = $this->importer->current();
		if ( null === $check || Run::MODE_CHECK !== $check->mode || Run::STATUS_DONE !== $check->status || ! hash_equals( $check->id, $token ) || get_current_user_id() !== $check->user_id || time() - (int) strtotime( $check->finished_at . ' UTC' ) > self::CHECK_VALID ) {
			$this->redirect( array( 'error' => __( 'The test run has expired. Please run it again.', 'eurob2b' ) ) );
			return;
		}
		try {
			$this->importer->start( $check->source, Run::MODE_IMPORT, $check->parts, get_current_user_id(), $check->id );
			$this->importer->step( self::BUDGET );
		} catch ( RuntimeException $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
			return;
		}
		$this->redirect( array() );
	}

	/**
	 * Starts the undo of the last import.
	 */
	public function handle_undo(): void {
		$this->authorise( self::ACTION_UNDO );
		try {
			$this->importer->start_undo( get_current_user_id() );
			$this->importer->step( self::BUDGET );
		} catch ( RuntimeException $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
			return;
		}
		$this->redirect( array() );
	}

	/**
	 * Closes the report; for an import also deletes its journal (no undo any more).
	 */
	public function handle_finish(): void {
		$this->authorise( self::ACTION_FINISH );
		try {
			$this->importer->finish();
		} catch ( RuntimeException $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
			return;
		}
		$this->redirect( array() );
	}

	/**
	 * Reloads the transfer page every few seconds while a run works.
	 */
	public function reload_while_running(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which admin page is shown.
		if ( ! isset( $_GET['page'] ) || SettingsTransferPage::SLUG !== $_GET['page'] || ! self::allowed() ) {
			return;
		}
		$run = $this->importer->current();
		if ( null !== $run && $run->running() ) {
			echo '<meta http-equiv="refresh" content="5" />';
		}
	}

	/**
	 * The section.
	 */
	public function render(): void {
		echo '<h2 id="eurob2b-import">' . esc_html__( 'Import from another plugin', 'eurob2b' ) . '</h2>';
		if ( ! self::allowed() ) {
			echo '<p>' . esc_html__( 'Importing needs the permissions to manage the settings and the customers.', 'eurob2b' ) . '</p>';
			return;
		}
		$names = implode( ', ', array_map( static fn( SourceInterface $s ): string => $s->label(), $this->importer->sources() ) );
		/* translators: 1: names of the supported plugins, 2: number of days */
		echo '<p>' . esc_html( sprintf( __( 'Takes over customer groups, customers, prices, rules and more from another B2B plugin (%1$s). The other plugin\'s data is only read, never changed. A test run shows first what would change. The import keeps every value it replaces, so you can undo it for %2$d days or until you finish it. Tax settings, VAT IDs, offers, conversations and purchase lists are not imported.', 'eurob2b' ), $names, JournalRetention::DAYS ) ) . '</p>';

		$run = $this->importer->current();
		if ( null !== $run && $run->running() ) {
			$run = $this->importer->step( self::BUDGET );
		}
		if ( null !== $run ) {
			$this->render_run( $run );
			return;
		}
		$found = false;
		foreach ( $this->importer->sources() as $source ) {
			if ( $source->detected() ) {
				$found = true;
				$this->render_source( $source );
			}
		}
		if ( ! $found ) {
			/* translators: %s: names of the supported plugins */
			echo '<p>' . esc_html( sprintf( __( 'No data of a supported plugin (%s) was found in this shop\'s database.', 'eurob2b' ), $names ) ) . '</p>';
		}
	}

	/**
	 * What was found of one plugin, the parts and the test run button.
	 *
	 * @param SourceInterface $source Source.
	 */
	private function render_source( SourceInterface $source ): void {
		echo '<h3>' . esc_html( $source->label() ) . '</h3>';
		if ( $source->active() ) {
			/* translators: %s: plugin name */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( '%s is still active. Run the test run now if you like, but deactivate it before the import: otherwise both plugins apply their prices and rules.', 'eurob2b' ), $source->label() ) ) . '</p></div>';
		}
		echo '<table class="widefat striped" style="max-width:40em"><tbody>';
		foreach ( $source->summary() as $label => $count ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right">' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_CHECK );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CHECK ) . '" /><input type="hidden" name="source" value="' . esc_attr( $source->id() ) . '" />';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Parts to import', 'eurob2b' ) . '</legend>';
		foreach ( $source->parts() as $part => $label ) {
			echo '<p><label><input type="checkbox" name="parts[]" value="' . esc_attr( $part ) . '" checked="checked" /> ' . esc_html( $label ) . '</label></p>';
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Customer groups are needed by most other parts: without them, prices, rules and customers of a group are left out.', 'eurob2b' ) . '</p>';
		submit_button( __( 'Check (test run)', 'eurob2b' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * State, report and buttons of the current run.
	 *
	 * @param Run $run Run.
	 */
	private function render_run( Run $run ): void {
		$source = $this->importer->source( $run->source );
		$parts  = null !== $source ? $source->parts() : array();
		$titles = array(
			Run::MODE_CHECK  => __( 'Test run', 'eurob2b' ),
			Run::MODE_IMPORT => __( 'Import', 'eurob2b' ),
			Run::MODE_UNDO   => __( 'Undo of the import', 'eurob2b' ),
		);
		$states = array(
			Run::STATUS_RUNNING => __( 'working', 'eurob2b' ),
			Run::STATUS_DONE    => __( 'finished', 'eurob2b' ),
			Run::STATUS_FAILED  => __( 'stopped with an error', 'eurob2b' ),
		);
		/* translators: 1: kind of run, 2: plugin name, 3: state */
		echo '<h3>' . esc_html( sprintf( __( '%1$s from %2$s: %3$s', 'eurob2b' ), $titles[ $run->mode ] ?? $run->mode, null !== $source ? $source->label() : $run->source, $states[ $run->status ] ?? $run->status ) ) . '</h3>';
		if ( $run->running() ) {
			$label = Run::MODE_UNDO === $run->mode ? $titles[ Run::MODE_UNDO ] : ( $parts[ $run->current_part() ] ?? $run->current_part() );
			/* translators: 1: number of the part, 2: number of parts, 3: name of the part */
			echo '<p>' . esc_html( sprintf( __( 'Part %1$d of %2$d: %3$s. This page reloads by itself; you may also leave it, the run goes on in the background.', 'eurob2b' ), $run->part + 1, count( $run->parts ), $label ) ) . '</p>';
		}
		$this->render_counts( $run, $parts );
		$this->render_messages( $run, $parts );
		if ( $run->running() ) {
			return;
		}
		if ( Run::MODE_CHECK === $run->mode && Run::STATUS_DONE === $run->status ) {
			echo '<p>' . esc_html__( 'Nothing was written yet. "Import now" writes the values above and keeps the replaced ones for the undo.', 'eurob2b' ) . '</p>';
			$this->button( self::ACTION_IMPORT, __( 'Import now', 'eurob2b' ), 'primary', array( 'run' => $run->id ) );
			$this->button( self::ACTION_FINISH, __( 'Discard the test run', 'eurob2b' ) );
			return;
		}
		if ( $this->importer->can_undo( $run ) ) {
			/* translators: %d: number of days */
			echo '<p>' . esc_html( sprintf( __( 'Check the shop now. "Undo the import" puts back every value as it was. "Finish the import" deletes the record of the replaced values; after that, and at the latest after %d days, the import can no longer be undone.', 'eurob2b' ), JournalRetention::DAYS ) ) . '</p>';
			$this->button( self::ACTION_UNDO, __( 'Undo the import', 'eurob2b' ) );
			$this->button( self::ACTION_FINISH, __( 'Finish the import', 'eurob2b' ), 'primary' );
			return;
		}
		$this->button( self::ACTION_FINISH, __( 'Close the report', 'eurob2b' ) );
	}

	/**
	 * Table of counts per part.
	 *
	 * @param Run                   $run   Run.
	 * @param array<string, string> $parts Part labels.
	 */
	private function render_counts( Run $run, array $parts ): void {
		if ( empty( $run->counts ) ) {
			return;
		}
		$undo = Run::MODE_UNDO === $run->mode;
		echo '<table class="widefat striped" style="max-width:60em"><thead><tr><th>' . esc_html__( 'Part', 'eurob2b' ) . '</th>';
		if ( $undo ) {
			echo '<th>' . esc_html__( 'Put back', 'eurob2b' ) . '</th><th>' . esc_html__( 'Failed', 'eurob2b' ) . '</th>';
		} else {
			echo '<th>' . esc_html__( 'New', 'eurob2b' ) . '</th><th>' . esc_html__( 'Changed', 'eurob2b' ) . '</th><th>' . esc_html__( 'Unchanged', 'eurob2b' ) . '</th><th>' . esc_html__( 'Not imported', 'eurob2b' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $run->counts as $part => $counts ) {
			echo '<tr><td>' . esc_html( $undo ? __( 'All parts', 'eurob2b' ) : ( $parts[ $part ] ?? $part ) ) . '</td>';
			foreach ( $undo ? array( 'changed', 'skipped' ) : Run::COUNTS as $key ) {
				echo '<td>' . esc_html( number_format_i18n( (int) ( $counts[ $key ] ?? 0 ) ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( Run::MODE_CHECK === $run->mode ) {
			echo '<p class="description">' . esc_html__( 'Counted are single values: a product with prices for three groups counts three times.', 'eurob2b' ) . '</p>';
		}
	}

	/**
	 * Messages, errors first.
	 *
	 * @param Run                   $run   Run.
	 * @param array<string, string> $parts Part labels.
	 */
	private function render_messages( Run $run, array $parts ): void {
		$headings = array(
			Run::LEVEL_ERROR   => __( 'Errors', 'eurob2b' ),
			Run::LEVEL_WARNING => __( 'Please check', 'eurob2b' ),
			Run::LEVEL_INFO    => __( 'Notes', 'eurob2b' ),
		);
		foreach ( $headings as $level => $heading ) {
			$items = array_filter( $run->messages, static fn( array $m ): bool => $level === $m['level'] );
			if ( empty( $items ) ) {
				continue;
			}
			echo '<p><strong>' . esc_html( $heading ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
			foreach ( $items as $item ) {
				$part = '' !== $item['part'] && isset( $parts[ $item['part'] ] ) ? $parts[ $item['part'] ] . ': ' : '';
				echo '<li>' . esc_html( $part . $item['text'] ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $run->more_messages > 0 ) {
			/* translators: %d: number of messages */
			echo '<p>' . esc_html( sprintf( _n( '%d more message is not shown.', '%d more messages are not shown.', $run->more_messages, 'eurob2b' ), $run->more_messages ) ) . '</p>';
		}
	}

	/**
	 * A one-button form.
	 *
	 * @param string                $action Action.
	 * @param string                $label  Label.
	 * @param string                $type   Button type.
	 * @param array<string, string> $fields Hidden fields.
	 */
	private function button( string $action, string $label, string $type = 'secondary', array $fields = array() ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:1em">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		submit_button( $label, $type, 'submit', false );
		echo '</form>';
	}

	/**
	 * Capabilities and nonce.
	 *
	 * @param string $nonce Nonce action.
	 */
	private function authorise( string $nonce ): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You are not allowed to import data.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Back to the section (skipped when the `wp_redirect` filter cancels it, e.g. in tests).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function redirect( array $args ): void {
		if ( wp_safe_redirect( Menu::url( SettingsTransferPage::SLUG, $args ) . '#eurob2b-import' ) ) {
			exit;
		}
	}
}

<?php
/**
 * STM EU-B2B → Settings transfer.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use EuroB2B\Infrastructure\Capabilities;
use RuntimeException;
use EuroB2B\Support\ExceptionMessage;

/**
 * PLT-012: download the configuration, check a file, then import it (two steps, so the shop owner
 * sees what changes before anything is written), and download the backup taken before the last
 * import. Capability `eurob2b_manage_settings`, a nonce on every action.
 */
final class SettingsTransferPage {

	public const SLUG = 'settings-transfer';

	public const ACTION_EXPORT = 'eurob2b_settings_export';
	public const ACTION_CHECK  = 'eurob2b_settings_check';
	public const ACTION_IMPORT = 'eurob2b_settings_import';
	public const ACTION_BACKUP = 'eurob2b_settings_backup';

	/**
	 * Checked file waiting for the import (per user, 15 minutes).
	 */
	public const TRANSIENT_PREFIX = 'eurob2b_settings_import_';

	/**
	 * Transfer service.
	 *
	 * @var SettingsTransfer
	 */
	private SettingsTransfer $transfer;

	/**
	 * Constructor.
	 *
	 * @param SettingsTransfer $transfer Transfer service.
	 */
	public function __construct( SettingsTransfer $transfer ) {
		$this->transfer = $transfer;
	}

	/**
	 * Menu entry after the settings.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'Transfer settings', 'stm-eu-b2b' ), __( 'Transfer settings', 'stm-eu-b2b' ), Capabilities::MANAGE_SETTINGS, array( $this, 'render' ), 101 );
	}

	/**
	 * Download of the current configuration.
	 */
	public function handle_export(): void {
		$this->authorise( self::ACTION_EXPORT );
		$this->send( $this->transfer->export(), 'eurob2b-settings-' . self::host() . '-' . gmdate( 'Y-m-d' ) . '.json' );
	}

	/**
	 * Download of the backup taken before the last import.
	 */
	public function handle_backup(): void {
		$this->authorise( self::ACTION_BACKUP );
		$backup = get_option( SettingsTransfer::OPTION_BACKUP );
		if ( ! is_array( $backup ) || ! is_array( $backup['data'] ?? null ) ) {
			$this->redirect( array( 'error' => __( 'There is no backup yet.', 'stm-eu-b2b' ) ) );
			return;
		}
		$this->send( $backup['data'], 'eurob2b-settings-before-import-' . self::host() . '-' . gmdate( 'Y-m-d', (int) strtotime( (string) $backup['time'] . ' UTC' ) ) . '.json' );
	}

	/**
	 * Step 1: reads the uploaded or pasted file and shows what an import would change.
	 */
	public function handle_check(): void {
		$this->authorise( self::ACTION_CHECK );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$json = '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- only tmp_name and size are read, checked below.
		$file = isset( $_FILES['eurob2b_settings_file'] ) && is_array( $_FILES['eurob2b_settings_file'] ) ? $_FILES['eurob2b_settings_file'] : array();
		$tmp  = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' !== $tmp && is_uploaded_file( $tmp ) ) {
			if ( (int) ( $file['size'] ?? 0 ) > SettingsTransfer::MAX_BYTES ) {
				$this->redirect( array( 'error' => __( 'The file is larger than 1 MB.', 'stm-eu-b2b' ) ) );
				return;
			}
			$json = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP's upload buffer, read once, never stored.
		} elseif ( isset( $_POST['eurob2b_settings_json'] ) && is_string( $_POST['eurob2b_settings_json'] ) ) {
			$json = trim( wp_unslash( $_POST['eurob2b_settings_json'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, parsed and validated below.
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		try {
			$data = SettingsTransfer::parse( $json );
		} catch ( RuntimeException $e ) {
			$this->redirect( array( 'error' => ExceptionMessage::plain( $e ) ) );
			return;
		}
		$token = wp_generate_password( 20, false );
		set_transient(
			self::TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'token' => $token,
				'data'  => $data,
			),
			15 * MINUTE_IN_SECONDS
		);
		$this->redirect( array( 'check' => $token ) );
	}

	/**
	 * Step 2: imports the checked file.
	 */
	public function handle_import(): void {
		$this->authorise( self::ACTION_IMPORT );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		$pending = get_transient( self::TRANSIENT_PREFIX . get_current_user_id() );
		if ( ! is_array( $pending ) || '' === $token || ! hash_equals( (string) ( $pending['token'] ?? '' ), $token ) || ! is_array( $pending['data'] ?? null ) ) {
			$this->redirect( array( 'error' => __( 'The checked file has expired. Please check it again.', 'stm-eu-b2b' ) ) );
			return;
		}
		// The token is consumed before anything is written, and only one request can consume it: of two
		// submissions of the same checked file, the second finds the transient gone and imports nothing.
		if ( ! delete_transient( self::TRANSIENT_PREFIX . get_current_user_id() ) ) {
			$this->redirect( array( 'error' => __( 'The checked file has expired. Please check it again.', 'stm-eu-b2b' ) ) );
			return;
		}
		$report = $this->transfer->import( $pending['data'], true );
		set_transient( self::TRANSIENT_PREFIX . 'report_' . get_current_user_id(), $report, 15 * MINUTE_IN_SECONDS );
		$this->redirect( array( 'imported' => 1 ) );
	}

	/**
	 * Page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'stm-eu-b2b' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$error    = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		$check    = isset( $_GET['check'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['check'] ) ) : '';
		$imported = isset( $_GET['imported'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		PageHeader::open( 'eurob2b-settings-transfer', __( 'Transfer settings', 'stm-eu-b2b' ) );
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( $imported ) {
			$report = get_transient( self::TRANSIENT_PREFIX . 'report_' . get_current_user_id() );
			if ( is_array( $report ) ) {
				$failed = ! empty( $report['errors'] );
				echo '<div class="notice ' . ( $failed ? 'notice-warning' : 'notice-success' ) . '"><p><strong>' . esc_html( $failed ? __( 'The settings were imported with errors.', 'stm-eu-b2b' ) : __( 'The settings were imported.', 'stm-eu-b2b' ) ) . '</strong></p>';
				$this->render_report( $report );
				echo '</div>';
			}
		}

		$pending = '' !== $check ? get_transient( self::TRANSIENT_PREFIX . get_current_user_id() ) : false;
		if ( is_array( $pending ) && hash_equals( (string) ( $pending['token'] ?? '' ), $check ) && is_array( $pending['data'] ?? null ) ) {
			$this->render_check( $pending['data'], $check );
		}

		echo '<h2>' . esc_html__( 'Export', 'stm-eu-b2b' ) . '</h2>';
		echo '<p>' . esc_html__( 'Downloads the EU-B2B configuration of this shop as a JSON file: settings, customer groups, registration types and fields, quote request fields, payment and shipping methods per audience, payment terms, surcharges, discounts and shipping costs. Not included: rules, prices, customers, companies, quotes and orders, and the setting that deletes the data on uninstall. The file holds no customer data; it does hold your shop\'s own VAT ID and the texts of the settings.', 'stm-eu-b2b' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_EXPORT );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_EXPORT ) . '" />';
		submit_button( __( 'Download settings', 'stm-eu-b2b' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Import', 'stm-eu-b2b' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose a file exported by EU-B2B (or paste its content). You see what would change before anything is written. Groups are matched by their slug; missing groups are created, and references to groups are adjusted to this shop. Before the import, the current configuration is kept as a backup.', 'stm-eu-b2b' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_CHECK );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CHECK ) . '" />';
		echo '<p><input type="file" name="eurob2b_settings_file" accept=".json,application/json" /></p>';
		echo '<p><label for="eurob2b-settings-json">' . esc_html__( 'Or paste the content', 'stm-eu-b2b' ) . '</label><br /><textarea id="eurob2b-settings-json" name="eurob2b_settings_json" rows="5" class="large-text code"></textarea></p>';
		submit_button( __( 'Check file', 'stm-eu-b2b' ), 'secondary', 'submit', false );
		echo '</form>';

		$backup = get_option( SettingsTransfer::OPTION_BACKUP );
		if ( is_array( $backup ) && isset( $backup['time'] ) ) {
			echo '<h2>' . esc_html__( 'Backup', 'stm-eu-b2b' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION_BACKUP );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_BACKUP ) . '" />';
			/* translators: %s: date and time of the last import */
			echo '<p>' . esc_html( sprintf( __( 'The configuration as it was before the last import (%s). Import this file to go back.', 'stm-eu-b2b' ), wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) strtotime( (string) $backup['time'] . ' UTC' ) ) ) ) . '</p>';
			submit_button( __( 'Download backup', 'stm-eu-b2b' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		/**
		 * Prints further sections of the transfer page, e.g. the import from another plugin (MIG-001).
		 */
		do_action( 'eurob2b_settings_transfer_sections' );
		echo '</div>';
	}

	/**
	 * What the checked file would change, with the import button.
	 *
	 * @param array<string, mixed> $data  Parsed file.
	 * @param string               $token Token of the checked file.
	 */
	private function render_check( array $data, string $token ): void {
		$report = $this->transfer->import( $data, false );
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Checked file', 'stm-eu-b2b' ) . '</strong> ';
		/* translators: 1: EU-B2B version, 2: export date */
		echo esc_html( sprintf( __( 'from EU-B2B %1$s, exported %2$s', 'stm-eu-b2b' ), (string) ( $data['plugin_version'] ?? '?' ), (string) ( $data['exported_at'] ?? '?' ) ) ) . '</p>';
		$this->render_report( $report );
		if ( ! empty( $report['errors'] ) ) {
			echo '</div>';
			return;
		}
		if ( empty( $report['changed'] ) && empty( $report['groups_created'] ) ) {
			echo '<p>' . esc_html__( 'The file matches this shop; there is nothing to import.', 'stm-eu-b2b' ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_IMPORT );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_IMPORT ) . '" /><input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		submit_button( __( 'Import now', 'stm-eu-b2b' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Report lists.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function render_report( array $report ): void {
		$lists = array(
			__( 'Changes', 'stm-eu-b2b' )      => $report['changed'] ?? array(),
			__( 'New groups', 'stm-eu-b2b' )   => $report['groups_created'] ?? array(),
			__( 'Please check', 'stm-eu-b2b' ) => $report['warnings'] ?? array(),
			__( 'Not imported', 'stm-eu-b2b' ) => $report['errors'] ?? array(),
		);
		foreach ( $lists as $heading => $items ) {
			if ( empty( $items ) || ! is_array( $items ) ) {
				continue;
			}
			echo '<p>' . esc_html( $heading ) . ':</p><ul style="list-style:disc;margin-left:2em">';
			foreach ( $items as $item ) {
				echo '<li>' . esc_html( (string) $item ) . '</li>';
			}
			echo '</ul>';
		}
		if ( isset( $report['groups_matched'] ) && (int) $report['groups_matched'] > 0 ) {
			/* translators: %d: number of groups */
			echo '<p>' . esc_html( sprintf( _n( '%d group of the file matches a group of this shop.', '%d groups of the file match groups of this shop.', (int) $report['groups_matched'], 'stm-eu-b2b' ), (int) $report['groups_matched'] ) ) . '</p>';
		}
	}

	/**
	 * Sends a JSON download through wp_send_json(), which also ends the request (the headers are
	 * skipped in tests, where output has started).
	 *
	 * @param array<string, mixed> $data     Data.
	 * @param string               $filename File name.
	 */
	private function send( array $data, string $filename ): void {
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		wp_send_json( $data, 200, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Host name for file names.
	 */
	private static function host(): string {
		return sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Capability and nonce.
	 *
	 * @param string $nonce Nonce action.
	 */
	private function authorise( string $nonce ): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'stm-eu-b2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Back to the page (skipped when the `wp_redirect` filter cancels it, e.g. in tests).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function redirect( array $args ): void {
		if ( wp_safe_redirect( Menu::url( self::SLUG, $args ) ) ) {
			exit;
		}
	}
}

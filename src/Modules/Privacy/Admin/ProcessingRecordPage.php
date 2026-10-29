<?php
/**
 * Admin page: record of processing activities.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Privacy\ProcessingRecord;

/**
 * EuroB2B → Processing record (PRV-010): shows the draft of the record of processing activities
 * and serves it as a standalone HTML document for printing (or saving as PDF) and as a download
 * that opens in a word processor. Capability `eurob2b_manage_settings`; the document handler is an
 * admin_post action with a nonce.
 */
final class ProcessingRecordPage {

	public const SLUG   = 'processing-record';
	public const ACTION = 'eurob2b_processing_record';
	public const NONCE  = 'eurob2b_processing_record';

	/**
	 * Styles of the record, on the admin page and in the document.
	 */
	private const CSS = '.eurob2b-record{max-width:60rem}'
		. '.eurob2b-record h2{font-size:1.15em;margin:2em 0 .5em}'
		. '.eurob2b-record h3{font-size:1em;margin:1.25em 0 .25em}'
		. '.eurob2b-record table{border-collapse:collapse;width:100%;margin:0 0 1em;background:#fff}'
		. '.eurob2b-record th,.eurob2b-record td{border:1px solid #c3c4c7;padding:.5em .75em;text-align:left;vertical-align:top}'
		. '.eurob2b-record th{width:30%;font-weight:600;background:#f6f7f7}'
		. '.eurob2b-record ul{margin:0;padding-left:1.2em;list-style:disc}'
		. '.eurob2b-record li{margin:0 0 .3em}'
		. '.eurob2b-record__scope{border-left:4px solid #72aee6;background:#fff;padding:.6em .9em}'
		. '.eurob2b-record__todo{color:#b32d2e;font-style:italic}'
		. '.eurob2b-record__footer{color:#50575e;font-size:.9em;margin-top:2em}'
		. '@media (max-width:782px){.eurob2b-record th,.eurob2b-record td{display:block;width:auto}.eurob2b-record th{border-bottom:0}}';

	/**
	 * Record.
	 *
	 * @var ProcessingRecord
	 */
	private ProcessingRecord $record;

	/**
	 * Constructor.
	 *
	 * @param ProcessingRecord $record Record.
	 */
	public function __construct( ProcessingRecord $record ) {
		$this->record = $record;
	}

	/**
	 * Menu entry.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'Record of processing activities', 'eurob2b' ), __( 'Processing record', 'eurob2b' ), Capabilities::MANAGE_SETTINGS, array( $this, 'render' ), 90 );
	}

	/**
	 * URL of the document; with $download the browser saves it as a file.
	 *
	 * @param bool $download Download instead of view.
	 */
	public static function document_url( bool $download = false ): string {
		$args = array( 'action' => self::ACTION );
		if ( $download ) {
			$args['download'] = '1';
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		$this->check_capability();
		echo '<div class="wrap eurob2b-processing-record"><h1>' . esc_html__( 'Record of processing activities', 'eurob2b' ) . '</h1>';
		echo '<p>' . esc_html__( 'A draft for your record of processing activities under Art. 30 GDPR, built from the current EuroB2B settings. Print it or save it as a PDF from the print view, or download it as an HTML file that you can edit in a word processor.', 'eurob2b' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( self::document_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Print view', 'eurob2b' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::document_url( true ) ) . '">' . esc_html__( 'Download as HTML file', 'eurob2b' ) . '</a></p>';
		echo '<style>' . self::CSS . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant stylesheet.
		echo $this->record->html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the record escapes its output.
		echo '</div>';
	}

	/**
	 * Document handler (admin-post): print view or download.
	 */
	public function handle_document(): void {
		$this->authorise();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in authorise().
		$download = isset( $_GET['download'] );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		if ( $download ) {
			header( 'Content-Disposition: attachment; filename="' . self::filename() . '"' );
		}
		echo $this->document( ! $download ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the record escapes its output.
		exit;
	}

	/**
	 * Refuses users without the capability and requests without a valid nonce.
	 */
	public function authorise(): void {
		$this->check_capability();
		check_admin_referer( self::NONCE );
	}

	/**
	 * Refuses users without the capability.
	 */
	private function check_capability(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to view the record of processing activities.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * File name of the download, e.g. "processing-record-2026-10-23.html".
	 */
	public static function filename(): string {
		$name = sanitize_file_name( _x( 'processing-record', 'file name', 'eurob2b' ) );
		return ( '' !== $name ? $name : 'processing-record' ) . '-' . wp_date( 'Y-m-d' ) . '.html';
	}

	/**
	 * The record as a standalone HTML document.
	 *
	 * @param bool $print_button Show a print button (the print view; a download goes without).
	 */
	public function document( bool $print_button = true ): string {
		$title = __( 'Record of processing activities', 'eurob2b' );
		$css   = 'body{font:11pt/1.5 Georgia,"Times New Roman",serif;color:#1d2327;margin:2.5em auto;max-width:60rem;padding:0 1em}'
			. 'h1{font-size:1.6em;font-weight:normal;margin:0 0 .25em}'
			. '.eurob2b-record__print{font:inherit;padding:.3em .9em;margin:0 0 1.5em;cursor:pointer}'
			. '@page{size:A4;margin:18mm}'
			. '@media print{body{margin:0;max-width:none;padding:0}.eurob2b-record__print{display:none}.eurob2b-record__activity,.eurob2b-record__cover{break-inside:avoid}.eurob2b-record__cover{break-after:page}}';
		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html( $title . ' – ' . get_bloginfo( 'name' ) ) . '</title>'
			. '<style>' . self::CSS . $css . '</style></head><body>'
			. '<h1>' . esc_html( $title ) . '</h1>'
			. ( $print_button ? '<button type="button" class="eurob2b-record__print" onclick="window.print()">' . esc_html__( 'Print', 'eurob2b' ) . '</button>' : '' )
			. $this->record->html()
			. '</body></html>';
	}
}

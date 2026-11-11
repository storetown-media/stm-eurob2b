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
use EuroB2B\Modules\Admin\PageHeader;
use EuroB2B\Modules\Admin\SettingsPage;
use EuroB2B\Modules\Privacy\ProcessingRecord;
use EuroB2B\Support\Kses;

/**
 * STM EU-B2B → Processing record (PRV-010): shows the draft of the record of processing activities
 * and serves it as a standalone HTML document for printing (or saving as PDF) and as a download
 * that opens in a word processor. Capability `eurob2b_manage_settings`; the document handler is an
 * admin_post action with a nonce.
 */
final class ProcessingRecordPage {

	public const SLUG   = 'processing-record';
	public const ACTION = 'eurob2b_processing_record';
	public const NONCE  = 'eurob2b_processing_record';

	/**
	 * Handles of the document's styles and of its print button's script (registered without a file).
	 */
	public const DOCUMENT_STYLE  = 'eurob2b-processing-record';
	public const DOCUMENT_SCRIPT = 'eurob2b-processing-record-print';

	/**
	 * Styles of the record, added with wp_add_inline_style(): on the admin page to the plugin's admin
	 * stylesheet, in the document to its own style handle, which wp_print_styles() prints into the
	 * document, so a downloaded record (a single HTML file opened, edited and archived without the
	 * site) keeps its layout.
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
		$menu->add_page( self::SLUG, __( 'Record of processing activities', 'stm-eu-b2b' ), __( 'Processing record', 'stm-eu-b2b' ), Capabilities::MANAGE_SETTINGS, array( $this, 'render' ), 90 );
		// After PageHeader::enqueue() (priority 10) has enqueued the admin stylesheet.
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_style' ), 20 );
	}

	/**
	 * Hooked to admin_enqueue_scripts: the styles of the record on this page.
	 */
	public static function enqueue_style(): void {
		if ( self::SLUG === PageHeader::current_slug() ) {
			wp_add_inline_style( PageHeader::STYLE, self::CSS );
		}
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
		PageHeader::open( 'eurob2b-processing-record', __( 'Record of processing activities', 'stm-eu-b2b' ) );
		echo '<p>' . esc_html__( 'A draft for your record of processing activities under Art. 30 GDPR, built from the current EU-B2B settings. Print it or save it as a PDF from the print view, or download it as an HTML file that you can edit in a word processor.', 'stm-eu-b2b' ) . '</p>';
		$settings = '<a href="' . esc_url( Menu::url( SettingsPage::SLUG, array( 'tab' => 'privacy' ) ) ) . '">' . esc_html__( 'STM EU-B2B → Settings → Privacy and data', 'stm-eu-b2b' ) . '</a>';
		/* translators: %s: link to the privacy settings */
		echo '<p>' . wp_kses_post( sprintf( esc_html__( 'Phone and e-mail address, contact person, joint controllers, the representative in the EU and the data protection officer are entered under %s; until then the record says "please add".', 'stm-eu-b2b' ), $settings ) ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( self::document_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Print view', 'stm-eu-b2b' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::document_url( true ) ) . '">' . esc_html__( 'Download as HTML file', 'stm-eu-b2b' ) . '</a></p>';
		echo wp_kses( $this->record->html(), Kses::allowed() );
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
		$this->print_document( ! $download );
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
			wp_die( esc_html__( 'You are not allowed to view the record of processing activities.', 'stm-eu-b2b' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * File name of the download, e.g. "processing-record-2026-10-23.html".
	 */
	public static function filename(): string {
		$name = sanitize_file_name( _x( 'processing-record', 'file name', 'stm-eu-b2b' ) );
		return ( '' !== $name ? $name : 'processing-record' ) . '-' . wp_date( 'Y-m-d' ) . '.html';
	}

	/**
	 * The record as a standalone HTML document (print_document() as a string).
	 *
	 * @param bool $print_button Show a print button (the print view; a download goes without).
	 */
	public function document( bool $print_button = true ): string {
		ob_start();
		$this->print_document( $print_button );
		return (string) ob_get_clean();
	}

	/**
	 * Prints the record as a standalone HTML document. Its styles and the script of the print button
	 * go through WordPress's script and style API: registered without a file, filled with
	 * wp_add_inline_style() and wp_add_inline_script(), and printed into the document with
	 * wp_print_styles() and wp_print_scripts().
	 *
	 * @param bool $print_button Show a print button (the print view; a download goes without).
	 */
	public function print_document( bool $print_button = true ): void {
		self::register_document_assets();
		$title = __( 'Record of processing activities', 'stm-eu-b2b' );
		echo '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html( $title . ' – ' . get_bloginfo( 'name' ) ) . '</title>';
		wp_print_styles( self::DOCUMENT_STYLE );
		echo '</head><body>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( $print_button ) {
			echo '<button type="button" class="eurob2b-record__print" data-eurob2b-print>' . esc_html__( 'Print', 'stm-eu-b2b' ) . '</button>';
		}
		echo wp_kses( $this->record->html(), Kses::allowed() );
		if ( $print_button ) {
			wp_print_scripts( self::DOCUMENT_SCRIPT );
		}
		echo '</body></html>';
	}

	/**
	 * Registers the document's style and script handles (no file; inline content only).
	 */
	private static function register_document_assets(): void {
		// A document printed again in the same request (tests) prints its handles again.
		wp_styles()->done  = array_values( array_diff( wp_styles()->done, array( self::DOCUMENT_STYLE ) ) );
		wp_scripts()->done = array_values( array_diff( wp_scripts()->done, array( self::DOCUMENT_SCRIPT ) ) );
		if ( ! wp_style_is( self::DOCUMENT_STYLE, 'registered' ) ) {
			wp_register_style( self::DOCUMENT_STYLE, false, array(), EUROB2B_VERSION );
			wp_add_inline_style(
				self::DOCUMENT_STYLE,
				self::CSS
				. 'body{font:11pt/1.5 Georgia,"Times New Roman",serif;color:#1d2327;margin:2.5em auto;max-width:60rem;padding:0 1em}'
				. 'h1{font-size:1.6em;font-weight:normal;margin:0 0 .25em}'
				. '.eurob2b-record__print{font:inherit;padding:.3em .9em;margin:0 0 1.5em;cursor:pointer}'
				. '@page{size:A4;margin:18mm}'
				. '@media print{body{margin:0;max-width:none;padding:0}.eurob2b-record__print{display:none}.eurob2b-record__activity,.eurob2b-record__cover{break-inside:avoid}.eurob2b-record__cover{break-after:page}}'
			);
		}
		if ( ! wp_script_is( self::DOCUMENT_SCRIPT, 'registered' ) ) {
			wp_register_script( self::DOCUMENT_SCRIPT, false, array(), EUROB2B_VERSION, true );
			wp_add_inline_script( self::DOCUMENT_SCRIPT, 'document.querySelectorAll("[data-eurob2b-print]").forEach(function(b){b.addEventListener("click",function(){window.print();});});' );
		}
	}
}

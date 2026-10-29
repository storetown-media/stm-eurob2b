<?php
/**
 * Admin page: VAT ID validations and manual decisions.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Tax\Validation\ValidationRecord;
use EuroB2B\Modules\Tax\Validation\ValidationService;
use EuroB2B\Modules\Tax\VatId\ExternalServices;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;
use EuroB2B\Modules\Tax\VatId\ViesValidator;

/**
 * Review list (pending, unavailable, invalid) with accept/reject and the recent history (TAX-008, TAX-009).
 */
final class ValidationsPage {

	public const SLUG           = 'vat-validations';
	public const ACTION_DECIDE  = 'eurob2b_decide_vat_validation';
	public const ACTION_RECHECK = 'eurob2b_recheck_vat_validation';
	public const NONCE          = 'eurob2b_vat_validations';

	/**
	 * Service.
	 *
	 * @var ValidationService
	 */
	private ValidationService $service;

	/**
	 * Constructor.
	 *
	 * @param ValidationService $service Service.
	 */
	public function __construct( ValidationService $service ) {
		$this->service = $service;
	}

	/**
	 * Registers the page (visible to everyone who may decide).
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'VAT ID validations', 'eurob2b' ), __( 'VAT IDs', 'eurob2b' ), Capabilities::DECIDE_VALIDATION, array( $this, 'render' ), 40 );
	}

	/**
	 * Number of records waiting for a look.
	 */
	public function review_count(): int {
		return count( $this->service->repository()->review_list( 500 ) );
	}

	/**
	 * Decision handler (accept/reject).
	 */
	public function handle_decide(): void {
		$id = $this->authorise();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$decision = isset( $_POST['decision'] ) ? sanitize_key( (string) wp_unslash( $_POST['decision'] ) ) : '';
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$status = 'accept' === $decision ? ValidationStatus::VALID : ( 'reject' === $decision ? ValidationStatus::INVALID : null );
		if ( null === $status ) {
			$this->redirect( array( 'error' => __( 'Unknown decision.', 'eurob2b' ) ) );
			return;
		}
		try {
			$this->service->decide( $id, $status, get_current_user_id(), $note );
			$this->redirect( array( 'decided' => $id ) );
		} catch ( \Throwable $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Re-check handler: asks the service again, ignoring cached results.
	 */
	public function handle_recheck(): void {
		$id     = $this->authorise();
		$record = $this->service->repository()->find( $id );
		if ( null === $record ) {
			$this->redirect( array( 'error' => __( 'Unknown record.', 'eurob2b' ) ) );
			return;
		}
		$vat_id = $this->service->normalize( $record->vat_id() );
		if ( ! $vat_id instanceof \EuroB2B\Modules\Tax\VatId\VatId ) {
			$this->redirect( array( 'error' => __( 'The stored number cannot be parsed.', 'eurob2b' ) ) );
			return;
		}
		$fresh = $this->service->check( $vat_id, $record->user_id, true );
		$this->redirect( array( 'rechecked' => $fresh->id ) );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::DECIDE_VALIDATION ) ) {
			wp_die( esc_html__( 'You are not allowed to decide on VAT ID validations.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notices.
		$decided   = isset( $_GET['decided'] ) ? absint( $_GET['decided'] ) : 0;
		$rechecked = isset( $_GET['rechecked'] ) ? absint( $_GET['rechecked'] ) : 0;
		$error     = isset( $_GET['error'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap eurob2b-vat-validations"><h1>' . esc_html__( 'VAT ID validations', 'eurob2b' ) . '</h1>';
		if ( $decided > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Decision stored.', 'eurob2b' ) . '</p></div>';
		}
		if ( $rechecked > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The number was checked again.', 'eurob2b' ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( ! $this->service->enabled() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Online validation via VIES is switched off (EuroB2B → Settings → Tax and VAT IDs). Numbers are only checked for syntax until you enable it.', 'eurob2b' ) . '</p></div>';
		}

		$review = $this->service->repository()->review_list( 200 );
		echo '<h2>' . esc_html__( 'Needs a look', 'eurob2b' ) . ' <span class="count">(' . esc_html( (string) count( $review ) ) . ')</span></h2>';
		if ( empty( $review ) ) {
			echo '<p>' . esc_html__( 'Nothing to review.', 'eurob2b' ) . '</p>';
		} else {
			$this->render_table( $review, true );
		}

		echo '<h2>' . esc_html__( 'Recent checks', 'eurob2b' ) . '</h2>';
		$this->render_table( $this->service->repository()->recent( 50 ), false );

		/**
		 * Fires at the end of the VAT IDs page (reports add their forms here). The core adds the country
		 * profiles at priority 20; EuroB2B Pro adds the OSS monitor (4), the recapitulative statement
		 * (5), the export of exempt sales (10) and the special territories (20, after the profiles).
		 */
		do_action( 'eurob2b_vat_validations_page_end' );
		echo '</div>';
	}

	/**
	 * Renders a table of records.
	 *
	 * @param ValidationRecord[] $records  Records.
	 * @param bool               $decidable Show decision forms.
	 */
	private function render_table( array $records, bool $decidable ): void {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'VAT ID', 'eurob2b' ), __( 'Status', 'eurob2b' ), __( 'Reason', 'eurob2b' ), _x( 'Service', 'VAT ID validation service', 'eurob2b' ), __( 'Checked', 'eurob2b' ), __( 'Attempts', 'eurob2b' ), __( 'Customer', 'eurob2b' ), __( 'Evidence', 'eurob2b' ), $decidable ? __( 'Decision', 'eurob2b' ) : __( 'Decided by', 'eurob2b' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $records as $record ) {
			$user = null !== $record->user_id ? get_userdata( $record->user_id ) : false;
			echo '<tr>';
			echo '<td><code>' . esc_html( $record->vat_id() ) . '</code></td>';
			echo '<td>' . esc_html( self::status_label( $record->status ) ) . '</td>';
			echo '<td>' . esc_html( (string) $record->reason_code ) . '</td>';
			echo '<td>' . esc_html( self::service_label( $record->service ) ) . '</td>';
			echo '<td>' . esc_html( self::checked_label( $record ) ) . '</td>';
			echo '<td>' . esc_html( (string) $record->attempts ) . '</td>';
			echo '<td>' . ( $user ? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a>' : '&mdash;' ) . ( null !== $record->order_id ? ' · ' . esc_html_x( 'Order', 'shop order', 'eurob2b' ) . ' #' . esc_html( (string) $record->order_id ) : '' ) . '</td>';
			$qualified = ExternalServices::BZST === $record->service ? ExternalServices::bzst_summary( $record->response_address ) : '';
			echo '<td>' . esc_html( trim( implode( ' ', array_filter( array( $record->consultation_number, $record->response_name, '' !== $qualified ? '(' . $qualified . ')' : '' ) ) ) ) ) . '</td>';
			if ( $decidable ) {
				echo '<td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px;">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DECIDE ) . '" /><input type="hidden" name="id" value="' . esc_attr( (string) $record->id ) . '" />';
				echo '<input type="text" name="note" class="regular-text" placeholder="' . esc_attr__( 'Note (e.g. confirmation letter received)', 'eurob2b' ) . '" style="max-width:220px;" /> ';
				echo '<button type="submit" class="button button-primary" name="decision" value="accept">' . esc_html__( 'Accept', 'eurob2b' ) . '</button> ';
				echo '<button type="submit" class="button" name="decision" value="reject">' . esc_html__( 'Reject', 'eurob2b' ) . '</button>';
				echo '</form>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RECHECK ) . '" /><input type="hidden" name="id" value="' . esc_attr( (string) $record->id ) . '" />';
				echo '<button type="submit" class="button">' . esc_html__( 'Check again', 'eurob2b' ) . '</button>';
				echo '</form>';
				echo '</td>';
			} else {
				$actor = $record->is_decided() ? get_userdata( (int) $record->decided_by ) : false;
				echo '<td>' . ( $actor ? esc_html( $actor->display_name ) . ( '' !== (string) $record->decision_note ? ': ' . esc_html( (string) $record->decision_note ) : '' ) : '&mdash;' ) . '</td>';
			}
			echo '</tr>';
		}//end foreach
		echo '</tbody></table>';
	}

	/**
	 * Check time in the site's time zone and language (wp_date() translates month and day names).
	 *
	 * @param ValidationRecord $record Record.
	 */
	public static function checked_label( ValidationRecord $record ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record->checked_at->getTimestamp() );
	}

	/**
	 * Name of the validation service (proper names, not translated).
	 *
	 * @param string $service Service id.
	 */
	public static function service_label( string $service ): string {
		return match ( $service ) {
			ViesValidator::ID       => 'VIES',
			ExternalServices::BZST  => 'BZSt',
			ExternalServices::UID   => 'UID (BFS)',
			ExternalServices::BRREG => 'Brønnøysund',
			default                 => $service,
		};
	}

	/**
	 * Translatable status label.
	 *
	 * @param ValidationStatus $status Status.
	 */
	public static function status_label( ValidationStatus $status ): string {
		return match ( $status ) {
			ValidationStatus::VALID       => __( 'Valid', 'eurob2b' ),
			ValidationStatus::INVALID     => __( 'Invalid', 'eurob2b' ),
			ValidationStatus::UNAVAILABLE => __( 'Service unavailable', 'eurob2b' ),
			ValidationStatus::PENDING     => __( 'Pending (retrying)', 'eurob2b' ),
		};
	}

	/**
	 * Capability + nonce + record id.
	 */
	private function authorise(): int {
		if ( ! current_user_can( Capabilities::DECIDE_VALIDATION ) ) {
			wp_die( esc_html__( 'You are not allowed to decide on VAT ID validations.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Unknown record.', 'eurob2b' ), '', array( 'response' => 400 ) );
		}
		return $id;
	}

	/**
	 * Redirects back to the page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function redirect( array $args ): void {
		if ( wp_safe_redirect( Menu::url( self::SLUG, $args ) ) ) {
			exit;
		}
	}
}

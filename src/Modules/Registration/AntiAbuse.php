<?php
/**
 * Registration anti-abuse checks.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use EuroB2B\Infrastructure\Schema;

/**
 * Honeypot, per-IP throttle and a hook for external captcha providers, for the registration and
 * (scope `gq`) for quote requests from guests (QUO-015).
 *
 * The IP is never stored in clear text: the throttle key is a salted hash
 * kept in a transient for the throttle window only.
 */
final class AntiAbuse {

	public const HONEYPOT_FIELD = 'eurob2b_website_url';

	/**
	 * Scope of the registration.
	 */
	public const SCOPE_REGISTRATION = 'reg';

	/**
	 * Scope of quote requests from guests.
	 */
	public const SCOPE_GUEST_QUOTE = 'gq';

	/**
	 * Max registrations per IP within the window.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Window in seconds.
	 *
	 * @var int
	 */
	private int $window;

	/**
	 * What is limited (part of the transient key).
	 *
	 * @var string
	 */
	private string $scope;

	/**
	 * Constructor.
	 *
	 * @param int    $limit  Submissions per window (0 = unlimited).
	 * @param int    $window Window in seconds.
	 * @param string $scope  SCOPE_REGISTRATION or SCOPE_GUEST_QUOTE.
	 */
	public function __construct( int $limit = 5, int $window = 3600, string $scope = self::SCOPE_REGISTRATION ) {
		$this->limit  = max( 0, $limit );
		$this->window = max( 60, $window );
		$this->scope  = self::SCOPE_GUEST_QUOTE === $scope ? self::SCOPE_GUEST_QUOTE : self::SCOPE_REGISTRATION;
	}

	/**
	 * Honeypot markup: a field humans never see or fill.
	 */
	public function honeypot_html(): string {
		return '<p class="eurob2b-hp" style="position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden" aria-hidden="true">'
			. '<label for="' . esc_attr( self::HONEYPOT_FIELD ) . '">' . esc_html__( 'Leave this field empty', 'eurob2b' ) . '</label>'
			. '<input type="text" name="' . esc_attr( self::HONEYPOT_FIELD ) . '" id="' . esc_attr( self::HONEYPOT_FIELD ) . '" value="" tabindex="-1" autocomplete="off" />'
			. '</p>';
	}

	/**
	 * Returns an error code when the submission looks abusive, else null.
	 *
	 * Codes: honeypot, throttled, captcha.
	 *
	 * @param array<string, mixed> $post Submitted data.
	 */
	public function check( array $post ): ?string {
		if ( isset( $post[ self::HONEYPOT_FIELD ] ) && '' !== trim( (string) $post[ self::HONEYPOT_FIELD ] ) ) {
			return 'honeypot';
		}

		if ( self::SCOPE_GUEST_QUOTE === $this->scope ) {
			/**
			 * Lets captcha providers veto a quote request from a guest (QUO-015).
			 *
			 * @param bool                 $valid True when the submission passed (default).
			 * @param array<string, mixed> $post  Submitted data.
			 */
			$valid = apply_filters( 'eurob2b_guest_quote_captcha_valid', true, $post );
		} else {
			/**
			 * Lets captcha providers veto a registration.
			 *
			 * @param bool                 $valid True when the submission passed (default).
			 * @param array<string, mixed> $post  Submitted data.
			 */
			$valid = apply_filters( 'eurob2b_registration_captcha_valid', true, $post );
		}
		if ( true !== $valid ) {
			return 'captcha';
		}

		if ( $this->limit > 0 && $this->count() >= $this->limit ) {
			return 'throttled';
		}
		return null;
	}

	/**
	 * Records a submission for the current IP.
	 */
	public function record(): void {
		if ( 0 === $this->limit ) {
			return;
		}
		$key = $this->key();
		if ( null === $key ) {
			return;
		}
		set_transient( $key, $this->count() + 1, $this->window );
	}

	/**
	 * Attempts in the current window.
	 */
	private function count(): int {
		$key = $this->key();
		return null === $key ? 0 : (int) get_transient( $key );
	}

	/**
	 * Transient key derived from a salted IP hash, null when no IP is known.
	 */
	private function key(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return null;
		}
		return Schema::TRANSIENT_PREFIX . $this->scope . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}
}

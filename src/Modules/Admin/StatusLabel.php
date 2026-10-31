<?php
/**
 * Coloured status label of the admin lists.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

/**
 * A status as a small coloured label (PLT-017): green for done or valid, yellow for waiting, red for
 * missing or refused, blue for new, grey for everything else. The text always names the status, so the
 * colour never carries the meaning alone. Styles in assets/css/admin.css (`.eurob2b-status--<tone>`).
 */
final class StatusLabel {

	public const OK      = 'ok';
	public const WARN    = 'warn';
	public const BAD     = 'bad';
	public const INFO    = 'info';
	public const NEUTRAL = 'neutral';

	/**
	 * Escaped HTML of the label.
	 *
	 * @param string $text Status text (plain text, escaped here).
	 * @param string $tone One of the constants; anything else is neutral.
	 */
	public static function html( string $text, string $tone ): string {
		$tone = in_array( $tone, array( self::OK, self::WARN, self::BAD, self::INFO ), true ) ? $tone : self::NEUTRAL;
		return '<span class="eurob2b-status' . ( self::NEUTRAL === $tone ? '' : ' eurob2b-status--' . $tone ) . '">' . esc_html( $text ) . '</span>';
	}
}

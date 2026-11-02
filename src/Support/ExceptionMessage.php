<?php
/**
 * Exception messages at their sinks (PLT-020).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

use Throwable;

/**
 * One rule for exception messages (docs/architecture.md, "Exception messages"):
 *
 * - A translated message meant for a person is escaped where it is thrown (`esc_html__()` or
 *   `esc_html( sprintf( __( … ) ) )`), so no sink can print it unescaped.
 * - An internal, untranslated message (value objects, repositories, migrations) is not escaped
 *   where it is thrown; those classes also run without WordPress (unit tests, the release tools).
 * - Every HTML sink escapes the message again with `html()`: WordPress' esc_html() does not encode
 *   an existing entity a second time, so an escaped message is shown exactly once escaped and
 *   "don't" never turns into "don&#039;t" on screen.
 * - Every plain-text sink (REST responses, WP-CLI, the WooCommerce log) decodes it with `plain()`.
 */
final class ExceptionMessage {

	/**
	 * The message for an HTML context (admin notices, WooCommerce notices, profile errors).
	 *
	 * @param Throwable $e Exception.
	 */
	public static function html( Throwable $e ): string {
		return esc_html( $e->getMessage() );
	}

	/**
	 * The message as plain text (REST, WP-CLI, logs): HTML entities of an escaped message decoded.
	 *
	 * @param Throwable $e Exception.
	 */
	public static function plain( Throwable $e ): string {
		return wp_specialchars_decode( $e->getMessage(), ENT_QUOTES );
	}
}

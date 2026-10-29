<?php
/**
 * Form input carried across the redirect after a failed admin save.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

/**
 * Admin forms post to admin-post.php and redirect back; when the save fails, the form would come back
 * empty. The handler keeps what was entered for the current user (transient, a few minutes) and the
 * form takes it once when it renders again (data inventory D-19).
 */
final class FormDraft {

	/**
	 * Transient prefix (the uninstaller removes every `eurob2b_*` transient).
	 */
	private const PREFIX = 'eurob2b_draft_';

	/**
	 * Lifetime in seconds: long enough for the redirect, short enough not to linger.
	 */
	public const TTL = 300;

	/**
	 * Keeps the entered values of a form for the current user.
	 *
	 * @param string               $form   Form name.
	 * @param array<string, mixed> $values Sanitised values.
	 */
	public static function put( string $form, array $values ): void {
		$user = get_current_user_id();
		if ( $user > 0 ) {
			set_transient( self::key( $form, $user ), $values, self::TTL );
		}
	}

	/**
	 * Returns the kept values of a form once and forgets them.
	 *
	 * @param string $form Form name.
	 * @return array<string, mixed>|null
	 */
	public static function take( string $form ): ?array {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return null;
		}
		$key    = self::key( $form, $user );
		$values = get_transient( $key );
		delete_transient( $key );
		return is_array( $values ) ? $values : null;
	}

	/**
	 * Transient name.
	 *
	 * @param string $form Form name.
	 * @param int    $user User id.
	 */
	private static function key( string $form, int $user ): string {
		return self::PREFIX . sanitize_key( $form ) . '_' . $user;
	}
}

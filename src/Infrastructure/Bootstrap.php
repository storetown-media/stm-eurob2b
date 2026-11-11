<?php
/**
 * Requirement check and start of the plugin.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure;

use EuroB2B\Plugin;
use EuroB2B\Support\I18n;

/**
 * Starts the plugin on plugins_loaded, or shows why it cannot run.
 *
 * Until 1.7.0 this lived in global functions of the main file (`eurob2b_bootstrap()` and two
 * helpers). Since the rename to EU-B2B (PLT-019) the old folder `eurob2b/` can be installed next to
 * `stm-eu-b2b/`: when WordPress loads both main files, a global function declared by both stops the site
 * with "Cannot redeclare". The main file therefore declares no global function; the old copy's
 * functions keep their names and stay unused (see docs/architecture.md §2.2).
 */
final class Bootstrap {

	/**
	 * Boots the plugin once all plugins are loaded, so WooCommerce can be detected.
	 */
	public static function boot(): void {
		// The check runs untranslated (plugins_loaded is too early for translations, PLT-020); the notice
		// translates its messages when it is shown.
		if ( array() !== I18n::untranslated( array( self::class, 'requirement_problems' ) ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					self::render_requirement_notice( self::requirement_problems() );
				}
			);
			return;
		}

		Plugin::instance()->boot();
	}

	/**
	 * Checks the runtime requirements before anything else is loaded.
	 *
	 * @return string[] Human readable problems, empty when everything is fine.
	 */
	public static function requirement_problems(): array {
		$problems = array();

		if ( version_compare( PHP_VERSION, EUROB2B_MIN_PHP, '<' ) ) {
			$problems[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				__( 'STM EU-B2B requires PHP %1$s or newer. You are running PHP %2$s.', 'stm-eu-b2b' ),
				EUROB2B_MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), EUROB2B_MIN_WP, '<' ) ) {
			$problems[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version */
				__( 'STM EU-B2B requires WordPress %1$s or newer. You are running WordPress %2$s.', 'stm-eu-b2b' ),
				EUROB2B_MIN_WP,
				get_bloginfo( 'version' )
			);
		}

		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			$problems[] = __( 'STM EU-B2B requires WooCommerce to be installed and active.', 'stm-eu-b2b' );
		} elseif ( version_compare( WC_VERSION, EUROB2B_MIN_WC, '<' ) ) {
			$problems[] = sprintf(
				/* translators: 1: required WooCommerce version, 2: current WooCommerce version */
				__( 'STM EU-B2B requires WooCommerce %1$s or newer. You are running WooCommerce %2$s.', 'stm-eu-b2b' ),
				EUROB2B_MIN_WC,
				WC_VERSION
			);
		}

		return $problems;
	}

	/**
	 * Shows unmet requirements as an admin notice instead of failing silently.
	 *
	 * @param string[] $problems Problems reported by requirement_problems().
	 */
	public static function render_requirement_notice( array $problems ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>STM EU-B2B</strong></p><ul>';
		foreach ( $problems as $problem ) {
			echo '<li>' . esc_html( $problem ) . '</li>';
		}
		echo '</ul></div>';
	}
}

<?php
/**
 * Notice for sites that still have the old plugin folder eurob2b/ (PLT-019).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure;

/**
 * EuroB2B 1.7.0 and earlier lived in `eurob2b/eurob2b.php`; since 1.8.0 the plugin is STM EU-B2B in
 * `stm-eu-b2b/stm-eu-b2b.php` and uses the same options, tables and meta (no data migration). While the
 * old plugin is active, it loads first (eurob2b/ sorts before stm-eu-b2b/) and runs; this copy stops at
 * the guard of its main file, which shows its own notice. Once the old plugin is inactive and this copy
 * runs, administrators see this notice while the old folder is still installed: delete the old plugin,
 * all data stays.
 *
 * Deleting the old plugin through the admin runs its uninstall.php. When this copy is active in
 * that request, `before_uninstall()` loads this copy's Uninstaller first, so the old uninstall.php
 * runs it, and `Uninstaller::run()` keeps every piece of data because another copy of the plugin
 * uses it. The notice still asks to switch off "Remove all data on uninstall" before deleting, for
 * the case that the old plugin is deleted while this one is not active (then the old uninstall
 * routine runs as it is). No setting is changed here.
 */
final class RenamedPluginNotice {

	/**
	 * Main file of the plugin up to 1.7.0, relative to the plugins folder.
	 */
	public const OLD_BASENAME = 'eurob2b/eurob2b.php';

	/**
	 * Hooks the notice and the uninstall guard (main file; independent of the requirement check).
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'network_admin_notices', array( self::class, 'render' ) );
		add_action( 'pre_uninstall_plugin', array( self::class, 'before_uninstall' ) );
	}

	/**
	 * Whether the old main file is installed next to the running copy.
	 *
	 * @param string $plugins_dir      Plugins folder (WP_PLUGIN_DIR).
	 * @param string $running_basename Basename of the running copy (EUROB2B_PLUGIN_BASENAME).
	 */
	public static function old_copy_installed( string $plugins_dir, string $running_basename ): bool {
		return self::OLD_BASENAME !== $running_basename && is_file( rtrim( $plugins_dir, '/\\' ) . '/' . self::OLD_BASENAME );
	}

	/**
	 * The paragraphs of the notice.
	 *
	 * @param bool $old_active  Whether the old plugin is active (on this site or network-wide).
	 * @param bool $remove_data Whether "Remove all data on uninstall" is on.
	 * @return array<int, array{0: string, 1: bool}> Text and whether it is the warning about the data.
	 */
	public static function messages( bool $old_active, bool $remove_data ): array {
		$messages   = array();
		$messages[] = array( __( 'EuroB2B is now called STM EU-B2B. The old plugin "EuroB2B" (folder eurob2b) is still installed. Your settings and data stay: EU-B2B uses them as they are.', 'stm-eu-b2b' ), false );
		$messages[] = array(
			$old_active
				? __( 'Deactivate the old plugin "EuroB2B" under Plugins and then delete it. Keep EU-B2B active while you do so; then deleting the old plugin removes only its files.', 'stm-eu-b2b' )
				: __( 'Delete the old plugin "EuroB2B" under Plugins. Keep EU-B2B active while you do so; then deleting the old plugin removes only its files.', 'stm-eu-b2b' ),
			false,
		);
		if ( $remove_data ) {
			$messages[] = array( __( 'Important: the setting "Remove all data on uninstall" is on. Deleting the old plugin runs its uninstall routine, and EU-B2B uses the same data. Switch the setting off under STM EU-B2B → Settings → Privacy and data before you delete the old plugin; you can switch it on again afterwards.', 'stm-eu-b2b' ), true );
		}
		return $messages;
	}

	/**
	 * Shows the notice to users who may manage plugins while the old folder is installed.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! defined( 'WP_PLUGIN_DIR' ) || ! self::old_copy_installed( (string) WP_PLUGIN_DIR, (string) EUROB2B_PLUGIN_BASENAME ) ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		echo self::html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
			is_plugin_active( self::OLD_BASENAME ),
			(bool) get_option( Schema::OPTION_REMOVE_DATA, false ),
			is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' )
		);
	}

	/**
	 * The notice as HTML (escaped).
	 *
	 * @param bool   $old_active  Whether the old plugin is active.
	 * @param bool   $remove_data Whether "Remove all data on uninstall" is on.
	 * @param string $plugins_url URL of the plugins screen.
	 */
	public static function html( bool $old_active, bool $remove_data, string $plugins_url ): string {
		$html = '<div class="notice ' . ( $remove_data ? 'notice-error' : 'notice-warning' ) . '"><p><strong>STM EU-B2B</strong></p>';
		foreach ( self::messages( $old_active, $remove_data ) as $message ) {
			$html .= '<p>' . ( $message[1] ? '<strong>' . esc_html( $message[0] ) . '</strong>' : esc_html( $message[0] ) ) . '</p>';
		}
		return $html . '<p><a href="' . esc_url( $plugins_url ) . '">' . esc_html__( 'Open the plugins page', 'stm-eu-b2b' ) . '</a></p></div>';
	}

	/**
	 * Before WordPress includes the old plugin's uninstall.php: loads this copy's Uninstaller, which
	 * the old uninstall.php then calls (a class that is loaded is not loaded again from the old
	 * folder) and which keeps the data while this copy runs.
	 *
	 * @param mixed $plugin Basename of the plugin being uninstalled.
	 */
	public static function before_uninstall( $plugin ): void {
		if ( self::OLD_BASENAME === $plugin ) {
			class_exists( Uninstaller::class );
		}
	}
}

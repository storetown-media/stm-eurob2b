<?php
/**
 * Plugin Name:       STM EU-B2B
 * Plugin URI:        https://github.com/storetown-media/eurob2b
 * Description:       B2B for WooCommerce, built for Europe: business customers, group prices, VAT ID check with VIES, reverse charge decisions and privacy by design. More with the add-on STM EU-B2B Pro.
 * Version:           1.8.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 10.0
 * WC tested up to:   11.2
 * Author:            Storetown Media
 * Author URI:        https://storetown-media.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stm-eu-b2b
 * Domain Path:       /languages
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/*
 * Another copy of the plugin is already loaded; never bootstrap twice. Since 1.8.0 (PLT-019) the
 * plugin lives in stm-eu-b2b/, and the folder eurob2b/ of EuroB2B 1.7.0 and earlier may still be
 * installed. WordPress loads active plugins in alphabetical order, so while the old plugin is active
 * eurob2b/ comes first and runs, and this file stops here: no constant, no hook, no activation hook,
 * so activating STM EU-B2B next to the old plugin changes nothing until the old one is deactivated.
 * This file declares no global function, so neither copy runs into "Cannot redeclare".
 */
if ( defined( 'EUROB2B_VERSION' ) ) {
	if ( defined( 'EUROB2B_PLUGIN_FILE' ) && __FILE__ !== EUROB2B_PLUGIN_FILE && function_exists( 'add_action' ) ) {
		// The other copy runs in this request and in every request until it is deactivated.
		$eurob2b_other_copy = array( (string) EUROB2B_VERSION, basename( dirname( (string) EUROB2B_PLUGIN_FILE ) ) );
		add_action(
			'admin_notices',
			static function () use ( $eurob2b_other_copy ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- this copy stops at its guard while the old plugin runs, so the bootstrap that registers the language folder never runs; the notice registers languages/ of this copy itself.
				load_plugin_textdomain( 'stm-eu-b2b', false, basename( __DIR__ ) . '/languages' );
				$eurob2b_message = 'eurob2b' === $eurob2b_other_copy[1]
					? sprintf(
						/* translators: %s: version number of the old plugin */
						__( 'The old plugin "EuroB2B" %s (folder eurob2b) is still active and runs instead of STM EU-B2B. Deactivate "EuroB2B" under Plugins: EU-B2B then takes over with all settings and data. Afterwards delete the old plugin while EU-B2B stays active.', 'stm-eu-b2b' ),
						$eurob2b_other_copy[0]
					)
					: sprintf(
						/* translators: 1: version number, 2: folder name */
						__( 'Another copy of the plugin, version %1$s in the folder %2$s, was loaded first and runs instead of this one. Deactivate it under Plugins; EU-B2B then takes over with all settings and data.', 'stm-eu-b2b' ),
						$eurob2b_other_copy[0],
						$eurob2b_other_copy[1]
					);
				echo '<div class="notice notice-warning"><p><strong>STM EU-B2B</strong></p><p>' . esc_html( $eurob2b_message ) . '</p></div>';
			}
		);
	}//end if
	return;
}//end if

define( 'EUROB2B_VERSION', '1.8.0' );
define( 'EUROB2B_DB_VERSION', 2 );
define( 'EUROB2B_PLUGIN_FILE', __FILE__ );
define( 'EUROB2B_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EUROB2B_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EUROB2B_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'EUROB2B_MIN_PHP', '8.1' );
define( 'EUROB2B_MIN_WP', '6.7' );
define( 'EUROB2B_MIN_WC', '10.0' );

/*
 * Autoloading: a Composer autoloader is used when present (development checkout);
 * distributed builds ship the same autoloader. The PSR-4 fallback keeps the plugin
 * bootable from a plain checkout without Composer.
 */
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'EuroB2B\\';
			if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}
// dompdf (OFR-011) is only needed by modules of EU-B2B Pro, which loads it (PLT-015).
require_once __DIR__ . '/src/functions.php';

// Boots once all plugins are loaded, so WooCommerce can be detected (EuroB2B\Infrastructure\Bootstrap).
add_action( 'plugins_loaded', array( EuroB2B\Infrastructure\Bootstrap::class, 'boot' ), 5 );
// PLT-019: the notice while the old folder eurob2b/ is installed, and the guard for its uninstall.php.
EuroB2B\Infrastructure\RenamedPluginNotice::register();

/*
 * WooCommerce feature compatibility must be declared before WooCommerce initialises,
 * independent of whether the requirement check passes.
 */
add_action( 'before_woocommerce_init', array( EuroB2B\Infrastructure\Compatibility\WooCommerceFeatures::class, 'declare_compatibility' ) );

register_activation_hook( __FILE__, array( EuroB2B\Infrastructure\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( EuroB2B\Infrastructure\Installer::class, 'deactivate' ) );
// A site added to a network where EU-B2B is network-active gets its tables at once (after WordPress's own, priority 10).
add_action( 'wp_initialize_site', array( EuroB2B\Infrastructure\Installer::class, 'initialize_site' ), 200 );

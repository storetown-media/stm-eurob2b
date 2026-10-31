<?php
/**
 * Plugin Name:       EuroB2B
 * Plugin URI:        https://github.com/storetown-media/eurob2b
 * Description:       B2B for WooCommerce, built for Europe: business customers, group prices, VAT ID check with VIES, reverse charge decisions and privacy by design. More with the add-on EuroB2B Pro.
 * Version:           1.6.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 10.0
 * WC tested up to:   11.2
 * Author:            Storetown Media
 * Author URI:        https://storetown-media.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eurob2b
 * Domain Path:       /languages
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( defined( 'EUROB2B_VERSION' ) ) {
	// Another copy of the plugin is already loaded; never bootstrap twice.
	return;
}

define( 'EUROB2B_VERSION', '1.6.0' );
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
// dompdf (OFR-011) is only needed by modules of EuroB2B Pro, which loads it (PLT-015).
require_once __DIR__ . '/src/functions.php';

/**
 * Checks the runtime requirements before anything else is loaded.
 *
 * @return string[] Human readable problems, empty when everything is fine.
 */
function eurob2b_requirement_problems(): array {
	$problems = array();

	if ( version_compare( PHP_VERSION, EUROB2B_MIN_PHP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'EuroB2B requires PHP %1$s or newer. You are running PHP %2$s.', 'eurob2b' ),
			EUROB2B_MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), EUROB2B_MIN_WP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'EuroB2B requires WordPress %1$s or newer. You are running WordPress %2$s.', 'eurob2b' ),
			EUROB2B_MIN_WP,
			get_bloginfo( 'version' )
		);
	}

	if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
		$problems[] = __( 'EuroB2B requires WooCommerce to be installed and active.', 'eurob2b' );
	} elseif ( version_compare( WC_VERSION, EUROB2B_MIN_WC, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required WooCommerce version, 2: current WooCommerce version */
			__( 'EuroB2B requires WooCommerce %1$s or newer. You are running WooCommerce %2$s.', 'eurob2b' ),
			EUROB2B_MIN_WC,
			WC_VERSION
		);
	}

	return $problems;
}

/**
 * Shows unmet requirements as an admin notice instead of failing silently.
 *
 * @param string[] $problems Problems reported by eurob2b_requirement_problems().
 */
function eurob2b_render_requirement_notice( array $problems ): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>EuroB2B</strong></p><ul>';
	foreach ( $problems as $problem ) {
		echo '<li>' . esc_html( $problem ) . '</li>';
	}
	echo '</ul></div>';
}

/**
 * Boots the plugin once all plugins are loaded, so WooCommerce can be detected.
 */
function eurob2b_bootstrap(): void {
	$problems = eurob2b_requirement_problems();

	if ( ! empty( $problems ) ) {
		add_action(
			'admin_notices',
			static function () use ( $problems ): void {
				eurob2b_render_requirement_notice( $problems );
			}
		);
		return;
	}

	EuroB2B\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'eurob2b_bootstrap', 5 );

/*
 * WooCommerce feature compatibility must be declared before WooCommerce initialises,
 * independent of whether the requirement check passes.
 */
add_action( 'before_woocommerce_init', array( EuroB2B\Infrastructure\Compatibility\WooCommerceFeatures::class, 'declare_compatibility' ) );

register_activation_hook( __FILE__, array( EuroB2B\Infrastructure\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( EuroB2B\Infrastructure\Installer::class, 'deactivate' ) );
// A site added to a network where EuroB2B is network-active gets its tables at once (after WordPress's own, priority 10).
add_action( 'wp_initialize_site', array( EuroB2B\Infrastructure\Installer::class, 'initialize_site' ), 200 );

<?php
/**
 * Runs when the plugin is deleted through the WordPress admin.
 *
 * Data is only removed when the shop owner explicitly opted in via the
 * "Remove all data on uninstall" setting. Business records such as quotes,
 * offers and VAT validation evidence may be subject to statutory retention
 * duties, so keeping them is the safe default (see docs/privacy-and-data-flows.md).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	require_once __DIR__ . '/src/Infrastructure/Uninstaller.php';
	require_once __DIR__ . '/src/Infrastructure/Schema.php';
}

EuroB2B\Infrastructure\Uninstaller::run();

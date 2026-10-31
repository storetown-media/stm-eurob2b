<?php
/**
 * Uninstall routine.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure;

/**
 * Removes plugin data only when the shop owner opted in (PLT-007, PRV-005).
 *
 * Without the opt-in only the version marker and the scheduled events go: events are not data,
 * and without the plugin code nothing would handle them. With the opt-in the plugin's tables,
 * options, transients, user meta, product, variation and coupon meta and term meta are deleted and
 * the capabilities removed. Order meta always stays: orders are WooCommerce's records.
 *
 * In a network every site is handled with its own opt-in. User meta is shared by all sites of a
 * network, so it is only deleted when every site that used EuroB2B opted in (PLT-012).
 */
final class Uninstaller {

	/**
	 * Entry point from uninstall.php.
	 */
	public static function run(): void {
		// WordPress only includes uninstall.php from the plugin deletion flow (admin with
		// delete_plugins, or WP-CLI without a user), so no capability check is repeated here.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$all = true;
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				// A site where EuroB2B never ran has nothing to remove and no say about the shared user meta.
				if ( false !== get_option( Schema::OPTION_INSTALLED_AT, false ) ) {
					$all = self::run_site() && $all;
				}
				restore_current_blog();
			}
			if ( $all ) {
				self::delete_user_meta();
			}
			return;
		}
		if ( self::run_site() ) {
			self::delete_user_meta();
		}
	}

	/**
	 * Removes the controller details of the record of processing activities from the stored settings
	 * (`Schema::RECORD_CONTROLLER_SETTINGS`); the other settings stay.
	 */
	private static function remove_record_controller(): void {
		$settings = get_option( Schema::OPTION_SETTINGS );
		if ( ! is_array( $settings ) ) {
			return;
		}
		$kept = array_diff_key( $settings, array_flip( Schema::RECORD_CONTROLLER_SETTINGS ) );
		if ( count( $kept ) !== count( $settings ) ) {
			update_option( Schema::OPTION_SETTINGS, $kept );
		}
	}

	/**
	 * Deletes a folder with its files and subfolders (two levels, as the offer PDF writes them).
	 *
	 * @param string $folder Folder.
	 */
	private static function remove_folder( string $folder ): void {
		if ( ! is_dir( $folder ) ) {
			return;
		}
		foreach ( array_merge( (array) glob( $folder . '/*/*' ), (array) glob( $folder . '/*' ), array( $folder ) ) as $path ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				wp_delete_file( $path );
			} elseif ( is_string( $path ) && is_dir( $path ) ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty by now.
			}
		}
	}

	/**
	 * Uninstall of the current site; true when its data was removed (opt-in).
	 */
	private static function run_site(): bool {
		self::unschedule();

		$remove_data = (bool) get_option( Schema::OPTION_REMOVE_DATA, false );
		if ( ! $remove_data ) {
			// Default: keep all data. Only the version marker goes so a re-install migrates cleanly, and the
			// controller details of the record of processing activities, which are never kept.
			delete_option( Schema::OPTION_PLUGIN_VERSION );
			self::remove_record_controller();
			return false;
		}

		global $wpdb;

		// Private files (PRV-006): the stored documents and the folder's protection files.
		$private = \EuroB2B\Modules\Privacy\PrivateFiles::dir();
		if ( is_dir( $private ) ) {
			// The font cache and leftover e-mail attachments of the offer PDF (OFR-011).
			self::remove_folder( $private . '/pdf-fonts' );
			self::remove_folder( $private . '/pdf-tmp' );
			foreach ( array_merge( (array) glob( $private . '/*.bin' ), array( $private . '/.htaccess', $private . '/index.php' ) ) as $path ) {
				if ( is_string( $path ) && is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
			@rmdir( $private ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- stays when other files were put there.
		}

		foreach ( Schema::table_suffixes() as $suffix ) {
			$table = Schema::table( $suffix );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		foreach ( Schema::options() as $option ) {
			delete_option( $option );
		}

		$prefix = $wpdb->esc_like( Schema::USER_META_PREFIX ) . '%';

		// Product, variation and coupon settings (group prices, tiers, quantities, visibility, coupon audiences).
		$types        = Schema::meta_post_types();
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND p.post_type IN ( {$placeholders} )",
				array_merge( array( $prefix ), $types )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// Category visibility.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $prefix ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $wpdb->esc_like( Schema::TRANSIENT_PREFIX ) . '%',
				'_transient_timeout_' . $wpdb->esc_like( Schema::TRANSIENT_PREFIX ) . '%'
			)
		);
		// Send locks of the dunning (PAY-023) that a request which died left behind.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'eurob2b_dunning_lock_' ) . '%' ) );

		if ( function_exists( 'wp_roles' ) ) {
			require_once __DIR__ . '/Capabilities.php';
			Capabilities::remove();
		}
		return true;
	}

	/**
	 * Deletes the plugin's user meta (shared by every site of a network).
	 */
	private static function delete_user_meta(): void {
		global $wpdb;
		$prefix = $wpdb->esc_like( Schema::USER_META_PREFIX ) . '%';

		// Plugin user meta and the values of the plugin's Checkout block fields WooCommerce keeps per customer.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s",
				$prefix,
				$wpdb->esc_like( '_wc_billing/eurob2b/' ) . '%',
				$wpdb->esc_like( '_wc_shipping/eurob2b/' ) . '%',
				$wpdb->esc_like( '_wc_other/eurob2b/' ) . '%'
			)
		);
	}

	/**
	 * Removes every scheduled event of the plugin (WP-Cron and Action Scheduler).
	 */
	private static function unschedule(): void {
		foreach ( Schema::cron_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Schema::ACTION_GROUP );
		}
	}
}

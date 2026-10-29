<?php
/**
 * Activation, deactivation and upgrade routines.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure;

use EuroB2B\Infrastructure\Migration\Migrator;
use EuroB2B\Modules\Customers\GroupRepository;

/**
 * Keeps the database schema in step with the plugin version.
 */
final class Installer {

	/**
	 * Most sites a network activation provisions at once; the others migrate on their first request.
	 */
	public const NETWORK_BATCH = 100;

	/**
	 * Activation hook. Runs on a fresh install and on re-activation; a network activation provisions
	 * the network's sites (PLT-012).
	 *
	 * @param bool $network_wide Activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		// No capability check here: WordPress already requires activate_plugins to reach this
		// hook, and WP-CLI activates without a current user, which must work too.
		if ( $network_wide && is_multisite() ) {
			self::for_each_site( array( self::class, 'activate_site' ), self::NETWORK_BATCH );
			return;
		}
		self::activate_site();
	}

	/**
	 * Activation of the current site.
	 */
	public static function activate_site(): void {
		self::install_or_upgrade();

		/**
		 * Fires after the plugin was activated and the schema is current.
		 */
		do_action( 'eurob2b_activated' );
	}

	/**
	 * A site created while the plugin is active for the whole network gets its tables at once.
	 *
	 * @param mixed $site New site (WP_Site).
	 */
	public static function initialize_site( $site ): void {
		if ( ! is_object( $site ) || ! isset( $site->blog_id ) || ! is_multisite() ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( EUROB2B_PLUGIN_BASENAME ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::activate_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Runs a callback on every site of the network (up to $limit sites, 0 = all).
	 *
	 * @param callable $callback Callback.
	 * @param int      $limit    Most sites.
	 */
	public static function for_each_site( callable $callback, int $limit = 0 ): void {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => $limit,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				$callback();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Deactivation hook. Must not delete data: deactivation is not uninstallation.
	 *
	 * The migration lock is deliberately left alone: a concurrent request may be
	 * migrating right now, and deleting its lock would let a later request run
	 * the same migration in parallel. A leftover lock expires on its own after
	 * MigrationLock::DEFAULT_TTL seconds. A network deactivation runs it on every site.
	 *
	 * @param bool $network_wide Deactivated for the whole network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			self::for_each_site( array( self::class, 'deactivate_site' ) );
			return;
		}
		self::deactivate_site();
	}

	/**
	 * Deactivation of the current site.
	 */
	public static function deactivate_site(): void {
		/**
		 * Fires on deactivation, e.g. to unschedule cron events.
		 */
		do_action( 'eurob2b_deactivated' );
	}

	/**
	 * Called on every request (init). Migrates after a plugin update without re-activation.
	 */
	public static function maybe_upgrade(): void {
		$stored   = (string) get_option( Schema::OPTION_PLUGIN_VERSION, '' );
		$migrator = Migrator::with_default_migrations();
		if ( EUROB2B_VERSION === $stored && ! $migrator->needs_migration() ) {
			return;
		}
		self::install_or_upgrade( $migrator );
		add_action( 'admin_notices', array( self::class, 'render_migration_error_notice' ) );
	}

	/**
	 * Tells administrators when a migration failed instead of failing silently.
	 */
	public static function render_migration_error_notice(): void {
		$error = Migrator::last_error();
		if ( null === $error || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>EuroB2B</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: schema version, 2: error message, 3: UTC timestamp */
				__( 'Database migration %1$d failed and will be retried on the next request. Error: %2$s (at %3$s UTC). See the recovery steps in the plugin documentation.', 'eurob2b' ),
				$error['version'],
				$error['message'],
				$error['time']
			)
		);
		echo '</p></div>';
	}

	/**
	 * Runs migrations and records the plugin version.
	 *
	 * @param Migrator|null $migrator Migrator to use (default set when null).
	 */
	private static function install_or_upgrade( ?Migrator $migrator = null ): void {
		$migrator = $migrator ?? Migrator::with_default_migrations();
		$applied  = $migrator->migrate();

		// The plugin version is recorded only when the schema is complete, so a failed
		// migration is retried on every request instead of being forgotten.
		if ( ! $migrator->needs_migration() ) {
			update_option( Schema::OPTION_PLUGIN_VERSION, EUROB2B_VERSION, true );
		}

		// Capabilities and the install timestamp are (re)applied on every version change, not only
		// on activation: a plugin updated by file copy never fires the activation hook.
		Capabilities::install();
		if ( ! $migrator->needs_migration() ) {
			( new GroupRepository() )->ensure_defaults();
		}
		if ( ! get_option( Schema::OPTION_INSTALLED_AT ) ) {
			add_option( Schema::OPTION_INSTALLED_AT, gmdate( 'Y-m-d H:i:s' ), '', false );
		}

		if ( ! empty( $applied ) ) {
			/**
			 * Fires after database migrations ran.
			 *
			 * @param int[] $applied Schema versions applied in this run.
			 */
			do_action( 'eurob2b_migrated', $applied );
		}
	}
}

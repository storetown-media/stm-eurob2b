<?php
/**
 * Customers module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

use EuroB2B\Container;
use EuroB2B\Infrastructure\Migration\Migrator;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Customers\Admin\GroupsPage;
use EuroB2B\Modules\Customers\Admin\UpgradeSuggestionsPage;
use EuroB2B\Modules\Customers\Upgrades\UpgradeSuggestions;
use EuroB2B\Modules\Customers\Admin\UserProfileFields;
use EuroB2B\Modules\Customers\Admin\UsersListFilters;

/**
 * Customer groups, customer type, approval status and the audience context.
 */
final class CustomersModule extends AbstractModule {

	/**
	 * User meta: approval state (pending|approved|rejected). Missing = approved (plain customer).
	 */
	public const META_STATUS = '_eurob2b_status';

	/**
	 * User meta: assigned group id.
	 */
	public const META_GROUP_ID = '_eurob2b_group_id';

	/**
	 * User meta: customer type (b2b|b2c). Missing = b2c.
	 */
	public const META_CUSTOMER_TYPE = '_eurob2b_customer_type';

	/**
	 * User meta: approval timestamp (UTC) and approving user id.
	 */
	public const META_APPROVED_AT = '_eurob2b_approved_at';
	public const META_APPROVED_BY = '_eurob2b_approved_by';

	/**
	 * Meta keys of the rejection audit (time and actor of the last rejection).
	 */
	public const META_REJECTED_AT = '_eurob2b_rejected_at';
	public const META_REJECTED_BY = '_eurob2b_rejected_by';

	/**
	 * User meta: staff keeps the customer in the group; no upgrade suggestions (GRP-008). The value is
	 * the time (UTC) it was set.
	 */
	public const META_GROUP_HOLD = '_eurob2b_group_hold';

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'customers';
	}

	/**
	 * Registers services.
	 *
	 * @param Container $container Service container.
	 */
	public function register( Container $container ): void {
		$container->set( GroupRepository::class, static fn(): GroupRepository => new GroupRepository() );
		$container->set( AudienceResolver::class, static fn( Container $c ): AudienceResolver => new AudienceResolver( $c->get( GroupRepository::class ) ) );
		$container->set( CustomerService::class, static fn( Container $c ): CustomerService => new CustomerService( $c->get( GroupRepository::class ), $c->get( AudienceResolver::class ) ) );
		$container->set( GroupsPage::class, static fn( Container $c ): GroupsPage => new GroupsPage( $c->get( GroupRepository::class ) ) );
		$container->set( UserProfileFields::class, static fn( Container $c ): UserProfileFields => new UserProfileFields( $c->get( GroupRepository::class ), $c->get( AudienceResolver::class ), $c->get( CustomerService::class ) ) );
		$container->set( LoginGuard::class, static fn( Container $c ): LoginGuard => new LoginGuard( $c->get( AudienceResolver::class ) ) );
		$container->set( UsersListFilters::class, static fn( Container $c ): UsersListFilters => new UsersListFilters( $c->get( GroupRepository::class ) ) );
		$container->set( UpgradeSuggestions::class, static fn( Container $c ): UpgradeSuggestions => new UpgradeSuggestions( $c->get( GroupRepository::class ), $c->get( CustomerService::class ), $c->get( AudienceResolver::class ) ) );
		$container->set( UpgradeSuggestionsPage::class, static fn( Container $c ): UpgradeSuggestionsPage => new UpgradeSuggestionsPage( $c->get( UpgradeSuggestions::class ) ) );
	}

	/**
	 * Adds hooks.
	 *
	 * @param Container $container Service container.
	 */
	public function boot( Container $container ): void {
		$groups = $container->get( GroupRepository::class );

		add_action( 'eurob2b_activated', array( $groups, 'ensure_defaults' ) );
		add_action( 'eurob2b_migrated', array( $groups, 'ensure_defaults' ) );
		// Plugins updated by file copy fire neither activation nor a migration; seed lazily in the admin,
		// but only once the schema is complete (a pending migration must show its notice, not fatal here).
		add_action(
			'admin_init',
			static function () use ( $groups ): void {
				if ( ! Migrator::with_default_migrations()->needs_migration() ) {
					$groups->ensure_defaults();
				}
			}
		);

		// Pending and rejected customers cannot log in; priority 30 runs after the password check.
		add_filter( 'authenticate', array( $container->get( LoginGuard::class ), 'check' ), 30 );
		// REST requests authenticated with an application password bypass "authenticate".
		add_filter( 'determine_current_user', array( $container->get( LoginGuard::class ), 'check_api_user' ), 30 );

		// Drop the resolver cache when the user changes within a request (tests, impersonation).
		$resolver = $container->get( AudienceResolver::class );
		add_action( 'set_current_user', static fn() => $resolver->flush() );
		add_action( 'wp_login', static fn() => $resolver->flush() );

		if ( ! is_admin() ) {
			return;
		}

		$page = $container->get( GroupsPage::class );
		add_action( 'eurob2b_admin_menu', array( $page, 'register_page' ) );
		add_action( 'admin_post_' . GroupsPage::ACTION_SAVE, array( $page, 'handle_save' ) );
		add_action( 'admin_post_' . GroupsPage::ACTION_DELETE, array( $page, 'handle_delete' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			$suggestions = $container->get( UpgradeSuggestionsPage::class );
			add_action( 'eurob2b_admin_menu', array( $suggestions, 'register_page' ) );
			add_action( 'admin_post_' . UpgradeSuggestionsPage::ACTION, array( $suggestions, 'handle' ) );
		}

		$profile = $container->get( UserProfileFields::class );
		add_action( 'show_user_profile', array( $profile, 'render' ) );
		add_action( 'edit_user_profile', array( $profile, 'render' ) );
		// Validate with core's collector; write only after wp_update_user() succeeded (profile_update),
		// so no other plugin's late rejection can leave a changed status behind.
		add_action( 'user_profile_update_errors', array( $profile, 'validate_before_update' ), 10, 3 );
		add_action( 'profile_update', array( $profile, 'save_after_update' ) );
		add_action( 'admin_notices', array( $profile, 'render_notice' ) );

		$filters = $container->get( UsersListFilters::class );
		add_action( 'restrict_manage_users', array( $filters, 'render' ) );
		add_filter( 'pre_get_users', array( $filters, 'apply' ) );
		add_filter( 'manage_users_columns', array( $filters, 'columns' ) );
		add_filter( 'manage_users_custom_column', array( $filters, 'column_value' ), 10, 3 );
	}
}

<?php
/**
 * Admin module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Registration\FieldRepository;
use EuroB2B\Modules\Registration\RegistrationTypeRepository;

/**
 * Settings framework and the EuroB2B admin menu.
 */
final class AdminModule extends AbstractModule {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'admin';
	}

	/**
	 * Registers services.
	 *
	 * @param Container $container Service container.
	 */
	public function register( Container $container ): void {
		$container->set(
			SettingsSchema::class,
			static function (): SettingsSchema {
				$schema = new SettingsSchema( 'sanitize_text_field' );
				self::core_schema( $schema );

				/**
				 * Lets modules add sections and fields.
				 *
				 * @param SettingsSchema $schema Schema.
				 */
				do_action( 'eurob2b_settings_schema', $schema );

				return $schema;
			}
		);
		// The schema is built on first use, not when Settings is resolved, so modules that add fields
		// in boot() are not missed even if Settings is resolved early.
		$container->set( Settings::class, static fn( Container $c ): Settings => new Settings( static fn(): SettingsSchema => $c->get( SettingsSchema::class ) ) );
		$container->set( Menu::class, static fn(): Menu => new Menu() );
		$container->set( SettingsPage::class, static fn( Container $c ): SettingsPage => new SettingsPage( $c->get( Settings::class ) ) );
		$container->set( SettingsTransfer::class, static fn( Container $c ): SettingsTransfer => new SettingsTransfer( $c->get( Settings::class ), $c->get( GroupRepository::class ), $c->get( RegistrationTypeRepository::class ), $c->get( FieldRepository::class ) ) );
		$container->set( SettingsTransferPage::class, static fn( Container $c ): SettingsTransferPage => new SettingsTransferPage( $c->get( SettingsTransfer::class ) ) );
		$container->set( BlocksCompatibilityNotice::class, static fn(): BlocksCompatibilityNotice => new BlocksCompatibilityNotice() );
	}

	/**
	 * Adds hooks.
	 *
	 * @param Container $container Service container.
	 */
	public function boot( Container $container ): void {
		if ( ! is_admin() ) {
			return;
		}
		// Services are resolved when the hooks fire, i.e. after every module has booted.
		add_action( 'admin_menu', static fn() => $container->get( Menu::class )->register() );
		add_action( 'eurob2b_admin_menu', static fn( Menu $menu ) => $container->get( SettingsPage::class )->register_page( $menu ) );
		add_action( 'admin_post_' . SettingsPage::ACTION, static fn() => $container->get( SettingsPage::class )->handle_save() );
		add_action( 'eurob2b_admin_menu', static fn( Menu $menu ) => $container->get( SettingsTransferPage::class )->register_page( $menu ) );
		add_action( 'admin_post_' . SettingsTransferPage::ACTION_EXPORT, static fn() => $container->get( SettingsTransferPage::class )->handle_export() );
		add_action( 'admin_post_' . SettingsTransferPage::ACTION_CHECK, static fn() => $container->get( SettingsTransferPage::class )->handle_check() );
		add_action( 'admin_post_' . SettingsTransferPage::ACTION_IMPORT, static fn() => $container->get( SettingsTransferPage::class )->handle_import() );
		add_action( 'admin_post_' . SettingsTransferPage::ACTION_BACKUP, static fn() => $container->get( SettingsTransferPage::class )->handle_backup() );
		$container->get( BlocksCompatibilityNotice::class )->hooks();
	}

	/**
	 * Sections and fields owned by the core.
	 *
	 * @param SettingsSchema $schema Schema.
	 */
	private static function core_schema( SettingsSchema $schema ): void {
		$schema->add_section( 'privacy', __( 'Privacy and data', 'eurob2b' ), 900 );

		$schema->add_field(
			array(
				'id'             => 'remove_data_on_uninstall',
				'section'        => 'privacy',
				'label'          => __( 'Remove all data on uninstall', 'eurob2b' ),
				'checkbox_label' => __( 'Delete groups, rules, validation records and plugin settings when the plugin is deleted', 'eurob2b' ),
				'description'    => __( 'Off by default. Business records such as VAT validation evidence may be subject to statutory retention duties; check with your accountant before enabling this.', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => false,
			)
		);
	}
}

<?php
/**
 * Audit log module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Audit;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Admin\SettingsSchema;
use EuroB2B\Modules\Audit\Admin\AuditLogPage;
use EuroB2B\Modules\Customers\GroupRepository;

/**
 * Audit log of administrative decisions (PRV-011, data inventory D-20): who decided on
 * registrations, customer classification, VAT ID checks and orders waiting for approval, and when.
 * Evidence for the accountability of Art. 5(2) GDPR, with its own retention period (default 365
 * days), export, and removal of a person's references on erasure.
 */
final class AuditModule extends AbstractModule {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'audit';
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Service container.
	 */
	public function register( Container $c ): void {
		$c->set( AuditLog::class, static fn(): AuditLog => new AuditLog() );
		$c->set( AuditFormatter::class, static fn( Container $c ): AuditFormatter => new AuditFormatter( $c->get( GroupRepository::class ) ) );
		$c->set( AuditEvents::class, static fn( Container $c ): AuditEvents => new AuditEvents( $c->get( AuditLog::class ) ) );
		$c->set( AuditRetention::class, static fn( Container $c ): AuditRetention => new AuditRetention( $c->get( AuditLog::class ), $c->get( Settings::class ) ) );
		$c->set( AuditDataProvider::class, static fn( Container $c ): AuditDataProvider => new AuditDataProvider( $c->get( AuditLog::class ), $c->get( AuditFormatter::class ) ) );
		$c->set( AuditLogPage::class, static fn( Container $c ): AuditLogPage => new AuditLogPage( $c->get( AuditLog::class ), $c->get( AuditFormatter::class ), $c->get( AuditRetention::class ) ) );
		add_action( 'eurob2b_settings_schema', array( self::class, 'settings_schema' ) );
	}

	/**
	 * Adds hooks.
	 *
	 * @param Container $c Service container.
	 */
	public function boot( Container $c ): void {
		$c->get( AuditEvents::class )->hooks();
		add_filter(
			'eurob2b_personal_data_providers',
			static function ( $providers ) use ( $c ) {
				$providers   = is_array( $providers ) ? $providers : array();
				$providers[] = $c->get( AuditDataProvider::class );
				return $providers;
			}
		);
		add_filter(
			'eurob2b_retention_tasks',
			static function ( $tasks ) use ( $c ) {
				$tasks   = is_array( $tasks ) ? $tasks : array();
				$tasks[] = $c->get( AuditRetention::class );
				return $tasks;
			}
		);
		// A deleted account must not stay referenced in the log; the entries stay without it.
		add_action(
			'deleted_user',
			static function ( $user_id ) use ( $c ): void {
				$c->get( AuditLog::class )->forget_user( (int) $user_id );
			}
		);
		if ( is_admin() ) {
			add_action( 'eurob2b_admin_menu', array( $c->get( AuditLogPage::class ), 'register_page' ) );
		}
	}

	/**
	 * Settings field: retention period.
	 *
	 * @param SettingsSchema $schema Schema.
	 */
	public static function settings_schema( SettingsSchema $schema ): void {
		$schema->add_field(
			array(
				'id'          => 'audit_retention_days',
				'section'     => 'privacy',
				'label'       => __( 'Delete audit log entries after (days)', 'eurob2b' ),
				'description' => __( 'Entries of the audit log (EuroB2B → Audit log) are deleted this many days after they were written by a daily job. Between 30 and 3650 days; the log cannot be kept without a limit.', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_NUMBER,
				'default'     => 365,
				'min'         => AuditRetention::MIN_DAYS,
				'max'         => AuditRetention::MAX_DAYS,
			)
		);
	}
}

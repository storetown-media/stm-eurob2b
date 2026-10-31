<?php
/**
 * Privacy module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Privacy\Admin\ProcessingRecordPage;
use EuroB2B\Modules\Privacy\ProData\ProDataPrivacy;

/**
 * Integrates plugin data with the WordPress personal data export and erasure tools.
 *
 * Every module that stores personal data registers a PersonalDataProviderInterface
 * through the "eurob2b_personal_data_providers" filter; this module wires them
 * into WordPress. Modules with a retention period register a RetentionTaskInterface
 * through "eurob2b_retention_tasks"; this module runs them once a day. The inventory
 * lives in docs/privacy-and-data-flows.md; ProcessingRecord turns it into a draft of the
 * record of processing activities (Art. 30 GDPR). The data that the features of EuroB2B Pro
 * store in the shared schema is handled here as well (ProDataPrivacy), so export, erasure, order
 * anonymisation and retention keep working while EuroB2B Pro is inactive or deleted (PLT-015).
 */
final class PrivacyModule extends AbstractModule {

	/**
	 * Priority of the exporter and eraser registration.
	 */
	public const PRIORITY = 99;

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'privacy';
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Service container.
	 */
	public function register( Container $c ): void {
		$c->set( RetentionRunner::class, static fn(): RetentionRunner => new RetentionRunner() );
		$c->set( PrivacyPolicyText::class, static fn( Container $c ): PrivacyPolicyText => new PrivacyPolicyText( $c->get( Settings::class ) ) );
		$c->set( ProcessingRecord::class, static fn( Container $c ): ProcessingRecord => new ProcessingRecord( $c->get( Settings::class ) ) );
		$c->set( ProcessingRecordPage::class, static fn( Container $c ): ProcessingRecordPage => new ProcessingRecordPage( $c->get( ProcessingRecord::class ) ) );
		$c->set( PrivacySelfTest::class, static fn(): PrivacySelfTest => new PrivacySelfTest() );
		ProDataPrivacy::register( $c );
		add_action( 'eurob2b_settings_schema', array( ProcessingRecord::class, 'settings_schema' ) );
	}

	/**
	 * Adds hooks.
	 *
	 * @param Container $container Service container.
	 */
	public function boot( Container $container ): void {
		// Late, so a plugin that replaces the whole list instead of adding to it (Germanized 4.1.5 with
		// its EU order withdrawal button package does) cannot drop EuroB2B's exporters and erasers.
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ), self::PRIORITY );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ), self::PRIORITY );

		add_action( RetentionRunner::HOOK, static fn() => $container->get( RetentionRunner::class )->run_scheduled() );
		add_action( RetentionRunner::FOLLOW_UP_HOOK, static fn() => $container->get( RetentionRunner::class )->run_scheduled() );
		add_action( 'eurob2b_deactivated', array( RetentionRunner::class, 'unschedule' ) );
		RetentionRunner::schedule();

		// Export, erasure, retention, user deletion and order anonymisation of the data of EuroB2B Pro.
		ProDataPrivacy::hooks( $container );

		add_action( 'admin_init', static fn() => $container->get( PrivacyPolicyText::class )->register() );

		// PRV-009: Site Health test and a warning on the privacy tool screens when a plugin drops entries.
		$container->get( PrivacySelfTest::class )->hooks();
		if ( is_admin() ) {
			$record = $container->get( ProcessingRecordPage::class );
			add_action( 'eurob2b_admin_menu', array( $record, 'register_page' ) );
			add_action( 'admin_post_' . ProcessingRecordPage::ACTION, array( $record, 'handle_document' ) );
		}
	}

	/**
	 * Registered providers.
	 *
	 * @return PersonalDataProviderInterface[]
	 */
	private function providers(): array {
		/**
		 * Collects personal data providers from all modules.
		 *
		 * @param PersonalDataProviderInterface[] $providers Providers.
		 */
		$providers = apply_filters( 'eurob2b_personal_data_providers', array() );
		return array_filter( $providers, static fn( $p ) => $p instanceof PersonalDataProviderInterface );
	}

	/**
	 * Adds one exporter per provider.
	 *
	 * @param array<string, array<string, mixed>> $exporters Existing exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporters( array $exporters ): array {
		foreach ( $this->providers() as $provider ) {
			$exporters[ 'eurob2b-' . $provider->id() ] = array(
				'exporter_friendly_name' => $provider->label(),
				'callback'               => array( $provider, 'export' ),
			);
		}
		return $exporters;
	}

	/**
	 * Adds one eraser per provider.
	 *
	 * @param array<string, array<string, mixed>> $erasers Existing erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_erasers( array $erasers ): array {
		foreach ( $this->providers() as $provider ) {
			$erasers[ 'eurob2b-' . $provider->id() ] = array(
				'eraser_friendly_name' => $provider->label(),
				'callback'             => array( $provider, 'erase' ),
			);
		}
		return $erasers;
	}
}

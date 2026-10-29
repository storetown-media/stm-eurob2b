<?php
/**
 * Orders module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Orders;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Admin\SettingsSchema;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Support\Pages;

/**
 * Plugin additions to WooCommerce orders (HPOS-safe): attributes at purchase, legal texts per
 * customer type (LGL-001, LGL-002).
 */
final class OrdersModule extends AbstractModule {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'orders';
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Service container.
	 */
	public function register( Container $c ): void {
		$c->set( OrderAttributes::class, static fn( Container $c ): OrderAttributes => new OrderAttributes( $c->get( AudienceResolver::class ) ) );
		$c->set( LegalTexts::class, static fn( Container $c ): LegalTexts => new LegalTexts( $c->get( Settings::class ), $c->get( AudienceResolver::class ) ) );

		add_action( 'eurob2b_settings_schema', array( self::class, 'settings_schema' ) );
	}

	/**
	 * Adds hooks.
	 *
	 * @param Container $c Service container.
	 */
	public function boot( Container $c ): void {
		if ( ! class_exists( 'WC_Order' ) ) {
			return;
		}
		$c->get( OrderAttributes::class )->hooks();
		$c->get( LegalTexts::class )->hooks();
	}

	/**
	 * Settings of the legal texts per customer type.
	 *
	 * @param SettingsSchema $schema Schema.
	 */
	public static function settings_schema( SettingsSchema $schema ): void {
		$schema->add_section( 'legal', __( 'Legal texts', 'eurob2b' ), 34 );
		$schema->add_field(
			array(
				'id'          => 'legal_terms_page_b2b',
				'section'     => 'legal',
				'label'       => __( 'Terms and conditions for business customers', 'eurob2b' ),
				'description' => __( 'Approved business customers see this page wherever the shop links the terms and conditions (checkout, Checkout block, legal checkboxes of other plugins). Everyone else keeps the page set in WooCommerce. The order records which page applied and when it was last changed.', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_SELECT,
				'options'     => array( self::class, 'terms_page_options' ),
				'default'     => '',
			)
		);
		$schema->add_field(
			array(
				'id'             => 'legal_withdrawal_consumers_only',
				'section'        => 'legal',
				'label'          => __( 'Withdrawal function only for consumers', 'eurob2b' ),
				'checkbox_label' => __( 'Do not offer the withdrawal button for orders placed by approved business customers', 'eurob2b' ),
				'description'    => __( 'For the plugin "EU Order Withdrawal Button" (also part of Germanized). The right of withdrawal and its electronic function (§ 356a BGB) are for consumers. No official guidance on shops that sell to both was found; decide with your legal adviser before you switch this on.', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => false,
			)
		);
	}

	/**
	 * Pages for the business terms setting, with "same as WooCommerce" first.
	 *
	 * @return array<string, string>
	 */
	public static function terms_page_options(): array {
		return array( '' => __( 'Same page as for everyone (WooCommerce setting)', 'eurob2b' ) ) + Pages::options();
	}
}

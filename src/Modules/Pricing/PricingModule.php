<?php
/**
 * Pricing module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Admin\SettingsSchema;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Pricing\Admin\ProductPriceFields;

/**
 * Group prices, the pricing pipeline, WooCommerce integration and checkout re-validation. Tiers,
 * customer prices, price lists and the recommended retail price are added by EuroB2B Pro
 * (`PricingProModule`, PLT-015). The core keeps their storage (`TierStorage`, `CustomerPrices`),
 * because the import, the group cleanup and the privacy export and erasure of customer prices work
 * without EuroB2B Pro.
 */
final class PricingModule extends AbstractModule {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'pricing';
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Service container.
	 */
	public function register( Container $c ): void {
		$c->set( GroupPrices::class, static fn(): GroupPrices => new GroupPrices() );
		$c->set( CustomerPrices::class, static fn(): CustomerPrices => new CustomerPrices() );
		$c->set( PricingContext::class, static fn(): PricingContext => new PricingContext() );
		$c->set( TierStorage::class, static fn(): TierStorage => new TierStorage() );
		$c->set(
			PriceResolver::class,
			static fn( Container $c ): PriceResolver => new PriceResolver(
				$c->get( AudienceResolver::class ),
				$c->get( Settings::class ),
				array(
					new BasePriceProvider(),
					new GroupPriceProvider( $c->get( GroupPrices::class ) ),
				)
			)
		);
		$c->set( WooCommercePrices::class, static fn( Container $c ): WooCommercePrices => new WooCommercePrices( $c->get( PriceResolver::class ), $c->get( PricingContext::class ), $c->get( AudienceResolver::class ) ) );
		$c->set( CheckoutRevalidation::class, static fn( Container $c ): CheckoutRevalidation => new CheckoutRevalidation( $c->get( PriceResolver::class ), $c->get( PricingContext::class ) ) );
		$c->set( ProductPriceFields::class, static fn( Container $c ): ProductPriceFields => new ProductPriceFields( $c->get( GroupRepository::class ), $c->get( GroupPrices::class ) ) );
		$c->set( CustomerPricesDataProvider::class, static fn( Container $c ): CustomerPricesDataProvider => new CustomerPricesDataProvider( $c->get( CustomerPrices::class ) ) );

		add_action( 'eurob2b_settings_schema', array( self::class, 'settings_schema' ) );
	}

	/**
	 * Adds hooks.
	 *
	 * @param Container $c Service container.
	 */
	public function boot( Container $c ): void {
		// Privacy export and erasure of customer prices (D-18), also of those EuroB2B Pro wrote while it
		// was active; a deleted account takes its prices along.
		add_filter(
			'eurob2b_personal_data_providers',
			static function ( $providers ) use ( $c ) {
				$providers   = is_array( $providers ) ? $providers : array();
				$providers[] = $c->get( CustomerPricesDataProvider::class );
				return $providers;
			}
		);
		add_action(
			'deleted_user',
			static function ( $user_id ) use ( $c ): void {
				$c->get( CustomerPrices::class )->delete_for_user( (int) $user_id );
			}
		);
		add_action(
			'eurob2b_group_deleted',
			static function ( $group ) use ( $c ): void {
				if ( $group instanceof Group ) {
					$c->get( GroupPrices::class )->delete_for_group( $group->id );
				}
			}
		);
		if ( ! class_exists( 'WC_Product' ) ) {
			return;
		}
		$c->get( WooCommercePrices::class )->hooks();
		$c->get( CheckoutRevalidation::class )->hooks();

		// Caches must not outlive the data they derive from.
		$flush = static function () use ( $c ): void {
			$c->get( PriceResolver::class )->flush();
		};
		add_action( 'woocommerce_update_product', $flush );
		add_action( 'woocommerce_update_product_variation', $flush );
		add_action( 'eurob2b_customer_assigned', $flush );
		add_action( 'set_current_user', $flush );
		add_action( 'wp_login', $flush );
		// Prices that depend on the cart (tiers with the "per parent product" basis): while WooCommerce
		// rebuilds the cart from the session it asks for prices with the cart still empty; those must
		// not stick.
		foreach ( array( 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_emptied', 'woocommerce_cart_loaded_from_session' ) as $hook ) {
			add_action( $hook, $flush );
		}

		if ( is_admin() ) {
			$c->get( ProductPriceFields::class )->hooks();
		}
	}

	/**
	 * Settings section.
	 *
	 * @param SettingsSchema $schema Schema.
	 */
	public static function settings_schema( SettingsSchema $schema ): void {
		$schema->add_section( 'pricing', __( 'Prices', 'eurob2b' ), 20 );
		$schema->add_field(
			array(
				'id'          => 'pricing_stacking',
				'section'     => 'pricing',
				'label'       => __( 'Discounts and markups', 'eurob2b' ),
				'description' => __( 'When several discount or markup rules match the same product: apply only the rule with the highest priority (default), or apply all of them one after another in priority order.', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_SELECT,
				'default'     => 'highest',
				'options'     => array(
					'highest' => __( 'Highest priority only', 'eurob2b' ),
					'stack'   => __( 'Stack in priority order', 'eurob2b' ),
				),
			)
		);
	}
}

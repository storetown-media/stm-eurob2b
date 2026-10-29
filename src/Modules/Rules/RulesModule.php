<?php
/**
 * Rules module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Pricing\PriceResolver;
use EuroB2B\Modules\Rules\Admin\RulesPage;

/**
 * Rules engine core (M3.1): storage, evaluation, percentage discount rules in the price pipeline
 * and the admin page. Every other rule type and mode, the conditions and the discount lines in the
 * cart (PRC-018) are added by EuroB2B Pro (`RulesProModule`, PLT-015); `RuleSupport` keeps rules
 * that need it from applying without it.
 */
final class RulesModule extends AbstractModule {

	/**
	 * Cart events after which cached prices and cart facts are stale.
	 */
	public const FLUSH_HOOKS = array( 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_emptied', 'woocommerce_cart_loaded_from_session', 'woocommerce_checkout_update_order_review', 'set_current_user', 'wp_login' );

	/**
	 * Id.
	 */
	public function id(): string {
		return 'rules';
	}

	/**
	 * Services and pipeline registration.
	 *
	 * @param Container $c Container.
	 */
	public function register( Container $c ): void {
		$c->set( RuleRepository::class, static fn(): RuleRepository => new RuleRepository() );
		$c->set(
			RuleEvaluator::class,
			static function ( Container $c ): RuleEvaluator {
				// Conditions are decided by EuroB2B Pro (PLT-015).
				$checker = $c->has( ConditionChecker::class ) ? $c->get( ConditionChecker::class ) : null;
				return new RuleEvaluator( $c->get( RuleRepository::class ), $checker instanceof ConditionChecker ? $checker : null );
			}
		);
		$c->set( RuleAdjustmentProvider::class, static fn( Container $c ): RuleAdjustmentProvider => new RuleAdjustmentProvider( $c->get( RuleEvaluator::class ) ) );
		$c->set( RulesPage::class, static fn( Container $c ): RulesPage => new RulesPage( $c->get( RuleRepository::class ), $c->get( GroupRepository::class ) ) );

		add_filter(
			'eurob2b_price_adjustment_providers',
			static function ( $providers ) use ( $c ) {
				if ( is_array( $providers ) ) {
					$providers[] = $c->get( RuleAdjustmentProvider::class );
				}
				return $providers;
			}
		);
	}

	/**
	 * Hooks.
	 *
	 * @param Container $c Container.
	 */
	public function boot( Container $c ): void {
		if ( ! class_exists( 'WC_Product' ) ) {
			return;
		}

		// Caches must not outlive the facts they derive from. While WooCommerce rebuilds the cart from
		// the session, it asks for prices with the cart still empty (cart_loaded_from_session follows).
		$flush = static function () use ( $c ): void {
			$c->get( PriceResolver::class )->flush();
		};
		foreach ( self::FLUSH_HOOKS as $hook ) {
			add_action( $hook, $flush );
		}
		$rules_changed = static function () use ( $c, $flush ): void {
			$c->get( RuleRepository::class )->flush();
			$flush();
		};
		add_action( 'eurob2b_rules_changed', $rules_changed );
		add_action( 'edited_term', array( Scope::class, 'flush' ) );
		add_action( 'set_object_terms', array( Scope::class, 'flush' ) );

		// Privacy: a deleted account must not stay referenced in audience tokens (of every rule type).
		add_action(
			'deleted_user',
			static function ( $user_id ) use ( $c ): void {
				$c->get( RuleRepository::class )->forget_user( (int) $user_id );
			}
		);

		if ( is_admin() ) {
			$page = $c->get( RulesPage::class );
			add_action( 'eurob2b_admin_menu', array( $page, 'register_page' ) );
			add_action( 'admin_post_' . RulesPage::ACTION_SAVE, array( $page, 'handle_save' ) );
			add_action( 'admin_post_' . RulesPage::ACTION_DELETE, array( $page, 'handle_delete' ) );
			add_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) );
		}
	}
}

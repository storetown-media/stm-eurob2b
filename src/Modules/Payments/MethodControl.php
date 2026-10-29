<?php
/**
 * Applies the method policies at checkout.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments;

use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Pricing\PricingContext;
use WC_Shipping_Rate;

/**
 * PAY-005, SHP-001: filters the available payment gateways and the shipping rates of every package
 * for the current audience. Both the classic checkout and the Store API read
 * `woocommerce_available_payment_gateways` and `woocommerce_package_rates`; the admin order editor
 * uses other APIs and is not affected.
 */
final class MethodControl {

	/**
	 * Policy.
	 *
	 * @var MethodPolicy
	 */
	private MethodPolicy $policy;

	/**
	 * Audience.
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $audiences;

	/**
	 * Storefront detection.
	 *
	 * @var PricingContext
	 */
	private PricingContext $context;

	/**
	 * Constructor.
	 *
	 * @param MethodPolicy     $policy    Policy.
	 * @param AudienceResolver $audiences Audience.
	 * @param PricingContext   $context   Storefront detection.
	 */
	public function __construct( MethodPolicy $policy, AudienceResolver $audiences, PricingContext $context ) {
		$this->policy    = $policy;
		$this->audiences = $audiences;
		$this->context   = $context;
	}

	/**
	 * Hooks.
	 */
	public function hooks(): void {
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_gateways' ), 100 );
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_rates' ), 100, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'mark_packages' ) );
	}

	/**
	 * Puts the shipping audience into every package. WooCommerce recalculates the rates of a package
	 * only when the package changes, so a customer whose group or override changes gets fresh rates.
	 *
	 * @param mixed $packages Packages.
	 * @return mixed
	 */
	public function mark_packages( $packages ) {
		if ( ! is_array( $packages ) || ! $this->context->applies() ) {
			return $packages;
		}
		$audience = $this->audiences->current();
		$override = null !== $audience->user_id ? $this->policy->user_override( $audience->user_id, MethodPolicy::KIND_SHIPPING ) : null;
		$mark     = md5( MethodPolicy::audience_key( $audience ) . '|' . (string) wp_json_encode( $override ) );
		foreach ( $packages as $index => $package ) {
			if ( is_array( $package ) ) {
				$packages[ $index ]['eurob2b_shipping_audience'] = $mark;
			}
		}
		return $packages;
	}

	/**
	 * Keeps only the gateways the audience may use.
	 *
	 * @param mixed $gateways Available gateways (id → gateway).
	 * @return mixed
	 */
	public function filter_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! $this->context->applies() ) {
			return $gateways;
		}
		$audience = $this->audiences->current();
		foreach ( array_keys( $gateways ) as $id ) {
			if ( ! $this->policy->allowed( MethodPolicy::KIND_PAYMENT, $audience, (string) $id ) ) {
				unset( $gateways[ $id ] );
			}
		}
		return $gateways;
	}

	/**
	 * Keeps only the shipping rates the audience may use. Rates of methods outside zones are
	 * controlled only when the shipping policy says so (SHP-002).
	 *
	 * @param mixed $rates   Rates (rate id → WC_Shipping_Rate).
	 * @param mixed $package Package.
	 * @return mixed
	 */
	public function filter_rates( $rates, $package ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce passes the package; the policy does not depend on it.
		if ( ! is_array( $rates ) || ! $this->context->applies() ) {
			return $rates;
		}
		$audience = $this->audiences->current();
		$non_zone = $this->policy->load( MethodPolicy::KIND_SHIPPING )['non_zone'];
		foreach ( $rates as $rate_id => $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate ) {
				continue;
			}
			$key = self::rate_key( $rate );
			if ( ! str_contains( $key, ':' ) && ! $non_zone ) {
				continue;
			}
			if ( ! $this->policy->allowed( MethodPolicy::KIND_SHIPPING, $audience, $key ) ) {
				unset( $rates[ $rate_id ] );
			}
		}
		return $rates;
	}

	/**
	 * Policy key of a rate: method_id:instance_id for zone methods, method_id otherwise.
	 *
	 * @param WC_Shipping_Rate $rate Rate.
	 */
	public static function rate_key( WC_Shipping_Rate $rate ): string {
		$instance = (int) $rate->get_instance_id();
		return $instance > 0 ? $rate->get_method_id() . ':' . $instance : (string) $rate->get_method_id();
	}
}

<?php
/**
 * Server-side re-validation of cart line prices at checkout.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Support\Money;
use WC_Cart;
use WC_Product;
use WP_Error;

/**
 * PRC-021: every cart line is re-priced at checkout from a freshly loaded product (pipeline price,
 * or WooCommerce's own price when the pipeline does not intervene) and compared with the line
 * subtotal the order would be created from. A mismatch (tampered or stale session data,
 * interfering code) aborts the checkout with a message instead of creating an order at the wrong
 * price. Classic checkout and Store API. Plugins that legitimately change line prices (product
 * add-ons) declare their expected unit price through the `eurob2b_checkout_expected_unit_price` filter.
 */
final class CheckoutRevalidation {

	/**
	 * Resolver.
	 *
	 * @var PriceResolver
	 */
	private PriceResolver $resolver;

	/**
	 * Context guard.
	 *
	 * @var PricingContext
	 */
	private PricingContext $context;

	/**
	 * Constructor.
	 *
	 * @param PriceResolver  $resolver Resolver.
	 * @param PricingContext $context  Context guard.
	 */
	public function __construct( PriceResolver $resolver, PricingContext $context ) {
		$this->resolver = $resolver;
		$this->context  = $context;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'validate_store_api' ), 10, 2 );
	}

	/**
	 * Classic checkout: a notice of type "error" aborts order creation.
	 */
	public function validate_classic(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart ) {
			return;
		}
		$mismatches = $this->mismatches( WC()->cart );
		if ( ! empty( $mismatches ) ) {
			wc_add_notice( self::message( $mismatches ), 'error' );
		}
	}

	/**
	 * Store API: errors returned here block the checkout.
	 *
	 * @param mixed $errors WP_Error collector.
	 * @param mixed $cart   Cart.
	 */
	public function validate_store_api( $errors, $cart ): void {
		if ( ! $errors instanceof WP_Error || ! $cart instanceof WC_Cart ) {
			return;
		}
		$mismatches = $this->mismatches( $cart );
		if ( ! empty( $mismatches ) ) {
			$errors->add( 'eurob2b_price_mismatch', self::message( $mismatches ), array( 'status' => 409 ) );
		}
	}

	/**
	 * Lines whose stored subtotal does not match the pipeline price.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array<int, array{key: string, name: string, expected: string, actual: string, expected_total: string, actual_total: string}>
	 */
	public function mismatches( WC_Cart $cart ): array {
		if ( ! $this->context->applies() ) {
			return array();
		}
		$decimals   = wc_get_price_decimals();
		$currency   = get_woocommerce_currency();
		$mismatches = array();
		$discounts  = self::discounts_by_item( $cart );
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			// A fresh product object: never the cart's (possibly manipulated) copy.
			$fresh = wc_get_product( $item['data']->get_id() );
			if ( ! $fresh instanceof WC_Product ) {
				continue;
			}
			// Uncached: a result computed from the cart's own (mutable) product object must never count.
			$resolved = $this->resolver->resolve_uncached( $fresh, null, $quantity );
			$unit     = $resolved->replaces_base() ? $resolved->price() : (string) $fresh->get_price();
			/**
			 * Filters the unit price a cart line is expected to have at checkout.
			 *
			 * @param string               $unit     Expected unit price (decimal string, "" = cannot be determined).
			 * @param array<string, mixed> $item     Cart item.
			 * @param WC_Product           $fresh    Freshly loaded product.
			 * @param ResolvedPrice        $resolved Pipeline result.
			 */
			$unit = apply_filters( 'eurob2b_checkout_expected_unit_price', $unit, $item, $fresh, $resolved );
			if ( ResolvedPrice::SOURCE_NONE === $resolved->source && ( ! is_string( $unit ) || '' === $unit ) ) {
				// No price in the currency of the customer's group (CUR-001) and nothing else declared
				// one: the line must not be paid at a number meant for another currency.
				$mismatches[] = array(
					'key'            => (string) $key,
					'name'           => $fresh->get_name(),
					'expected'       => '',
					'actual'         => wc_format_decimal( (float) ( $item['line_subtotal'] ?? 0 ), $decimals ),
					'expected_total' => '',
					'actual_total'   => wc_format_decimal( (float) ( $item['line_total'] ?? 0 ), $decimals ),
				);
				continue;
			}
			if ( ! is_string( $unit ) || '' === $unit || ! is_numeric( $unit ) ) {
				continue;
			}
			// WooCommerce hands out floats (its cart totals are floats by design); they are turned into
			// Money at the shop's scale once and compared in minor units, never as floats.
			$expected = Money::of_decimal(
				wc_format_decimal(
					wc_get_price_excluding_tax(
						$fresh,
						array(
							'qty'   => $quantity,
							'price' => $unit,
						)
					),
					$decimals
				),
				$currency,
				$decimals
			);
			$actual   = Money::of_decimal( wc_format_decimal( (float) ( $item['line_subtotal'] ?? 0 ), $decimals ), $currency, $decimals );
			// The payable amount WooCommerce copies into the order item is line_total (subtotal minus the
			// coupon discounts WooCommerce's own discount engine attributes to this line).
			$discount       = Money::of_decimal( wc_format_decimal( self::net_discount( (float) ( $discounts[ $key ] ?? 0 ), $fresh, $cart ), $decimals ), $currency, $decimals );
			$expected_total = $expected->subtract( $discount );
			$actual_total   = Money::of_decimal( wc_format_decimal( (float) ( $item['line_total'] ?? 0 ), $decimals ), $currency, $decimals );
			// Tolerance: one minor unit per line (WooCommerce rounds the line once, after removing tax);
			// it must not grow with the quantity, or large lines could be undercharged unnoticed.
			if ( abs( $expected->minor() - $actual->minor() ) > 1 || abs( $expected_total->minor() - $actual_total->minor() ) > 1 ) {
				$mismatches[] = array(
					'key'            => (string) $key,
					'name'           => $fresh->get_name(),
					'expected'       => $expected->to_decimal(),
					'actual'         => $actual->to_decimal(),
					'expected_total' => $expected_total->to_decimal(),
					'actual_total'   => $actual_total->to_decimal(),
				);
			}
		}//end foreach
		return $mismatches;
	}

	/**
	 * Coupon discount per cart item, recomputed with WooCommerce's discount engine from the cart's
	 * applied coupons (the same engine WC_Cart_Totals uses), so legitimate coupons never trip the check.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array<string, float> cart item key => discount (ex tax, major units)
	 */
	private static function discounts_by_item( WC_Cart $cart ): array {
		if ( empty( $cart->get_coupons() ) || ! class_exists( 'WC_Discounts' ) ) {
			return array();
		}
		$engine = new \WC_Discounts( $cart );
		foreach ( $cart->get_coupons() as $coupon ) {
			$engine->apply_coupon( $coupon );
		}
		$result = array();
		foreach ( $engine->get_discounts_by_item() as $item_key => $amount ) {
			$result[ (string) $item_key ] = (float) $amount;
		}
		return $result;
	}

	/**
	 * WC_Discounts works on the entered price: with tax-inclusive prices the per-line discount is gross,
	 * while line_total is net. The tax share of the discount is removed with the product's rates for
	 * the cart's customer, which is what WooCommerce's totals end up with.
	 *
	 * @param float      $discount Discount as WC_Discounts reports it (major units).
	 * @param WC_Product $product  Product.
	 * @param WC_Cart    $cart     Cart (customer location).
	 */
	private static function net_discount( float $discount, WC_Product $product, WC_Cart $cart ): float {
		if ( $discount <= 0 || ! wc_prices_include_tax() || ! wc_tax_enabled() || ! $product->is_taxable() ) {
			return $discount;
		}
		$rates = \WC_Tax::get_rates( $product->get_tax_class(), $cart->get_customer() );
		if ( empty( $rates ) ) {
			return $discount;
		}
		return $discount - array_sum( \WC_Tax::calc_tax( $discount, $rates, true ) );
	}

	/**
	 * Customer-facing message (no prices: the cart page shows the corrected totals).
	 *
	 * @param array<int, array{key: string, name: string, expected: string, actual: string, expected_total: string, actual_total: string}> $mismatches Mismatches.
	 */
	private static function message( array $mismatches ): string {
		$names = implode( ', ', array_unique( array_map( static fn( array $m ): string => $m['name'], $mismatches ) ) );
		/* translators: %s: product names */
		return sprintf( __( 'The price of %s has changed. Please review your cart and try again.', 'eurob2b' ), $names );
	}
}

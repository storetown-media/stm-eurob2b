<?php
/**
 * Feeds the pipeline into WooCommerce's price getters.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Modules\Customers\AudienceResolver;
use WC_Cart;
use WC_Product;

/**
 * Price filters at a late priority (PRC-005): product and variation getters, the variation
 * price arrays (ranges, variation form), a per-audience variation price hash, "on sale",
 * and the cart lines before totals. Only intervenes when the pipeline replaces the base price.
 *
 * A cart line is priced for its quantity (tiers) in price_cart_lines(). WooCommerce reads that
 * price back through get_price(), so the getters answer the line's own product object with the
 * price resolved for the line quantity, never with the quantity-1 price of the catalogue.
 *
 * An audience that pays in its group's own currency (CUR-001) has no base price: the getters
 * always answer for it, and a product without a price in that currency gets an empty price, which
 * WooCommerce treats as "cannot be bought". The shop's prices are never shown in another currency.
 */
final class WooCommercePrices {

	public const PRIORITY = 999;

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
	 * Audience resolver (for the hash).
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $audiences;

	/**
	 * Prices resolved for cart lines, keyed by the line's product object.
	 *
	 * @var \WeakMap<WC_Product, ResolvedPrice>
	 */
	private \WeakMap $lines;

	/**
	 * WooCommerce's own price of cart line objects whose price the pipeline replaced.
	 *
	 * @var \WeakMap<WC_Product, string>
	 */
	private \WeakMap $originals;

	/**
	 * Constructor.
	 *
	 * @param PriceResolver    $resolver  Resolver.
	 * @param PricingContext   $context   Context guard.
	 * @param AudienceResolver $audiences Audience resolver.
	 */
	public function __construct( PriceResolver $resolver, PricingContext $context, AudienceResolver $audiences ) {
		$this->resolver  = $resolver;
		$this->context   = $context;
		$this->audiences = $audiences;
		$this->lines     = new \WeakMap();
		$this->originals = new \WeakMap();
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		foreach ( array( 'woocommerce_product', 'woocommerce_product_variation' ) as $prefix ) {
			add_filter( $prefix . '_get_price', array( $this, 'filter_price' ), self::PRIORITY, 2 );
			add_filter( $prefix . '_get_regular_price', array( $this, 'filter_regular_price' ), self::PRIORITY, 2 );
			add_filter( $prefix . '_get_sale_price', array( $this, 'filter_sale_price' ), self::PRIORITY, 2 );
		}
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'filter_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'filter_regular_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'filter_sale_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'filter_variation_prices_hash' ), self::PRIORITY );
		add_filter( 'woocommerce_product_is_on_sale', array( $this, 'filter_is_on_sale' ), self::PRIORITY, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'price_cart_lines' ), self::PRIORITY );
		add_filter( 'woocommerce_empty_price_html', array( $this, 'filter_empty_price_html' ), 10, 2 );
		add_filter( 'woocommerce_variable_empty_price_html', array( $this, 'filter_empty_price_html' ), 10, 2 );
	}

	/**
	 * Active price.
	 *
	 * @param mixed $value   WooCommerce's value.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public function filter_price( $value, $product ) {
		$resolved = $this->resolved( $product );
		return null === $resolved ? $value : $resolved->price();
	}

	/**
	 * Regular price.
	 *
	 * @param mixed $value   WooCommerce's value.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public function filter_regular_price( $value, $product ) {
		$resolved = $this->resolved( $product );
		return null === $resolved ? $value : $resolved->regular;
	}

	/**
	 * Sale price.
	 *
	 * @param mixed $value   WooCommerce's value.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public function filter_sale_price( $value, $product ) {
		$resolved = $this->resolved( $product );
		return null === $resolved ? $value : $resolved->sale;
	}

	/**
	 * "On sale" follows the resolved prices (the base product's sale schedule must not interfere).
	 *
	 * @param mixed $on_sale WooCommerce's decision.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public function filter_is_on_sale( $on_sale, $product ) {
		$resolved = $this->resolved( $product );
		return null === $resolved ? $on_sale : $resolved->is_on_sale();
	}

	/**
	 * The variation price transient is keyed by audience, so groups never see each other's ranges.
	 *
	 * @param mixed $hash Hash parts.
	 * @return mixed
	 */
	public function filter_variation_prices_hash( $hash ) {
		if ( ! $this->context->applies() || ! is_array( $hash ) ) {
			return $hash;
		}
		// The currency is part of the key: a group that changes its currency gets fresh ranges at once.
		$audience        = $this->audiences->current();
		$hash['eurob2b'] = array( $audience->hash(), $audience->currency(), EUROB2B_VERSION );
		return $hash;
	}

	/**
	 * A product without a price in the currency of the visitor's group says so instead of showing
	 * nothing. Other visitors keep WooCommerce's (empty) output.
	 *
	 * @param mixed $html    WooCommerce's HTML.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public function filter_empty_price_html( $html, $product ) {
		if ( ! $product instanceof WC_Product || ! $this->context->applies() || '' === $this->audiences->current()->currency() ) {
			return $html;
		}
		return '<span class="eurob2b-no-price">' . esc_html__( 'Price on request', 'eurob2b' ) . '</span>';
	}

	/**
	 * Prices cart lines with the pipeline (quantity-aware, for tiers) before totals are calculated.
	 *
	 * @param mixed $cart Cart.
	 */
	public function price_cart_lines( $cart ): void {
		if ( ! $cart instanceof WC_Cart || ! $this->context->applies() ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$product                 = $item['data'];
			$resolved                = $this->resolver->resolve( $product, null, (int) ( $item['quantity'] ?? 1 ) );
			$this->lines[ $product ] = $resolved;
			if ( $resolved->replaces_base() && '' !== $resolved->price() ) {
				// Keep WooCommerce's own price of the line, to give it back when the pipeline stops
				// intervening later in the same request.
				if ( ! isset( $this->originals[ $product ] ) ) {
					$this->originals[ $product ] = (string) $product->get_price( 'edit' );
				}
				$product->set_price( $resolved->price() );
			} elseif ( isset( $this->originals[ $product ] ) ) {
				// An earlier calculation of this request set a pipeline price (a tier the quantity no
				// longer reaches, a discount now shown as a cart line, PRC-018): restore the line's own price.
				$product->set_price( $this->originals[ $product ] );
				unset( $this->originals[ $product ] );
			}
		}//end foreach
	}

	/**
	 * Resolved price when the pipeline intervenes, null otherwise.
	 *
	 * @param mixed $product Product.
	 */
	private function resolved( $product ): ?ResolvedPrice {
		if ( ! $product instanceof WC_Product || ! $this->context->applies() ) {
			return null;
		}
		// A cart line's product object carries the price of its quantity, not the catalogue price.
		$resolved = $this->lines[ $product ] ?? $this->resolver->resolve( $product );
		if ( $resolved->replaces_base() && '' !== $resolved->price() ) {
			return $resolved;
		}
		// Without a price in the currency of the visitor's group there is no price at all. Variable and
		// grouped products take their prices from their children, which are answered one by one.
		if ( ResolvedPrice::SOURCE_NONE === $resolved->source && ! $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return $resolved;
		}
		return null;
	}
}

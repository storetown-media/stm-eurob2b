<?php
/**
 * Pricing pipeline bound to WooCommerce products.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Customers\AudienceContext;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Support\Currency;
use EuroB2B\Support\Decimal;
use EuroB2B\Support\Money;
use WC_Product;

/**
 * Resolves the price of a product for an audience once per request (see docs/architecture.md §5).
 *
 * Steps: base price → replacement by the most specific source → adjustments (stacking per
 * setting). Tiers follow with their module and plug in between replacement and adjustments.
 */
final class PriceResolver {

	/**
	 * Audience resolver.
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $audiences;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Core providers (before the filter).
	 *
	 * @var PriceProviderInterface[]
	 */
	private array $core_providers;

	/**
	 * Providers after the filter, built on first use.
	 *
	 * @var PriceProviderInterface[]|null
	 */
	private ?array $providers = null;

	/**
	 * Adjustment providers after the filter, built on first use.
	 *
	 * @var AdjustmentProviderInterface[]|null
	 */
	private ?array $adjusters = null;

	/**
	 * Tier providers (lazy, filterable).
	 *
	 * @var TierProviderInterface[]|null
	 */
	private ?array $tier_providers = null;

	/**
	 * Request cache: key => result.
	 *
	 * @var array<string, ResolvedPrice>
	 */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param AudienceResolver         $audiences Audience resolver.
	 * @param Settings                 $settings  Settings (stacking mode).
	 * @param PriceProviderInterface[] $providers Core price providers.
	 */
	public function __construct( AudienceResolver $audiences, Settings $settings, array $providers ) {
		$this->audiences      = $audiences;
		$this->settings       = $settings;
		$this->core_providers = $providers;
	}

	/**
	 * Resolves the price of a product for an audience and quantity.
	 *
	 * @param WC_Product           $product  Product or variation.
	 * @param AudienceContext|null $audience Audience; null = the current request's audience.
	 * @param int                  $quantity Quantity (1 for display).
	 */
	public function resolve( WC_Product $product, ?AudienceContext $audience = null, int $quantity = 1 ): ResolvedPrice {
		$audience = $audience ?? $this->audiences->current();
		$quantity = max( 1, $quantity );
		$key      = implode( '|', array( $product->get_id(), $product->get_parent_id(), $audience->hash(), $quantity ) );
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}
		$this->cache[ $key ] = $this->compute( $product, $audience, $quantity );
		return $this->cache[ $key ];
	}

	/**
	 * Resolves without reading or writing the request cache. Checkout re-validation uses this with
	 * a freshly loaded product, so a result computed from the cart's mutable product object can
	 * never satisfy the validation.
	 *
	 * @param WC_Product           $product  Product or variation (freshly loaded).
	 * @param AudienceContext|null $audience Audience; null = the current request's audience.
	 * @param int                  $quantity Quantity.
	 */
	public function resolve_uncached( WC_Product $product, ?AudienceContext $audience = null, int $quantity = 1 ): ResolvedPrice {
		return $this->compute( $product, $audience ?? $this->audiences->current(), max( 1, $quantity ) );
	}

	/**
	 * Runs the pipeline.
	 *
	 * @param WC_Product      $product  Product or variation.
	 * @param AudienceContext $audience Audience.
	 * @param int             $quantity Quantity (>= 1).
	 */
	private function compute( WC_Product $product, AudienceContext $audience, int $quantity ): ResolvedPrice {

		$candidates = array();
		foreach ( $this->providers() as $provider ) {
			$candidate = $provider->resolve( $product, $audience, $quantity );
			if ( null !== $candidate ) {
				$candidates[] = $candidate;
			}
		}
		// No candidate: the base price is missing only for an audience with its own currency, which
		// then has no price at all (CUR-001); otherwise an empty base, as WooCommerce has it.
		$result = PricePipeline::select( $candidates ) ?? new ResolvedPrice( '', '', '' !== $audience->currency() ? ResolvedPrice::SOURCE_NONE : ResolvedPrice::SOURCE_BASE, PriceProviderInterface::SPECIFICITY_BASE );

		// Tiers: quantity-dependent unit price (product tiers beat tiered rules), before adjustments.
		$tiers     = array();
		$providers = $this->tier_providers();
		if ( ! empty( $providers ) ) {
			/**
			 * Filters the quantity the tier providers look up their tiers with (EuroB2B Pro: the tier
			 * quantity basis, PRC-015, counts all variations of a product in the cart together).
			 *
			 * @param int        $quantity Quantity being priced.
			 * @param WC_Product $product  Product or variation.
			 */
			$tier_quantity = max( 1, (int) apply_filters( 'eurob2b_price_tier_quantity', $quantity, $product ) );
			foreach ( $providers as $provider ) {
				$tier = $provider->tier( $product, $audience, $tier_quantity, $result->price() );
				if ( null !== $tier ) {
					$tiers[] = $tier;
				}
			}
		}
		$tier = TierPrice::select( $tiers );
		if ( null !== $tier ) {
			$result = $result->with_tier( $tier->price, $tier->source );
		}

		$adjustments = array();
		foreach ( $this->adjusters() as $adjuster ) {
			foreach ( $adjuster->adjustments( $product, $audience, $quantity ) as $adjustment ) {
				$adjustments[] = $adjustment;
			}
		}
		if ( ! empty( $adjustments ) && '' !== $result->price() ) {
			$scale = wc_get_price_decimals();
			// Imported or group prices may carry more decimals than the shop uses: round once (half away
			// from zero, the documented policy) instead of failing for every product with such a price.
			$money = Money::of_decimal( Decimal::round( $result->price(), $scale ), Currency::code( $audience->currency() ), $scale );
			// One decision for both display modes (PRC-018): discounts shown as a cart line stay out of
			// the shown price and are carried as amounts per unit for the cart discount lines.
			$split = PricePipeline::split( $money, $adjustments, 'stack' === $this->settings->get( 'pricing_stacking' ) );
			if ( ! $split['shown']->equals( $money ) ) {
				$result = $result->with_adjusted_price( $split['shown']->to_decimal() );
			}
			if ( ! empty( $split['cart'] ) ) {
				// The cart line amounts are based on the rounded price: the cart must charge that price,
				// not a more precise one (imported or group prices), or the customer would pay more
				// than in the price mode.
				if ( 0 !== Decimal::compare( $result->price(), $split['shown']->to_decimal() ) ) {
					$result = $result->with_rounded_prices( $scale );
				}
				$result = $result->with_cart_discounts( array_map( static fn( Money $m ): string => $m->to_decimal(), $split['cart'] ), $split['final']->to_decimal() );
			}
		}//end if

		/**
		 * Filters the resolved price of a product for an audience.
		 *
		 * @param ResolvedPrice   $result   Result.
		 * @param WC_Product      $product  Product or variation.
		 * @param AudienceContext $audience Audience.
		 * @param int             $quantity Quantity.
		 */
		$filtered = apply_filters( 'eurob2b_resolved_price', $result, $product, $audience, $quantity );
		if ( $filtered instanceof ResolvedPrice ) {
			$result = $filtered;
		}

		return $result;
	}

	/**
	 * Drops the request cache (after saving prices, switching users in tests).
	 */
	public function flush(): void {
		$this->cache          = array();
		$this->providers      = null;
		$this->adjusters      = null;
		$this->tier_providers = null;
	}

	/**
	 * Price providers including add-ons.
	 *
	 * @return PriceProviderInterface[]
	 */
	private function providers(): array {
		if ( null === $this->providers ) {
			/**
			 * Filters the price providers (customer prices, company prices, fixed-price rules, ...).
			 *
			 * @param PriceProviderInterface[] $providers Providers.
			 */
			$providers       = apply_filters( 'eurob2b_price_providers', $this->core_providers );
			$this->providers = array_values( array_filter( is_array( $providers ) ? $providers : array(), static fn( $p ): bool => $p instanceof PriceProviderInterface ) );
		}
		return $this->providers;
	}

	/**
	 * Tier providers: core product tiers plus anything added through the filter.
	 *
	 * @return TierProviderInterface[]
	 */
	private function tier_providers(): array {
		if ( null === $this->tier_providers ) {
			/**
			 * Filters the tier providers (TierProviderInterface) consulted between replacement and adjustments.
			 *
			 * @param TierProviderInterface[] $providers Providers.
			 */
			$providers            = apply_filters( 'eurob2b_price_tier_providers', array() );
			$this->tier_providers = array_values( array_filter( is_array( $providers ) ? $providers : array(), static fn( $p ): bool => $p instanceof TierProviderInterface ) );
		}
		return $this->tier_providers;
	}

	/**
	 * Adjustment providers (rules module and add-ons).
	 *
	 * @return AdjustmentProviderInterface[]
	 */
	private function adjusters(): array {
		if ( null === $this->adjusters ) {
			/**
			 * Filters the adjustment providers (discount and markup rules).
			 *
			 * @param AdjustmentProviderInterface[] $providers Providers.
			 */
			$providers       = apply_filters( 'eurob2b_price_adjustment_providers', array() );
			$this->adjusters = array_values( array_filter( is_array( $providers ) ? $providers : array(), static fn( $p ): bool => $p instanceof AdjustmentProviderInterface ) );
		}
		return $this->adjusters;
	}
}

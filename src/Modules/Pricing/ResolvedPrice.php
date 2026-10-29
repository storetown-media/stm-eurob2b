<?php
/**
 * Result of the pricing pipeline for one product and audience.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

use EuroB2B\Support\Decimal;

/**
 * Immutable price result. Prices are decimal strings in WooCommerce's convention ("" = none).
 */
final class ResolvedPrice {

	/**
	 * Source of the base price (WooCommerce's own regular/sale price).
	 */
	public const SOURCE_BASE = 'base';

	/**
	 * No price at all: the audience pays in its group's own currency (CUR-001) and nothing priced
	 * the product in that currency. The product cannot be bought by that audience.
	 */
	public const SOURCE_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param string                $regular        Regular price (decimal string, "" when the product has none).
	 * @param string                $sale           Sale/active reduced price (decimal string or "").
	 * @param string                $source         Where the price came from (base, group:<id>, customer:<id>, ...).
	 * @param int                   $specificity    Specificity of the source (higher wins in the pipeline).
	 * @param bool                  $adjusted       Whether an adjustment (discount/markup) changed the price.
	 * @param array<string, string> $cart_discounts PRC-018: amount per unit (decimal string) that a cart
	 *                                              discount line takes off, by adjustment key (`rule:<id>`);
	 *                                              the shown price does not contain it.
	 * @param string                $cart_final     Price after every adjustment including the cart
	 *                                              discounts ("" when there are none: then price()).
	 */
	public function __construct(
		public readonly string $regular,
		public readonly string $sale,
		public readonly string $source,
		public readonly int $specificity,
		public readonly bool $adjusted = false,
		public readonly array $cart_discounts = array(),
		public readonly string $cart_final = ''
	) {
	}

	/**
	 * The price after every adjustment, including discounts shown as a cart line (PRC-018). Equals
	 * price() when no rule shows its discount in the cart. Rule conditions on the cart value measure
	 * this price, so both display modes take the same decisions.
	 */
	public function final_price(): string {
		return ! empty( $this->cart_discounts ) && '' !== $this->cart_final ? $this->cart_final : $this->price();
	}

	/**
	 * Copy with discounts that the cart shows as discount lines (PRC-018).
	 *
	 * @param array<string, string> $discounts Amount per unit by adjustment key.
	 * @param string                $cart_final Price after every adjustment.
	 */
	public function with_cart_discounts( array $discounts, string $cart_final ): self {
		return new self( $this->regular, $this->sale, $this->source, $this->specificity, $this->adjusted, $discounts, $cart_final );
	}

	/**
	 * Copy with regular and sale price rounded to the shop's decimals (half away from zero), marked
	 * as set by the pipeline. PRC-018: with a discount shown as a cart line the cart must charge
	 * exactly the price the discount was worked out from, never a more precise imported price.
	 *
	 * @param int $scale Price decimals.
	 */
	public function with_rounded_prices( int $scale ): self {
		$regular = '' !== $this->regular ? Decimal::round( $this->regular, $scale ) : '';
		$sale    = '' !== $this->sale ? Decimal::round( $this->sale, $scale ) : '';
		return new self( $regular, $sale, $this->source, $this->specificity, true, $this->cart_discounts, $this->cart_final );
	}

	/**
	 * The price the customer pays (sale when set, else regular).
	 */
	public function price(): string {
		return '' !== $this->sale ? $this->sale : $this->regular;
	}

	/**
	 * Whether a reduced price applies.
	 */
	public function is_on_sale(): bool {
		return '' !== $this->sale && '' !== $this->regular && Decimal::lt( $this->sale, $this->regular );
	}

	/**
	 * Whether this result differs from "whatever WooCommerce would do on its own".
	 * Only then the pipeline intervenes in WooCommerce's price getters.
	 */
	public function replaces_base(): bool {
		return self::SOURCE_BASE !== $this->source || $this->adjusted;
	}

	/**
	 * Copy with an adjusted final price (kept as the "sale" so the regular price stays visible).
	 *
	 * @param string $price Adjusted price.
	 */
	public function with_adjusted_price( string $price ): self {
		return new self( $this->regular, $price, $this->source, $this->specificity, true, $this->cart_discounts, $this->cart_final );
	}

	/**
	 * Copy with a tier price: the tier's unit price becomes the (regular) price, the source names the tier.
	 *
	 * @param string $price  Tier unit price.
	 * @param string $source Tier source (tier:product, tier:group:<id>, tier:rule:<id>).
	 */
	public function with_tier( string $price, string $source ): self {
		return new self( $price, '', $source, $this->specificity, $this->adjusted, $this->cart_discounts, $this->cart_final );
	}
}

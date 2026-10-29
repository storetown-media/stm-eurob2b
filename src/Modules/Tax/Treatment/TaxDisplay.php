<?php
/**
 * Price display per audience (net for B2B, gross for B2C).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Treatment;

use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Pricing\PricingContext;

/**
 * TAX-026/027: when enabled, business customers see prices excluding tax in shop and cart (tax is
 * added as a line in cart and checkout: "pay tax in cart"), consumers see prices including tax.
 * The price suffix ("excl. VAT" / "incl. VAT") follows the audience as well.
 */
final class TaxDisplay {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Audience resolver.
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $audiences;

	/**
	 * Storefront guard (admin and feeds keep WooCommerce's own settings).
	 *
	 * @var PricingContext
	 */
	private PricingContext $context;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param AudienceResolver $audiences Audience resolver.
	 * @param PricingContext   $context   Storefront guard.
	 */
	public function __construct( Settings $settings, AudienceResolver $audiences, PricingContext $context ) {
		$this->settings  = $settings;
		$this->audiences = $audiences;
		$this->context   = $context;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		add_filter( 'pre_option_woocommerce_tax_display_shop', array( $this, 'display_mode' ) );
		add_filter( 'pre_option_woocommerce_tax_display_cart', array( $this, 'display_mode' ) );
		add_filter( 'woocommerce_get_price_suffix', array( $this, 'price_suffix' ), 20 );
	}

	/**
	 * Whether the per-audience display is on.
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'tax_display_per_audience' );
	}

	/**
	 * "excl" for business customers, "incl" for everyone else, on the storefront only.
	 *
	 * @param mixed $pre False to use the stored option.
	 * @return mixed
	 */
	public function display_mode( $pre ) {
		if ( ! $this->enabled() || ! $this->context->applies() || ! wc_tax_enabled() ) {
			return $pre;
		}
		return $this->audiences->current()->is_b2b() ? 'excl' : 'incl';
	}

	/**
	 * Suffix per audience (empty setting = no suffix).
	 *
	 * @param mixed $html WooCommerce's suffix markup.
	 * @return mixed
	 */
	public function price_suffix( $html ) {
		if ( ! $this->enabled() || ! $this->context->applies() || ! wc_tax_enabled() ) {
			return $html;
		}
		$suffix = (string) $this->settings->get( $this->audiences->current()->is_b2b() ? 'tax_suffix_b2b' : 'tax_suffix_b2c' );
		if ( '' === trim( $suffix ) ) {
			return $html;
		}
		return ' <small class="woocommerce-price-suffix">' . esc_html( $suffix ) . '</small>';
	}
}

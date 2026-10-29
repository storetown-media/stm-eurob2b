<?php
/**
 * Legal texts per customer type.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Orders;

use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Customers\AudienceContext;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerType;
use WC_Order;
use WP_Post;

/**
 * LGL-001: approved business customers see their own terms and conditions page wherever
 * WooCommerce (and plugins that ask WooCommerce, such as Germanized's legal checkboxes) links the
 * terms page, in the classic checkout and the Checkout block; the order records which page and
 * which version applied. Between businesses only § 307 BGB governs standard terms (§ 310 (1) BGB),
 * so separate B2B terms are common; retrieving and saving them stays possible (§ 312i (1) Nr. 4 BGB).
 *
 * LGL-002: the withdrawal function of "EU Order Withdrawal Button for WooCommerce" (also shipped
 * inside Germanized) is a consumer right (§ 356a BGB, Art. 11a Directive 2011/83/EU). With the
 * setting on, orders EuroB2B recorded as placed by an approved business customer are not offered
 * for withdrawal. Off by default: no official guidance on mixed shops was found (UNVERIFIED,
 * docs/country-matrix.md LG-03), so the shop owner decides.
 */
final class LegalTexts {

	public const META_TERMS_PAGE    = '_eurob2b_terms_page_id';
	public const META_TERMS_VERSION = '_eurob2b_terms_page_modified';

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
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param AudienceResolver $audiences Audience resolver.
	 */
	public function __construct( Settings $settings, AudienceResolver $audiences ) {
		$this->settings  = $settings;
		$this->audiences = $audiences;
	}

	/**
	 * Adds hooks.
	 */
	public function hooks(): void {
		add_filter( 'woocommerce_get_terms_page_id', array( $this, 'terms_page_id' ) );
		add_filter( 'eurob2b_order_attributes', array( $this, 'order_attributes' ), 10, 3 );
		add_filter( 'eurob2b_order_attribute_rows', array( $this, 'order_rows' ), 10, 2 );
		add_filter( 'eu_owb_woocommerce_order_is_withdrawable', array( $this, 'order_is_withdrawable' ), 20, 2 );
	}

	/**
	 * The business terms page, when one is set and published; 0 otherwise.
	 */
	public function business_terms_page(): int {
		$id   = (int) $this->settings->get( 'legal_terms_page_b2b' );
		$page = $id > 0 ? get_post( $id ) : null;
		return $page instanceof WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status ? $id : 0;
	}

	/**
	 * Terms page for the current visitor: the business page for approved business customers on the
	 * storefront (checkout, Store API, My Account). Admin screens keep WooCommerce's own setting.
	 *
	 * @param mixed $page_id WooCommerce's terms page id.
	 * @return mixed
	 */
	public function terms_page_id( $page_id ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $page_id;
		}
		$business = $this->business_terms_page();
		if ( 0 === $business || ! $this->audiences->current()->is_b2b() ) {
			return $page_id;
		}
		return $business;
	}

	/**
	 * Records the terms page that applied to the buyer and its last change.
	 *
	 * @param mixed $attributes Attributes.
	 * @param mixed $audience   Audience of the buyer.
	 * @param mixed $order      Order.
	 * @return mixed
	 */
	public function order_attributes( $attributes, $audience, $order ) {
		unset( $order );
		if ( ! is_array( $attributes ) || ! $audience instanceof AudienceContext ) {
			return $attributes;
		}
		$business = $audience->is_b2b() ? $this->business_terms_page() : 0;
		$page_id  = $business > 0 ? $business : (int) get_option( 'woocommerce_terms_page_id', 0 );
		$page     = $page_id > 0 ? get_post( $page_id ) : null;
		if ( ! $page instanceof WP_Post ) {
			$attributes[ self::META_TERMS_PAGE ]    = '';
			$attributes[ self::META_TERMS_VERSION ] = '';
			return $attributes;
		}
		$attributes[ self::META_TERMS_PAGE ]    = (string) $page_id;
		$attributes[ self::META_TERMS_VERSION ] = (string) $page->post_modified_gmt;
		return $attributes;
	}

	/**
	 * Row of the EuroB2B box on the order screen.
	 *
	 * @param mixed $rows  Rows.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public function order_rows( $rows, $order ) {
		if ( ! is_array( $rows ) || ! $order instanceof WC_Order ) {
			return $rows;
		}
		$page_id = (int) $order->get_meta( self::META_TERMS_PAGE, true );
		if ( $page_id <= 0 ) {
			return $rows;
		}
		$title    = get_the_title( $page_id );
		$modified = (string) $order->get_meta( self::META_TERMS_VERSION, true );
		$version  = '' !== $modified ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $modified . ' UTC' ) ) : '';
		/* translators: 1: page title, 2: date of the page's last change */
		$rows[ __( 'Terms and conditions', 'eurob2b' ) ] = '' !== $version ? sprintf( __( '%1$s (as of %2$s)', 'eurob2b' ), '' !== $title ? $title : '#' . $page_id, $version ) : $title;
		return $rows;
	}

	/**
	 * LGL-002: no withdrawal function for business orders, when the shop owner switched it on.
	 *
	 * @param mixed $withdrawable Whether the plugin offers withdrawal.
	 * @param mixed $order        Order.
	 * @return mixed
	 */
	public function order_is_withdrawable( $withdrawable, $order ) {
		if ( ! $withdrawable || ! $order instanceof WC_Order || ! $this->settings->get( 'legal_withdrawal_consumers_only' ) ) {
			return $withdrawable;
		}
		return CustomerType::B2B->value === (string) $order->get_meta( OrderAttributes::META_CUSTOMER_TYPE, true ) ? false : $withdrawable;
	}
}

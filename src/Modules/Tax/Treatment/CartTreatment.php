<?php
/**
 * Applies the tax treatment to carts and orders.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Treatment;

use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Admin\StatusLabel;
use EuroB2B\Modules\Countries\CountryProfiles;
use EuroB2B\Modules\Countries\CountryRegistry;
use EuroB2B\Modules\Customers\AudienceContext;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Tax\Validation\ValidationService;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;
use EuroB2B\Modules\Tax\VatId\VatId;
use EuroB2B\Modules\Tax\VatIdField;
use WC_Cart;
use WC_Customer;
use WC_Order;
use WC_Product;

/**
 * TAX-019: decides for the current cart before totals are calculated and sets WooCommerce's
 * customer VAT exemption (shipping included); records treatment, reason and invoice text on the
 * order through the order-attributes filter; prints the exemption text on order pages and e-mails.
 * The addresses' states and postcodes are passed on to the decision; EuroB2B Pro switches on the
 * special territories (TAX-024, the resolved territory is stored as `_eurob2b_tax_territory`) and
 * Northern Ireland (TAX-034) through `eurob2b_tax_treatment_input` (PLT-015).
 */
final class CartTreatment {

	public const META_TREATMENT    = '_eurob2b_tax_treatment';
	public const META_EXEMPT       = '_eurob2b_tax_exempt';
	public const META_REASON_CODE  = '_eurob2b_tax_reason_code';
	public const META_REASON       = '_eurob2b_tax_reason';
	public const META_NEEDS_REVIEW = '_eurob2b_tax_needs_review';
	public const META_TERRITORY    = '_eurob2b_tax_territory';
	public const SESSION_DECISION  = 'eurob2b_tax_treatment';
	public const SESSION_SET_FLAG  = 'eurob2b_tax_exempt_set';

	/**
	 * Decider.
	 *
	 * @var TaxTreatmentDecider
	 */
	private TaxTreatmentDecider $decider;

	/**
	 * Validation service.
	 *
	 * @var ValidationService
	 */
	private ValidationService $validations;

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
	 * Constructor.
	 *
	 * @param TaxTreatmentDecider $decider     Decider.
	 * @param ValidationService   $validations Validation service.
	 * @param AudienceResolver    $audiences   Audience resolver.
	 * @param Settings            $settings    Settings.
	 */
	public function __construct( TaxTreatmentDecider $decider, ValidationService $validations, AudienceResolver $audiences, Settings $settings ) {
		$this->decider     = $decider;
		$this->validations = $validations;
		$this->audiences   = $audiences;
		$this->settings    = $settings;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_to_cart' ), 5 );
		add_filter( 'eurob2b_order_attributes', array( $this, 'order_attributes' ), 10, 3 );
		// Re-derived once the VAT ID meta is on the order: classic checkout writes it at priority 5, the
		// Store API mirror runs on order_processed at 20; both are earlier than these hooks.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'refresh_order_treatment' ), 30 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'refresh_order_treatment_and_save' ), 30 );
		add_filter( 'eurob2b_order_attribute_rows', array( $this, 'order_rows' ), 20, 2 );
		add_filter( 'eurob2b_order_attribute_tones', array( $this, 'order_tones' ), 20, 3 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_note' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_order_note' ) );
	}

	/**
	 * Decides for the cart and sets the customer's VAT exemption accordingly.
	 *
	 * @param mixed $cart Cart.
	 */
	public function apply_to_cart( $cart ): void {
		if ( ! $cart instanceof WC_Cart || ! function_exists( 'WC' ) || null === WC()->customer || ! wc_tax_enabled() ) {
			return;
		}
		$decision = $this->decide_for_cart( $cart );
		$session  = WC()->session;
		$mixed    = TaxTreatment::MIXED === $decision->treatment;
		foreach ( $cart->get_cart() as $item ) {
			if ( $mixed && isset( $item['data'] ) && $item['data'] instanceof WC_Product && SupplyType::SERVICES === SupplyType::of( $item['data'] ) ) {
				// Goods decide the customer-wide exemption; the reverse-charged service lines carry no tax.
				$item['data']->set_tax_status( 'none' );
			}
		}
		if ( $decision->is_exempt() ) {
			WC()->customer->set_is_vat_exempt( true );
			if ( null !== $session ) {
				$session->set( self::SESSION_SET_FLAG, 1 );
			}
		} elseif ( null !== $session && $session->get( self::SESSION_SET_FLAG ) ) {
			// Only undo an exemption this module set; another plugin's exemption stays.
			WC()->customer->set_is_vat_exempt( false );
			$session->set( self::SESSION_SET_FLAG, null );
		}
		if ( null !== $session ) {
			$session->set( self::SESSION_DECISION, $decision->to_array() );
		}
	}

	/**
	 * Decision for the current cart and customer.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public function decide_for_cart( WC_Cart $cart ): TreatmentDecision {
		$customer = WC()->customer;
		$user_id  = get_current_user_id();
		$audience = $this->audiences->current();
		$products = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
				$products[] = $item['data'];
			}
		}
		$chosen    = function_exists( 'wc_get_chosen_shipping_method_ids' ) ? wc_get_chosen_shipping_method_ids() : array();
		$is_pickup = ! empty( $chosen ) && count( array_filter( $chosen, static fn( $m ): bool => is_string( $m ) && ( str_starts_with( $m, 'local_pickup' ) || str_starts_with( $m, 'pickup_location' ) ) ) ) === count( $chosen );

		[ $status, $vat_country ]         = $this->vat_facts( $user_id > 0 ? $user_id : null, (string) $customer->get_billing_country() );
		[ , $shop_state, $shop_postcode ] = self::shop_address();

		return $this->decide_lines(
			$products,
			fn( bool $services_only ): TreatmentInput => $this->input(
				$services_only,
				$audience->is_b2b(),
				$status,
				$vat_country,
				$is_pickup,
				array( $shop_state, $shop_postcode ),
				array( (string) $customer->get_billing_country(), (string) $customer->get_billing_state(), (string) $customer->get_billing_postcode() ),
				array( (string) $customer->get_shipping_country(), (string) $customer->get_shipping_state(), (string) $customer->get_shipping_postcode() )
			)
		);
	}

	/**
	 * Facts for the decider. The rules review gate (CTY-004) asks for the billing country's profile.
	 * The special cases are off here; EuroB2B Pro switches them on by their settings (for goods to
	 * Northern Ireland the gate then asks for a profile `XI`, NI-02).
	 *
	 * @param bool                                   $services_only Every line is a service.
	 * @param bool                                   $is_b2b        Business customer.
	 * @param ValidationStatus|null                  $status        VAT ID status.
	 * @param string|null                            $vat_country   VAT ID country.
	 * @param bool                                   $is_pickup     Pick-up order.
	 * @param array{0: string, 1: string}            $shop          Shop state and postcode.
	 * @param array{0: string, 1: string, 2: string} $billing  Billing country, state, postcode.
	 * @param array{0: string, 1: string, 2: string} $shipping Shipping country, state, postcode.
	 */
	private function input( bool $services_only, bool $is_b2b, ?ValidationStatus $status, ?string $vat_country, bool $is_pickup, array $shop, array $billing, array $shipping ): TreatmentInput {
		$input = new TreatmentInput(
			self::shop_country(),
			$billing[0],
			$shipping[0],
			$is_b2b,
			$status,
			$vat_country,
			(bool) $this->settings->get( 'tax_exemption_enabled' ),
			$services_only,
			$is_pickup,
			$this->rules_reviewed( $billing[0] ),
			false,
			$shop[0],
			$shop[1],
			$billing[1],
			$billing[2],
			$shipping[1],
			$shipping[2],
			false
		);
		/**
		 * Filters the facts of a tax treatment decision for a cart or an order (EuroB2B Pro switches on
		 * the special territories and Northern Ireland by their settings, TAX-024 and TAX-034, see
		 * `TreatmentInput::with_special_cases()`). The special cases themselves come through
		 * `eurob2b_tax_special_treatments`.
		 *
		 * @param TreatmentInput $input Facts with the special cases off.
		 */
		$filtered = apply_filters( 'eurob2b_tax_treatment_input', $input );
		return $filtered instanceof TreatmentInput ? $filtered : $input;
	}

	/**
	 * Decision for an order (at creation and for later display).
	 *
	 * @param WC_Order        $order    Order.
	 * @param AudienceContext $audience Buyer.
	 */
	public function decide_for_order( WC_Order $order, AudienceContext $audience ): TreatmentDecision {
		$products = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( $product instanceof WC_Product ) {
				$products[] = $product;
			}
		}
		$is_pickup = self::is_pickup_order( $order );

		$status      = ValidationStatus::tryFrom( (string) $order->get_meta( VatIdField::META_VALIDATION_STATE, true ) );
		$vat_id_raw  = (string) $order->get_meta( VatIdField::META_VAT_ID, true );
		$vat_country = null;
		$provisional = false;
		if ( '' !== $vat_id_raw ) {
			$vat_id      = $this->validations->normalize( $vat_id_raw );
			$vat_country = $vat_id instanceof VatId ? $vat_id->country_code() : null;
			$record_id   = (int) $order->get_meta( VatIdField::META_VALIDATION_ID, true );
			$record      = $record_id > 0 ? $this->validations->repository()->find( $record_id ) : null;
			if ( null !== $record ) {
				$customer    = $order->get_customer_id() > 0 ? $order->get_customer_id() : null;
				$status      = $this->validations->effective_status( $record, $customer );
				$provisional = $this->validations->is_provisional( $record, $customer );
			}
		} else {
			$status = null;
		}

		[ , $shop_state, $shop_postcode ] = self::shop_address();
		$decision                         = $this->decide_lines(
			$products,
			fn( bool $services_only ): TreatmentInput => $this->input(
				$services_only,
				$audience->is_b2b(),
				$status,
				$vat_country,
				$is_pickup,
				array( $shop_state, $shop_postcode ),
				array( (string) $order->get_billing_country(), (string) $order->get_billing_state(), (string) $order->get_billing_postcode() ),
				array( (string) $order->get_shipping_country(), (string) $order->get_shipping_state(), (string) $order->get_shipping_postcode() )
			)
		);
		// An exemption that rests on provisional acceptance (no final result for this number yet) is
		// marked for review (product owner decision of 2026-10-27).
		if ( $provisional && ! $decision->needs_review && ( $decision->is_exempt() || TaxTreatment::MIXED === $decision->treatment ) ) {
			return new TreatmentDecision( $decision->treatment, $decision->reason_code, true, $decision->territory );
		}
		return $decision;
	}

	/**
	 * Whether the customer collects the order: it has shipping methods and every one of them is a
	 * pick-up method (`local_pickup`, or `pickup_location` of the Checkout block).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function is_pickup_order( WC_Order $order ): bool {
		$methods = $order->get_shipping_methods();
		if ( empty( $methods ) ) {
			return false;
		}
		foreach ( $methods as $method ) {
			$id = is_callable( array( $method, 'get_method_id' ) ) ? (string) $method->get_method_id() : '';
			if ( ! str_starts_with( $id, 'local_pickup' ) && ! str_starts_with( $id, 'pickup_location' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Decides goods and services separately. A pure cart gets one decision. In a mixed cart the goods
	 * decision applies to the customer; when only the services are exempt the result is MIXED and the
	 * exempt service lines are untaxed individually (see apply_to_cart()).
	 *
	 * @param WC_Product[]                   $products Line products.
	 * @param callable(bool): TreatmentInput $input    Facts for services_only true/false.
	 */
	private function decide_lines( array $products, callable $input ): TreatmentDecision {
		$decide       = fn( bool $services_only ): TreatmentDecision => $this->decider->decide( $input( $services_only ) );
		$has_goods    = false;
		$has_services = false;
		foreach ( $products as $product ) {
			if ( SupplyType::SERVICES === SupplyType::of( $product ) ) {
				$has_services = true;
			} else {
				$has_goods = true;
			}
		}
		if ( $has_services && ! $has_goods ) {
			return $decide( true );
		}
		$goods_input = $input( false );
		$goods       = $this->decider->decide( $goods_input );
		if ( ! $has_services ) {
			return $goods;
		}
		return $this->decider->combine( $goods, $decide( true ), $goods_input );
	}

	/**
	 * Writes the treatment keys from the order's own, now complete, data; idempotent.
	 *
	 * @param mixed $order Order.
	 */
	public function refresh_order_treatment( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$customer_id = $order->get_customer_id();
		$audience    = $customer_id > 0 ? $this->audiences->for_user( $customer_id ) : $this->audiences->current();
		foreach ( $this->treatment_meta( $this->decide_for_order( $order, $audience ) ) as $key => $value ) {
			if ( '' === $value ) {
				$order->delete_meta_data( $key );
			} else {
				$order->update_meta_data( $key, $value );
			}
		}
	}

	/**
	 * Store API variant: the order is already saved when order_processed fires.
	 *
	 * @param mixed $order Order.
	 */
	public function refresh_order_treatment_and_save( $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->refresh_order_treatment( $order );
			$order->save();
		}
	}

	/**
	 * Meta values for a decision ('partial' marks a mixed order).
	 *
	 * @param TreatmentDecision $decision Decision.
	 * @return array<string, string>
	 */
	private function treatment_meta( TreatmentDecision $decision ): array {
		$exempt = $decision->is_exempt() ? 'yes' : ( TaxTreatment::MIXED === $decision->treatment ? 'partial' : 'no' );
		return array(
			self::META_TREATMENT    => $decision->treatment->value,
			self::META_EXEMPT       => $exempt,
			self::META_REASON_CODE  => $decision->reason_code,
			self::META_REASON       => self::invoice_text( $decision ),
			self::META_NEEDS_REVIEW => $decision->needs_review ? 'yes' : '',
			self::META_TERRITORY    => (string) $decision->territory,
		);
	}

	/**
	 * Whether the exemption rules for a customer country may be applied automatically: the profile is
	 * professionally reviewed, or the shop owner accepted the unreviewed rules in the settings.
	 *
	 * @param string $country ISO country.
	 */
	public function rules_reviewed( string $country ): bool {
		if ( $this->settings->get( 'tax_exempt_with_unreviewed_rules' ) ) {
			return true;
		}
		$profile = CountryProfiles::get( CountryRegistry::vat_country( $country ) );
		return null !== $profile && $profile->is_professionally_reviewed();
	}

	/**
	 * Adds treatment, reason and invoice text to the order attributes written at purchase.
	 *
	 * @param mixed $attributes Attributes.
	 * @param mixed $audience   Buyer.
	 * @param mixed $order      Order.
	 * @return mixed
	 */
	public function order_attributes( $attributes, $audience, $order ) {
		if ( ! is_array( $attributes ) || ! $audience instanceof AudienceContext || ! $order instanceof WC_Order ) {
			return $attributes;
		}
		return array_merge( $attributes, $this->treatment_meta( $this->decide_for_order( $order, $audience ) ) );
	}

	/**
	 * Colour of a tax treatment that needs review (red) in the EuroB2B box of the order screen (PLT-017).
	 *
	 * @param mixed $tones Label => tone (StatusLabel constants).
	 * @param mixed $rows  Rows of the box (label => text).
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public function order_tones( $tones, $rows, $order ) {
		if ( ! is_array( $tones ) || ! is_array( $rows ) || ! $order instanceof WC_Order ) {
			return $tones;
		}
		if ( 'yes' === (string) $order->get_meta( self::META_NEEDS_REVIEW, true ) ) {
			$tones[ __( 'Tax treatment', 'eurob2b' ) ] = StatusLabel::BAD;
		}
		return $tones;
	}

	/**
	 * Rows for the EuroB2B box on the order screen.
	 *
	 * @param mixed $rows  Rows.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public function order_rows( $rows, $order ) {
		if ( ! is_array( $rows ) || ! $order instanceof WC_Order ) {
			return $rows;
		}
		$treatment = TaxTreatment::tryFrom( (string) $order->get_meta( self::META_TREATMENT, true ) );
		if ( null === $treatment ) {
			return $rows;
		}
		$rows[ __( 'Tax treatment', 'eurob2b' ) ] = self::treatment_label( $treatment ) . ( 'yes' === (string) $order->get_meta( self::META_NEEDS_REVIEW, true ) ? ' – ' . __( 'needs review', 'eurob2b' ) : '' );
		$reason                                   = (string) $order->get_meta( self::META_REASON_CODE, true );
		if ( '' !== $reason ) {
			$rows[ __( 'Reason', 'eurob2b' ) ] = self::reason_label( $reason );
		}
		return $rows;
	}

	/**
	 * Prints the exemption text under the order table (customer order page, e-mails).
	 *
	 * @param mixed $order Order.
	 */
	public function render_order_note( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! in_array( (string) $order->get_meta( self::META_EXEMPT, true ), array( 'yes', 'partial' ), true ) && '' === (string) $order->get_meta( self::META_TERRITORY, true ) ) {
			return;
		}
		$text = (string) $order->get_meta( self::META_REASON, true );
		if ( '' === $text ) {
			return;
		}
		echo '<p class="eurob2b-tax-note">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Invoice wording for a decision (national wording follows with the country profiles).
	 *
	 * @param TreatmentDecision $decision Decision.
	 */
	public static function invoice_text( TreatmentDecision $decision ): string {
		/**
		 * Filters the invoice text of a decision a special case made, before the exemption texts
		 * (EuroB2B Pro: the supply to a special territory outside the EU VAT area, TAX-024). An empty
		 * text leaves the decision to the core, which writes a text only for exempt and mixed orders.
		 *
		 * @param string            $text     ''.
		 * @param TreatmentDecision $decision Decision.
		 */
		$special = apply_filters( 'eurob2b_tax_special_invoice_text', '', $decision );
		if ( is_string( $special ) && '' !== $special ) {
			$text = $special;
		} elseif ( ! $decision->is_exempt() && TaxTreatment::MIXED !== $decision->treatment ) {
			return '';
		} else {
			$text = TaxTreatmentDecider::REASON_INTRA_EU_SERVICE === $decision->reason_code
				? __( 'Reverse charge: VAT to be accounted for by the recipient (Art. 196 VAT Directive 2006/112/EC).', 'eurob2b' )
				: __( 'VAT-exempt intra-Community supply (Art. 138 VAT Directive 2006/112/EC); reverse charge, VAT to be accounted for by the recipient.', 'eurob2b' );
		}
		/**
		 * Filters the exemption text written to the order for invoices.
		 *
		 * @param string            $text     Text.
		 * @param TreatmentDecision $decision Decision.
		 */
		$filtered = apply_filters( 'eurob2b_tax_exemption_text', $text, $decision );
		return is_string( $filtered ) ? $filtered : $text;
	}

	/**
	 * Label for a treatment.
	 *
	 * @param TaxTreatment $treatment Treatment.
	 */
	public static function treatment_label( TaxTreatment $treatment ): string {
		return match ( $treatment ) {
			TaxTreatment::DOMESTIC                => __( 'Domestic, taxed', 'eurob2b' ),
			TaxTreatment::INTRA_EU_TAXED          => __( 'EU customer, taxed', 'eurob2b' ),
			TaxTreatment::INTRA_EU_REVERSE_CHARGE => __( 'Intra-Community B2B, exempt (reverse charge)', 'eurob2b' ),
			TaxTreatment::SWISS_AREA              => __( 'Swiss VAT area', 'eurob2b' ),
			TaxTreatment::EXPORT                  => __( 'Export / third country', 'eurob2b' ),
			TaxTreatment::MANUAL                  => __( 'Taxed, manual review', 'eurob2b' ),
			TaxTreatment::MIXED => __( 'Mixed: goods taxed, services reverse charge', 'eurob2b' ),
			TaxTreatment::OUT_OF_SCOPE            => __( 'No automatic decision (shop outside the EU)', 'eurob2b' ),
		};
	}

	/**
	 * Translatable label of a decision reason (TaxTreatmentDecider::REASON_*); unknown codes as stored.
	 *
	 * @param string $code Reason code.
	 */
	public static function reason_label( string $code ): string {
		return match ( $code ) {
			TaxTreatmentDecider::REASON_SHOP_OUTSIDE_EU     => __( 'The shop is not in the EU', 'eurob2b' ),
			TaxTreatmentDecider::REASON_COUNTRY_UNKNOWN     => __( 'Billing country unknown', 'eurob2b' ),
			TaxTreatmentDecider::REASON_DOMESTIC            => __( "Customer in the shop's country", 'eurob2b' ),
			TaxTreatmentDecider::REASON_HOME_COUNTRY_VAT_ID => __( "VAT ID from the shop's country", 'eurob2b' ),
			TaxTreatmentDecider::REASON_CONSUMER            => __( 'Private customer in another EU country', 'eurob2b' ),
			TaxTreatmentDecider::REASON_NO_VAT_ID           => __( 'No VAT ID', 'eurob2b' ),
			TaxTreatmentDecider::REASON_VAT_ID_INVALID      => __( 'VAT ID invalid', 'eurob2b' ),
			TaxTreatmentDecider::REASON_VAT_ID_UNAVAILABLE  => __( 'VAT ID check service unavailable', 'eurob2b' ),
			TaxTreatmentDecider::REASON_VAT_ID_PENDING      => __( 'VAT ID check still pending', 'eurob2b' ),
			TaxTreatmentDecider::REASON_DELIVERY_IN_SHOP    => __( "Delivery to the shop's country", 'eurob2b' ),
			TaxTreatmentDecider::REASON_EXPORT_DESTINATION  => __( 'Delivery outside the EU', 'eurob2b' ),
			TaxTreatmentDecider::REASON_PICKUP              => __( 'Pickup: proof of transport needed', 'eurob2b' ),
			TaxTreatmentDecider::REASON_EXEMPTION_DISABLED  => __( 'Exemption switched off in the settings', 'eurob2b' ),
			TaxTreatmentDecider::REASON_INTRA_EU_SUPPLY     => __( 'Goods to a business in another EU country', 'eurob2b' ),
			TaxTreatmentDecider::REASON_INTRA_EU_SERVICE    => __( 'Services to a business in another EU country', 'eurob2b' ),
			TaxTreatmentDecider::REASON_SWISS_AREA          => __( 'Customer in the Swiss VAT area', 'eurob2b' ),
			TaxTreatmentDecider::REASON_THIRD_COUNTRY       => __( 'Customer outside the EU', 'eurob2b' ),
			TaxTreatmentDecider::REASON_RULES_NOT_REVIEWED  => __( 'Country rules not reviewed yet', 'eurob2b' ),
			TaxTreatmentDecider::REASON_SPECIAL_TERRITORY   => __( 'Territory outside the EU VAT area', 'eurob2b' ),
			TaxTreatmentDecider::REASON_SHOP_IN_TERRITORY   => __( 'The shop is in a territory outside the EU VAT area', 'eurob2b' ),
			TaxTreatmentDecider::REASON_NI_CONSUMER         => __( 'Private customer in Northern Ireland (goods under EU VAT rules)', 'eurob2b' ),
			TaxTreatmentDecider::REASON_NI_NEEDS_XI_VAT_ID  => __( 'Northern Ireland: only a valid XI VAT ID exempts goods', 'eurob2b' ),
			TaxTreatmentDecider::REASON_NI_MIXED            => __( 'Northern Ireland: goods under EU rules, services under UK rules; check the service lines', 'eurob2b' ),
			default                                         => $code,
		};//end match
	}

	/**
	 * The value of the Checkout block's VAT ID field as WooCommerce keeps it on the customer (the
	 * block sends it with every address change), null when the customer has none or when this is
	 * not a request of the Cart and Checkout blocks (Store API): elsewhere the account number counts.
	 */
	private static function block_field_value(): ?string {
		if ( ! function_exists( 'WC' ) || ! WC()->is_store_api_request() ) {
			return null;
		}
		$customer = WC()->customer;
		if ( ! $customer instanceof WC_Customer || ! $customer->meta_exists( VatIdField::BLOCK_META ) ) {
			return null;
		}
		$value = $customer->get_meta( VatIdField::BLOCK_META, true );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * VAT facts of the Checkout block's field: empty means no VAT ID (no fallback to the account);
	 * a number is checked as the field does it (fresh results are reused, no extra request).
	 *
	 * @param string   $raw             Field value.
	 * @param int|null $user_id         Customer.
	 * @param string   $billing_country Billing country (prefix hint).
	 * @return array{0: ValidationStatus|null, 1: string|null}
	 */
	private function block_facts( string $raw, ?int $user_id, string $billing_country ): array {
		if ( '' === $raw ) {
			return array( null, null );
		}
		$vat_id = $this->validations->normalize( $raw, $billing_country );
		if ( ! $vat_id instanceof VatId ) {
			return array( ValidationStatus::INVALID, null );
		}
		if ( ! $this->validations->enabled() ) {
			return array( ValidationStatus::UNAVAILABLE, $vat_id->country_code() );
		}
		$record = $this->validations->check( $vat_id, $user_id );
		return array( $this->validations->effective_status( $record, $user_id ), $vat_id->country_code() );
	}

	/**
	 * Effective VAT status and ID country of a customer (the checkout session first, else user meta).
	 *
	 * @param int|null $user_id         Customer.
	 * @param string   $billing_country Billing country (prefix hint).
	 * @return array{0: ValidationStatus|null, 1: string|null}
	 */
	private function vat_facts( ?int $user_id, string $billing_country ): array {
		// The number validated in this checkout wins over the one saved on the account.
		$session    = function_exists( 'WC' ) ? WC()->session : null;
		$vat_id_raw = null !== $session ? (string) $session->get( VatIdField::SESSION_VAT_ID, '' ) : '';
		$record_id  = '' !== $vat_id_raw ? (int) $session->get( VatIdField::SESSION_RECORD_ID, 0 ) : 0;
		if ( '' === $vat_id_raw && null !== $session && $session->get( VatIdField::SESSION_EMPTIED ) ) {
			// The customer emptied the VAT ID field of the classic checkout: no fallback to the account.
			return array( null, null );
		}
		if ( '' === $vat_id_raw ) {
			$block = self::block_field_value();
			if ( null !== $block ) {
				return $this->block_facts( $block, $user_id, $billing_country );
			}
		}
		if ( '' === $vat_id_raw && null !== $user_id ) {
			$vat_id_raw = (string) get_user_meta( $user_id, VatIdField::META_VAT_ID, true );
			$record_id  = (int) get_user_meta( $user_id, VatIdField::META_VALIDATION_ID, true );
		}
		if ( '' === $vat_id_raw ) {
			return array( null, null );
		}
		$vat_id = $this->validations->normalize( $vat_id_raw, $billing_country );
		if ( ! $vat_id instanceof VatId ) {
			return array( ValidationStatus::INVALID, null );
		}
		$record = $record_id > 0 ? $this->validations->repository()->find( $record_id ) : null;
		if ( null === $record ) {
			$record = $this->validations->repository()->latest_for( $vat_id->country_code(), $vat_id->number );
		}
		if ( null === $record ) {
			return array( ValidationStatus::UNAVAILABLE, $vat_id->country_code() );
		}
		// TAX-010: a valid result older than the re-check period no longer exempts; the VAT ID field
		// checks the number again at checkout and that fresh result counts. Manual decisions stay final.
		if ( ValidationStatus::VALID === $record->status && ! $record->is_decided() && $record->older_than( $this->validations->revalidate_days(), ValidationService::now() ) ) {
			return array( ValidationStatus::UNAVAILABLE, $vat_id->country_code() );
		}
		return array( $this->validations->effective_status( $record, $user_id ), $vat_id->country_code() );
	}

	/**
	 * The shop's country (WooCommerce base location).
	 */
	public static function shop_country(): string {
		$base = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
		return strtoupper( (string) ( $base['country'] ?? '' ) );
	}

	/**
	 * The shop's country, state and postcode (WooCommerce store address), for TAX-024.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	public static function shop_address(): array {
		$base     = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
		$postcode = function_exists( 'WC' ) && WC()->countries instanceof \WC_Countries ? WC()->countries->get_base_postcode() : '';
		return array( strtoupper( (string) ( $base['country'] ?? '' ) ), strtoupper( (string) ( $base['state'] ?? '' ) ), (string) $postcode );
	}
}

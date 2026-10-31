<?php
/**
 * VAT ID field in registration, My Account and both checkouts.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax;

use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Countries\CountryRegistry;
use EuroB2B\Modules\Countries\TaxArea;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Registration\RegistrationType;
use EuroB2B\Modules\Registration\RegistrationTypeRepository;
use EuroB2B\Modules\Tax\Treatment\CartTreatment;
use EuroB2B\Modules\Tax\Validation\ValidationRecord;
use EuroB2B\Modules\Tax\Validation\ValidationService;
use EuroB2B\Modules\Tax\VatId\ExternalServices;
use EuroB2B\Modules\Tax\VatId\TraderDetails;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;
use EuroB2B\Modules\Tax\VatId\VatId;
use WC_Order;
use WP_Error;

/**
 * TAX-013..016: one field, four places. Input is normalised (prefix + number), checked through the
 * ValidationService, and the result travels to the user (meta) and to the order (meta for invoices).
 * Blocking follows the settings: invalid numbers block when configured, outages per downtime policy.
 */
final class VatIdField {

	public const INPUT                 = 'eurob2b_vat_id';
	public const BLOCK_FIELD_ID        = 'eurob2b/vat-id';
	public const BLOCK_META            = '_wc_billing/eurob2b/vat-id';
	public const BLOCK_META_SHIPPING   = '_wc_shipping/eurob2b/vat-id';
	public const META_VAT_ID           = '_eurob2b_vat_id';
	public const META_VALIDATION_ID    = '_eurob2b_vat_validation_id';
	public const META_VALIDATION_STATE = '_eurob2b_vat_validation_status';
	public const SESSION_FEEDBACK      = 'eurob2b_vat_feedback';
	public const SESSION_VAT_ID        = 'eurob2b_vat_id';
	public const SESSION_RECORD_ID     = 'eurob2b_vat_record_id';
	public const SESSION_EMPTIED       = 'eurob2b_vat_emptied';
	public const MODE_OFF              = 'off';
	public const MODE_OPTIONAL         = 'optional';
	public const MODE_REQUIRED_B2B     = 'required_b2b';

	/**
	 * Service.
	 *
	 * @var ValidationService
	 */
	private ValidationService $service;

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
	 * Registration types (business types get the field at registration).
	 *
	 * @var RegistrationTypeRepository
	 */
	private RegistrationTypeRepository $types;

	/**
	 * Result of the current request's registration check, applied on woocommerce_created_customer.
	 *
	 * @var array{vat_id: VatId, record: ValidationRecord}|null
	 */
	private ?array $pending_registration = null;

	/**
	 * Result of the current request's classic checkout check, applied on order creation.
	 *
	 * @var array{vat_id: VatId, record: ValidationRecord}|null
	 */
	private ?array $pending_checkout = null;

	/**
	 * Check of the number posted with the classic checkout submit, made before WooCommerce
	 * calculates the totals and reused by the validation after it (no second request to the
	 * service). Keys: the number, billing country and trader details it was checked with, and the
	 * outcome of check_input().
	 *
	 * @var array{key: string, outcome: array{vat_id: VatId, record: ValidationRecord}|WP_Error}|null
	 */
	private ?array $posted_check = null;

	/**
	 * Checks of account form values in this request ("user id|input" => outcome).
	 *
	 * @var array<string, array{vat_id: VatId, record: ValidationRecord}|WP_Error>
	 */
	private array $account_outcomes = array();

	/**
	 * Checkout block address checks of this request: error message or null, by country, number and user.
	 *
	 * @var array<string, string|null>
	 */
	private array $block_errors = array();

	/**
	 * Constructor.
	 *
	 * @param ValidationService          $service   Service.
	 * @param Settings                   $settings  Settings.
	 * @param AudienceResolver           $audiences Audience resolver.
	 * @param RegistrationTypeRepository $types     Registration types.
	 */
	public function __construct( ValidationService $service, Settings $settings, AudienceResolver $audiences, RegistrationTypeRepository $types ) {
		$this->service   = $service;
		$this->settings  = $settings;
		$this->audiences = $audiences;
		$this->types     = $types;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		if ( self::MODE_OFF === $this->mode() ) {
			return;
		}
		// Registration (after the custom fields).
		add_action( 'woocommerce_register_form', array( $this, 'render_registration_field' ), 20 );
		add_filter( 'woocommerce_registration_errors', array( $this, 'validate_registration' ), 20 );
		add_action( 'woocommerce_created_customer', array( $this, 'save_registration' ), 20 );

		// My Account.
		add_action( 'woocommerce_edit_account_form', array( $this, 'render_account_field' ), 20 );
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'validate_account' ), 20 );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_account' ), 20 );

		// Classic checkout.
		add_filter( 'woocommerce_billing_fields', array( $this, 'classic_billing_field' ), 30 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 20, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'live_feedback' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'remember_posted_field' ) );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_feedback' ) );
		// Early, so later hooks (order attributes, tax treatment) see the validated VAT ID on the order.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'write_classic_order' ), 5 );

		// Checkout block (Store API).
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_action( 'woocommerce_blocks_validate_location_address_fields', array( $this, 'validate_block_address' ), 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_' . self::BLOCK_FIELD_ID, array( $this, 'block_default_value' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'write_block_order' ), 20 );

		// Order screen.
		add_filter( 'eurob2b_order_attribute_rows', array( $this, 'order_rows' ), 10, 2 );
		add_filter( 'eurob2b_order_attribute_tones', array( $this, 'order_tones' ), 10, 3 );
	}

	/**
	 * Field mode setting.
	 */
	public function mode(): string {
		$mode = (string) $this->settings->get( 'tax_vat_field_mode' );
		return in_array( $mode, array( self::MODE_OFF, self::MODE_OPTIONAL, self::MODE_REQUIRED_B2B ), true ) ? $mode : self::MODE_OPTIONAL;
	}

	/**
	 * Countries the field is shown for (ISO codes).
	 *
	 * @return string[]
	 */
	public function countries(): array {
		$countries = $this->settings->get( 'tax_vat_field_countries' );
		return is_array( $countries ) ? array_values( array_map( 'strval', $countries ) ) : array();
	}

	/**
	 * Whether the field applies to a billing country (empty country = unknown yet = applies).
	 *
	 * @param string $country ISO country.
	 */
	public function applies_to_country( string $country ): bool {
		return '' === $country || in_array( strtoupper( $country ), $this->countries(), true );
	}

	/* ---------------------------------------------------------------- Registration */

	/**
	 * Renders the field on the registration form (business types only, via the type data attribute).
	 */
	public function render_registration_field(): void {
		$business = $this->business_type_slugs();
		if ( empty( $business ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- re-populating after a failed submit; WooCommerce verifies the nonce.
		$value = isset( $_POST[ self::INPUT ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::INPUT ] ) ) : '';
		$this->render_input( $value, implode( ',', $business ), self::MODE_REQUIRED_B2B === $this->mode() );
	}

	/**
	 * Validates the VAT ID at registration (syntax, then the service when enabled).
	 *
	 * @param WP_Error $errors Errors so far.
	 */
	public function validate_registration( WP_Error $errors ): WP_Error {
		$this->pending_registration = null;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the register nonce.
		$post = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $post ) || ( ! isset( $post['woocommerce-register-nonce'] ) && ! isset( $post['eurob2b_registration_type'] ) && ! isset( $post['eurob2b_locked_type'] ) ) ) {
			return $errors;
			// Checkout account creation or API: not a registration form.
		}
		$type     = $this->registration_type( $post );
		$business = null !== $type && CustomerType::B2B === $type->customer_type;
		$raw      = isset( $post[ self::INPUT ] ) && is_scalar( $post[ self::INPUT ] ) ? trim( (string) $post[ self::INPUT ] ) : '';
		if ( '' === $raw ) {
			if ( $business && self::MODE_REQUIRED_B2B === $this->mode() ) {
				$errors->add( 'eurob2b_vat_id', __( 'Please enter your VAT ID.', 'eurob2b' ) );
			}
			return $errors;
		}
		if ( ! $business ) {
			return $errors;
			// Consumers may not submit a VAT ID; silently ignored.
		}
		$outcome = $this->check_input( $raw, null, get_current_user_id() > 0 ? get_current_user_id() : null, (bool) $this->settings->get( 'tax_validate_at_registration' ) );
		if ( $outcome instanceof WP_Error ) {
			$errors->add( $outcome->get_error_code(), $outcome->get_error_message() );
			return $errors;
		}
		$this->pending_registration = $outcome;
		return $errors;
	}

	/**
	 * Stores the VAT ID on the new customer.
	 *
	 * @param int $customer_id New user id.
	 */
	public function save_registration( int $customer_id ): void {
		if ( null === $this->pending_registration ) {
			return;
		}
		$this->store_on_user( $customer_id, $this->pending_registration['vat_id'], $this->pending_registration['record'] );
		$this->pending_registration = null;
	}

	/**
	 * REG-011: the VAT ID of a business registration made by another form, with the rules of the
	 * EuroB2B registration form (required when the field is mandatory for business customers). With
	 * a customer, the number is checked online when "validate at registration" is on and the
	 * evidence belongs to that customer; without one, only presence and syntax are checked and
	 * nothing is stored.
	 *
	 * @param string   $raw     Input ('' = none given).
	 * @param int|null $user_id Customer, or null for the check before the account exists.
	 * @return array{vat_id: VatId, record: ValidationRecord}|WP_Error|null Null: nothing to store.
	 */
	public function check_registration_input( string $raw, ?int $user_id = null ) {
		if ( self::MODE_OFF === $this->mode() ) {
			return null;
		}
		$raw = trim( sanitize_text_field( $raw ) );
		if ( '' === $raw ) {
			return self::MODE_REQUIRED_B2B === $this->mode() ? new WP_Error( 'eurob2b_vat_id', __( 'Please enter your VAT ID.', 'eurob2b' ) ) : null;
		}
		return $this->check_input( $raw, null, $user_id, null !== $user_id && (bool) $this->settings->get( 'tax_validate_at_registration' ) );
	}

	/**
	 * REG-011: stores an outcome of check_registration_input() on the new customer.
	 *
	 * @param int                                            $user_id Customer.
	 * @param array{vat_id: VatId, record: ValidationRecord} $outcome Checked VAT ID.
	 */
	public function store_registration_input( int $user_id, array $outcome ): void {
		$this->store_on_user( $user_id, $outcome['vat_id'], $outcome['record'] );
	}

	/* ---------------------------------------------------------------- My Account */

	/**
	 * Renders the field in My Account for business customers.
	 */
	public function render_account_field(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! $this->audiences->for_user( $user_id )->is_b2b() ) {
			return;
		}
		$this->render_input( (string) get_user_meta( $user_id, self::META_VAT_ID, true ), '', self::MODE_REQUIRED_B2B === $this->mode() );
	}

	/**
	 * Validates the account form value.
	 *
	 * @param WP_Error $errors Errors.
	 */
	public function validate_account( WP_Error $errors ): void {
		// A new account form submission starts here (WooCommerce validates before it saves).
		$this->account_outcomes = array();
		$user_id                = get_current_user_id();
		if ( $user_id <= 0 || ! $this->audiences->for_user( $user_id )->is_b2b() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified save-account-details-nonce.
		$raw = isset( $_POST[ self::INPUT ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ self::INPUT ] ) ) ) : '';
		if ( '' === $raw ) {
			if ( self::MODE_REQUIRED_B2B === $this->mode() ) {
				$errors->add( 'eurob2b_vat_id', __( 'Please enter your VAT ID.', 'eurob2b' ) );
			}
			return;
		}
		$outcome = $this->account_outcome( $raw, $user_id );
		if ( $outcome instanceof WP_Error ) {
			$errors->add( $outcome->get_error_code(), $outcome->get_error_message() );
		}
	}

	/**
	 * The check of an account form value, done once per request: validation and save share it, so
	 * one save never asks the service twice or stores two evidence rows (with retries off, an outage
	 * result is not reused).
	 *
	 * @param string $raw     Input.
	 * @param int    $user_id Customer.
	 * @return array{vat_id: VatId, record: ValidationRecord}|WP_Error
	 */
	private function account_outcome( string $raw, int $user_id ) {
		$key = $user_id . '|' . $raw;
		if ( ! isset( $this->account_outcomes[ $key ] ) ) {
			$this->account_outcomes[ $key ] = $this->check_input( $raw, (string) get_user_meta( $user_id, 'billing_country', true ), $user_id, $this->service->enabled() );
		}
		return $this->account_outcomes[ $key ];
	}

	/**
	 * Saves the account form value.
	 *
	 * @param int $user_id User id.
	 */
	public function save_account( int $user_id ): void {
		if ( ! $this->audiences->for_user( $user_id )->is_b2b() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified save-account-details-nonce.
		$raw = isset( $_POST[ self::INPUT ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ self::INPUT ] ) ) ) : '';
		if ( '' === $raw ) {
			delete_user_meta( $user_id, self::META_VAT_ID );
			delete_user_meta( $user_id, self::META_VALIDATION_ID );
			delete_user_meta( $user_id, self::META_VALIDATION_STATE );
			delete_user_meta( $user_id, self::BLOCK_META );
			return;
		}
		$outcome = $this->account_outcome( $raw, $user_id );
		if ( ! $outcome instanceof WP_Error ) {
			$this->store_on_user( $user_id, $outcome['vat_id'], $outcome['record'] );
		}
	}

	/* ---------------------------------------------------------------- Classic checkout */

	/**
	 * Adds the field to the billing section.
	 *
	 * @param array<string, array<string, mixed>> $fields Billing fields.
	 * @return array<string, array<string, mixed>>
	 */
	public function classic_billing_field( array $fields ): array {
		$user_id               = get_current_user_id();
		$fields[ self::INPUT ] = array(
			'label'        => __( 'VAT ID', 'eurob2b' ),
			'placeholder'  => __( 'e.g. DE123456789', 'eurob2b' ),
			'description'  => __( 'With country prefix.', 'eurob2b' ),
			'required'     => $this->required_at_checkout(),
			// update_totals_on_change: WooCommerce refreshes the order review when the number changes,
			// so the totals shown before "Place order" already follow it.
			'class'        => array( 'form-row-wide', 'eurob2b-vat-id', 'update_totals_on_change' ),
			'priority'     => 35,
			'default'      => $user_id > 0 ? (string) get_user_meta( $user_id, self::META_VAT_ID, true ) : '',
			'autocomplete' => 'off',
		);
		return $fields;
	}

	/**
	 * Validates the posted VAT ID with the billing country as hint.
	 *
	 * @param array<string, mixed> $data   Posted checkout data.
	 * @param WP_Error             $errors Errors.
	 */
	public function validate_classic_checkout( array $data, WP_Error $errors ): void {
		$this->pending_checkout = null;
		$raw                    = isset( $data[ self::INPUT ] ) && is_scalar( $data[ self::INPUT ] ) ? trim( (string) $data[ self::INPUT ] ) : '';
		$country                = isset( $data['billing_country'] ) ? strtoupper( (string) $data['billing_country'] ) : '';
		if ( '' === $raw || ! $this->applies_to_country( $country ) ) {
			return;
			// Required-ness of an empty field is handled by WooCommerce via the field definition.
		}
		$outcome = $this->check_posted( $raw, $country, $data );
		if ( $outcome instanceof WP_Error ) {
			// The field id lets WooCommerce mark the field invalid and link the message to it (WCAG 3.3.1).
			$errors->add( $outcome->get_error_code(), $outcome->get_error_message(), array( 'id' => self::INPUT ) );
			return;
		}
		$this->pending_checkout = $outcome;
		self::remember( $outcome['vat_id'], $outcome['record'] );
	}

	/**
	 * Live feedback: WooCommerce re-renders the order review on every field change; the VAT ID is
	 * checked here (cached by the service) and the result shown next to the order button.
	 *
	 * @param mixed $post_data Serialised form data.
	 */
	public function live_feedback( $post_data ): void {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}
		parse_str( is_string( $post_data ) ? $post_data : '', $form );
		$raw     = isset( $form[ self::INPUT ] ) && is_scalar( $form[ self::INPUT ] ) ? trim( sanitize_text_field( (string) $form[ self::INPUT ] ) ) : '';
		$country = isset( $form['billing_country'] ) ? strtoupper( sanitize_text_field( (string) $form['billing_country'] ) ) : '';
		self::remember_emptied( isset( $form[ self::INPUT ] ) && '' === $raw && $this->applies_to_country( $country ) );
		if ( '' === $raw || ! $this->applies_to_country( $country ) ) {
			WC()->session->set( self::SESSION_FEEDBACK, null );
			WC()->session->set( self::SESSION_VAT_ID, null );
			WC()->session->set( self::SESSION_RECORD_ID, null );
			return;
		}
		$outcome = $this->check_input( $raw, $country, get_current_user_id() > 0 ? get_current_user_id() : null, $this->service->enabled() );
		if ( $outcome instanceof WP_Error ) {
			WC()->session->set(
				self::SESSION_FEEDBACK,
				array(
					'status'  => 'error',
					'message' => $outcome->get_error_message(),
				)
			);
			return;
		}
		WC()->session->set(
			self::SESSION_FEEDBACK,
			array(
				'status'  => $outcome['record']->status->value,
				'message' => self::feedback_message( $outcome['record'] ),
			)
		);
		self::remember( $outcome['vat_id'], $outcome['record'] );
	}

	/**
	 * Classic checkout submit: WooCommerce calculates the totals before the validation runs, so the
	 * state of the field is taken from the posted form here, before that calculation. A number typed
	 * after the last order review refresh is checked here, so the tax charged follows the number the
	 * order records; before, the totals followed the number of the last refresh.
	 */
	public function remember_posted_field(): void {
		$this->posted_check = null;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before this action.
		$post   = wp_unslash( $_POST );
		$post   = is_array( $post ) ? $post : array();
		$posted = isset( $post[ self::INPUT ] ) && is_scalar( $post[ self::INPUT ] );
		$raw    = $posted ? trim( sanitize_text_field( (string) $post[ self::INPUT ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// The billing details as WooCommerce will validate them, after `woocommerce_checkout_posted_data`
		// (a company account's own billing address replaces the typed one there).
		$data    = function_exists( 'WC' ) && is_callable( array( WC()->checkout(), 'get_posted_data' ) ) ? WC()->checkout()->get_posted_data() : $post;
		$data    = is_array( $data ) ? $data : $post;
		$country = isset( $data['billing_country'] ) && is_scalar( $data['billing_country'] ) ? strtoupper( sanitize_text_field( (string) $data['billing_country'] ) ) : '';
		self::remember_emptied( $posted && '' === $raw && $this->applies_to_country( $country ) );
		if ( '' === $raw || ! $this->applies_to_country( $country ) ) {
			return;
		}
		$outcome = $this->check_posted( $raw, $country, $data );
		if ( $outcome instanceof WP_Error ) {
			// The validation refuses the order; no earlier number of this session may exempt it meanwhile.
			if ( function_exists( 'WC' ) && null !== WC()->session ) {
				WC()->session->set( self::SESSION_VAT_ID, null );
				WC()->session->set( self::SESSION_RECORD_ID, null );
			}
			return;
		}
		self::remember( $outcome['vat_id'], $outcome['record'] );
	}

	/**
	 * Remembers whether the customer emptied the VAT ID field of this checkout: then the number saved
	 * on the account must not exempt the order (no VAT ID on the order, no exemption).
	 *
	 * @param bool $emptied Field shown and empty.
	 */
	private static function remember_emptied( bool $emptied ): void {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}
		WC()->session->set( self::SESSION_EMPTIED, $emptied ? 1 : null );
		if ( $emptied ) {
			WC()->session->set( self::SESSION_VAT_ID, null );
			WC()->session->set( self::SESSION_RECORD_ID, null );
		}
	}

	/**
	 * Prints the feedback line (part of the refreshed order review fragment).
	 */
	public function render_feedback(): void {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}
		$feedback = WC()->session->get( self::SESSION_FEEDBACK );
		if ( ! is_array( $feedback ) || empty( $feedback['message'] ) ) {
			return;
		}
		echo '<p class="eurob2b-vat-feedback eurob2b-vat-feedback--' . esc_attr( (string) $feedback['status'] ) . '">' . esc_html( (string) $feedback['message'] ) . '</p>';
	}

	/**
	 * Writes the VAT ID and the evidence to the order (classic checkout).
	 *
	 * @param WC_Order $order Order.
	 */
	public function write_classic_order( WC_Order $order ): void {
		if ( null === $this->pending_checkout ) {
			return;
		}
		$this->store_on_order( $order, $this->pending_checkout['vat_id'], $this->pending_checkout['record'] );
		if ( $order->get_customer_id() > 0 ) {
			$this->store_on_user( $order->get_customer_id(), $this->pending_checkout['vat_id'], $this->pending_checkout['record'] );
		}
		$this->pending_checkout = null;
	}

	/* ---------------------------------------------------------------- Checkout block */

	/**
	 * Registers the additional checkout field (address location, billing).
	 */
	public function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => self::BLOCK_FIELD_ID,
				'label'             => __( 'VAT ID (with country prefix)', 'eurob2b' ),
				'optionalLabel'     => __( 'VAT ID (optional, with country prefix)', 'eurob2b' ),
				'location'          => 'address',
				'type'              => 'text',
				'required'          => is_admin() && ! wp_doing_ajax() ? false : $this->required_at_checkout(),
				'sanitize_callback' => fn( $value ) => is_scalar( $value ) ? trim( (string) $value ) : '',
				// No field check: it cannot tell the billing from the shipping address. The address
				// check validate_block_address() checks the number of the billing address.
			)
		);
	}

	/**
	 * Checkout block: the VAT ID of the billing address is checked here, and a number typed without
	 * its country prefix is read with the billing country as hint, as in the classic checkout. The
	 * block shows the field in the shipping address too (address fields cannot be billing-only);
	 * that value is ignored. WooCommerce runs this check when it reads the request and again before
	 * payment, so the outcome is kept for the request.
	 *
	 * @param mixed $errors Error collector of the address.
	 * @param mixed $fields Address fields (key => value), including the additional fields.
	 * @param mixed $group  billing or shipping.
	 */
	public function validate_block_address( $errors, $fields, $group ): void {
		if ( ! $errors instanceof WP_Error || 'billing' !== $group || ! is_array( $fields ) ) {
			return;
		}
		$raw     = isset( $fields[ self::BLOCK_FIELD_ID ] ) && is_scalar( $fields[ self::BLOCK_FIELD_ID ] ) ? trim( (string) $fields[ self::BLOCK_FIELD_ID ] ) : '';
		$country = isset( $fields['country'] ) && is_scalar( $fields['country'] ) ? strtoupper( (string) $fields['country'] ) : '';
		if ( '' === $raw ) {
			return;
		}
		$user_id = get_current_user_id() > 0 ? get_current_user_id() : null;
		$key     = $country . '|' . $raw . '|' . (string) $user_id;
		if ( ! array_key_exists( $key, $this->block_errors ) ) {
			$outcome                    = $this->check_input( $raw, '' !== $country ? $country : null, $user_id, $this->service->enabled() );
			$this->block_errors[ $key ] = $outcome instanceof WP_Error ? $outcome->get_error_message() : null;
		}
		if ( null !== $this->block_errors[ $key ] ) {
			$errors->add( 'eurob2b_vat_id', $this->block_errors[ $key ] );
		}
	}

	/**
	 * Prefills the Checkout block field with the number saved on the account, so the block shows the
	 * number that would count. WooCommerce asks only while the customer has no value of its own.
	 *
	 * @param mixed $value     Default so far.
	 * @param mixed $group     billing, shipping or other.
	 * @param mixed $wc_object Customer or order.
	 * @return mixed
	 */
	public function block_default_value( $value, $group, $wc_object ) {
		if ( ( null !== $value && '' !== $value ) || 'billing' !== $group || ! $wc_object instanceof \WC_Customer || $wc_object->get_id() < 1 ) {
			return $value;
		}
		$saved = (string) get_user_meta( $wc_object->get_id(), self::META_VAT_ID, true );
		return '' !== $saved ? $saved : $value;
	}

	/**
	 * An emptied block field applies to that one order: WooCommerce would keep the empty value with
	 * the customer and it would win over the account number in every later block checkout.
	 *
	 * @param int $customer_id Customer (0 for guests).
	 */
	private static function forget_empty_block_value( int $customer_id ): void {
		if ( $customer_id < 1 ) {
			return;
		}
		delete_user_meta( $customer_id, self::BLOCK_META );
		$customer = function_exists( 'WC' ) ? WC()->customer : null;
		if ( $customer instanceof \WC_Customer && $customer->get_id() === $customer_id ) {
			$customer->delete_meta_data( self::BLOCK_META );
			$customer->save();
		}
	}

	/**
	 * After a block checkout: the additional field value is on the order; mirror it to our keys.
	 *
	 * @param WC_Order $order Order.
	 */
	public function write_block_order( WC_Order $order ): void {
		$raw = $order->get_meta( self::BLOCK_META, true );
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $raw ) {
			self::forget_empty_block_value( $order->get_customer_id() );
			return;
		}
		$trader  = TraderDetails::from( $order->get_billing_company(), $order->get_billing_city(), $order->get_billing_postcode(), $order->get_billing_address_1() );
		$outcome = $this->check_input( $raw, $order->get_billing_country(), $order->get_customer_id() > 0 ? $order->get_customer_id() : null, $this->service->enabled(), $trader );
		if ( $outcome instanceof WP_Error ) {
			return;
			// The validate callback already rejected it; nothing to mirror.
		}
		$this->store_on_order( $order, $outcome['vat_id'], $outcome['record'] );
		if ( $order->get_customer_id() > 0 ) {
			$this->store_on_user( $order->get_customer_id(), $outcome['vat_id'], $outcome['record'] );
		}
		$order->save();
	}

	/* ---------------------------------------------------------------- Order screen */

	/**
	 * Adds VAT ID rows to the EuroB2B box on the order screen.
	 *
	 * @param mixed $rows  Rows.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public function order_rows( $rows, $order ) {
		if ( ! is_array( $rows ) || ! $order instanceof WC_Order ) {
			return $rows;
		}
		$vat_id = (string) $order->get_meta( self::META_VAT_ID, true );
		if ( '' === $vat_id ) {
			return $rows;
		}
		$rows[ __( 'VAT ID', 'eurob2b' ) ] = $vat_id;
		$status                            = ValidationStatus::tryFrom( (string) $order->get_meta( self::META_VALIDATION_STATE, true ) );
		if ( null !== $status ) {
			$rows[ __( 'VAT ID check', 'eurob2b' ) ] = Admin\ValidationsPage::status_label( $status );
		}
		return $rows;
	}

	/**
	 * Colour of the VAT ID check in the EuroB2B box of the order screen (PLT-017).
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
		$status = ValidationStatus::tryFrom( (string) $order->get_meta( self::META_VALIDATION_STATE, true ) );
		if ( null !== $status ) {
			$tones[ __( 'VAT ID check', 'eurob2b' ) ] = Admin\ValidationsPage::status_tone( $status );
		}
		return $tones;
	}

	/* ---------------------------------------------------------------- Shared */

	/**
	 * Normalises and checks an input. Returns the outcome or a WP_Error that blocks the action.
	 *
	 * @param string             $raw          Input.
	 * @param string|null        $country_hint Billing country.
	 * @param int|null           $user_id      Customer.
	 * @param bool               $online       Whether to ask the service (false = syntax only).
	 * @param TraderDetails|null $trader       Company data of a checkout (qualified BZSt confirmation); null = the registered customer's own.
	 * @return array{vat_id: VatId, record: ValidationRecord}|WP_Error
	 */
	public function check_input( string $raw, ?string $country_hint, ?int $user_id, bool $online, ?TraderDetails $trader = null ) {
		$vat_id = $this->service->normalize( $raw, $country_hint );
		if ( ! $vat_id instanceof VatId ) {
			return new WP_Error( 'eurob2b_vat_id', self::syntax_message( $vat_id ) );
		}
		if ( ! $online ) {
			$record = new ValidationRecord( 0, $user_id, null, null, $vat_id->country_code(), $vat_id->number, ValidationStatus::UNAVAILABLE, 'validation_disabled', 'none', null, null, null, null, null, null, 0, ValidationService::now(), null );
			return array(
				'vat_id' => $vat_id,
				'record' => $record,
			);
		}
		$record = $this->service->check( $vat_id, $user_id, false, $trader );
		if ( ValidationStatus::INVALID === $record->status && $this->settings->get( 'tax_block_invalid' ) ) {
			if ( ExternalServices::UID_PREFIX === $vat_id->prefix && ExternalServices::REASON_NOT_VAT_REGISTERED === $record->reason_code ) {
				// A Liechtenstein business may hold a UID, but its VAT number is the five-digit one.
				return new WP_Error( 'eurob2b_vat_id', __( 'The number is not an active Swiss VAT number. Businesses in Liechtenstein enter their five-digit Liechtenstein VAT number instead. Please check it or leave the field empty.', 'eurob2b' ) );
			}
			return new WP_Error( 'eurob2b_vat_id', __( 'The VAT ID could not be confirmed as registered. Please check it or leave the field empty.', 'eurob2b' ) );
		}
		if ( $this->service->blocks_action( $record ) ) {
			return new WP_Error( 'eurob2b_vat_id', __( 'The VAT ID could not be checked right now. Please try again in a few minutes.', 'eurob2b' ) );
		}
		return array(
			'vat_id' => $vat_id,
			'record' => $record,
		);
	}

	/**
	 * Checks a number posted with the classic checkout, once per request for the same number, billing
	 * country and trader details (the submit checks before the totals and validates after them).
	 *
	 * @param string               $raw     Posted number.
	 * @param string               $country Billing country.
	 * @param array<string, mixed> $data    Posted checkout data (billing details).
	 * @return array{vat_id: VatId, record: ValidationRecord}|WP_Error
	 */
	private function check_posted( string $raw, string $country, array $data ) {
		$trader = TraderDetails::from( self::posted( $data, 'billing_company' ), self::posted( $data, 'billing_city' ), self::posted( $data, 'billing_postcode' ), self::posted( $data, 'billing_address_1' ) );
		$key    = (string) wp_json_encode( array( $raw, $country, null !== $trader ? get_object_vars( $trader ) : null ) );
		if ( null !== $this->posted_check && $this->posted_check['key'] === $key ) {
			return $this->posted_check['outcome'];
		}
		$outcome            = $this->check_input( $raw, $country, get_current_user_id() > 0 ? get_current_user_id() : null, $this->service->enabled(), $trader );
		$this->posted_check = array(
			'key'     => $key,
			'outcome' => $outcome,
		);
		return $outcome;
	}

	/**
	 * A posted checkout value as plain text.
	 *
	 * @param array<string, mixed> $data Posted checkout data.
	 * @param string               $key  Key.
	 */
	private static function posted( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
	}

	/**
	 * Writes VAT ID and evidence to a user.
	 *
	 * @param int              $user_id User id.
	 * @param VatId            $vat_id  Number.
	 * @param ValidationRecord $record  Record.
	 */
	private function store_on_user( int $user_id, VatId $vat_id, ValidationRecord $record ): void {
		update_user_meta( $user_id, self::META_VAT_ID, $vat_id->canonical() );
		// WooCommerce's copy of the Checkout block field prefills the block and counts in the cart;
		// keep it in step when the customer has one (no new copy is created).
		if ( metadata_exists( 'user', $user_id, self::BLOCK_META ) ) {
			update_user_meta( $user_id, self::BLOCK_META, $vat_id->canonical() );
		}
		update_user_meta( $user_id, self::META_VALIDATION_STATE, $record->status->value );
		if ( $record->exists() ) {
			update_user_meta( $user_id, self::META_VALIDATION_ID, (string) $record->id );
			if ( null === $record->user_id ) {
				$this->service->repository()->set_user( $record->id, $user_id );
			}
		} else {
			delete_user_meta( $user_id, self::META_VALIDATION_ID );
		}
	}

	/**
	 * WooCommerce order anonymisation (PRV-003): the VAT ID copies go with the other billing data,
	 * except on orders with a tax exemption, where the number is part of the tax evidence (invoice,
	 * recapitulative statement) and stays. The validation id and state carry no personal data.
	 *
	 * @param mixed $order Order.
	 */
	public function anonymise_order( $order ): void {
		if ( ! $order instanceof WC_Order || in_array( (string) $order->get_meta( CartTreatment::META_EXEMPT, true ), array( 'yes', 'partial' ), true ) ) {
			return;
		}
		/** This filter is documented in VatIdField::store_on_order(). */
		$keys = apply_filters( 'eurob2b_order_vat_id_meta_keys', array( self::META_VAT_ID, '_billing_vat_id' ) );
		// The defaults always go, even if the filter changed since older orders were written.
		// The Checkout block keeps the address field's value for the shipping address too.
		$keys    = array_merge( is_array( $keys ) ? array_filter( $keys, 'is_string' ) : array(), array( self::META_VAT_ID, '_billing_vat_id', self::BLOCK_META, self::BLOCK_META_SHIPPING ) );
		$changed = false;
		foreach ( array_unique( $keys ) as $key ) {
			if ( '' !== $key && '' !== (string) $order->get_meta( $key, true ) ) {
				$order->delete_meta_data( $key );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Writes VAT ID and evidence to an order (HPOS-safe CRUD; invoice plugins read `_billing_vat_id`).
	 *
	 * @param WC_Order         $order  Order.
	 * @param VatId            $vat_id Number.
	 * @param ValidationRecord $record Record.
	 */
	private function store_on_order( WC_Order $order, VatId $vat_id, ValidationRecord $record ): void {
		/**
		 * Filters the order meta keys that receive the VAT ID (for invoice plugins).
		 *
		 * @param string[] $keys Meta keys.
		 */
		$keys = apply_filters( 'eurob2b_order_vat_id_meta_keys', array( self::META_VAT_ID, '_billing_vat_id' ) );
		foreach ( is_array( $keys ) ? $keys : array( self::META_VAT_ID ) as $key ) {
			if ( is_string( $key ) && '' !== $key ) {
				$order->update_meta_data( $key, $vat_id->canonical() );
			}
		}
		$order->update_meta_data( self::META_VALIDATION_STATE, $record->status->value );
		if ( $record->exists() ) {
			$order->update_meta_data( self::META_VALIDATION_ID, (string) $record->id );
			if ( $order->get_id() > 0 ) {
				$linked = $this->service->attach_order( $record, $order->get_id() );
				$order->update_meta_data( self::META_VALIDATION_ID, (string) $linked->id );
			} else {
				// The order is saved right after this hook; attach the evidence once it has an id.
				add_action(
					'woocommerce_checkout_order_created',
					function ( $created ) use ( $record ): void {
						if ( $created instanceof WC_Order && (string) $created->get_meta( self::META_VALIDATION_ID, true ) === (string) $record->id ) {
							$linked = $this->service->attach_order( $record, $created->get_id() );
							if ( $linked->id !== $record->id ) {
								$created->update_meta_data( self::META_VALIDATION_ID, (string) $linked->id );
								$created->save();
							}
						}
					}
				);
			}
		}//end if
	}

	/**
	 * Keeps the checked number in the session so the tax treatment of a guest cart can use it.
	 *
	 * @param VatId            $vat_id Number.
	 * @param ValidationRecord $record Record.
	 */
	private static function remember( VatId $vat_id, ValidationRecord $record ): void {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}
		WC()->session->set( self::SESSION_VAT_ID, $vat_id->canonical() );
		WC()->session->set( self::SESSION_RECORD_ID, $record->exists() ? $record->id : 0 );
	}

	/**
	 * Whether the field is required for the current visitor (mode required_b2b and a B2B audience).
	 */
	private function required_for_current_user(): bool {
		return self::MODE_REQUIRED_B2B === $this->mode() && $this->audiences->current()->is_b2b();
	}

	/**
	 * Required at checkout: audience rule plus the billing country currently chosen (the country list
	 * is enforced again on submit, and the field is optional outside the list).
	 */
	private function required_at_checkout(): bool {
		if ( ! $this->required_for_current_user() ) {
			return false;
		}
		$country = function_exists( 'WC' ) && null !== WC()->customer ? (string) WC()->customer->get_billing_country() : '';
		return $this->applies_to_country( $country );
	}

	/**
	 * Registration type chosen in the posted form.
	 *
	 * @param array<string, mixed> $post Posted data.
	 */
	private function registration_type( array $post ): ?RegistrationType {
		$slug = isset( $post['eurob2b_locked_type'] ) && is_scalar( $post['eurob2b_locked_type'] ) ? sanitize_key( (string) $post['eurob2b_locked_type'] ) : '';
		if ( '' === $slug ) {
			$slug = isset( $post['eurob2b_registration_type'] ) && is_scalar( $post['eurob2b_registration_type'] ) ? sanitize_key( (string) $post['eurob2b_registration_type'] ) : '';
		}
		return '' !== $slug ? $this->types->find( $slug ) : null;
	}

	/**
	 * Slugs of the enabled business registration types.
	 *
	 * @return string[]
	 */
	private function business_type_slugs(): array {
		return array_values( array_map( static fn( RegistrationType $t ): string => $t->slug, array_filter( $this->types->enabled(), static fn( RegistrationType $t ): bool => CustomerType::B2B === $t->customer_type ) ) );
	}

	/**
	 * Renders the input (WooCommerce form markup).
	 *
	 * @param string $value    Current value.
	 * @param string $types    Comma-separated type slugs for the conditional display, "" for always.
	 * @param bool   $required Whether the field is required.
	 */
	private function render_input( string $value, string $types, bool $required ): void {
		$attr = $required ? ( '' === $types ? ' required' : ' data-eurob2b-required="1"' ) : '';
		echo '<p class="form-row form-row-wide eurob2b-vat-id-row" data-eurob2b-types="' . esc_attr( $types ) . '">';
		echo '<label for="' . esc_attr( self::INPUT ) . '">' . esc_html__( 'VAT ID', 'eurob2b' ) . ( $required ? ' <span class="required" aria-hidden="true">*</span>' : ' <span class="optional">(' . esc_html__( 'optional', 'eurob2b' ) . ')</span>' ) . '</label>';
		echo '<input type="text" class="input-text" id="' . esc_attr( self::INPUT ) . '" name="' . esc_attr( self::INPUT ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'e.g. DE123456789', 'eurob2b' ) . '" autocomplete="off" aria-describedby="' . esc_attr( self::INPUT . '-hint' ) . '"' . $attr . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute.
		echo '<span class="description" id="' . esc_attr( self::INPUT . '-hint' ) . '">' . esc_html__( 'With country prefix.', 'eurob2b' ) . '</span></p>';
	}

	/**
	 * Message for a normaliser reason code.
	 *
	 * @param string $reason Reason code.
	 */
	public static function syntax_message( string $reason ): string {
		return match ( $reason ) {
			'empty'          => __( 'Please enter your VAT ID.', 'eurob2b' ),
			'unknown_prefix' => __( 'Please enter the VAT ID with its country prefix, e.g. DE123456789.', 'eurob2b' ),
			default          => __( 'This does not look like a valid VAT ID. Please check it.', 'eurob2b' ),
		};
	}

	/**
	 * Customer-facing wording for a check result.
	 *
	 * @param ValidationRecord $record Record.
	 */
	public static function feedback_message( ValidationRecord $record ): string {
		// Swiss and Norwegian numbers never change the tax of the order (CH-05, NO-02), so their
		// wording says nothing about VAT or a review.
		$outside_eu = in_array( CountryRegistry::tax_area( $record->country_code ), array( TaxArea::SWISS_VAT_AREA, TaxArea::EEA_OWN_VAT ), true );
		if ( $outside_eu && ValidationService::is_format_only( $record ) ) {
			return __( 'VAT ID format checked.', 'eurob2b' );
		}
		return match ( $record->status ) {
			ValidationStatus::VALID       => __( 'VAT ID confirmed.', 'eurob2b' ),
			ValidationStatus::INVALID     => __( 'The VAT ID could not be confirmed as registered.', 'eurob2b' ),
			ValidationStatus::PENDING, ValidationStatus::UNAVAILABLE => $outside_eu
				? __( 'The VAT ID could not be checked right now.', 'eurob2b' )
				: __( 'The VAT ID could not be checked right now; your order will be processed with VAT and reviewed.', 'eurob2b' ),
		};
	}
}

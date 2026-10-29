<?php
/**
 * Registration form integration.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Registration\RegistrationType;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\LoginGuard;
use EuroB2B\Support\Assets;
use EuroB2B\Support\Templates;
use WP_Error;

/**
 * Adds the type choice and custom fields to the WooCommerce registration form,
 * validates the submission and completes the customer after WooCommerce created the user.
 *
 * Also provides the [eurob2b_registration] shortcode and the block render
 * callback, both of which render WooCommerce's own form locked to one type.
 */
final class RegistrationForm {

	public const SHORTCODE = 'eurob2b_registration';

	/**
	 * Service.
	 *
	 * @var RegistrationService
	 */
	private RegistrationService $service;

	/**
	 * Types.
	 *
	 * @var RegistrationTypeRepository
	 */
	private RegistrationTypeRepository $types;

	/**
	 * Fields.
	 *
	 * @var FieldRepository
	 */
	private FieldRepository $fields;

	/**
	 * Renderer.
	 *
	 * @var FieldRenderer
	 */
	private FieldRenderer $renderer;

	/**
	 * Anti-abuse.
	 *
	 * @var AntiAbuse
	 */
	private AntiAbuse $anti_abuse;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Validation result of the current request, consumed in on_created_customer().
	 *
	 * @var array{type: RegistrationType|null, values: array<string, string>, terms: bool, uploads?: array<string, array{tmp: string, name: string, mime: string, size: int}>}|null
	 */
	private ?array $pending = null;

	/**
	 * Type slug the currently rendered form is locked to (shortcode/block), null for the dropdown.
	 *
	 * @var string|null
	 */
	private ?string $locked = null;

	/**
	 * Whether WooCommerce's My Account registration handler runs in this request: set by its
	 * woocommerce_process_registration_errors filter, which only that handler fires, and consumed by
	 * validate(). Every registration through that handler is validated, whatever keys were posted.
	 *
	 * @var bool
	 */
	private bool $form_handler = false;

	/**
	 * Constructor.
	 *
	 * @param RegistrationService        $service    Service.
	 * @param RegistrationTypeRepository $types      Types.
	 * @param FieldRepository            $fields     Fields.
	 * @param FieldRenderer              $renderer   Renderer.
	 * @param AntiAbuse                  $anti_abuse Anti-abuse checks.
	 * @param Settings                   $settings   Settings.
	 */
	public function __construct( RegistrationService $service, RegistrationTypeRepository $types, FieldRepository $fields, FieldRenderer $renderer, AntiAbuse $anti_abuse, Settings $settings ) {
		$this->service    = $service;
		$this->types      = $types;
		$this->fields     = $fields;
		$this->renderer   = $renderer;
		$this->anti_abuse = $anti_abuse;
		$this->settings   = $settings;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_register_form_start', array( $this, 'render_type_choice' ) );
		add_action( 'woocommerce_register_form', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_register_form_tag', array( $this, 'form_tag' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'mark_form_handler' ) );
		add_filter( 'woocommerce_registration_errors', array( $this, 'validate' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'on_created_customer' ), 10, 1 );
		add_filter( 'woocommerce_registration_auth_new_customer', array( $this, 'maybe_prevent_auto_login' ) );
		add_filter( 'woocommerce_registration_redirect', array( $this, 'maybe_redirect_pending' ) );
		add_filter( 'woocommerce_email_enabled_customer_new_account', array( $this, 'maybe_suppress_new_account_email' ), 10, 2 );
		add_filter( 'woocommerce_email_additional_content_customer_new_account', array( $this, 'pending_notice_in_welcome_email' ), 10, 2 );
		add_action( 'woocommerce_after_template_part', array( $this, 'pending_notice_in_block_email' ), 10, 4 );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Registers and enqueues the small progressive-enhancement script on account pages.
	 */
	public function enqueue(): void {
		wp_register_script( 'eurob2b-registration', EUROB2B_PLUGIN_URL . 'assets/js/registration.js', array(), Assets::version( 'assets/js/registration.js' ), true );
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_script( 'eurob2b-registration' );
		}
	}

	/**
	 * The registration form sends files when a file field is set up (REG-016).
	 */
	public function form_tag(): void {
		foreach ( $this->fields->all() as $field ) {
			if ( $field->is_file() ) {
				echo ' enctype="multipart/form-data"';
				return;
			}
		}
	}

	/**
	 * Renders the registration type dropdown (or a hidden input when locked).
	 */
	public function render_type_choice(): void {
		$types = $this->types->offered();
		echo $this->anti_abuse->honeypot_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method.

		if ( null !== $this->locked ) {
			echo '<input type="hidden" name="eurob2b_registration_type" value="' . esc_attr( $this->locked ) . '" />';
			return;
		}
		if ( count( $types ) < 2 ) {
			$only = reset( $types );
			if ( $only instanceof RegistrationType ) {
				echo '<input type="hidden" name="eurob2b_registration_type" value="' . esc_attr( $only->slug ) . '" />';
			}
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- re-rendering the previous choice only; WooCommerce verifies the nonce on submit.
		$current = isset( $_POST['eurob2b_registration_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['eurob2b_registration_type'] ) ) : '';

		echo '<p class="form-row form-row-wide eurob2b-registration-type">';
		echo '<label for="eurob2b_registration_type">' . esc_html__( 'I am registering as', 'eurob2b' ) . ' <span class="required" aria-hidden="true">*</span></label>';
		echo '<select class="select" id="eurob2b_registration_type" name="eurob2b_registration_type" required>';
		echo '<option value="">' . esc_html__( 'Please choose', 'eurob2b' ) . '</option>';
		foreach ( $types as $type ) {
			echo '<option value="' . esc_attr( $type->slug ) . '" ' . selected( $current, $type->slug, false ) . '>' . esc_html( $type->label ) . '</option>';
		}
		echo '</select>';
		foreach ( $types as $type ) {
			if ( '' !== $type->description ) {
				// registration.js links the visible description to the select (aria-describedby).
				echo '<span class="description" id="' . esc_attr( 'eurob2b-registration-type-description-' . $type->slug ) . '" data-eurob2b-type-description="' . esc_attr( $type->slug ) . '" hidden>' . esc_html( $type->description ) . '</span>';
			}
		}
		echo '</p>';
	}

	/**
	 * Renders the custom fields (all of them; the script hides the ones of other types).
	 */
	public function render_fields(): void {
		$enabled = array_keys( $this->types->offered() );
		foreach ( $this->fields->all() as $field ) {
			$types = empty( $field->registration_types ) ? array() : array_values( array_intersect( $field->registration_types, $enabled ) );
			// A file field cannot be filled in again after an error; the customer chooses the file anew.
			if ( ! empty( $field->registration_types ) && empty( $types ) ) {
				continue;
				// Only belongs to disabled types.
			}
			if ( null !== $this->locked && ! $field->applies_to( $this->locked ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- re-populating after a failed submit; WooCommerce verifies the nonce; value sanitised by FieldDefinition::sanitize().
			$value = isset( $_POST[ $field->input_name() ] ) ? $field->sanitize( wp_unslash( $_POST[ $field->input_name() ] ) ) : '';
			echo $this->renderer->render( $field, $value, null !== $this->locked ? array() : $types ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer.
		}

		// The terms checkbox belongs to the business types (REG-012): shown only for them, and without
		// a hard "required" on the unlocked form so a consumer can submit without the script.
		$business = array_values( array_map( static fn( RegistrationType $t ): string => $t->slug, array_filter( $this->types->offered(), static fn( RegistrationType $t ): bool => CustomerType::B2B === $t->customer_type ) ) );
		$show     = $this->settings->get( 'registration_terms_required' ) && ! empty( $business ) && ( null === $this->locked || in_array( $this->locked, $business, true ) );
		if ( $show ) {
			$text = (string) $this->settings->get( 'registration_terms_text' );
			$attr = null !== $this->locked ? ' required' : ' data-eurob2b-required="1"';
			echo '<p class="form-row form-row-wide eurob2b-terms" data-eurob2b-types="' . esc_attr( null !== $this->locked ? '' : implode( ',', $business ) ) . '"><label class="checkbox" for="eurob2b_terms">';
			echo '<input type="checkbox" class="input-checkbox" id="eurob2b_terms" name="eurob2b_terms" value="1"' . $attr . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute.
			echo wp_kses( '' !== $text ? $text : __( 'I accept the terms and conditions.', 'eurob2b' ), RegistrationModule::terms_allowed_html() );
			echo ' <span class="required" aria-hidden="true">*</span></label></p>';
		}
	}

	/**
	 * Notes that WooCommerce's My Account registration handler processes this request
	 * (woocommerce_process_registration_errors; fired after its nonce check, before it creates the
	 * customer). That handler also accepts its nonce as `_wpnonce`, so the posted keys alone cannot
	 * tell a registration form from checkout account creation.
	 *
	 * @param mixed $errors Errors so far (returned unchanged).
	 * @return mixed
	 */
	public function mark_form_handler( $errors ) {
		$this->form_handler = true;
		return $errors;
	}

	/**
	 * Validates the EuroB2B part of the registration (woocommerce_registration_errors).
	 *
	 * @param WP_Error $errors Errors so far.
	 */
	public function validate( WP_Error $errors ): WP_Error {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce-register-nonce before this filter runs.
		$post = wp_unslash( $_POST );
		if ( ! is_array( $post ) ) {
			$post = array();
		}

		$form_handler       = $this->form_handler;
		$this->form_handler = false;
		if ( ! self::is_registration_form_submit( $post, $form_handler ) ) {
			// Account creation at checkout (classic or Store API) and programmatic wc_create_new_customer()
			// calls run this filter too; they carry no registration type and must not be blocked.
			$this->pending = null;
			return $errors;
		}

		$abuse = $this->anti_abuse->check( $post );
		if ( null !== $abuse ) {
			$errors->add( 'eurob2b_' . $abuse, 'throttled' === $abuse ? __( 'Too many registrations from your connection. Please try again later.', 'eurob2b' ) : __( 'Your registration could not be processed. Please try again.', 'eurob2b' ) );
			$this->pending = null;
			return $errors;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$locked = isset( $post['eurob2b_locked_type'] ) ? sanitize_key( (string) $post['eurob2b_locked_type'] ) : null;
		$locked = '' === $locked ? null : $locked;
		if ( null !== $locked ) {
			$signature = isset( $post['eurob2b_locked_sig'] ) && is_scalar( $post['eurob2b_locked_sig'] ) ? (string) $post['eurob2b_locked_sig'] : '';
			if ( ! hash_equals( self::locked_signature( $locked ), $signature ) ) {
				$errors->add( 'eurob2b_registration_type', __( 'The registration form could not be verified. Please reload the page and try again.', 'eurob2b' ) );
				$this->pending = null;
				return $errors;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; PrivateFiles checks every upload.
		$files         = $_FILES;
		$this->pending = $this->service->validate( $post, $errors, $locked, (bool) $this->settings->get( 'registration_terms_required' ), $files );
		return $errors;
	}

	/**
	 * Whether the posted data comes from a registration form this plugin renders (My Account form,
	 * shortcode or block), as opposed to checkout account creation or an API call. A request
	 * WooCommerce's My Account registration handler processes always is one: a visitor who posts the
	 * nonce as `_wpnonce` without EuroB2B's fields, or adds a checkout key, must not skip the type
	 * choice, the required fields, the terms, the spam checks and the approval.
	 *
	 * @param array<string, mixed> $post         Posted data.
	 * @param bool                 $form_handler Whether WooCommerce's My Account registration handler runs.
	 */
	public static function is_registration_form_submit( array $post, bool $form_handler = false ): bool {
		if ( $form_handler ) {
			return true;
		}
		if ( isset( $post['woocommerce-process-checkout-nonce'] ) || isset( $post['woocommerce_checkout_place_order'] ) ) {
			return false;
		}
		return isset( $post['woocommerce-register-nonce'] ) || isset( $post['eurob2b_registration_type'] ) || isset( $post['eurob2b_locked_type'] );
	}

	/**
	 * Completes the customer after WooCommerce created the user (woocommerce_created_customer).
	 *
	 * @param int $customer_id New user id.
	 */
	public function on_created_customer( int $customer_id ): void {
		if ( null === $this->pending || null === $this->pending['type'] ) {
			return;
			// Not an EuroB2B registration (e.g. checkout account creation handled elsewhere).
		}
		$this->anti_abuse->record();
		$this->service->complete( $customer_id, $this->pending['type'], $this->pending['values'], (bool) ( $this->pending['terms'] ?? false ), $this->pending['uploads'] ?? array() );
	}

	/**
	 * Pending customers must not be logged in automatically after registering. When their welcome
	 * e-mail is suppressed, WooCommerce's notice "… login details have been sent to your email
	 * address", added just before this filter, is not true and goes; the pending notice explains.
	 *
	 * @param bool $auth Whether WooCommerce would log the new customer in.
	 */
	public function maybe_prevent_auto_login( bool $auth ): bool {
		if ( null !== $this->pending && null !== $this->pending['type'] && $this->pending['type']->requires_approval() ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				if ( $this->settings->get( 'registration_suppress_pending_welcome' ) ) {
					self::drop_account_created_notice();
				}
				wc_add_notice( LoginGuard::pending_message(), 'notice' );
			}
			return false;
		}
		return $auth;
	}

	/**
	 * Removes the success notice WooCommerce added last (its "account created" notice: WooCommerce
	 * adds it right before woocommerce_registration_auth_new_customer).
	 */
	private static function drop_account_created_notice(): void {
		if ( null === WC()->session ) {
			return;
		}
		$notices = wc_get_notices();
		if ( empty( $notices['success'] ) || ! is_array( $notices['success'] ) ) {
			return;
		}
		array_pop( $notices['success'] );
		wc_set_notices( $notices );
	}

	/**
	 * Suppresses WooCommerce's "new account" e-mail (which may carry a set-password link) for
	 * customers who still wait for approval, when the setting says so. The approval e-mail
	 * carries a set-password link instead.
	 *
	 * @param bool  $enabled Whether the e-mail is enabled.
	 * @param mixed $user    The new user (WP_User) or null.
	 */
	public function maybe_suppress_new_account_email( bool $enabled, $user ): bool {
		if ( ! $enabled || ! $this->settings->get( 'registration_suppress_pending_welcome' ) ) {
			return $enabled;
		}
		$user_id = $user instanceof \WP_User ? $user->ID : ( is_numeric( $user ) ? (int) $user : 0 );
		if ( $user_id > 0 && $this->is_pending( $user_id ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * When the welcome e-mail goes out to a customer who still waits for approval (the setting above
	 * is off), the pending notice of the login screen is appended (REG-006): in the classic templates
	 * it opens the additional content below the account details.
	 *
	 * @param mixed $content Additional content of the e-mail.
	 * @param mixed $user    The new user (WP_User) or null in the e-mail preview.
	 * @return mixed
	 */
	public function pending_notice_in_welcome_email( $content, $user ) {
		$user_id = $user instanceof \WP_User ? $user->ID : 0;
		if ( $user_id < 1 || ! $this->is_pending( $user_id ) ) {
			return $content;
		}
		$notice = LoginGuard::pending_message();
		return '' === trim( (string) $content ) ? $notice : $notice . "\n\n" . (string) $content;
	}

	/**
	 * WooCommerce's block e-mail editor renders the welcome e-mail from a block template that does
	 * not use the additional-content filter; there the notice follows the template's content.
	 *
	 * @param mixed $template_name Template name.
	 * @param mixed $template_path Template path.
	 * @param mixed $located       Located file.
	 * @param mixed $args          Template arguments.
	 */
	public function pending_notice_in_block_email( $template_name, $template_path, $located, $args ): void {
		if ( ! is_string( $template_name ) || ! str_starts_with( $template_name, 'emails/block/' ) || ! is_array( $args ) ) {
			return;
		}
		$email = $args['email'] ?? null;
		if ( ! $email instanceof \WC_Email || 'customer_new_account' !== $email->id || ! $email->object instanceof \WP_User || ! $this->is_pending( $email->object->ID ) ) {
			return;
		}
		echo '<p>' . esc_html( LoginGuard::pending_message() ) . '</p>';
	}

	/**
	 * Keeps pending customers on the account page so they see the notice.
	 *
	 * @param string $redirect Redirect URL.
	 */
	public function maybe_redirect_pending( string $redirect ): string {
		if ( null !== $this->pending && null !== $this->pending['type'] && $this->pending['type']->requires_approval() && function_exists( 'wc_get_page_permalink' ) ) {
			return wc_get_page_permalink( 'myaccount' );
		}
		return $redirect;
	}

	/**
	 * [eurob2b_registration type="business"] renders WooCommerce's registration form locked to one type.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'type' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		return $this->render_locked_form( sanitize_key( (string) $atts['type'] ) );
	}

	/**
	 * Renders the WooCommerce registration form locked to a type.
	 *
	 * @param string $type_slug Type slug.
	 */
	public function render_locked_form( string $type_slug ): string {
		$type    = $this->types->find( $type_slug );
		$problem = null;
		if ( null === $type || ! $this->types->is_offered( $type ) ) {
			$problem = __( 'EuroB2B: unknown or disabled registration type in the form.', 'eurob2b' );
		} elseif ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			$problem = __( 'EuroB2B: enable "Allow customers to create an account on the My account page" in WooCommerce → Settings → Accounts.', 'eurob2b' );
		}
		// The administrator who looks at the page is logged in, so a set-up hint comes before
		// "already logged in"; every other visitor never sees it.
		if ( null !== $problem && current_user_can( 'manage_options' ) ) {
			return '<p class="eurob2b-registration-missing">' . esc_html( $problem ) . '</p>';
		}
		if ( is_user_logged_in() ) {
			return '<p class="eurob2b-registration-logged-in">' . esc_html__( 'You are already logged in.', 'eurob2b' ) . '</p>';
		}
		if ( null !== $problem || null === $type ) {
			return '';
		}

		$this->locked = $type->slug;
		wp_enqueue_script( 'eurob2b-registration' );
		add_action( 'woocommerce_register_form_start', array( $this, 'render_locked_marker' ) );

		$html = '';
		if ( function_exists( 'wc_print_notices' ) ) {
			ob_start();
			wc_print_notices();
			$html = (string) ob_get_clean();
		}
		$html .= Templates::render( 'registration-form.php', array( 'type' => $type ) );

		remove_action( 'woocommerce_register_form_start', array( $this, 'render_locked_marker' ) );
		$this->locked = null;
		return $html;
	}

	/**
	 * Marks a locked form so the server knows which type to validate against.
	 */
	public function render_locked_marker(): void {
		echo '<input type="hidden" name="eurob2b_locked_type" value="' . esc_attr( (string) $this->locked ) . '" />';
		echo '<input type="hidden" name="eurob2b_locked_sig" value="' . esc_attr( self::locked_signature( (string) $this->locked ) ) . '" />';
	}

	/**
	 * Keyed signature binding a locked form to its configured type, so the posted marker cannot be
	 * swapped for another (e.g. automatically approved) type by the client.
	 *
	 * @param string $type_slug Type slug.
	 */
	public static function locked_signature( string $type_slug ): string {
		return wp_hash( 'eurob2b_locked_type|' . $type_slug, 'nonce' );
	}

	/**
	 * Whether a user registered through a type that requires approval and is still pending.
	 *
	 * @param int $user_id User id.
	 */
	public function is_pending( int $user_id ): bool {
		return CustomerStatus::PENDING->value === get_user_meta( $user_id, \EuroB2B\Modules\Customers\CustomersModule::META_STATUS, true );
	}
}

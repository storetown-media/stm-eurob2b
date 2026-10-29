<?php
/**
 * Business registrations made by other forms.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\LoginGuard;
use EuroB2B\Modules\Tax\VatIdField;
use Throwable;
use WP_Error;
use WP_User;

/**
 * REG-011: a form builder (Gravity Forms, WPForms, Forminator …) creates the WordPress user, and
 * EuroB2B turns it into a business customer: registration type, custom field values, terms
 * acceptance and VAT ID, with the validation of the EuroB2B registration form. The registration
 * type decides between pending (manual approval) and approved, and the usual e-mails go out.
 *
 * Input (`$data`):
 * - `type`: slug of an enabled business registration type; default: the first one.
 * - `fields`: custom field values by field key.
 * - `vat_id`: VAT ID ('' or missing = none).
 * - `terms_accepted`: true (or "1", "yes") when the form had the terms checkbox ticked.
 */
final class ExternalRegistration {

	/**
	 * Roles an account may have to become a customer this way (staff accounts are refused).
	 */
	public const CUSTOMER_ROLES = array( 'customer', 'subscriber' );

	/**
	 * Registration service.
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
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * VAT ID field (null without the tax module).
	 *
	 * @var VatIdField|null
	 */
	private ?VatIdField $vat;

	/**
	 * Constructor.
	 *
	 * @param RegistrationService        $service  Registration service.
	 * @param RegistrationTypeRepository $types    Types.
	 * @param FieldRepository            $fields   Fields.
	 * @param Settings                   $settings Settings.
	 * @param VatIdField|null            $vat      VAT ID field.
	 */
	public function __construct( RegistrationService $service, RegistrationTypeRepository $types, FieldRepository $fields, Settings $settings, ?VatIdField $vat ) {
		$this->service  = $service;
		$this->types    = $types;
		$this->fields   = $fields;
		$this->settings = $settings;
		$this->vat      = $vat;
	}

	/**
	 * Checks the data of a business registration before the form builder creates the user. Field,
	 * terms and VAT ID messages are translated and meant for the visitor. Nothing is written: the
	 * VAT ID is checked for presence and syntax here and online only by register(), when the
	 * evidence can belong to the customer.
	 *
	 * @param array<string, mixed> $data Registration data.
	 * @return true|WP_Error
	 */
	public function validate( array $data ) {
		$checked = $this->prepare( $data, null );
		return $checked instanceof WP_Error ? $checked : true;
	}

	/**
	 * Checks the data; the VAT ID online only for an existing customer.
	 *
	 * @param array<string, mixed> $data    Registration data.
	 * @param int|null             $user_id Customer for the online VAT ID check, or null (syntax only).
	 * @return array{type: RegistrationType, values: array<string, string>, terms: bool, vat: mixed}|WP_Error
	 */
	private function prepare( array $data, ?int $user_id ) {
		$type = $this->type( is_scalar( $data['type'] ?? null ) ? (string) $data['type'] : '' );
		if ( null === $type ) {
			return new WP_Error( 'eurob2b_registration_type', 'No enabled business registration type matches the data.' );
		}

		$errors = new WP_Error();
		$given  = is_array( $data['fields'] ?? null ) ? $data['fields'] : array();
		$values = array();
		foreach ( $this->fields->for_registration_type( $type->slug ) as $field ) {
			if ( $field->is_file() ) {
				// Form builders keep their own uploads (docs/form-builders.md).
				continue;
			}
			$value = $field->sanitize( $given[ $field->key ] ?? null );
			$code  = $field->validate( $value );
			if ( null !== $code ) {
				$errors->add( 'eurob2b_field_' . $field->key, FieldRenderer::message( $field, $code ) );
			}
			$values[ $field->key ] = $value;
		}

		$terms = is_scalar( $data['terms_accepted'] ?? null ) && filter_var( $data['terms_accepted'], FILTER_VALIDATE_BOOLEAN );
		if ( ! $terms && $this->settings->get( 'registration_terms_required' ) ) {
			$errors->add( 'eurob2b_terms', __( 'Please accept the terms to register.', 'eurob2b' ) );
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$vat = null !== $this->vat ? $this->vat->check_registration_input( is_scalar( $data['vat_id'] ?? null ) ? (string) $data['vat_id'] : '', $user_id ) : null;
		if ( $vat instanceof WP_Error ) {
			return $vat;
		}
		return array(
			'type'   => $type,
			'values' => $values,
			'terms'  => $terms,
			'vat'    => $vat,
		);
	}

	/**
	 * Turns a user the form builder just created into a business customer.
	 *
	 * @param int                  $user_id New user.
	 * @param array<string, mixed> $data    Registration data.
	 * @return true|WP_Error Nothing is written on an error.
	 */
	public function register( int $user_id, array $data ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'eurob2b_unknown_user', sprintf( 'User %d does not exist.', $user_id ) );
		}
		if ( '' !== (string) get_user_meta( $user_id, RegistrationService::META_REGISTRATION_TYPE, true ) || metadata_exists( 'user', $user_id, CustomersModule::META_STATUS ) ) {
			return new WP_Error( 'eurob2b_already_registered', sprintf( 'User %d is already an EuroB2B customer; use eurob2b_set_customer_group() to move it.', $user_id ) );
		}
		if ( array() !== array_diff( $user->roles, self::CUSTOMER_ROLES ) || LoginGuard::is_staff( $user ) ) {
			return new WP_Error( 'eurob2b_not_a_customer_account', sprintf( 'User %d is a staff account (a role besides customer or subscriber, or staff capabilities) and is not registered as a customer.', $user_id ) );
		}

		$checked = $this->prepare( $data, $user_id );
		if ( $checked instanceof WP_Error ) {
			return $checked;
		}
		try {
			$this->service->complete( $user_id, $checked['type'], $checked['values'], $checked['terms'] );
		} catch ( Throwable $e ) {
			return new WP_Error( 'eurob2b_registration_failed', $e->getMessage() );
		}
		if ( null !== $this->vat && is_array( $checked['vat'] ) ) {
			$this->vat->store_registration_input( $user_id, $checked['vat'] );
		}
		return true;
	}

	/**
	 * The action `eurob2b_register_business_customer`: like register(), but an error can only be
	 * logged (codes and the user id, no submitted values) and passed on as an action.
	 *
	 * @param mixed $user_id New user.
	 * @param mixed $data    Registration data.
	 */
	public function on_action( $user_id, $data = array() ): void {
		$result = $this->register( (int) $user_id, is_array( $data ) ? $data : array() );
		if ( ! $result instanceof WP_Error ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( sprintf( 'Business registration of user %d through another form failed: %s.', (int) $user_id, implode( ', ', $result->get_error_codes() ) ), array( 'source' => 'eurob2b' ) );
		}
		/**
		 * Fires when the action `eurob2b_register_business_customer` could not register the user.
		 *
		 * The account stays as the form builder created it (not an EuroB2B business customer).
		 *
		 * @param int      $user_id User id.
		 * @param WP_Error $error   Reasons (field, terms and VAT ID messages are translated).
		 */
		do_action( 'eurob2b_registration_external_failed', (int) $user_id, $result );
	}

	/**
	 * The requested business type, or the first enabled one.
	 *
	 * @param string $slug Slug ('' = default).
	 */
	private function type( string $slug ): ?RegistrationType {
		$slug = sanitize_key( $slug );
		foreach ( $this->types->offered() as $type ) {
			if ( CustomerType::B2B === $type->customer_type && ( '' === $slug || $slug === $type->slug ) ) {
				return $type;
			}
		}
		return null;
	}
}

<?php
/**
 * Custom fields on the My Account details page.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use WP_Error;

/**
 * Lets customers edit their "show in My Account" fields under Account details.
 */
final class AccountFields {

	/**
	 * Fields.
	 *
	 * @var FieldRepository
	 */
	private FieldRepository $fields;

	/**
	 * Storage.
	 *
	 * @var FieldStorage
	 */
	private FieldStorage $storage;

	/**
	 * Renderer.
	 *
	 * @var FieldRenderer
	 */
	private FieldRenderer $renderer;

	/**
	 * Registration service (knows the customer's registration type).
	 *
	 * @var RegistrationService
	 */
	private RegistrationService $service;

	/**
	 * Constructor.
	 *
	 * @param FieldRepository     $fields   Fields.
	 * @param FieldStorage        $storage  Storage.
	 * @param FieldRenderer       $renderer Renderer.
	 * @param RegistrationService $service  Registration service.
	 */
	public function __construct( FieldRepository $fields, FieldStorage $storage, FieldRenderer $renderer, RegistrationService $service ) {
		$this->service  = $service;
		$this->fields   = $fields;
		$this->storage  = $storage;
		$this->renderer = $renderer;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_edit_account_form', array( $this, 'render' ) );
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'validate' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'save' ) );
	}

	/**
	 * Renders the fields.
	 */
	public function render(): void {
		$user_id = get_current_user_id();
		foreach ( $this->fields_for( $user_id ) as $field ) {
			$value = $this->storage->get( $user_id, $field );
			echo $this->renderer->render( $field, $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer.
		}
	}

	/**
	 * Validates submitted values.
	 *
	 * @param WP_Error $errors Errors.
	 */
	public function validate( WP_Error $errors ): void {
		foreach ( $this->fields_for( get_current_user_id() ) as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verified save-account-details-nonce; value sanitised by FieldDefinition::sanitize().
			$raw  = isset( $_POST[ $field->input_name() ] ) ? wp_unslash( $_POST[ $field->input_name() ] ) : null;
			$code = $field->validate( $field->sanitize( $raw ) );
			if ( null !== $code ) {
				$errors->add( 'eurob2b_field_' . $field->key, FieldRenderer::message( $field, $code ) );
			}
		}
	}

	/**
	 * Saves submitted values.
	 *
	 * @param int $user_id User id.
	 */
	public function save( int $user_id ): void {
		foreach ( $this->fields_for( $user_id ) as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verified save-account-details-nonce; value sanitised by FieldDefinition::sanitize().
			$raw = isset( $_POST[ $field->input_name() ] ) ? wp_unslash( $_POST[ $field->input_name() ] ) : null;
			$this->storage->set( $user_id, $field, $field->sanitize( $raw ) );
		}
	}

	/**
	 * Account fields that apply to this customer: fields limited to registration types are only
	 * shown (and required) for customers of one of those types. Customers without a registration
	 * type (created at checkout or before the plugin) only see fields that apply to every type.
	 *
	 * @param int $user_id User id.
	 * @return FieldDefinition[]
	 */
	public function fields_for( int $user_id ): array {
		$type = $user_id > 0 ? $this->service->registration_type_of( $user_id ) : null;
		return array_values(
			array_filter(
				$this->fields->for_account(),
				static fn( FieldDefinition $field ): bool => null === $type ? empty( $field->registration_types ) : $field->applies_to( $type->slug )
			)
		);
	}
}

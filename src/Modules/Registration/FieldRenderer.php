<?php
/**
 * Field renderer.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

/**
 * Renders a custom field as WooCommerce-style form row markup.
 *
 * Uses WooCommerce's own classes (form-row, input-text) so themes style the
 * fields like every other checkout/account field. No plugin CSS.
 */
final class FieldRenderer {

	/**
	 * Size limit of file fields in megabytes (REG-016).
	 *
	 * @var int
	 */
	private int $file_max_mb;

	/**
	 * Constructor.
	 *
	 * @param int $file_max_mb Size limit of file fields in megabytes.
	 */
	public function __construct( int $file_max_mb = 5 ) {
		$this->file_max_mb = max( 1, $file_max_mb );
	}

	/**
	 * Renders one field.
	 *
	 * @param FieldDefinition $field      Field.
	 * @param string          $value      Current value.
	 * @param string[]        $type_slugs Registration types the field belongs to (for the data attribute); empty = all.
	 * @param string          $name_prefix Input name prefix of another form; '' = the registration field name.
	 */
	public function render( FieldDefinition $field, string $value = '', array $type_slugs = array(), string $name_prefix = '' ): string {
		// Other forms (quote requests) pass their own prefix so their inputs never collide with registration fields.
		$name  = '' !== $name_prefix ? $name_prefix . $field->key : $field->input_name();
		$id    = '' !== $name_prefix ? str_replace( '_', '-', rtrim( $name_prefix, '_' ) ) . '-' . $field->key : 'eurob2b-field-' . $field->key;
		$attrs = ' data-eurob2b-types="' . esc_attr( implode( ',', $type_slugs ) ) . '"';
		$req   = $field->required ? ' <span class="required" aria-hidden="true">*</span>' : '';
		// Fields of one registration type must not block submission for other types without the script.
		$req_attr = $field->required ? ( empty( $type_slugs ) ? ' required' : ' data-eurob2b-required="1"' ) : '';
		$label    = esc_html( $field->label ) . $req;
		// The help text is read out with the field (WCAG 1.3.1, 3.3.2), not only shown next to it.
		$help_id   = $id . '-description';
		$described = '' !== $field->help ? ' aria-describedby="' . esc_attr( $help_id ) . '"' : '';

		$html = '<p class="form-row form-row-wide eurob2b-field eurob2b-field-' . esc_attr( $field->type ) . '"' . $attrs . '>';

		switch ( $field->type ) {
			case FieldDefinition::TYPE_CHECKBOX:
				$html .= '<label class="checkbox" for="' . esc_attr( $id ) . '">';
				$html .= '<input type="checkbox" class="input-checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( '1', $value, false ) . $req_attr . $described . ' /> ';
				$html .= $label . '</label>';
				break;

			case FieldDefinition::TYPE_SELECT:
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label>';
				$html .= '<select class="select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req_attr . $described . '>';
				$html .= '<option value="">' . esc_html( '' !== $field->placeholder ? $field->placeholder : __( 'Please choose', 'eurob2b' ) ) . '</option>';
				foreach ( $field->options as $option_value => $option_label ) {
					$html .= '<option value="' . esc_attr( (string) $option_value ) . '" ' . selected( (string) $option_value, $value, false ) . '>' . esc_html( (string) $option_label ) . '</option>';
				}
				$html .= '</select>';
				break;

			case FieldDefinition::TYPE_FILE:
				// The limits are part of the description, so they are read out with the field.
				$limits    = sprintf( /* translators: %d: size limit in megabytes */ __( 'PDF, JPG or PNG, at most %d MB.', 'eurob2b' ), $this->file_max_mb );
				$limits_id = $id . '-limits';
				$html     .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label>';
				$html     .= '<input type="file" class="input-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"' . $req_attr . ' aria-describedby="' . esc_attr( trim( $limits_id . ( '' !== $field->help ? ' ' . $help_id : '' ) ) ) . '" />';
				$html     .= '<span class="description" id="' . esc_attr( $limits_id ) . '">' . esc_html( $limits ) . '</span>';
				break;

			case FieldDefinition::TYPE_TEXTAREA:
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label>';
				$html .= '<textarea class="input-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" placeholder="' . esc_attr( $field->placeholder ) . '"' . $req_attr . $described . '>' . esc_textarea( $value ) . '</textarea>';
				break;

			default:
				$input_type = in_array( $field->type, array( FieldDefinition::TYPE_NUMBER, FieldDefinition::TYPE_TEL, FieldDefinition::TYPE_EMAIL, FieldDefinition::TYPE_DATE ), true ) ? $field->type : 'text';
				$html      .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label>';
				$html      .= '<input type="' . esc_attr( $input_type ) . '" class="input-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $field->placeholder ) . '"' . $req_attr . $described . ( FieldDefinition::TYPE_NUMBER === $field->type ? ' step="any"' : '' ) . ' />';
		}//end switch

		if ( '' !== $field->help ) {
			$html .= '<span class="description" id="' . esc_attr( $help_id ) . '">' . esc_html( $field->help ) . '</span>';
		}
		return $html . '</p>';
	}

	/**
	 * Human readable validation message for an error code.
	 *
	 * @param FieldDefinition $field Field.
	 * @param string          $code  Error code from FieldDefinition::validate().
	 */
	public static function message( FieldDefinition $field, string $code ): string {
		switch ( $code ) {
			case 'required':
				/* translators: %s: field label */
				return sprintf( __( '%s is a required field.', 'eurob2b' ), $field->label );
			case 'email':
				/* translators: %s: field label */
				return sprintf( __( '%s must be a valid e-mail address.', 'eurob2b' ), $field->label );
			case 'number':
				/* translators: %s: field label */
				return sprintf( __( '%s must be a number.', 'eurob2b' ), $field->label );
			case 'date':
				/* translators: %s: field label */
				return sprintf( __( '%s must be a date (YYYY-MM-DD).', 'eurob2b' ), $field->label );
			case 'tel':
				/* translators: %s: field label */
				return sprintf( __( '%s must be a phone number.', 'eurob2b' ), $field->label );
			case 'option':
				/* translators: %s: field label */
				return sprintf( __( 'Please choose a valid option for %s.', 'eurob2b' ), $field->label );
			case 'file_type':
				/* translators: %s: field label */
				return sprintf( __( '%s: only PDF, JPG and PNG files can be uploaded.', 'eurob2b' ), $field->label );
			case 'file_size':
				/* translators: %s: field label */
				return sprintf( __( '%s: the file is too large.', 'eurob2b' ), $field->label );
			case 'file_failed':
				/* translators: %s: field label */
				return sprintf( __( '%s: the file could not be uploaded. Please try again.', 'eurob2b' ), $field->label );
			default:
				/* translators: %s: field label */
				return sprintf( __( '%s is too long.', 'eurob2b' ), $field->label );
		}//end switch
	}
}

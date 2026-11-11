<?php
/**
 * The HTML the plugin prints from its own builders (WordPress.org review of 2026-11).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

/**
 * Markup that is built in one place (a field renderer, a status label, the record of processing
 * activities, a WooCommerce field) and printed in another is escaped again where it is printed
 * ("escape late"): `echo wp_kses( $html, Kses::allowed() );`. The list is the HTML WordPress allows
 * in post content plus the form elements the plugin's forms use, with their attributes.
 */
final class Kses {

	/**
	 * Attributes every added element may carry (WordPress adds the same to the post elements).
	 */
	private const GLOBAL_ATTRIBUTES = array(
		'aria-controls'    => true,
		'aria-current'     => true,
		'aria-describedby' => true,
		'aria-details'     => true,
		'aria-expanded'    => true,
		'aria-hidden'      => true,
		'aria-invalid'     => true,
		'aria-label'       => true,
		'aria-labelledby'  => true,
		'aria-live'        => true,
		'aria-required'    => true,
		'class'            => true,
		'data-*'           => true,
		'dir'              => true,
		'hidden'           => true,
		'id'               => true,
		'lang'             => true,
		'role'             => true,
		'style'            => true,
		'tabindex'         => true,
		'title'            => true,
	);

	/**
	 * Attributes of the form elements, by element.
	 */
	private const FORM_ELEMENTS = array(
		'form'     => array( 'action', 'method', 'enctype', 'target', 'novalidate', 'autocomplete', 'accept-charset', 'name' ),
		'input'    => array( 'type', 'name', 'value', 'placeholder', 'required', 'checked', 'disabled', 'readonly', 'min', 'max', 'step', 'minlength', 'maxlength', 'size', 'pattern', 'autocomplete', 'inputmode', 'list', 'multiple', 'accept', 'form', 'spellcheck', 'autofocus' ),
		'select'   => array( 'name', 'required', 'disabled', 'multiple', 'size', 'autocomplete', 'form' ),
		'option'   => array( 'value', 'selected', 'disabled', 'label' ),
		'optgroup' => array( 'label', 'disabled' ),
		'textarea' => array( 'name', 'rows', 'cols', 'placeholder', 'required', 'disabled', 'readonly', 'minlength', 'maxlength', 'autocomplete', 'spellcheck', 'form' ),
		'button'   => array( 'type', 'name', 'value', 'disabled', 'form', 'formaction', 'formmethod' ),
		'label'    => array( 'for', 'form' ),
		'fieldset' => array( 'name', 'disabled', 'form' ),
		'legend'   => array(),
		'datalist' => array(),
		'output'   => array( 'for', 'form', 'name' ),
		// Row templates that the admin scripts clone (tier rows of the product data panel).
		'template' => array(),
	);

	/**
	 * Allowed elements and attributes for wp_kses().
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed(): array {
		$allowed = function_exists( 'wp_kses_allowed_html' ) ? (array) wp_kses_allowed_html( 'post' ) : array();
		foreach ( self::FORM_ELEMENTS as $element => $attributes ) {
			$own = array_fill_keys( $attributes, true );
			// Keep what WordPress already allows for the element (label, fieldset, legend are post elements).
			$allowed[ $element ] = array_merge( isset( $allowed[ $element ] ) && is_array( $allowed[ $element ] ) ? $allowed[ $element ] : array(), self::GLOBAL_ATTRIBUTES, $own );
		}
		return $allowed;
	}
}

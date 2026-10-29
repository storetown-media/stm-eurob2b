<?php
/**
 * Custom registration field definition.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use InvalidArgumentException;

/**
 * Describes one custom field: where it shows, what it accepts, where its value goes.
 *
 * Values live in user meta `_eurob2b_field_<key>`; mapped fields additionally
 * write the WooCommerce billing meta so checkout is pre-filled. Validation
 * and sanitising are framework-free so they can be unit-tested.
 *
 * A file field (REG-016) belongs to the registration form only: never in the checkout, never on the
 * account page, never mapped. Its value is the id of the file in the private storage (PRV-006);
 * the upload itself is checked by RegistrationService.
 */
final class FieldDefinition {

	public const TYPE_TEXT     = 'text';
	public const TYPE_TEXTAREA = 'textarea';
	public const TYPE_NUMBER   = 'number';
	public const TYPE_TEL      = 'tel';
	public const TYPE_SELECT   = 'select';
	public const TYPE_EMAIL    = 'email';
	public const TYPE_DATE     = 'date';
	public const TYPE_CHECKBOX = 'checkbox';
	public const TYPE_FILE     = 'file';

	public const META_PREFIX = '_eurob2b_field_';

	/**
	 * Keys of the billing fields a custom field may be mapped to (no WordPress needed).
	 *
	 * @return string[]
	 */
	public static function mappable_billing_keys(): array {
		return array( 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_city', 'billing_state', 'billing_country', 'billing_phone' );
	}

	/**
	 * Billing fields a custom field may be mapped to, with translated labels (admin form).
	 *
	 * @return array<string, string>
	 */
	public static function mappable_billing_fields(): array {
		return array(
			'billing_company'   => __( 'Company', 'eurob2b' ),
			'billing_address_1' => __( 'Address line 1', 'eurob2b' ),
			'billing_address_2' => __( 'Address line 2', 'eurob2b' ),
			'billing_postcode'  => __( 'Postcode', 'eurob2b' ),
			'billing_city'      => __( 'City', 'eurob2b' ),
			'billing_state'     => __( 'State / county', 'eurob2b' ),
			'billing_country'   => __( 'Country', 'eurob2b' ),
			'billing_phone'     => __( 'Phone', 'eurob2b' ),
		);
	}

	/**
	 * Known types.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( self::TYPE_TEXT, self::TYPE_TEXTAREA, self::TYPE_NUMBER, self::TYPE_TEL, self::TYPE_SELECT, self::TYPE_EMAIL, self::TYPE_DATE, self::TYPE_CHECKBOX, self::TYPE_FILE );
	}

	/**
	 * Whether the field takes an uploaded file (REG-016).
	 */
	public function is_file(): bool {
		return self::TYPE_FILE === $this->type;
	}

	/**
	 * Constructor.
	 *
	 * @param string   $key              Unique key (a-z0-9_), used in meta keys and form names.
	 * @param string   $label            Label.
	 * @param string   $type             One of types().
	 * @param bool     $required         Required on registration.
	 * @param string[] $registration_types Slugs of registration types that show the field; empty = all.
	 * @param string   $placeholder      Placeholder.
	 * @param string   $help             Help text.
	 * @param string[] $options          Options for select fields (value = label).
	 * @param bool     $show_in_checkout Show in the checkout billing section.
	 * @param bool     $show_in_account  Editable on the My Account details page.
	 * @param string   $map_to           Billing field key to mirror into, or empty.
	 * @param int      $order            Sort order.
	 * @throws InvalidArgumentException On invalid key, label, type or mapping.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $type = self::TYPE_TEXT,
		public readonly bool $required = false,
		public readonly array $registration_types = array(),
		public readonly string $placeholder = '',
		public readonly string $help = '',
		public readonly array $options = array(),
		public readonly bool $show_in_checkout = false,
		public readonly bool $show_in_account = true,
		public readonly string $map_to = '',
		public readonly int $order = 10
	) {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) ) {
			throw new InvalidArgumentException( sprintf( 'FieldDefinition: "%s" is not a valid key.', $key ) );
		}
		if ( '' === trim( $label ) ) {
			throw new InvalidArgumentException( 'FieldDefinition: label must not be empty.' );
		}
		if ( ! in_array( $type, self::types(), true ) ) {
			throw new InvalidArgumentException( sprintf( 'FieldDefinition: unknown type "%s".', $type ) );
		}
		if ( '' !== $map_to && ! in_array( $map_to, self::mappable_billing_keys(), true ) ) {
			throw new InvalidArgumentException( sprintf( 'FieldDefinition: cannot map to "%s".', $map_to ) );
		}
		if ( self::TYPE_FILE === $type && ( $show_in_checkout || '' !== $map_to ) ) {
			throw new InvalidArgumentException( 'FieldDefinition: a file field belongs to the registration form only.' );
		}
	}

	/**
	 * Meta key holding the value.
	 */
	public function meta_key(): string {
		return self::META_PREFIX . $this->key;
	}

	/**
	 * Form input name.
	 */
	public function input_name(): string {
		return 'eurob2b_field_' . $this->key;
	}

	/**
	 * Whether the field is shown for a registration type.
	 *
	 * @param string $type_slug Registration type slug.
	 */
	public function applies_to( string $type_slug ): bool {
		return empty( $this->registration_types ) || in_array( $type_slug, $this->registration_types, true );
	}

	/**
	 * Sanitises a raw submitted value to the canonical stored form.
	 *
	 * Checkbox -> "1" or "". Number -> normalised decimal string (invalid input is kept for
	 * validate()). Select -> trimmed
	 * value (validate() rejects unknown options). Everything else -> trimmed
	 * single-line text (textarea keeps lines).
	 *
	 * @param mixed $raw Raw value.
	 */
	public function sanitize( $raw ): string {
		// A file field carries no posted text; RegistrationService checks the upload.
		if ( is_array( $raw ) || self::TYPE_FILE === $this->type ) {
			return '';
		}
		$value = null === $raw ? '' : (string) $raw;

		switch ( $this->type ) {
			case self::TYPE_CHECKBOX:
				return in_array( strtolower( trim( $value ) ), array( '1', 'on', 'yes', 'true' ), true ) ? '1' : '';
			case self::TYPE_NUMBER:
				$value = str_replace( ',', '.', trim( $value ) );
				// An invalid value is kept (tags stripped) so validate() reports "number" instead of dropping it.
				return is_numeric( $value ) ? (string) ( 0 + $value ) : trim( strip_tags( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			case self::TYPE_SELECT:
				// Unknown values are kept (trimmed, tags stripped) so validate() can report them.
				return trim( strip_tags( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			case self::TYPE_TEXTAREA:
				$lines = preg_split( '/\r\n|\r|\n/', $value ) ?: array();
				return trim( implode( "\n", array_map( static fn( string $l ): string => trim( strip_tags( $l ) ), $lines ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			case self::TYPE_EMAIL:
				return strtolower( trim( strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			default:
				return trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		}
	}

	/**
	 * Validates a sanitised value. Returns an error code or null.
	 *
	 * Codes: required, email, number, date, tel, option, length.
	 *
	 * @param string $value Sanitised value.
	 */
	public function validate( string $value ): ?string {
		if ( '' === $value ) {
			return $this->required ? 'required' : null;
		}
		if ( strlen( $value ) > ( self::TYPE_TEXTAREA === $this->type ? 5000 : 500 ) ) {
			return 'length';
		}
		switch ( $this->type ) {
			case self::TYPE_EMAIL:
				return false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ? 'email' : null;
			case self::TYPE_NUMBER:
				return is_numeric( $value ) ? null : 'number';
			case self::TYPE_DATE:
				$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
				return $d && $d->format( 'Y-m-d' ) === $value ? null : 'date';
			case self::TYPE_TEL:
				return preg_match( '/^[0-9+][0-9 \-\/().]{3,30}$/', $value ) ? null : 'tel';
			case self::TYPE_SELECT:
				return isset( $this->options[ $value ] ) ? null : 'option';
		}
		return null;
	}

	/**
	 * Array form for option storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'                => $this->key,
			'label'              => $this->label,
			'type'               => $this->type,
			'required'           => $this->required,
			'registration_types' => $this->registration_types,
			'placeholder'        => $this->placeholder,
			'help'               => $this->help,
			'options'            => $this->options,
			'show_in_checkout'   => $this->show_in_checkout,
			'show_in_account'    => $this->show_in_account,
			'map_to'             => $this->map_to,
			'order'              => $this->order,
		);
	}

	/**
	 * Builds a definition from stored or submitted data.
	 *
	 * @param array<string, mixed> $data Data; "options" may be an array or a "value|Label" per line string.
	 * @throws InvalidArgumentException On invalid data.
	 */
	public static function from_array( array $data ): self {
		$options = $data['options'] ?? array();
		if ( is_string( $options ) ) {
			$options = self::parse_options( $options );
		}
		$types = $data['registration_types'] ?? array();
		$types = is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : array();
		$type  = (string) ( $data['type'] ?? self::TYPE_TEXT );
		// A file field stays in the registration form, whatever else was submitted.
		$file = self::TYPE_FILE === $type;

		return new self(
			(string) ( $data['key'] ?? '' ),
			trim( (string) ( $data['label'] ?? '' ) ),
			$type,
			filter_var( $data['required'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			$types,
			trim( (string) ( $data['placeholder'] ?? '' ) ),
			trim( (string) ( $data['help'] ?? '' ) ),
			is_array( $options ) ? array_map( 'strval', $options ) : array(),
			! $file && filter_var( $data['show_in_checkout'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			! $file && filter_var( $data['show_in_account'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			$file ? '' : (string) ( $data['map_to'] ?? '' ),
			(int) ( $data['order'] ?? 10 )
		);
	}

	/**
	 * Parses "value|Label" lines (or just "Label") into options.
	 *
	 * @param string $text Lines.
	 * @return array<string, string>
	 */
	public static function parse_options( string $text ): array {
		$options = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$value = $parts[0];
			$label = $parts[1] ?? $parts[0];
			if ( '' !== $value ) {
				$options[ $value ] = $label;
			}
		}
		return $options;
	}

	/**
	 * Options as "value|Label" lines for the admin textarea.
	 */
	public function options_as_text(): string {
		$lines = array();
		foreach ( $this->options as $value => $label ) {
			$lines[] = $value === $label ? (string) $value : $value . '|' . $label;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Converts a label into a key candidate.
	 *
	 * @param string $label Label.
	 */
	public static function keyify( string $label ): string {
		$key = strtolower( function_exists( 'remove_accents' ) ? remove_accents( $label ) : $label );
		$key = (string) preg_replace( '/[^a-z0-9]+/', '_', $key );
		$key = trim( $key, '_' );
		if ( '' === $key || ! ctype_alpha( $key[0] ) ) {
			$key = 'f_' . $key;
		}
		return substr( $key, 0, 40 );
	}
}

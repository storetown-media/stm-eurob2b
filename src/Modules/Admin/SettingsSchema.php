<?php
/**
 * Settings schema.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use InvalidArgumentException;

/**
 * Declarative description of every setting: section, type, default, options.
 *
 * Modules add fields through the "eurob2b_settings_schema" filter. The schema
 * is the single source of truth for sanitising: unknown keys are dropped,
 * values are coerced to the declared type, selects fall back to their default
 * when the value is not an allowed option. This class has no WordPress
 * dependency so the sanitiser can be unit-tested.
 */
final class SettingsSchema {

	public const TYPE_BOOLEAN     = 'boolean';
	public const TYPE_TEXT        = 'text';
	public const TYPE_TEXTAREA    = 'textarea';
	public const TYPE_NUMBER      = 'number';
	public const TYPE_SELECT      = 'select';
	public const TYPE_MULTISELECT = 'multiselect';

	/**
	 * Sections keyed by id: label and order.
	 *
	 * @var array<string, array{label: string, order: int}>
	 */
	private array $sections = array();

	/**
	 * Fields keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields = array();

	/**
	 * Text sanitiser (defaults to a framework-free implementation).
	 *
	 * @var callable(string): string
	 */
	private $text_sanitizer;

	/**
	 * Constructor.
	 *
	 * @param callable|null $text_sanitizer Sanitiser for text values; `sanitize_text_field` in WordPress.
	 */
	public function __construct( ?callable $text_sanitizer = null ) {
		$this->text_sanitizer = $text_sanitizer ?? static fn( string $v ): string => trim( strip_tags( $v ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}

	/**
	 * Adds a section.
	 *
	 * @param string $id    Section id.
	 * @param string $label Human readable label.
	 * @param int    $order Sort order.
	 */
	public function add_section( string $id, string $label, int $order = 10 ): self {
		$this->sections[ $id ] = array(
			'label' => $label,
			'order' => $order,
		);
		return $this;
	}

	/**
	 * Adds a field.
	 *
	 * @param array<string, mixed> $field Keys: id, section, label, type, default; optional: description, options (array|callable), min, max, step.
	 * @throws InvalidArgumentException On missing keys or unknown type.
	 */
	public function add_field( array $field ): self {
		foreach ( array( 'id', 'section', 'label', 'type' ) as $key ) {
			if ( empty( $field[ $key ] ) || ! is_string( $field[ $key ] ) ) {
				throw new InvalidArgumentException( sprintf( 'SettingsSchema: field is missing "%s".', $key ) );
			}
		}
		if ( ! in_array( $field['type'], self::types(), true ) ) {
			throw new InvalidArgumentException( sprintf( 'SettingsSchema: unknown type "%s".', $field['type'] ) );
		}
		if ( ! array_key_exists( 'default', $field ) ) {
			$field['default'] = self::TYPE_MULTISELECT === $field['type'] ? array() : ( self::TYPE_BOOLEAN === $field['type'] ? false : '' );
		}
		$this->fields[ $field['id'] ] = $field;
		return $this;
	}

	/**
	 * Known types.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( self::TYPE_BOOLEAN, self::TYPE_TEXT, self::TYPE_TEXTAREA, self::TYPE_NUMBER, self::TYPE_SELECT, self::TYPE_MULTISELECT );
	}

	/**
	 * Sections sorted by order.
	 *
	 * @return array<string, array{label: string, order: int}>
	 */
	public function sections(): array {
		$sections = $this->sections;
		uasort( $sections, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
		return $sections;
	}

	/**
	 * Fields of a section, in registration order.
	 *
	 * @param string $section Section id.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields_in( string $section ): array {
		return array_filter( $this->fields, static fn( array $f ): bool => $f['section'] === $section );
	}

	/**
	 * All fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Whether a field exists.
	 *
	 * @param string $id Field id.
	 */
	public function has( string $id ): bool {
		return isset( $this->fields[ $id ] );
	}

	/**
	 * Default values for every field.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		$defaults = array();
		foreach ( $this->fields as $id => $field ) {
			$defaults[ $id ] = $field['default'];
		}
		return $defaults;
	}

	/**
	 * Resolved options of a select/multiselect field (callables are invoked).
	 *
	 * @param string $id Field id.
	 * @return array<string, string> value => label
	 */
	public function options( string $id ): array {
		$options = $this->fields[ $id ]['options'] ?? array();
		if ( is_callable( $options ) ) {
			$options = $options();
		}
		if ( ! is_array( $options ) ) {
			return array();
		}
		$resolved = array();
		foreach ( $options as $value => $label ) {
			$resolved[ (string) $value ] = (string) $label;
		}
		return $resolved;
	}

	/**
	 * Sanitises raw input against the schema.
	 *
	 * Unknown keys are dropped. Missing keys get their default (so a saved form
	 * with an unchecked checkbox results in false). Checkboxes posted as "1"/"on"
	 * become true. Select values outside the options fall back to the default.
	 *
	 * @param array<string, mixed> $input Raw input (e.g. $_POST subset).
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$clean = array();
		foreach ( $this->fields as $id => $field ) {
			$raw          = $input[ $id ] ?? null;
			$clean[ $id ] = $this->sanitize_value( $field, $raw );
		}
		return $clean;
	}

	/**
	 * Sanitises one value.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $raw   Raw value.
	 * @return mixed
	 */
	private function sanitize_value( array $field, $raw ) {
		if ( isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] ) ) {
			if ( null === $raw || is_array( $raw ) ) {
				return $field['default'];
			}
			return call_user_func( $field['sanitize_callback'], (string) $raw );
		}
		switch ( $field['type'] ) {
			case self::TYPE_BOOLEAN:
				if ( null === $raw ) {
					return false;
				}
				if ( is_bool( $raw ) ) {
					return $raw;
				}
				return in_array( strtolower( trim( (string) $raw ) ), array( '1', 'on', 'yes', 'true' ), true );

			case self::TYPE_TEXT:
				if ( null === $raw || is_array( $raw ) ) {
					return $field['default'];
				}
				return ( $this->text_sanitizer )( (string) $raw );

			case self::TYPE_TEXTAREA:
				if ( null === $raw || is_array( $raw ) ) {
					return $field['default'];
				}
				$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
				$lines = array_map( fn( string $l ): string => ( $this->text_sanitizer )( $l ), $lines ?: array() );
				return implode( "\n", $lines );

			case self::TYPE_NUMBER:
				if ( null === $raw || is_array( $raw ) || '' === $raw || ! is_numeric( $raw ) ) {
					return $field['default'];
				}
				$number = isset( $field['step'] ) && is_float( $field['step'] ) ? (float) $raw : (int) $raw;
				if ( isset( $field['min'] ) && $number < $field['min'] ) {
					$number = $field['min'];
				}
				if ( isset( $field['max'] ) && $number > $field['max'] ) {
					$number = $field['max'];
				}
				return $number;

			case self::TYPE_SELECT:
				$options = $this->options( $field['id'] );
				if ( null === $raw || is_array( $raw ) ) {
					return $field['default'];
				}
				$value = (string) $raw;
				return isset( $options[ $value ] ) ? $value : $field['default'];

			case self::TYPE_MULTISELECT:
				$options = $this->options( $field['id'] );
				if ( ! is_array( $raw ) ) {
					return array();
				}
				$values = array();
				foreach ( $raw as $value ) {
					$value = (string) $value;
					if ( isset( $options[ $value ] ) ) {
						$values[] = $value;
					}
				}
				return array_values( array_unique( $values ) );
		}//end switch
		return $field['default'];
	}
}

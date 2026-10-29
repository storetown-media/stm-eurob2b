<?php
/**
 * Field value storage.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use EuroB2B\Modules\Privacy\PrivateFiles;

/**
 * Reads and writes custom field values for users and mirrors mapped values into billing meta.
 * The value of a file field is the id of the file in the private storage (REG-016, PRV-006); it is
 * shown as the file's name, type, size and upload time.
 */
final class FieldStorage {

	/**
	 * Repository.
	 *
	 * @var FieldRepository
	 */
	private FieldRepository $fields;

	/**
	 * Private files (null where no storage is wired, e.g. in older tests).
	 *
	 * @var PrivateFiles|null
	 */
	private ?PrivateFiles $files;

	/**
	 * Constructor.
	 *
	 * @param FieldRepository   $fields Repository.
	 * @param PrivateFiles|null $files  Private files.
	 */
	public function __construct( FieldRepository $fields, ?PrivateFiles $files = null ) {
		$this->fields = $fields;
		$this->files  = $files;
	}

	/**
	 * The user's file a stored value points to, checked for owner, purpose and field; null otherwise.
	 *
	 * @param int    $user_id   User.
	 * @param string $field_key Field.
	 * @param string $value     Stored value (file id).
	 * @return array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}|null
	 */
	private function owned_file( int $user_id, string $field_key, string $value ): ?array {
		if ( null === $this->files || ! ctype_digit( $value ) ) {
			return null;
		}
		return $this->files->find_owned( (int) $value, $user_id, PrivateFiles::CONTEXT_REGISTRATION, $field_key );
	}

	/**
	 * A stored file as text: "name (size, uploaded …)". A value that is no file of this user and field
	 * (entered before the field became a file field) is shown as it is.
	 *
	 * @param int    $user_id   User.
	 * @param string $field_key Field.
	 * @param string $value     Stored value (file id).
	 */
	public function file_text( int $user_id, string $field_key, string $value ): string {
		$file = $this->owned_file( $user_id, $field_key, $value );
		if ( null === $file ) {
			// Retention and erasure remove the value together with the file, so this is no file reference.
			return $value;
		}
		/* translators: 1: file name, 2: file size, 3: date and time of the upload */
		return sprintf( __( '%1$s (%2$s, uploaded %3$s)', 'eurob2b' ), $file['original_name'], size_format( $file['size'] ), mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $file['created_at'] ) ) );
	}

	/**
	 * The file id of a stored value when it is a file of this user and field, else 0.
	 *
	 * @param int    $user_id   User.
	 * @param string $field_key Field.
	 * @param string $value     Stored value.
	 */
	private function existing_file( int $user_id, string $field_key, string $value ): int {
		$file = $this->owned_file( $user_id, $field_key, $value );
		return null !== $file ? $file['id'] : 0;
	}

	/**
	 * A stored value for display: files as file_text(), checkboxes as "Yes", else the value.
	 *
	 * @param int    $user_id   User.
	 * @param string $field_key Field.
	 * @param string $type      Field type (of the definition or its tombstone).
	 * @param string $value     Stored value.
	 */
	public function display( int $user_id, string $field_key, string $type, string $value ): string {
		if ( FieldDefinition::TYPE_FILE === $type ) {
			return $this->file_text( $user_id, $field_key, $value );
		}
		return FieldDefinition::TYPE_CHECKBOX === $type ? __( 'Yes', 'eurob2b' ) : $value;
	}

	/**
	 * Stored value of a field for a user ("" when unset).
	 *
	 * @param int             $user_id User id.
	 * @param FieldDefinition $field   Field.
	 */
	public function get( int $user_id, FieldDefinition $field ): string {
		$value = get_user_meta( $user_id, $field->meta_key(), true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * All stored values for a user, keyed by field key.
	 *
	 * @param int $user_id User id.
	 * @return array<string, string>
	 */
	public function all( int $user_id ): array {
		$values = array();
		foreach ( $this->fields->all() as $key => $field ) {
			$values[ $key ] = $this->get( $user_id, $field );
		}
		return $values;
	}

	/**
	 * Stores a sanitised value and mirrors it into the mapped billing field.
	 *
	 * @param int             $user_id User id.
	 * @param FieldDefinition $field   Field.
	 * @param string          $value   Sanitised value.
	 */
	public function set( int $user_id, FieldDefinition $field, string $value ): void {
		if ( '' === $value ) {
			delete_user_meta( $user_id, $field->meta_key() );
		} else {
			update_user_meta( $user_id, $field->meta_key(), $value );
		}
		if ( '' !== $field->map_to ) {
			update_user_meta( $user_id, $field->map_to, $value );
		}
	}

	/**
	 * Every stored custom field value of a user, including values of deleted definitions.
	 *
	 * @param int $user_id User id.
	 * @return array<string, string> field key => value
	 */
	public function all_including_orphans( int $user_id ): array {
		$values = array();
		foreach ( get_user_meta( $user_id ) as $meta_key => $meta_values ) {
			if ( ! str_starts_with( (string) $meta_key, FieldDefinition::META_PREFIX ) || ! is_array( $meta_values ) ) {
				continue;
			}
			$value = (string) reset( $meta_values );
			if ( '' !== $value ) {
				$values[ substr( (string) $meta_key, strlen( FieldDefinition::META_PREFIX ) ) ] = $value;
			}
		}
		return $values;
	}

	/**
	 * Every non-empty value of a user for display, keyed by label and in definition order, followed
	 * by values of deleted definitions (marked as such; the former label comes from the tombstone).
	 * Checkbox values are rendered as "Yes". Used by the approval card and the admin e-mails so a
	 * pending application always shows everything that was submitted (REG-005).
	 *
	 * @param int $user_id User id.
	 * @return array<string, string> label => display value
	 */
	public function labelled_values( int $user_id ): array {
		$result = array();
		foreach ( $this->labelled_entries( $user_id ) as $entry ) {
			$result[ $entry['label'] ] = $entry['value'];
		}
		return $result;
	}

	/**
	 * Like labelled_values(), with the id of an uploaded file per row (0 for other fields), so the approval
	 * card can link the file for staff who may download it.
	 *
	 * @param int $user_id User id.
	 * @return array<int, array{label: string, value: string, file_id: int}>
	 */
	public function labelled_entries( int $user_id ): array {
		$result  = array();
		$entries = array();
		$live    = $this->fields->all();
		foreach ( $live as $field ) {
			$value = $this->get( $user_id, $field );
			if ( '' !== $value ) {
				$label            = self::unique_label( $result, $field->label, $field->key );
				$result[ $label ] = $this->display( $user_id, $field->key, $field->type, $value );
				$entries[]        = array(
					'label'   => $label,
					'value'   => $result[ $label ],
					'file_id' => $field->is_file() ? $this->existing_file( $user_id, $field->key, $value ) : 0,
				);
			}
		}
		$tombstones = $this->fields->tombstones();
		foreach ( $this->all_including_orphans( $user_id ) as $key => $value ) {
			if ( isset( $live[ $key ] ) ) {
				continue;
			}
			$tomb  = $tombstones[ $key ] ?? array(
				'label' => '',
				'type'  => FieldDefinition::TYPE_TEXT,
			);
			$label = '' !== $tomb['label'] ? $tomb['label'] : ucfirst( str_replace( '_', ' ', $key ) );
			/* translators: %s: former label of a deleted custom field */
			$label            = sprintf( __( '%s (deleted field)', 'eurob2b' ), $label );
			$label            = self::unique_label( $result, $label, $key );
			$result[ $label ] = $this->display( $user_id, $key, (string) $tomb['type'], $value );
			$entries[]        = array(
				'label'   => $label,
				'value'   => $result[ $label ],
				'file_id' => FieldDefinition::TYPE_FILE === $tomb['type'] ? $this->existing_file( $user_id, $key, $value ) : 0,
			);
		}
		return $entries;
	}

	/**
	 * Type of a stored value's field: of the definition, or of its tombstone when it was deleted.
	 *
	 * @param string $key Field key.
	 */
	public function type_of( string $key ): string {
		$field = $this->fields->find( $key );
		if ( null !== $field ) {
			return $field->type;
		}
		return (string) ( $this->fields->tombstones()[ $key ]['type'] ?? FieldDefinition::TYPE_TEXT );
	}

	/**
	 * Labels are not unique (keys are); a second field with the same label gets its key appended so
	 * no submitted value disappears from cards, e-mails or exports.
	 *
	 * @param array<string, string> $existing Rows so far (label => value).
	 * @param string                $label    Wanted label.
	 * @param string                $key      Field key for disambiguation.
	 */
	public static function unique_label( array $existing, string $label, string $key ): string {
		if ( ! array_key_exists( $label, $existing ) ) {
			return $label;
		}
		$candidate = $label . ' (' . $key . ')';
		$n         = 2;
		while ( array_key_exists( $candidate, $existing ) ) {
			$candidate = $label . ' (' . $key . ' ' . $n . ')';
			++$n;
		}
		return $candidate;
	}

	/**
	 * Deletes every custom field value of a user (erasure), including values whose
	 * definition was deleted, plus the billing fields that are or were mapped and the user's files.
	 *
	 * @param int $user_id User id.
	 * @return int Number of values removed.
	 */
	public function erase( int $user_id ): int {
		$removed = null !== $this->files ? $this->files->delete_owner( $user_id ) : 0;
		foreach ( array_keys( $this->all_including_orphans( $user_id ) ) as $key ) {
			delete_user_meta( $user_id, FieldDefinition::META_PREFIX . $key );
			++$removed;
		}
		foreach ( $this->fields->mapped_billing_fields() as $billing_key ) {
			if ( '' !== (string) get_user_meta( $user_id, $billing_key, true ) ) {
				delete_user_meta( $user_id, $billing_key );
				++$removed;
			}
		}
		// The values WooCommerce keeps on the customer for the fields in the Checkout block (billing and
		// shipping address, also of deleted fields); WooCommerce's own eraser does not know them. The VAT
		// ID's field belongs to the tax provider (D-03).
		$meta = get_user_meta( $user_id );
		foreach ( array_keys( is_array( $meta ) ? $meta : array() ) as $key ) {
			$key = (string) $key;
			if ( ( str_starts_with( $key, CheckoutFields::BLOCK_META_PREFIX ) || str_starts_with( $key, CheckoutFields::BLOCK_META_PREFIX_SHIPPING ) ) && ! str_ends_with( $key, '/vat-id' ) ) {
				delete_user_meta( $user_id, $key );
				++$removed;
			}
		}
		return $removed;
	}
}

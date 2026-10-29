<?php
/**
 * Field definition repository.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use InvalidArgumentException;

/**
 * Stores custom field definitions in one option (configuration).
 *
 * Values entered by customers are stored per user by FieldStorage.
 */
final class FieldRepository {

	public const OPTION         = 'eurob2b_registration_fields';
	public const OPTION_DELETED = 'eurob2b_registration_fields_deleted';

	/**
	 * Option: billing fields that were mapped from a custom field at some point (erasure must still find them).
	 */
	public const OPTION_RETIRED_MAPPINGS = 'eurob2b_registration_fields_retired_mappings';

	/**
	 * Request cache.
	 *
	 * @var array<string, FieldDefinition>|null
	 */
	private ?array $cache = null;

	/**
	 * Fields whose billing mapping was dropped while loading: key => billing key.
	 *
	 * @var array<string, string>
	 */
	private array $dropped = array();

	/**
	 * All fields in order, keyed by key.
	 *
	 * @return array<string, FieldDefinition>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$this->cache = array();
		$stored      = get_option( self::OPTION, array() );
		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$field                      = FieldDefinition::from_array( $row );
				$this->cache[ $field->key ] = $field;
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
		}
		uasort( $this->cache, static fn( FieldDefinition $a, FieldDefinition $b ): int => $a->order <=> $b->order ?: strcmp( $a->key, $b->key ) );
		// Definitions stored before save() refused duplicates can map two fields to one billing field.
		// The first field in display order keeps the mapping (the owner mapping_owners() reports); the
		// others keep their own value but no longer write the billing field, so storage and checkout
		// never overwrite each other. The stored option is cleaned with the next save.
		$owners        = array();
		$this->dropped = array();
		foreach ( $this->cache as $key => $field ) {
			if ( '' === $field->map_to ) {
				continue;
			}
			if ( isset( $owners[ $field->map_to ] ) ) {
				$this->dropped[ $key ] = $field->map_to;
				$this->cache[ $key ]   = FieldDefinition::from_array( array_merge( $field->to_array(), array( 'map_to' => '' ) ) );
				continue;
			}
			$owners[ $field->map_to ] = $key;
		}
		return $this->cache;
	}

	/**
	 * Fields whose billing mapping was dropped because an earlier field fills the same billing field.
	 *
	 * @return array<string, string> Field key => billing key.
	 */
	public function dropped_mappings(): array {
		$this->all();
		return $this->dropped;
	}

	/**
	 * Fields shown for a registration type.
	 *
	 * @param string $type_slug Registration type slug.
	 * @return array<string, FieldDefinition>
	 */
	public function for_registration_type( string $type_slug ): array {
		return array_filter( $this->all(), static fn( FieldDefinition $f ): bool => $f->applies_to( $type_slug ) );
	}

	/**
	 * Fields shown at checkout.
	 *
	 * @return array<string, FieldDefinition>
	 */
	public function for_checkout(): array {
		return array_filter( $this->all(), static fn( FieldDefinition $f ): bool => $f->show_in_checkout && ! $f->is_file() );
	}

	/**
	 * Fields editable in My Account.
	 *
	 * @return array<string, FieldDefinition>
	 */
	public function for_account(): array {
		return array_filter( $this->all(), static fn( FieldDefinition $f ): bool => $f->show_in_account && ! $f->is_file() );
	}

	/**
	 * Finds a field.
	 *
	 * @param string $key Key.
	 */
	public function find( string $key ): ?FieldDefinition {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * The other fields' billing mappings: billing key => field that fills it.
	 *
	 * @param string $except_key Field to leave out (the one being edited).
	 * @return array<string, FieldDefinition>
	 */
	public function mapping_owners( string $except_key = '' ): array {
		$owners = array();
		foreach ( $this->all() as $key => $field ) {
			if ( $key !== $except_key && '' !== $field->map_to && ! isset( $owners[ $field->map_to ] ) ) {
				$owners[ $field->map_to ] = $field;
			}
		}
		return $owners;
	}

	/**
	 * Creates or replaces a field.
	 *
	 * @param FieldDefinition $field Field.
	 * @throws InvalidArgumentException When another field already fills the same billing field.
	 */
	public function save( FieldDefinition $field ): void {
		$owner = '' !== $field->map_to ? ( $this->mapping_owners( $field->key )[ $field->map_to ] ?? null ) : null;
		if ( null !== $owner ) {
			// Two fields writing one billing field would overwrite each other on every save.
			throw new InvalidArgumentException(
				sprintf(
					/* translators: 1: WooCommerce billing field key, e.g. billing_company, 2: label of the field that already fills it */
					__( 'The billing field %1$s is already filled from the field "%2$s". A billing field takes its value from one field only.', 'eurob2b' ),
					$field->map_to,
					$owner->label
				)
			);
		}
		$all      = $this->all();
		$previous = $all[ $field->key ] ?? null;
		if ( null !== $previous && '' !== $previous->map_to && $previous->map_to !== $field->map_to ) {
			// Users keep the mirrored value under the old billing key; erasure must still find it.
			$this->retire_mapping( $previous->map_to );
		}
		if ( null === $previous ) {
			// Re-creating a deleted key: its tombstone would be hidden once the key is live again, so
			// the former mapping is retired explicitly and the tombstone removed.
			$stored = get_option( self::OPTION_DELETED, array() );
			$stored = is_array( $stored ) ? $stored : array();
			if ( isset( $stored[ $field->key ] ) ) {
				$tomb    = $stored[ $field->key ];
				$old_map = is_array( $tomb ) ? (string) ( $tomb['map_to'] ?? '' ) : (string) $tomb;
				if ( '' !== $old_map && $old_map !== $field->map_to ) {
					$this->retire_mapping( $old_map );
				}
				unset( $stored[ $field->key ] );
				update_option( self::OPTION_DELETED, $stored, false );
			}
		}
		$all[ $field->key ] = $field;
		$this->persist( $all );
	}

	/**
	 * Billing fields that were mapped from a custom field earlier (mapping changed or field deleted).
	 *
	 * @return string[]
	 */
	public function retired_mappings(): array {
		$stored = get_option( self::OPTION_RETIRED_MAPPINGS, array() );
		return array_values( array_filter( array_map( 'strval', is_array( $stored ) ? $stored : array() ) ) );
	}

	/**
	 * Remembers a former mapping.
	 *
	 * @param string $map_to Billing field key.
	 */
	private function retire_mapping( string $map_to ): void {
		$list = $this->retired_mappings();
		if ( ! in_array( $map_to, $list, true ) ) {
			$list[] = $map_to;
			update_option( self::OPTION_RETIRED_MAPPINGS, $list, false );
		}
	}

	/**
	 * Deletes a field definition. A tombstone (mapped billing field, label, type) is kept so stored
	 * values can still be shown with their former label, found and erased later.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void {
		$all = $this->all();
		if ( isset( $all[ $key ] ) ) {
			$tombstones         = $this->tombstones();
			$tombstones[ $key ] = array(
				'map_to' => $all[ $key ]->map_to,
				'label'  => $all[ $key ]->label,
				'type'   => $all[ $key ]->type,
			);
			update_option( self::OPTION_DELETED, $tombstones, false );
		}
		unset( $all[ $key ] );
		$this->persist( $all );
	}

	/**
	 * Deleted field definitions: key => mapped billing field (or ""), former label and type.
	 * Older tombstones that stored only the mapped field are read as well.
	 *
	 * @return array<string, array{map_to: string, label: string, type: string}>
	 */
	public function tombstones(): array {
		$stored = get_option( self::OPTION_DELETED, array() );
		$result = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $tomb ) {
			if ( ! is_string( $key ) || isset( $this->all()[ $key ] ) ) {
				continue;
			}
			if ( is_string( $tomb ) ) {
				$tomb = array( 'map_to' => $tomb );
			}
			if ( ! is_array( $tomb ) ) {
				$tomb = array();
			}
			$result[ $key ] = array(
				'map_to' => isset( $tomb['map_to'] ) && is_string( $tomb['map_to'] ) ? $tomb['map_to'] : '',
				'label'  => isset( $tomb['label'] ) && is_string( $tomb['label'] ) ? $tomb['label'] : '',
				'type'   => isset( $tomb['type'] ) && is_string( $tomb['type'] ) ? $tomb['type'] : FieldDefinition::TYPE_TEXT,
			);
		}
		return $result;
	}

	/**
	 * Billing fields that are or were mapped from a custom field.
	 *
	 * @return string[]
	 */
	public function mapped_billing_fields(): array {
		$keys = array();
		foreach ( $this->all() as $field ) {
			if ( '' !== $field->map_to ) {
				$keys[] = $field->map_to;
			}
		}
		foreach ( $this->tombstones() as $tomb ) {
			if ( '' !== $tomb['map_to'] ) {
				$keys[] = $tomb['map_to'];
			}
		}
		foreach ( $this->retired_mappings() as $map_to ) {
			$keys[] = $map_to;
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Writes the option.
	 *
	 * @param array<string, FieldDefinition> $fields Fields.
	 */
	private function persist( array $fields ): void {
		update_option( self::OPTION, array_values( array_map( static fn( FieldDefinition $f ): array => $f->to_array(), $fields ) ), false );
		$this->cache = null;
	}

	/**
	 * Drops the request cache.
	 */
	public function flush(): void {
		$this->cache = null;
	}
}

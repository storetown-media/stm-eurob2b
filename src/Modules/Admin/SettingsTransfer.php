<?php
/**
 * Settings export and import.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use EuroB2B\Infrastructure\Schema;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Registration\FieldDefinition;
use EuroB2B\Modules\Registration\FieldRepository;
use EuroB2B\Modules\Registration\RegistrationType;
use EuroB2B\Modules\Registration\RegistrationTypeRepository;
use RuntimeException;
use Throwable;

/**
 * PLT-012: the shop's EuroB2B configuration as one JSON file, and its import into another shop
 * (or back into the same one). Exported: the settings (without the uninstall opt-in), customer
 * groups, registration types and fields, quote request fields, the payment and shipping method
 * policies, payment terms and the per-method/per-group adjustments when present. Not exported:
 * rules, prices, customers, companies, quotes, orders (they refer to products and people of this
 * shop).
 *
 * The settings, groups, registration types and registration fields are the core's parts; the
 * other parts come from their modules through the filter `eurob2b_settings_transfer_parts`
 * (TransferPart): the payment and shipping parts from the payments module, the quote request fields
 * from EuroB2B Pro (PLT-015).
 *
 * Groups are matched by slug (the default group of each customer type also by its role), missing
 * groups are created, and every reference "group:<id>" or `group_id` is rewritten to the local ids.
 * Before an import writes anything, the current configuration is kept as a backup (one level).
 */
final class SettingsTransfer {

	public const FORMAT  = 'eurob2b-settings';
	public const VERSION = 1;

	/**
	 * The configuration as it was before the last import.
	 */
	public const OPTION_BACKUP = 'eurob2b_settings_backup';

	/**
	 * Largest accepted file.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Keys of the core's own parts (and of the file header); a module's part cannot take them.
	 */
	private const CORE_KEYS = array( 'format', 'version', 'plugin_version', 'exported_at', 'groups', 'settings', 'registration_types', 'registration_fields' );

	/**
	 * Settings that never travel: the uninstall opt-in decides about data deletion on this site only;
	 * the controller details of the record of processing activities are personal data of this site.
	 */
	public const LOCAL_SETTINGS = array( 'remove_data_on_uninstall', ...Schema::RECORD_CONTROLLER_SETTINGS );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Registration types.
	 *
	 * @var RegistrationTypeRepository
	 */
	private RegistrationTypeRepository $types;

	/**
	 * Registration fields.
	 *
	 * @var FieldRepository
	 */
	private FieldRepository $fields;

	/**
	 * Constructor.
	 *
	 * @param Settings                   $settings Settings.
	 * @param GroupRepository            $groups   Groups.
	 * @param RegistrationTypeRepository $types    Registration types.
	 * @param FieldRepository            $fields   Registration fields.
	 */
	public function __construct( Settings $settings, GroupRepository $groups, RegistrationTypeRepository $types, FieldRepository $fields ) {
		$this->settings = $settings;
		$this->groups   = $groups;
		$this->types    = $types;
		$this->fields   = $fields;
	}

	/**
	 * The parts the modules add, by key, sorted by their order (equal orders keep the order they
	 * were added in).
	 *
	 * @return array<string, TransferPart>
	 */
	private function parts(): array {
		/**
		 * Parts of the settings file that belong to modules (PLT-012, PLT-015).
		 *
		 * @param TransferPart[] $parts Parts.
		 */
		$added = apply_filters( 'eurob2b_settings_transfer_parts', array() );
		$parts = array();
		foreach ( is_array( $added ) ? $added : array() as $part ) {
			if ( $part instanceof TransferPart && ! in_array( $part->key, self::CORE_KEYS, true ) && ! isset( $parts[ $part->key ] ) ) {
				$parts[ $part->key ] = $part;
			}
		}
		uasort( $parts, static fn( TransferPart $a, TransferPart $b ): int => $a->order <=> $b->order );
		return $parts;
	}

	/**
	 * The current configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function export(): array {
		$settings = $this->settings->all();
		foreach ( self::LOCAL_SETTINGS as $key ) {
			unset( $settings[ $key ] );
		}
		$data = array(
			'format'              => self::FORMAT,
			'version'             => self::VERSION,
			'plugin_version'      => EUROB2B_VERSION,
			'exported_at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'groups'              => array_values(
				array_map(
					static fn( Group $g ): array => array(
						'id'            => $g->id,
						'slug'          => $g->slug,
						'name'          => $g->name,
						'customer_type' => $g->customer_type->value,
						'is_default'    => $g->is_default,
						'priority'      => $g->priority,
						'settings'      => $g->settings,
					),
					$this->groups->all()
				)
			),
			'settings'            => $settings,
			'registration_types'  => array_values( array_map( static fn( RegistrationType $t ): array => $t->to_array(), $this->types->all() ) ),
			'registration_fields' => array_values( array_map( static fn( FieldDefinition $f ): array => $f->to_array(), $this->fields->all() ) ),
		);
		foreach ( $this->parts() as $key => $part ) {
			$data[ $key ] = $part->export();
		}
		return $data;
	}

	/**
	 * Reads an export file's content.
	 *
	 * @param string $json File content.
	 * @return array<string, mixed>
	 * @throws RuntimeException Not an EuroB2B export (translated message).
	 */
	public static function parse( string $json ): array {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new RuntimeException( __( 'The file is larger than 1 MB.', 'eurob2b' ) );
		}
		$data = json_decode( $json, true, 64 );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) ) {
			throw new RuntimeException( __( 'This is not a EuroB2B settings file.', 'eurob2b' ) );
		}
		if ( (int) ( $data['version'] ?? 0 ) !== self::VERSION ) {
			throw new RuntimeException( __( 'This settings file was written by a EuroB2B version this one cannot read.', 'eurob2b' ) );
		}
		return $data;
	}

	/**
	 * What an import would do, or does.
	 *
	 * @param array<string, mixed> $data   Parsed export.
	 * @param bool                 $apply  Write (true) or only report (false).
	 * @return array{groups_created: string[], groups_matched: int, changed: string[], warnings: string[], errors: string[]}
	 */
	public function import( array $data, bool $apply ): array {
		$report = array(
			'groups_created' => array(),
			'groups_matched' => 0,
			'changed'        => array(),
			'warnings'       => array(),
			'errors'         => array(),
		);
		$groups = is_array( $data['groups'] ?? null ) ? $data['groups'] : array();
		if ( $apply ) {
			// Group conflicts are found before anything is written (no group created, no backup).
			$probe = $report;
			$this->map_groups( $groups, false, $probe );
			if ( ! empty( $probe['errors'] ) ) {
				return self::stop( $probe );
			}
			update_option(
				self::OPTION_BACKUP,
				array(
					'time' => gmdate( 'Y-m-d H:i:s' ),
					'data' => $this->export(),
				),
				false
			);
		}

		$map  = $this->map_groups( $groups, $apply, $report );
		$data = self::remap( $data, $map );
		$this->warn_local_references( $data, $report );
		if ( ! empty( $report['errors'] ) ) {
			return self::stop( $report );
		}

		$current = $this->export();
		$labels  = $this->section_labels();
		foreach ( array_keys( $labels ) as $section ) {
			if ( ! array_key_exists( $section, $data ) || ! is_array( $data[ $section ] ) ) {
				continue;
			}
			$incoming = $data[ $section ];
			if ( 'settings' === $section ) {
				foreach ( self::LOCAL_SETTINGS as $key ) {
					unset( $incoming[ $key ] );
				}
				$incoming = array_intersect_key( $incoming, $this->settings->schema()->defaults() );
				$changed  = array_diff_assoc( array_map( 'serialize', $incoming ), array_map( 'serialize', array_intersect_key( $current['settings'], $incoming ) ) );
				if ( empty( $changed ) ) {
					continue;
				}
			} elseif ( wp_json_encode( $incoming ) === wp_json_encode( $current[ $section ] ?? null ) ) {
				continue;
			}
			$report['changed'][] = $labels[ $section ];
			if ( ! $apply ) {
				continue;
			}
			try {
				$this->apply_section( $section, $incoming, $report );
			} catch ( Throwable $e ) {
				/* translators: 1: part of the settings, 2: error message */
				$report['errors'][] = sprintf( __( '%1$s: %2$s', 'eurob2b' ), $labels[ $section ], $e->getMessage() );
			}
		}//end foreach
		if ( $apply && class_exists( '\WC_Cache_Helper' ) ) {
			// Carts get fresh shipping rates for the new method policies.
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
		return $report;
	}

	/**
	 * Ends an import after group errors: a group that could not be matched or created would leave
	 * its references pointing at whatever group has that number here, so nothing else is written.
	 *
	 * @param array{groups_created: string[], groups_matched: int, changed: string[], warnings: string[], errors: string[]} $report Report.
	 * @return array{groups_created: string[], groups_matched: int, changed: string[], warnings: string[], errors: string[]}
	 */
	private static function stop( array $report ): array {
		$report['errors'][] = __( 'The other parts were not imported because a group could not be matched or created. Fix the group and check the file again.', 'eurob2b' );
		return $report;
	}

	/**
	 * Names of the parts of the configuration, in file order.
	 *
	 * @return array<string, string>
	 */
	public function section_labels(): array {
		$labels = array(
			'settings'            => __( 'Settings', 'eurob2b' ),
			'registration_types'  => __( 'Registration types', 'eurob2b' ),
			'registration_fields' => __( 'Registration fields', 'eurob2b' ),
		);
		foreach ( $this->parts() as $key => $part ) {
			$labels[ $key ] = $part->label;
		}
		return $labels;
	}

	/**
	 * Writes one part.
	 *
	 * @param string               $section  Part.
	 * @param array<string, mixed> $incoming Remapped data.
	 * @param array<string, mixed> $report   Report (errors are added).
	 */
	private function apply_section( string $section, array $incoming, array &$report ): void {
		switch ( $section ) {
			case 'settings':
				$this->settings->patch( $incoming );
				return;
			case 'registration_types':
				$keep = array();
				foreach ( $incoming as $row ) {
					if ( is_array( $row ) ) {
						$type   = RegistrationType::from_array( $row );
						$keep[] = $type->slug;
						$this->types->save( $type );
					}
				}
				foreach ( array_keys( $this->types->all() ) as $slug ) {
					if ( ! in_array( (string) $slug, $keep, true ) ) {
						$this->types->delete( (string) $slug );
					}
				}
				$this->types->flush();
				return;
			case 'registration_fields':
				$keep = array();
				foreach ( $incoming as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					try {
						$field  = FieldDefinition::from_array( $row );
						$keep[] = $field->key;
						$this->fields->save( $field );
					} catch ( Throwable $e ) {
						/* translators: 1: field key, 2: error message */
						$report['errors'][] = sprintf( __( 'Registration field "%1$s": %2$s', 'eurob2b' ), (string) ( $row['key'] ?? '' ), $e->getMessage() );
					}
				}
				foreach ( array_keys( $this->fields->all() ) as $key ) {
					if ( ! in_array( (string) $key, $keep, true ) ) {
						// Through the repository: a deleted field keeps its tombstone for erasure requests.
						$this->fields->delete( (string) $key );
					}
				}
				$this->fields->flush();
				return;
		}//end switch
		$parts = $this->parts();
		if ( isset( $parts[ $section ] ) ) {
			$parts[ $section ]->apply( $incoming, $report );
		}
	}

	/**
	 * Matches the exported groups to local ones (slug, then the default group of the type) and
	 * creates the missing ones when applying.
	 *
	 * In a check, a group that would be created maps to a negative placeholder (-1, -2, …): its
	 * references then differ from anything in this shop, so the parts that use it show as changed
	 * instead of comparing equal to a local group that happens to have the exported number.
	 *
	 * @param array<int, mixed>    $groups Exported groups.
	 * @param bool                 $apply  Create missing groups.
	 * @param array<string, mixed> $report Report.
	 * @return array<int, int> Exported id → local id (a negative placeholder while a group would only be created).
	 */
	private function map_groups( array $groups, bool $apply, array &$report ): array {
		$map     = array();
		$created = array();
		foreach ( $groups as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$type = CustomerType::tryFrom( (string) ( $row['customer_type'] ?? '' ) );
			if ( null === $type || CustomerType::GUEST === $type ) {
				continue;
			}
			$local     = $this->groups->find_by_slug( (string) ( $row['slug'] ?? '' ) );
			$collision = null !== $local && $local->customer_type !== $type;
			if ( $collision ) {
				// Slugs are unique across customer types: a business group must never stand in for a
				// private one (or the reverse).
				$local = null;
			}
			if ( null === $local && ! empty( $row['is_default'] ) ) {
				$local = $this->groups->default_for( $type );
			}
			if ( null === $local && $collision ) {
				/* translators: 1: group name, 2: error message */
				$report['errors'][] = sprintf( __( 'Group "%1$s": %2$s', 'eurob2b' ), (string) ( $row['name'] ?? '' ), sprintf( /* translators: %s: group slug */ __( 'The slug "%s" belongs to a group of the other customer type in this shop.', 'eurob2b' ), (string) ( $row['slug'] ?? '' ) ) );
				continue;
			}
			if ( null !== $local ) {
				$map[ (int) $row['id'] ] = $local->id;
				++$report['groups_matched'];
				continue;
			}
			$report['groups_created'][] = (string) ( $row['name'] ?? $row['slug'] ?? '' );
			if ( ! $apply ) {
				$map[ (int) $row['id'] ] = -count( $report['groups_created'] );
				continue;
			}
			try {
				$group                   = $this->groups->save( new Group( 0, (string) $row['slug'], (string) $row['name'], $type, false, (int) ( $row['priority'] ?? 10 ), array() ) );
				$map[ (int) $row['id'] ] = $group->id;
				$created[ $group->id ]   = is_array( $row['settings'] ?? null ) ? $row['settings'] : array();
			} catch ( Throwable $e ) {
				/* translators: 1: group name, 2: error message */
				$report['errors'][] = sprintf( __( 'Group "%1$s": %2$s', 'eurob2b' ), (string) ( $row['name'] ?? '' ), $e->getMessage() );
			}
		}//end foreach
		// The settings of new groups may point at other groups: written once every id is known.
		foreach ( $created as $id => $settings ) {
			$group = $this->groups->find( $id );
			if ( null !== $group && ! empty( $settings ) ) {
				$this->groups->save( $group->with( array( 'settings' => self::remap( $settings, $map ) ) ) );
			}
		}
		return $map;
	}

	/**
	 * Rewrites group references ("group:<id>" in keys and strings, `group_id` values) to local ids,
	 * or to the negative placeholders of a check. References to groups that are not in the file (or
	 * mapped to 0) stay as they are.
	 *
	 * @param mixed           $value Value.
	 * @param array<int, int> $map   Exported id → local id.
	 * @return mixed
	 */
	public static function remap( $value, array $map ) {
		if ( is_string( $value ) ) {
			return (string) preg_replace_callback(
				'/\bgroup:(\d+)\b/',
				static fn( array $m ): string => isset( $map[ (int) $m[1] ] ) && 0 !== $map[ (int) $m[1] ] ? 'group:' . $map[ (int) $m[1] ] : $m[0],
				$value
			);
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( 'group_id' === $key && is_numeric( $item ) && isset( $map[ (int) $item ] ) && 0 !== $map[ (int) $item ] ) {
				$out[ $key ] = $map[ (int) $item ];
				continue;
			}
			$out[ is_string( $key ) ? self::remap( $key, $map ) : $key ] = self::remap( $item, $map );
		}
		return $out;
	}

	/**
	 * Warns about references that only mean something in the source shop.
	 *
	 * @param array<string, mixed> $data   Remapped data.
	 * @param array<string, mixed> $report Report.
	 */
	private function warn_local_references( array $data, array &$report ): void {
		$json = (string) wp_json_encode( $data );
		if ( 1 === preg_match( '/\b(user|company):\d+\b/', $json ) ) {
			$report['warnings'][] = __( 'The file names individual customers or companies (user:… or company:… in an audience). Their numbers belong to the shop the file comes from; check those audiences after the import.', 'eurob2b' );
		}
		/**
		 * Warnings about values of a settings file that only mean something in the shop the file
		 * comes from (e.g. page numbers), shown before and after an import (PLT-012).
		 *
		 * @param string[]             $warnings Warnings.
		 * @param array<string, mixed> $data     The file's content, group references rewritten.
		 */
		$warnings = apply_filters( 'eurob2b_settings_transfer_warnings', array(), $data );
		foreach ( is_array( $warnings ) ? $warnings : array() as $warning ) {
			if ( is_string( $warning ) && '' !== $warning ) {
				$report['warnings'][] = $warning;
			}
		}
	}
}

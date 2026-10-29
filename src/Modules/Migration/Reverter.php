<?php
/**
 * Undo of an import.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Payments\MethodPolicy;
use EuroB2B\Modules\Registration\FieldDefinition;
use EuroB2B\Modules\Registration\FieldRepository;
use EuroB2B\Modules\Registration\RegistrationType;
use EuroB2B\Modules\Registration\RegistrationTypeRepository;
use EuroB2B\Modules\Rules\RuleRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Puts back what one journal row of an import changed (MIG-001). Rows are reverted newest first,
 * so later changes (a member linked to a company) are undone before earlier ones (the customer's
 * classification, the group). A value is restored exactly as it was stored, or removed when there
 * was none; created groups, rules, registration types, fields and companies are deleted.
 */
final class Reverter {

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
	 * Rules.
	 *
	 * @var RuleRepository
	 */
	private RuleRepository $rules;

	/**
	 * Method policies.
	 *
	 * @var MethodPolicy
	 */
	private MethodPolicy $policy;

	/**
	 * Company accounts (EuroB2B Pro); null without it.
	 *
	 * @var CompanyTarget|null
	 */
	private ?CompanyTarget $companies;

	/**
	 * Audience cache.
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $audiences;

	/**
	 * Constructor.
	 *
	 * @param GroupRepository            $groups    Groups.
	 * @param RegistrationTypeRepository $types     Registration types.
	 * @param FieldRepository            $fields    Registration fields.
	 * @param RuleRepository             $rules     Rules.
	 * @param MethodPolicy               $policy    Method policies.
	 * @param AudienceResolver           $audiences Audience cache.
	 * @param CompanyTarget|null         $companies Company accounts, when EuroB2B Pro is active.
	 */
	public function __construct( GroupRepository $groups, RegistrationTypeRepository $types, FieldRepository $fields, RuleRepository $rules, MethodPolicy $policy, AudienceResolver $audiences, ?CompanyTarget $companies = null ) {
		$this->groups    = $groups;
		$this->types     = $types;
		$this->fields    = $fields;
		$this->rules     = $rules;
		$this->policy    = $policy;
		$this->audiences = $audiences;
		$this->companies = $companies;
	}

	/**
	 * Error text of a company row while EuroB2B Pro is not active: the row stays in the journal.
	 */
	private static function needs_pro(): string {
		return __( 'Company accounts are a feature of EuroB2B Pro; activate it to undo this part of the import.', 'eurob2b' );
	}

	/**
	 * Reverts one row; returns an error text, or '' when it worked (or there was nothing left to do).
	 *
	 * @param string                                                                          $source Source id of the import.
	 * @param array{object_type: string, object_id: int, ref: string, old_value: string|null} $row    Journal row.
	 */
	public function revert( string $source, array $row ): string {
		$id  = $row['object_id'];
		$ref = $row['ref'];
		$old = $row['old_value'];
		try {
			switch ( $row['object_type'] ) {
				case Journal::TYPE_POST_META:
				case Journal::TYPE_USER_META:
				case Journal::TYPE_TERM_META:
					$type = substr( $row['object_type'], 0, -5 );
					if ( null === $old ) {
						delete_metadata( $type, $id, $ref );
					} else {
						update_metadata( $type, $id, $ref, wp_slash( $old ) );
					}
					if ( 'post' === $type && function_exists( 'wc_delete_product_transients' ) ) {
						wc_delete_product_transients( $id );
					}
					if ( 'user' === $type ) {
						$this->audiences->flush( $id );
					}
					break;
				case Journal::TYPE_CUSTOMER:
					$snapshot = json_decode( (string) $old, true );
					foreach ( is_array( $snapshot ) ? $snapshot : array() as $key => $value ) {
						if ( null === $value ) {
							delete_user_meta( $id, (string) $key );
						} else {
							update_user_meta( $id, (string) $key, wp_slash( (string) $value ) );
						}
					}
					$this->audiences->flush( $id );
					break;
				case Journal::TYPE_GROUP:
					$default = $this->groups->default_for( CustomerType::B2B );
					if ( null !== $this->groups->find( $id ) && null !== $default ) {
						$this->groups->delete( $id, $default->id );
					}
					break;
				case Journal::TYPE_REGISTRATION_TYPE:
					if ( null === $old ) {
						$this->types->delete( $ref );
					} else {
						$this->types->save( RegistrationType::from_array( (array) json_decode( $old, true ) ) );
					}
					break;
				case Journal::TYPE_FIELD:
					if ( null === $old ) {
						$this->fields->delete( $ref );
					} else {
						$this->fields->save( FieldDefinition::from_array( (array) json_decode( $old, true ) ) );
					}
					break;
				case Journal::TYPE_RULE:
					if ( null === $old ) {
						$this->rules->delete( $id );
					} elseif ( null !== $this->rules->find( $id ) ) {
						$this->rules->save( $this->rules->hydrate( array( 'id' => $id ) + (array) json_decode( $old, true ) ) );
					}
					break;
				case Journal::TYPE_POLICY:
					if ( null === $old ) {
						delete_option( MethodPolicy::option( $ref ) );
						$this->policy->flush();
					} else {
						$this->policy->save( $ref, (array) json_decode( $old, true ) );
					}
					break;
				case Journal::TYPE_COMPANY:
					if ( null === $this->companies ) {
						return self::needs_pro();
					}
					$this->companies->delete( $id );
					break;
				case Journal::TYPE_MEMBER:
					if ( null === $this->companies ) {
						return self::needs_pro();
					}
					$this->companies->remove_member( (int) $ref, $id );
					break;
				case Journal::TYPE_MAP:
					$this->revert_map( $source, $ref, $old );
					break;
			}//end switch
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			return $e->getMessage();
		}//end try
		return '';
	}

	/**
	 * Puts back an entry of the source-to-EuroB2B map.
	 *
	 * @param string      $source Source id.
	 * @param string      $ref    kind:source key.
	 * @param string|null $old    Previous target.
	 */
	private function revert_map( string $source, string $ref, ?string $old ): void {
		$parts = explode( ':', $ref, 2 );
		if ( 2 !== count( $parts ) ) {
			return;
		}
		$map = get_option( ImportWriter::OPTION_MAP, array() );
		$map = is_array( $map ) ? $map : array();
		if ( null === $old ) {
			unset( $map[ $source ][ $parts[0] ][ $parts[1] ] );
			if ( empty( $map[ $source ][ $parts[0] ] ) ) {
				unset( $map[ $source ][ $parts[0] ] );
			}
			if ( empty( $map[ $source ] ) ) {
				unset( $map[ $source ] );
			}
		} else {
			$map[ $source ][ $parts[0] ][ $parts[1] ] = ctype_digit( $old ) ? (int) $old : $old;
		}
		if ( empty( $map ) ) {
			delete_option( ImportWriter::OPTION_MAP );
			return;
		}
		update_option( ImportWriter::OPTION_MAP, $map, false );
	}
}

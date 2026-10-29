<?php
/**
 * Personal data provider for customer classification and registration data.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

use EuroB2B\Modules\Customers\Admin\GroupsPage;
use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;
use EuroB2B\Modules\Registration\FieldRepository;
use EuroB2B\Modules\Registration\FieldDefinition;
use EuroB2B\Modules\Registration\FieldStorage;
use EuroB2B\Modules\Registration\RegistrationService;
use EuroB2B\Modules\Registration\RegistrationTypeRepository;

/**
 * Exports and erases everything the customers and registration modules store per user
 * (inventory D-01 and D-02 in docs/privacy-and-data-flows.md).
 *
 * Erasure removes classification, approval audit, registration meta and custom
 * field values. Group membership is removed too; the user then falls back to
 * the default group of a private customer.
 */
final class CustomersDataProvider implements PersonalDataProviderInterface {

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
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Registration types (labels for the export).
	 *
	 * @var RegistrationTypeRepository
	 */
	private RegistrationTypeRepository $types;

	/**
	 * Constructor.
	 *
	 * @param FieldRepository            $fields  Fields.
	 * @param FieldStorage               $storage Storage.
	 * @param GroupRepository            $groups  Groups.
	 * @param RegistrationTypeRepository $types   Registration types (labels for the export).
	 */
	public function __construct( FieldRepository $fields, FieldStorage $storage, GroupRepository $groups, RegistrationTypeRepository $types ) {
		$this->fields  = $fields;
		$this->storage = $storage;
		$this->groups  = $groups;
		$this->types   = $types;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'customer';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B customer data', 'eurob2b' );
	}

	/**
	 * Meta keys owned by these modules (besides custom field values).
	 *
	 * @return string[]
	 */
	public static function meta_keys(): array {
		return array(
			CustomersModule::META_CUSTOMER_TYPE,
			CustomersModule::META_STATUS,
			CustomersModule::META_STATUS . '_note',
			CustomersModule::META_GROUP_ID,
			CustomersModule::META_APPROVED_AT,
			CustomersModule::META_APPROVED_BY,
			CustomersModule::META_REJECTED_AT,
			CustomersModule::META_REJECTED_BY,
			CustomersModule::META_GROUP_HOLD,
			RegistrationService::META_REGISTRATION_TYPE,
			RegistrationService::META_REGISTERED_AT,
			RegistrationService::META_TERMS_ACCEPTED_AT,
		);
	}

	/**
	 * Export callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$items  = array();
		$type   = get_user_meta( $user->ID, CustomersModule::META_CUSTOMER_TYPE, true );
		$status = get_user_meta( $user->ID, CustomersModule::META_STATUS, true );
		$group  = $this->groups->find( (int) get_user_meta( $user->ID, CustomersModule::META_GROUP_ID, true ) );
		$type   = is_string( $type ) ? CustomerType::tryFrom( $type ) : null;
		$rows   = array(
			__( 'Customer type', 'eurob2b' )           => null !== $type ? GroupsPage::type_label( $type ) : '',
			__( 'Status', 'eurob2b' )                  => is_string( $status ) && '' !== $status ? CustomerStatus::from_meta( $status )->label() : '',
			__( 'Group', 'eurob2b' )                   => null !== $group ? $group->name : '',
			__( 'Registration type', 'eurob2b' )       => $this->registration_type_label( (string) get_user_meta( $user->ID, RegistrationService::META_REGISTRATION_TYPE, true ) ),
			__( 'Registered at', 'eurob2b' )           => (string) get_user_meta( $user->ID, RegistrationService::META_REGISTERED_AT, true ),
			__( 'Approved at', 'eurob2b' )             => (string) get_user_meta( $user->ID, CustomersModule::META_APPROVED_AT, true ),
			__( 'Approved by (user id)', 'eurob2b' )   => (string) get_user_meta( $user->ID, CustomersModule::META_APPROVED_BY, true ),
			__( 'Status note', 'eurob2b' )             => (string) get_user_meta( $user->ID, CustomersModule::META_STATUS . '_note', true ),
			__( 'Rejected at', 'eurob2b' )             => (string) get_user_meta( $user->ID, CustomersModule::META_REJECTED_AT, true ),
			__( 'Rejected by (user id)', 'eurob2b' )   => (string) get_user_meta( $user->ID, CustomersModule::META_REJECTED_BY, true ),
			__( 'Kept in the group since', 'eurob2b' ) => (string) get_user_meta( $user->ID, CustomersModule::META_GROUP_HOLD, true ),
			__( 'Terms accepted at', 'eurob2b' )       => (string) get_user_meta( $user->ID, RegistrationService::META_TERMS_ACCEPTED_AT, true ),
		);
		foreach ( $this->storage->all_including_orphans( $user->ID ) as $key => $value ) {
			$field = $this->fields->find( $key );
			$type  = $this->storage->type_of( $key );
			// Uploaded files (REG-016) are listed with name, size and upload time, not their id.
			$rows[ FieldStorage::unique_label( $rows, null !== $field ? $field->label : $key, $key ) ] = FieldDefinition::TYPE_FILE === $type ? $this->storage->file_text( $user->ID, $key, $value ) : $value;
		}
		foreach ( $rows as $name => $value ) {
			if ( '' !== $value ) {
				$items[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}

		return array(
			'data' => empty( $items ) ? array() : array(
				array(
					'group_id'    => 'eurob2b-customer',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-customer-' . $user->ID,
					'data'        => $items,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Label of a registration type; the stored slug when the type was deleted since.
	 *
	 * @param string $slug Slug.
	 */
	private function registration_type_label( string $slug ): string {
		$type = '' !== $slug ? $this->types->find( $slug ) : null;
		return null !== $type ? $type->label : $slug;
	}

	/**
	 * Erase callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// A missing status counts as approved; an application that was pending or rejected must stay
		// blocked after the erasure, so the (non-identifying) blocked marker is written back.
		$status  = (string) get_user_meta( $user->ID, CustomersModule::META_STATUS, true );
		$blocked = in_array( $status, array( CustomerStatus::PENDING->value, CustomerStatus::REJECTED->value ), true );

		$removed = $this->storage->erase( $user->ID ) > 0;
		foreach ( self::meta_keys() as $key ) {
			if ( '' !== (string) get_user_meta( $user->ID, $key, true ) ) {
				delete_user_meta( $user->ID, $key );
				$removed = true;
			}
		}

		$messages = array();
		if ( $blocked ) {
			update_user_meta( $user->ID, CustomersModule::META_STATUS, CustomerStatus::REJECTED->value );
			$messages[] = __( 'The account stays blocked for login: the status marker "rejected" was retained. It contains no personal data.', 'eurob2b' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $blocked,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}

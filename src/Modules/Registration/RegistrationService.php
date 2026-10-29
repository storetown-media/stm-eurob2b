<?php
/**
 * Registration service.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use EuroB2B\Modules\Customers\CustomerService;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Privacy\PrivateFiles;
use RuntimeException;
use WP_Error;

/**
 * Validates a registration submission and completes a new customer.
 *
 * The form (WooCommerce My Account, shortcode or block) collects the data;
 * this service decides: type, custom field validation, status (pending or
 * approved), group, stored values and the events the e-mails hook into.
 */
final class RegistrationService {

	public const META_REGISTRATION_TYPE = '_eurob2b_registration_type';
	public const META_TERMS_ACCEPTED_AT = '_eurob2b_terms_accepted_at';
	public const META_REGISTERED_AT     = '_eurob2b_registered_at';

	/**
	 * Types.
	 *
	 * @var RegistrationTypeRepository
	 */
	private RegistrationTypeRepository $types;

	/**
	 * Fields.
	 *
	 * @var FieldRepository
	 */
	private FieldRepository $fields;

	/**
	 * Field storage.
	 *
	 * @var FieldStorage
	 */
	private FieldStorage $storage;

	/**
	 * Customer service.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Private files for uploads (REG-016); null where no storage is wired.
	 *
	 * @var PrivateFiles|null
	 */
	private ?PrivateFiles $files;

	/**
	 * Size limit of uploads in bytes.
	 *
	 * @var int
	 */
	private int $file_max_bytes;

	/**
	 * Constructor.
	 *
	 * @param RegistrationTypeRepository $types       Types.
	 * @param FieldRepository            $fields      Fields.
	 * @param FieldStorage               $storage     Storage.
	 * @param CustomerService            $customers   Customer service.
	 * @param GroupRepository            $groups      Groups.
	 * @param PrivateFiles|null          $files       Private files for uploads.
	 * @param int                        $file_max_mb Size limit of uploads in megabytes.
	 */
	public function __construct( RegistrationTypeRepository $types, FieldRepository $fields, FieldStorage $storage, CustomerService $customers, GroupRepository $groups, ?PrivateFiles $files = null, int $file_max_mb = 5 ) {
		$this->types          = $types;
		$this->fields         = $fields;
		$this->storage        = $storage;
		$this->customers      = $customers;
		$this->groups         = $groups;
		$this->files          = $files;
		$this->file_max_bytes = max( 1, $file_max_mb ) * MB_IN_BYTES;
	}

	/**
	 * Group a registration type approves into: its configured group if it still exists and matches
	 * the customer type, else null so the default group of the customer type is used.
	 *
	 * @param RegistrationType $type Type.
	 */
	public function target_group_id( RegistrationType $type ): ?int {
		if ( null === $type->group_id ) {
			return null;
		}
		$group = $this->groups->find( $type->group_id );
		return null !== $group && $group->customer_type === $type->customer_type ? $group->id : null;
	}

	/**
	 * Resolves the submitted registration type.
	 *
	 * @param array<string, mixed> $post   Submitted data.
	 * @param string|null          $locked Type slug when the form is locked to one type.
	 */
	public function resolve_type( array $post, ?string $locked = null ): ?RegistrationType {
		$slug = null !== $locked ? $locked : sanitize_key( (string) ( $post['eurob2b_registration_type'] ?? '' ) );
		$type = $this->types->find( $slug );
		return null !== $type && $this->types->is_offered( $type ) ? $type : null;
	}

	/**
	 * Validates type choice, custom fields and terms; adds errors to $errors.
	 *
	 * @param array<string, mixed> $post           Submitted data.
	 * @param WP_Error             $errors         Error collector (WooCommerce passes its own).
	 * @param string|null          $locked         Locked type slug or null.
	 * @param bool                 $terms_required Whether the terms checkbox is mandatory.
	 * @param array<string, mixed> $files          Uploaded files ($_FILES) for file fields (REG-016).
	 * @return array{type: RegistrationType|null, values: array<string, string>, terms: bool, uploads: array<string, array{tmp: string, name: string, mime: string, size: int}>}
	 */
	public function validate( array $post, WP_Error $errors, ?string $locked = null, bool $terms_required = false, array $files = array() ): array {
		$type = $this->resolve_type( $post, $locked );
		if ( null === $type ) {
			$errors->add( 'eurob2b_registration_type', __( 'Please choose what kind of customer you are.', 'eurob2b' ) );
			return array(
				'type'    => null,
				'values'  => array(),
				'terms'   => false,
				'uploads' => array(),
			);
		}

		$values  = array();
		$uploads = array();
		foreach ( $this->fields->for_registration_type( $type->slug ) as $field ) {
			if ( $field->is_file() ) {
				$checked = $this->check_upload( $field, $files[ $field->input_name() ] ?? null );
				if ( is_string( $checked ) ) {
					if ( '' !== $checked ) {
						$errors->add( 'eurob2b_field_' . $field->key, FieldRenderer::message( $field, $checked ) );
					}
				} else {
					$uploads[ $field->key ] = $checked;
				}
				continue;
			}
			$value = $field->sanitize( $post[ $field->input_name() ] ?? null );
			$code  = $field->validate( $value );
			if ( null !== $code ) {
				$errors->add( 'eurob2b_field_' . $field->key, FieldRenderer::message( $field, $code ) );
			}
			$values[ $field->key ] = $value;
		}

		// Only the checkbox's own value counts ("no", "0" or any other value is not an acceptance), and
		// only while the checkbox is offered: no acceptance record for terms the customer never saw.
		// REG-012: the checkbox belongs to the business form; consumers are never asked for it.
		$terms_required = $terms_required && CustomerType::B2B === $type->customer_type;
		$terms          = $terms_required && isset( $post['eurob2b_terms'] ) && is_scalar( $post['eurob2b_terms'] ) && '1' === trim( (string) $post['eurob2b_terms'] );
		if ( $terms_required && ! $terms ) {
			$errors->add( 'eurob2b_terms', __( 'Please accept the terms to register.', 'eurob2b' ) );
		}

		return array(
			'type'    => $type,
			'values'  => $values,
			'terms'   => $terms,
			'uploads' => $uploads,
		);
	}

	/**
	 * Checks the upload of a file field: the checked file, '' when an optional field was left empty,
	 * or an error code for FieldRenderer::message().
	 *
	 * @param FieldDefinition $field File field.
	 * @param mixed           $file  Entry of $_FILES.
	 * @return array{tmp: string, name: string, mime: string, size: int}|string
	 */
	private function check_upload( FieldDefinition $field, $file ) {
		$checked = PrivateFiles::check_upload( $file, $this->file_max_bytes );
		if ( 'missing' === $checked ) {
			return $field->required ? 'required' : '';
		}
		if ( is_string( $checked ) ) {
			return 'file_' . $checked;
		}
		return $checked;
	}

	/**
	 * Completes a freshly created WordPress user as an EuroB2B customer.
	 *
	 * @param int                                                                      $user_id        New user id.
	 * @param RegistrationType                                                         $type           Chosen type.
	 * @param array<string, string>                                                    $values         Sanitised custom field values keyed by field key.
	 * @param bool                                                                     $terms_accepted Whether the terms checkbox was ticked.
	 * @param array<string, array{tmp: string, name: string, mime: string, size: int}> $uploads        Checked uploads of file fields keyed by field key.
	 */
	public function complete( int $user_id, RegistrationType $type, array $values, bool $terms_accepted = false, array $uploads = array() ): void {
		$status = $type->requires_approval() ? CustomerStatus::PENDING : CustomerStatus::APPROVED;

		// Status and group first, so the account can never end up without a status (fail closed):
		// a missing status would count as approved. A deleted target group falls back to the default.
		$this->customers->assign( $user_id, $type->customer_type, $status, $this->target_group_id( $type ), 0 );

		update_user_meta( $user_id, self::META_REGISTRATION_TYPE, $type->slug );
		update_user_meta( $user_id, self::META_REGISTERED_AT, current_time( 'mysql', true ) );
		if ( $terms_accepted ) {
			update_user_meta( $user_id, self::META_TERMS_ACCEPTED_AT, current_time( 'mysql', true ) );
		}

		foreach ( $this->fields->for_registration_type( $type->slug ) as $field ) {
			if ( array_key_exists( $field->key, $values ) ) {
				$this->storage->set( $user_id, $field, $values[ $field->key ] );
			}
			if ( $field->is_file() && isset( $uploads[ $field->key ] ) && null !== $this->files ) {
				try {
					$this->storage->set( $user_id, $field, (string) $this->files->store( $uploads[ $field->key ], $user_id, PrivateFiles::CONTEXT_REGISTRATION, $field->key ) );
				} catch ( RuntimeException $e ) {
					// The account exists already; the shop sees the missing document on the approval card.
					if ( function_exists( 'wc_get_logger' ) ) {
						wc_get_logger()->error( 'Registration document of user ' . $user_id . ' not stored: ' . $e->getMessage(), array( 'source' => 'eurob2b' ) );
					}
				}
			}
		}

		/**
		 * Fires after a customer registered through EuroB2B.
		 *
		 * @param int                   $user_id User id.
		 * @param RegistrationType      $type    Registration type.
		 * @param CustomerStatus        $status  Resulting status.
		 * @param array<string, string> $values  Custom field values.
		 */
		do_action( 'eurob2b_customer_registered', $user_id, $type, $status, $values );
	}

	/**
	 * Registration type slug stored for a user, or null.
	 *
	 * @param int $user_id User id.
	 */
	public function registration_type_of( int $user_id ): ?RegistrationType {
		$slug = get_user_meta( $user_id, self::META_REGISTRATION_TYPE, true );
		return is_string( $slug ) && '' !== $slug ? $this->types->find( $slug ) : null;
	}

	/**
	 * Approves a pending user (into the type's group unless another group is given).
	 *
	 * @param int      $user_id  User id.
	 * @param int|null $group_id Group id or null for the registration type's group / default.
	 * @param int      $actor_id Actor.
	 */
	public function approve( int $user_id, ?int $group_id, int $actor_id ): void {
		$this->assert_pending( $user_id );
		if ( null === $group_id ) {
			// No group chosen: the registration type's group, else the default group of the type's customer
			// type (or of the stored type when the registration type is gone). Never implicitly B2B.
			$type     = $this->registration_type_of( $user_id );
			$group_id = null !== $type ? $this->target_group_id( $type ) : null;
			if ( null === $group_id ) {
				$customer_type = null !== $type ? $type->customer_type : $this->customers->type_of( $user_id );
				$default       = $this->groups->default_for( $customer_type );
				$group_id      = null !== $default ? $default->id : null;
			}
		}
		$this->customers->approve( $user_id, $group_id, $actor_id );

		/**
		 * Fires after a registration was approved.
		 *
		 * @param int $user_id  User id.
		 * @param int $actor_id Actor.
		 */
		do_action( 'eurob2b_registration_approved', $user_id, $actor_id );
	}

	/**
	 * Rejects a pending user with an optional note (stored for the audit trail).
	 *
	 * @param int    $user_id  User id.
	 * @param string $note     Reason shown to staff (and to the customer in the e-mail when enabled).
	 * @param int    $actor_id Actor.
	 */
	public function reject( int $user_id, string $note, int $actor_id ): void {
		$this->assert_pending( $user_id );
		$this->customers->reject( $user_id, $actor_id );
		update_user_meta( $user_id, CustomersModule::META_STATUS . '_note', sanitize_textarea_field( $note ) );

		/**
		 * Fires after a registration was rejected.
		 *
		 * @param int    $user_id  User id.
		 * @param string $note     Note.
		 * @param int    $actor_id Actor.
		 */
		do_action( 'eurob2b_registration_rejected', $user_id, $note, $actor_id );
	}

	/**
	 * The approval workflow only decides on pending applications; everything else is handled on
	 * the user profile. Guards against stale forms reversing a colleague's decision.
	 *
	 * @param int $user_id User id.
	 * @throws RuntimeException When the user is not pending.
	 */
	private function assert_pending( int $user_id ): void {
		if ( CustomerStatus::PENDING !== $this->customers->status_of( $user_id ) ) {
			throw new RuntimeException( esc_html__( 'This application was already decided. Please reload the list.', 'eurob2b' ) );
		}
	}
}

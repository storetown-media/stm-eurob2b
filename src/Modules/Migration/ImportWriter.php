<?php
/**
 * Writes imported data into EuroB2B's model.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Customers\CustomerService;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Payments\MethodPolicy;
use EuroB2B\Modules\Registration\FieldDefinition;
use EuroB2B\Modules\Registration\FieldRepository;
use EuroB2B\Modules\Registration\RegistrationType;
use EuroB2B\Modules\Registration\RegistrationTypeRepository;
use EuroB2B\Modules\Rules\Admin\RulesPage;
use EuroB2B\Modules\Rules\RuleRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * The one place an import writes through (MIG-001). Sources call it with values already mapped
 * to EuroB2B's model. In a test run it only compares with what is stored and counts new, changed,
 * unchanged and skipped values; in an import it writes through EuroB2B's own repositories and
 * services (same validation as the admin screens) and journals the previous value of everything
 * it changes, so Reverter can undo the import. The journal row is written first; when it cannot
 * be written, the writing methods throw JournalException (an object created just before is taken
 * back), and the run stops.
 *
 * It never deletes a value: an empty source value leaves the EuroB2B value as it is. Objects it
 * creates (groups, rules, registration types and fields) are remembered per source in the option
 * `eurob2b_migration_map`, so a repeated import updates them instead of creating them twice.
 */
final class ImportWriter {

	public const OPTION_MAP = 'eurob2b_migration_map';

	/**
	 * Group id that stands for a group a test run would create, while a rule is validated.
	 */
	private const PLACEHOLDER_GROUP = 999999000;

	/**
	 * Journal.
	 *
	 * @var Journal
	 */
	private Journal $journal;

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
	 * The rules screen: its form validation builds every imported rule.
	 *
	 * @var RulesPage
	 */
	private RulesPage $rules_page;

	/**
	 * Payment and shipping method policies.
	 *
	 * @var MethodPolicy
	 */
	private MethodPolicy $policy;

	/**
	 * Customer classification.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * Company accounts (EuroB2B Pro); null without it.
	 *
	 * @var CompanyTarget|null
	 */
	private ?CompanyTarget $companies;

	/**
	 * Current run.
	 *
	 * @var Run|null
	 */
	private ?Run $run = null;

	/**
	 * Products and variations whose meta changed in this batch (caches to clear).
	 *
	 * @var array<int, bool>
	 */
	private array $touched = array();

	/**
	 * Constructor.
	 *
	 * @param Journal                    $journal    Journal.
	 * @param GroupRepository            $groups     Groups.
	 * @param RegistrationTypeRepository $types      Registration types.
	 * @param FieldRepository            $fields     Registration fields.
	 * @param RuleRepository             $rules      Rules.
	 * @param RulesPage                  $rules_page Rules screen (validation).
	 * @param MethodPolicy               $policy     Method policies.
	 * @param CustomerService            $customers  Customers.
	 * @param CompanyTarget|null         $companies  Company accounts, when EuroB2B Pro is active.
	 */
	public function __construct( Journal $journal, GroupRepository $groups, RegistrationTypeRepository $types, FieldRepository $fields, RuleRepository $rules, RulesPage $rules_page, MethodPolicy $policy, CustomerService $customers, ?CompanyTarget $companies = null ) {
		$this->journal    = $journal;
		$this->groups     = $groups;
		$this->types      = $types;
		$this->fields     = $fields;
		$this->rules      = $rules;
		$this->rules_page = $rules_page;
		$this->policy     = $policy;
		$this->customers  = $customers;
		$this->companies  = $companies;
	}

	/**
	 * Starts working for a run.
	 *
	 * @param Run $run Run.
	 */
	public function begin( Run $run ): void {
		$this->run     = $run;
		$this->touched = array();
	}

	/**
	 * The current run.
	 *
	 * @throws RuntimeException Without a run.
	 */
	public function run(): Run {
		if ( null === $this->run ) {
			throw new RuntimeException( 'ImportWriter: no run.' );
		}
		return $this->run;
	}

	/**
	 * Whether this is a test run (nothing is written).
	 */
	public function is_check(): bool {
		return Run::MODE_CHECK === $this->run()->mode;
	}

	/**
	 * Products and variations whose meta was written since begin().
	 *
	 * @return int[]
	 */
	public function touched_posts(): array {
		return array_keys( $this->touched );
	}

	/**
	 * A target resolved earlier: in this run, else by an earlier import of the same source.
	 *
	 * @param string $kind Kind (group, registration_type, field, rule).
	 * @param string $key  Source key.
	 * @return int|string|null
	 */
	public function target( string $kind, string $key ) {
		return $this->run()->target( $kind, $key ) ?? $this->persistent( $kind, $key );
	}

	/**
	 * Adds a message to the report.
	 *
	 * @param string $level Run::LEVEL_ constant.
	 * @param string $text  Text.
	 */
	public function note( string $level, string $text ): void {
		$this->run()->message( $level, $text );
	}

	/**
	 * Counts a source item that is not imported, with the reason.
	 *
	 * @param string $text Reason.
	 */
	public function skip( string $text ): void {
		$this->run()->count( 'skipped' );
		$this->run()->message( Run::LEVEL_WARNING, $text );
	}

	/**
	 * Matches or creates a business customer group; returns its id (negative in a test run for a
	 * group that would be created, 0 when it cannot be created).
	 *
	 * @param string $key      Source key.
	 * @param string $name     Name.
	 * @param string $slug     Preferred slug.
	 * @param string $fallback Slug when the preferred one belongs to a group of the other customer type.
	 * @throws JournalException When the journal cannot be written (the new group is deleted again).
	 */
	public function group( string $key, string $name, string $slug, string $fallback ): int {
		$run   = $this->run();
		$known = $run->target( 'group', $key );
		if ( null !== $known ) {
			return (int) $known;
		}
		$mapped = (int) $this->persistent( 'group', $key );
		$group  = $mapped > 0 ? $this->groups->find( $mapped ) : null;
		if ( null === $group ) {
			foreach ( array( $slug, $fallback ) as $candidate ) {
				$existing = $this->groups->find_by_slug( $candidate );
				if ( null === $existing ) {
					$slug = $candidate;
					break;
				}
				if ( CustomerType::B2B === $existing->customer_type ) {
					$group = $existing;
					break;
				}
				$slug = '';
			}
		}
		if ( null !== $group ) {
			$run->count( 'unchanged' );
			$run->remember( 'group', $key, $group->id );
			$this->persist( 'group', $key, $group->id );
			return $group->id;
		}
		if ( '' === $slug ) {
			/* translators: 1: group name, 2: slug */
			$this->skip( sprintf( __( 'Group "%1$s": the slug %2$s belongs to a private customer group here; rename one of them and run the import again.', 'eurob2b' ), $name, $fallback ) );
			$run->remember( 'group', $key, 0 );
			return 0;
		}
		$run->count( 'new' );
		if ( $this->is_check() ) {
			$virtual = -1 - count( array_filter( $run->map['group'] ?? array(), static fn( $id ): bool => (int) $id < 0 ) );
			$run->remember( 'group', $key, $virtual );
			return $virtual;
		}
		try {
			$group = $this->groups->save( new Group( 0, $slug, $name, CustomerType::B2B ) );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			$run->count( 'new', -1 );
			/* translators: 1: group name, 2: error message */
			$this->skip( sprintf( __( 'Group "%1$s" could not be created: %2$s', 'eurob2b' ), $name, $e->getMessage() ) );
			$run->remember( 'group', $key, 0 );
			return 0;
		}
		try {
			$this->journal( Journal::TYPE_GROUP, $group->id, $group->slug, null );
		} catch ( JournalException $e ) {
			// Without its journal row the group could not be undone: take it back before stopping.
			$default = $this->groups->default_for( CustomerType::B2B );
			if ( null !== $default ) {
				$this->groups->delete( $group->id, $default->id );
			}
			throw $e;
		}
		$run->remember( 'group', $key, $group->id );
		$this->persist( 'group', $key, $group->id );
		return $group->id;
	}

	/**
	 * Creates or updates a registration type; returns its slug ('' when refused).
	 *
	 * @param string           $key  Source key.
	 * @param RegistrationType $type Type.
	 */
	public function registration_type( string $key, RegistrationType $type ): string {
		$run     = $this->run();
		$current = $this->types->find( $type->slug );
		$run->remember( 'registration_type', $key, $type->slug );
		if ( null !== $current && $current->to_array() === $type->to_array() ) {
			$run->count( 'unchanged' );
			$this->persist( 'registration_type', $key, $type->slug );
			return $type->slug;
		}
		$run->count( null === $current ? 'new' : 'changed' );
		if ( $this->is_check() ) {
			return $type->slug;
		}
		$this->journal( Journal::TYPE_REGISTRATION_TYPE, 0, $type->slug, null !== $current ? (string) wp_json_encode( $current->to_array() ) : null );
		try {
			$this->types->save( $type );
		} catch ( RuntimeException $e ) {
			$run->count( null === $current ? 'new' : 'changed', -1 );
			/* translators: 1: registration type, 2: error message */
			$this->skip( sprintf( __( 'Registration type "%1$s" was not imported: %2$s', 'eurob2b' ), $type->label, $e->getMessage() ) );
			$run->remember( 'registration_type', $key, '' );
			return '';
		}
		$this->persist( 'registration_type', $key, $type->slug );
		return $type->slug;
	}

	/**
	 * Creates or updates a registration field; returns its key ('' when refused). A billing field
	 * that another field fills already is not mapped twice: the field is imported without it.
	 *
	 * @param string          $key   Source key.
	 * @param FieldDefinition $field Field.
	 */
	public function field( string $key, FieldDefinition $field ): string {
		$run     = $this->run();
		$current = $this->fields->find( $field->key );
		if ( '' !== $field->map_to && isset( $this->fields->mapping_owners( $field->key )[ $field->map_to ] ) ) {
			/* translators: 1: field label, 2: WooCommerce billing field key */
			$this->note( Run::LEVEL_WARNING, sprintf( __( 'Field "%1$s" is imported without filling %2$s: another field fills it already.', 'eurob2b' ), $field->label, $field->map_to ) );
			$data           = $field->to_array();
			$data['map_to'] = '';
			$field          = FieldDefinition::from_array( $data );
		}
		$run->remember( 'field', $key, $field->key );
		if ( null !== $current && $current->to_array() === $field->to_array() ) {
			$run->count( 'unchanged' );
			$this->persist( 'field', $key, $field->key );
			return $field->key;
		}
		$run->count( null === $current ? 'new' : 'changed' );
		if ( $this->is_check() ) {
			return $field->key;
		}
		$this->journal( Journal::TYPE_FIELD, 0, $field->key, null !== $current ? (string) wp_json_encode( $current->to_array() ) : null );
		try {
			$this->fields->save( $field );
		} catch ( InvalidArgumentException $e ) {
			$run->count( null === $current ? 'new' : 'changed', -1 );
			/* translators: 1: field label, 2: error message */
			$this->skip( sprintf( __( 'Field "%1$s" was not imported: %2$s', 'eurob2b' ), $field->label, $e->getMessage() ) );
			$run->remember( 'field', $key, '' );
			return '';
		}
		$this->persist( 'field', $key, $field->key );
		return $field->key;
	}

	/**
	 * Creates or updates a rule from values in the shape of the rules screen's form, validated by
	 * that form; returns the rule id (0 when refused or in a test run for a new rule).
	 *
	 * @param string               $key  Source key.
	 * @param array<string, mixed> $form Form values (name, type, enabled, priority, audience_tokens, audience_groups, audience_users, scope_kind, scope_products, scope_categories, scope_tags, cond, condition_match, action_*).
	 * @throws JournalException When the journal cannot be written (a new rule is deleted again).
	 */
	public function rule( string $key, array $form ): int {
		$run      = $this->run();
		$mapped   = (int) $this->persistent( 'rule', $key );
		$existing = $mapped > 0 ? $this->rules->find( $mapped ) : null;
		if ( $this->is_check() && isset( $form['audience_groups'] ) && is_array( $form['audience_groups'] ) ) {
			// Groups the import would create have no id yet; any id passes the form's validation.
			$form['audience_groups'] = array_map( static fn( $id ): int => (int) $id < 0 ? self::PLACEHOLDER_GROUP - (int) $id : (int) $id, $form['audience_groups'] );
		}
		try {
			$rule = $this->rules_page->rule_from_request( null !== $existing ? $existing->id : 0, $form );
		} catch ( RuntimeException $e ) {
			/* translators: 1: rule name, 2: reason */
			$this->skip( sprintf( __( 'Rule "%1$s" was not imported: %2$s', 'eurob2b' ), (string) ( $form['name'] ?? $key ), $e->getMessage() ) );
			return 0;
		}
		if ( null !== $existing ) {
			if ( RuleRepository::columns( $existing ) === RuleRepository::columns( $rule ) ) {
				$run->count( 'unchanged' );
				return $existing->id;
			}
			$run->count( 'changed' );
			if ( ! $this->is_check() ) {
				$this->journal( Journal::TYPE_RULE, $existing->id, $key, (string) wp_json_encode( RuleRepository::columns( $existing ) ) );
				$this->rules->save( $rule );
			}
			return $existing->id;
		}
		$run->count( 'new' );
		if ( $this->is_check() ) {
			return 0;
		}
		$saved = $this->rules->save( $rule );
		try {
			$this->journal( Journal::TYPE_RULE, $saved->id, $key, null );
		} catch ( JournalException $e ) {
			$this->rules->delete( $saved->id );
			throw $e;
		}
		$this->persist( 'rule', $key, $saved->id );
		return $saved->id;
	}

	/**
	 * Sets the allowed payment or shipping methods of audiences (keys group:<id>, b2c, guest); the
	 * audiences that are not named keep their lists.
	 *
	 * @param string                  $kind      MethodPolicy::KIND_PAYMENT or KIND_SHIPPING.
	 * @param array<string, string[]> $audiences Audience key => allowed method ids.
	 * @param string[]                $known     Method ids that existed when the lists were made.
	 */
	public function policy( string $kind, array $audiences, array $known ): void {
		$run     = $this->run();
		$current = $this->policy->load( $kind );
		$next    = $current;
		foreach ( $audiences as $audience => $methods ) {
			$next['audiences'][ $audience ] = array_values( $methods );
		}
		$next['known'] = array_values( array_unique( array_merge( $current['known'], $known ) ) );
		$virtual       = array_filter( array_keys( $audiences ), static fn( string $a ): bool => str_starts_with( $a, 'group:-' ) );
		$next          = MethodPolicy::normalise( $next );
		if ( empty( $virtual ) && $next === $current ) {
			$run->count( 'unchanged' );
			return;
		}
		$run->count( empty( $current['audiences'] ) ? 'new' : 'changed' );
		if ( $this->is_check() ) {
			return;
		}
		$stored = false !== get_option( MethodPolicy::option( $kind ), false );
		$this->journal( Journal::TYPE_POLICY, 0, $kind, $stored ? (string) wp_json_encode( $current ) : null );
		$this->policy->save( $kind, $next );
	}

	/**
	 * Sets a customer's type, approval status and group through the customer service (validation,
	 * caches, audit log with the person who started the import).
	 *
	 * @param int            $user_id  User id.
	 * @param CustomerType   $type     Customer type.
	 * @param CustomerStatus $status   Approval status.
	 * @param int|null       $group_id Group id; null for the type's default group.
	 */
	public function customer( int $user_id, CustomerType $type, CustomerStatus $status, ?int $group_id ): void {
		$run      = $this->run();
		$snapshot = self::classification( $user_id );
		$group    = null !== $group_id && $group_id > 0 ? $this->groups->find( $group_id ) : null;
		$target   = null !== $group_id && $group_id < 0 ? null : ( null !== $group ? $group : $this->groups->default_for( $type ) );
		if ( $snapshot[ CustomersModule::META_CUSTOMER_TYPE ] === $type->value && $snapshot[ CustomersModule::META_STATUS ] === $status->value && null !== $target && (string) $target->id === $snapshot[ CustomersModule::META_GROUP_ID ] ) {
			$run->count( 'unchanged' );
			return;
		}
		$is_new = null === $snapshot[ CustomersModule::META_CUSTOMER_TYPE ];
		if ( $this->is_check() ) {
			$run->count( $is_new ? 'new' : 'changed' );
			return;
		}
		$this->journal( Journal::TYPE_CUSTOMER, $user_id, '', (string) wp_json_encode( $snapshot ) );
		try {
			$this->customers->assign( $user_id, $type, $status, null !== $group ? $group->id : null, $run->user_id );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			/* translators: 1: user id, 2: error message */
			$this->skip( sprintf( __( 'Customer #%1$d was not classified: %2$s', 'eurob2b' ), $user_id, $e->getMessage() ) );
			return;
		}
		$run->count( $is_new ? 'new' : 'changed' );
	}

	/**
	 * User meta keys a customer classification writes (journaled together).
	 *
	 * @return string[]
	 */
	public static function customer_keys(): array {
		return array( CustomersModule::META_CUSTOMER_TYPE, CustomersModule::META_STATUS, CustomersModule::META_GROUP_ID, CustomersModule::META_APPROVED_AT, CustomersModule::META_APPROVED_BY );
	}

	/**
	 * The stored classification of a user (null for a key that does not exist).
	 *
	 * @param int $user_id User id.
	 * @return array<string, string|null>
	 */
	private static function classification( int $user_id ): array {
		$snapshot = array();
		foreach ( self::customer_keys() as $meta_key ) {
			$snapshot[ $meta_key ] = metadata_exists( 'user', $user_id, $meta_key ) ? (string) get_user_meta( $user_id, $meta_key, true ) : null;
		}
		return $snapshot;
	}

	/**
	 * Journals a user's classification before a company link may change it, so the undo puts
	 * back exactly what was stored (the company's own undo restores only type, status and group).
	 *
	 * @param int $user_id User id.
	 */
	private function journal_classification( int $user_id ): void {
		$this->journal( Journal::TYPE_CUSTOMER, $user_id, '', (string) wp_json_encode( self::classification( $user_id ) ) );
	}

	/**
	 * Writes a journal row before the change it allows to undo.
	 *
	 * @param string      $object_type Journal::TYPE_ constant.
	 * @param int         $object_id   Object id.
	 * @param string      $ref         Meta key, slug or key.
	 * @param string|null $old_value   Previous value, null when there was none.
	 * @throws JournalException When the row could not be written: the run stops before changing anything it could not undo.
	 */
	private function journal( string $object_type, int $object_id, string $ref, ?string $old_value ): void {
		if ( ! $this->journal->record( $this->run()->id, $object_type, $object_id, $ref, $old_value ) ) {
			throw new JournalException( __( 'The import journal could not be written, so the import stopped before changing anything it could not undo. Undo or finish what was imported so far.', 'eurob2b' ) );
		}
	}

	/**
	 * Sets one meta value of a product or variation, a user or a term (EuroB2B keys only).
	 *
	 * @param string $object_type post, user or term.
	 * @param int    $object_id   Object id.
	 * @param string $key         Meta key.
	 * @param string $value       Value (never empty: an empty source value is not imported).
	 */
	public function meta( string $object_type, int $object_id, string $key, string $value ): void {
		$run = $this->run();
		if ( '' === $value || $object_id <= 0 || ! str_starts_with( $key, '_eurob2b_' ) ) {
			return;
		}
		$exists  = metadata_exists( $object_type, $object_id, $key );
		$current = $exists ? get_metadata( $object_type, $object_id, $key, true ) : null;
		if ( $exists && is_scalar( $current ) && (string) $current === $value ) {
			$run->count( 'unchanged' );
			return;
		}
		$run->count( $exists ? 'changed' : 'new' );
		if ( $this->is_check() ) {
			return;
		}
		$types = array(
			'post' => Journal::TYPE_POST_META,
			'user' => Journal::TYPE_USER_META,
			'term' => Journal::TYPE_TERM_META,
		);
		$this->journal( $types[ $object_type ] ?? $object_type, $object_id, $key, $exists && is_scalar( $current ) ? (string) $current : null );
		// Meta functions unslash; free text and JSON keep their backslashes only when slashed first.
		update_metadata( $object_type, $object_id, $key, wp_slash( $value ) );
		if ( 'post' === $object_type ) {
			$this->touched[ $object_id ] = true;
		}
	}

	/**
	 * Creates the company of an account and its sub-accounts, or adds missing members to the
	 * company the account owns already. Members get the template "buyer".
	 *
	 * @param string   $key      Source key.
	 * @param string   $name     Company name.
	 * @param int      $owner    Owner (the main account).
	 * @param int|null $group_id Business group of the company.
	 * @param int[]    $members  Users to link.
	 * @throws JournalException When the journal cannot be written (a new company is deleted again).
	 */
	public function company( string $key, string $name, int $owner, ?int $group_id, array $members ): void {
		$run = $this->run();
		if ( null === $this->companies ) {
			/* translators: %d: user id */
			$this->skip( sprintf( __( 'Account #%d has sub-accounts; company accounts are a feature of EuroB2B Pro, so they were not linked.', 'eurob2b' ), $owner ) );
			return;
		}
		$company = $this->companies->company_of( $owner );
		if ( null === $company && $this->companies->membership_company( $owner ) > 0 ) {
			/* translators: %d: user id */
			$this->skip( sprintf( __( 'Account #%d is a member of a company already; its sub-accounts were not linked.', 'eurob2b' ), $owner ) );
			return;
		}
		if ( null !== $company && $company['owner'] !== $owner ) {
			/* translators: %d: user id */
			$this->skip( sprintf( __( 'Account #%d belongs to a company it does not own; its sub-accounts were not linked.', 'eurob2b' ), $owner ) );
			return;
		}
		$add = array();
		foreach ( $members as $member ) {
			$membership = $this->companies->membership_company( $member );
			if ( 0 === $membership ) {
				$add[] = $member;
			} elseif ( null === $company || $membership !== $company['id'] ) {
				/* translators: 1: user id, 2: user id */
				$this->note( Run::LEVEL_WARNING, sprintf( __( 'Sub-account #%1$d of account #%2$d belongs to another company and was not linked.', 'eurob2b' ), $member, $owner ) );
			}
		}
		if ( null !== $company && empty( $add ) ) {
			$run->count( 'unchanged' );
			return;
		}
		$run->count( null === $company ? 'new' : 'changed' );
		if ( $this->is_check() ) {
			return;
		}
		try {
			if ( null === $company ) {
				$this->journal_classification( $owner );
				$company_id = $this->companies->create( $name, $owner, null !== $group_id && $group_id > 0 ? $group_id : null, $run->user_id );
				try {
					$this->journal( Journal::TYPE_COMPANY, $company_id, $key, null );
				} catch ( JournalException $e ) {
					$this->companies->delete( $company_id );
					throw $e;
				}
			} else {
				$company_id = $company['id'];
			}
			foreach ( $add as $member ) {
				$this->journal_classification( $member );
				$this->journal( Journal::TYPE_MEMBER, $member, (string) $company_id, null );
				$this->companies->add_buyer( $company_id, $member, $run->user_id );
			}
		} catch ( RuntimeException $e ) {
			/* translators: 1: user id, 2: error message */
			$this->skip( sprintf( __( 'The company of account #%1$d was not completed: %2$s', 'eurob2b' ), $owner, $e->getMessage() ) );
		}//end try
	}

	/**
	 * Target of an earlier import of the current source.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Source key.
	 * @return int|string|null
	 */
	private function persistent( string $kind, string $key ) {
		$map = get_option( self::OPTION_MAP, array() );
		return is_array( $map ) ? ( $map[ $this->run()->source ][ $kind ][ $key ] ?? null ) : null;
	}

	/**
	 * Remembers a target for later imports (journaled; nothing in a test run).
	 *
	 * @param string     $kind   Kind.
	 * @param string     $key    Source key.
	 * @param int|string $target Target.
	 */
	private function persist( string $kind, string $key, $target ): void {
		if ( $this->is_check() ) {
			return;
		}
		$run = $this->run();
		$map = get_option( self::OPTION_MAP, array() );
		$map = is_array( $map ) ? $map : array();
		$old = $map[ $run->source ][ $kind ][ $key ] ?? null;
		if ( $old === $target ) {
			return;
		}
		$this->journal( Journal::TYPE_MAP, 0, $kind . ':' . $key, null === $old ? null : (string) $old );
		$map[ $run->source ][ $kind ][ $key ] = $target;
		update_option( self::OPTION_MAP, $map, false );
	}
}

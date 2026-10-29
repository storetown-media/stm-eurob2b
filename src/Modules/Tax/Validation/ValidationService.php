<?php
/**
 * Orchestrates VAT ID checks: caching, retries, downtime policy, decisions, retention.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Validation;

use DateTimeImmutable;
use DateTimeZone;
use EuroB2B\Infrastructure\Schema;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Tax\VatId\ExternalServices;
use EuroB2B\Modules\Tax\VatId\QualifiedVatIdValidatorInterface;
use EuroB2B\Modules\Tax\VatId\TraderDetails;
use EuroB2B\Modules\Tax\VatId\ValidationResult;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;
use EuroB2B\Modules\Tax\VatId\VatId;
use EuroB2B\Modules\Tax\VatId\VatIdNormalizer;
use EuroB2B\Modules\Tax\VatId\VatIdValidatorInterface;
use EuroB2B\Modules\Tax\VatId\ViesValidator;
use WC_Customer;

/**
 * The one entry point for "is this VAT ID good?" (TAX-005..012). Results are stored with evidence;
 * outages become pending with scheduled retries (backoff, concurrency 1), then unavailable after the
 * configured attempts; the downtime policy decides what a non-final result means for an action.
 */
final class ValidationService {

	public const RETRY_HOOK       = 'eurob2b_vat_revalidate';
	public const MAINTENANCE_HOOK = 'eurob2b_vat_maintenance';
	public const LOCK_TRANSIENT   = Schema::TRANSIENT_PREFIX . 'vies_lock';
	public const POLICY_REVIEW    = 'taxed_review';
	public const POLICY_BLOCK     = 'block';
	public const POLICY_PROVISION = 'provisional';

	/**
	 * Reasons of a result no service was asked for: every checking service is off, or none covers
	 * the prefix (Liechtenstein numbers, CHE or NO numbers while their check is off). Such a number
	 * passed the format check only; that is not an outage.
	 */
	public const REASON_DISABLED     = 'validation_disabled';
	public const REASON_NO_VALIDATOR = 'no_validator';

	/**
	 * Repository.
	 *
	 * @var ValidationRepository
	 */
	private ValidationRepository $repository;

	/**
	 * Normaliser.
	 *
	 * @var VatIdNormalizer
	 */
	private VatIdNormalizer $normalizer;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Audience resolver (approval status for the provisional policy).
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $audiences;

	/**
	 * Core validators.
	 *
	 * @var VatIdValidatorInterface[]
	 */
	private array $core_validators;

	/**
	 * Constructor.
	 *
	 * @param ValidationRepository      $repository Repository.
	 * @param VatIdNormalizer           $normalizer Normaliser.
	 * @param Settings                  $settings   Settings.
	 * @param AudienceResolver          $audiences  Audience resolver.
	 * @param VatIdValidatorInterface[] $validators Core validators (VIES; EuroB2B Pro adds its services through `eurob2b_vat_validators`).
	 */
	public function __construct( ValidationRepository $repository, VatIdNormalizer $normalizer, Settings $settings, AudienceResolver $audiences, array $validators ) {
		$this->repository      = $repository;
		$this->normalizer      = $normalizer;
		$this->settings        = $settings;
		$this->audiences       = $audiences;
		$this->core_validators = $validators;
	}

	/**
	 * Whether online validation is switched on (the external connection is off by default, D-03/§4).
	 */
	public function enabled(): bool {
		/**
		 * Filters whether a checking service is switched on: VIES, or a service of an add-on (EuroB2B
		 * Pro: the BZSt, the Swiss UID register and the Brønnøysund register, each by its setting).
		 * Off, numbers are only checked for their format.
		 *
		 * @param bool $enabled Whether VIES is switched on.
		 */
		return (bool) apply_filters( 'eurob2b_vat_validation_enabled', $this->vies_enabled() );
	}

	/**
	 * Whether VIES may be asked (its own switch; the BZSt has another).
	 */
	public function vies_enabled(): bool {
		return (bool) $this->settings->get( 'tax_vies_enabled' );
	}

	/**
	 * Normalises customer input.
	 *
	 * @param string      $input        Input.
	 * @param string|null $country_hint Billing country.
	 * @return VatId|string VatId or a reason code (empty, unknown_prefix, syntax).
	 */
	public function normalize( string $input, ?string $country_hint = null ): VatId|string {
		return $this->normalizer->normalize( $input, $country_hint );
	}

	/**
	 * The shop's own VAT ID (for consultation numbers), when configured and well-formed.
	 */
	public function requester(): ?VatId {
		$configured = (string) $this->settings->get( 'tax_shop_vat_id' );
		if ( '' === trim( $configured ) ) {
			return null;
		}
		$vat_id = $this->normalizer->normalize( $configured );
		return $vat_id instanceof VatId ? $vat_id : null;
	}

	/**
	 * Checks a VAT ID: reuses a fresh final result, otherwise asks the service and stores the evidence.
	 *
	 * @param VatId              $vat_id  Number.
	 * @param int|null           $user_id Customer (null for guests).
	 * @param bool               $force   Ignore cached results.
	 * @param TraderDetails|null $trader  Company data for a qualified confirmation; null = from the customer's billing data.
	 */
	public function check( VatId $vat_id, ?int $user_id = null, bool $force = false, ?TraderDetails $trader = null ): ValidationRecord {
		$country = $vat_id->country_code();
		$trader  = $trader ?? $this->trader_for( $user_id );
		if ( ! $force ) {
			$latest = $this->repository->latest_for( $country, $vat_id->number );
			if ( null !== $latest && $this->is_reusable( $latest ) ) {
				$same = $this->same_trader( $latest, $trader );
				if ( $this->may_reuse( $latest, $trader, $same ) ) {
					return $this->row_for( $latest, $user_id, $same );
				}
			}
		}

		if ( ! $this->enabled() ) {
			return $this->unsaved( $vat_id, $user_id, ValidationStatus::UNAVAILABLE, self::REASON_DISABLED );
		}
		$validator = $this->validator_for( $vat_id->prefix );
		if ( null === $validator ) {
			return $this->unsaved( $vat_id, $user_id, ValidationStatus::UNAVAILABLE, self::REASON_NO_VALIDATOR );
		}

		$result = $this->ask( $validator, $vat_id, $trader );
		$this->honour_removal( $result );
		$pending  = ValidationStatus::UNAVAILABLE === $result->status && $this->max_attempts() > 1 && $this->can_schedule();
		$id       = $this->repository->insert( $result, $user_id, 1, $this->orderless_expiry(), $pending ? ValidationStatus::PENDING : null );
		$record   = $this->repository->find( $id );
		$record ??= $this->unsaved( $vat_id, $user_id, $result->status, $result->reason_code );
		if ( $pending ) {
			$this->schedule_retry( $id, 1 );
		} else {
			$this->after_final( $record );
		}
		return $record;
	}

	/**
	 * Scheduled retry (Action Scheduler / WP-Cron hook). Concurrency 1 through a short lock.
	 *
	 * @param mixed $record_id Row id.
	 */
	public function retry( $record_id ): void {
		$record = $this->repository->find( (int) $record_id );
		if ( null === $record || ValidationStatus::PENDING !== $record->status || $record->is_decided() ) {
			return;
		}
		// A person decided on this number meanwhile, on a newer row (typically the copy of this row made
		// for a later order): that decision is final and reused. Answering later would make this row
		// the newest result and override it, so the chain ends here and the row keeps its pending
		// state. A deliberate re-check after an older decision is a newer row and keeps retrying.
		if ( $this->repository->decided_after( $record->country_code, $record->vat_number, $record->id ) ) {
			return;
		}
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			$this->schedule_retry( $record->id, $record->attempts, 60 );
			return;
		}
		set_transient( self::LOCK_TRANSIENT, '1', 30 );
		try {
			$vat_id    = $this->normalizer->normalize( $record->vat_id() );
			$validator = $vat_id instanceof VatId ? $this->validator_for( $vat_id->prefix ) : null;
			if ( ! $vat_id instanceof VatId || null === $validator || ! $this->enabled() ) {
				$this->repository->update_result_unless_decided( $record->id, $this->result_for( $record, ValidationStatus::UNAVAILABLE, self::REASON_NO_VALIDATOR ), $record->attempts );
				return;
			}
			$attempts = $record->attempts + 1;
			$result   = $this->ask( $validator, $vat_id, $this->trader_for( $record->user_id ) );
			$this->honour_removal( $result );
			$pending = ValidationStatus::UNAVAILABLE === $result->status && $attempts < $this->max_attempts();
			// The write itself checks for a decision, because a person may decide while the service is
			// being asked; the check above only saves the service call.
			if ( ! $this->repository->update_result_unless_decided( $record->id, $result, $attempts, $pending ? ValidationStatus::PENDING : null ) ) {
				return;
			}
			if ( $pending ) {
				$this->schedule_retry( $record->id, $attempts );
			} else {
				$updated = $this->repository->find( $record->id );
				if ( null !== $updated ) {
					$this->after_final( $updated );
				}
			}
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}//end try
	}

	/**
	 * The row a customer may hold: the reusable row itself when it is free or already theirs and not
	 * yet linked to an order, otherwise a copy of its service answer (rows never change hands).
	 *
	 * @param ValidationRecord $latest      Reusable row.
	 * @param int|null         $user_id     Customer.
	 * @param bool             $same_trader The customer's company data equals the data the row compared.
	 */
	private function row_for( ValidationRecord $latest, ?int $user_id, bool $same_trader = false ): ValidationRecord {
		$same_owner = null === $latest->user_id || $latest->user_id === $user_id;
		if ( $same_owner && null === $latest->order_id ) {
			if ( null !== $user_id && null === $latest->user_id ) {
				$this->repository->set_user( $latest->id, $user_id );
				return $this->repository->find( $latest->id ) ?? $latest;
			}
			return $latest;
		}
		$copy = $this->repository->find( $this->repository->copy_for( $latest, $user_id, $same_trader ) );
		return $copy ?? $latest;
	}

	/**
	 * Whether a stored result can be reused instead of asking the service again.
	 *
	 * @param ValidationRecord $record Record.
	 */
	public function is_reusable( ValidationRecord $record ): bool {
		if ( $record->is_decided() ) {
			return true;
		}
		if ( ValidationStatus::PENDING === $record->status ) {
			return true;
			// A retry is on its way; do not pile up requests.
		}
		if ( ValidationStatus::UNAVAILABLE === $record->status ) {
			return false;
		}
		return ! $record->older_than( $this->revalidate_days(), self::now() );
	}

	/**
	 * Whether the downtime policy says an action (registration, checkout) must be blocked for this result.
	 *
	 * @param ValidationRecord $record Record.
	 */
	public function blocks_action( ValidationRecord $record ): bool {
		if ( in_array( $record->status, array( ValidationStatus::VALID, ValidationStatus::INVALID ), true ) || self::is_format_only( $record ) ) {
			// No service was asked for a format-only result, so waiting would never help.
			return false;
		}
		return self::POLICY_BLOCK === $this->policy();
	}

	/**
	 * Whether no service was asked for this result (see REASON_DISABLED, REASON_NO_VALIDATOR).
	 *
	 * @param ValidationRecord $record Record.
	 */
	public static function is_format_only( ValidationRecord $record ): bool {
		return ValidationStatus::UNAVAILABLE === $record->status && in_array( (string) $record->reason_code, array( self::REASON_DISABLED, self::REASON_NO_VALIDATOR ), true );
	}

	/**
	 * Status to act on for exemptions: final results as they are; non-final results per policy
	 * (provisional acceptance only for approved customers with a recent valid result of the same
	 * country and number; an order exempted that way is marked for review, see is_provisional()).
	 *
	 * @param ValidationRecord $record  Record.
	 * @param int|null         $user_id Customer.
	 */
	public function effective_status( ValidationRecord $record, ?int $user_id ): ValidationStatus {
		if ( in_array( $record->status, array( ValidationStatus::VALID, ValidationStatus::INVALID ), true ) ) {
			return $record->status;
		}
		if ( self::POLICY_PROVISION === $this->policy() && null !== $user_id && $user_id > 0 && CustomerStatus::APPROVED === $this->audiences->stored_status( $user_id ) ) {
			$previous = $this->repository->latest_valid_for_user( $user_id, $record->country_code, $record->vat_number );
			if ( null !== $previous && ! $previous->older_than( (int) $this->settings->get( 'tax_provisional_days' ), self::now() ) ) {
				return ValidationStatus::VALID;
			}
		}
		return $record->status;
	}

	/**
	 * Whether a record counts as valid only through provisional acceptance (no final result yet).
	 *
	 * @param ValidationRecord $record  Record.
	 * @param int|null         $user_id Customer.
	 */
	public function is_provisional( ValidationRecord $record, ?int $user_id ): bool {
		return ValidationStatus::VALID !== $record->status && ValidationStatus::VALID === $this->effective_status( $record, $user_id );
	}

	/**
	 * Manual decision by a person with the capability (checked by the caller).
	 *
	 * @param int              $record_id Row id.
	 * @param ValidationStatus $status    valid or invalid.
	 * @param int              $actor_id  Actor.
	 * @param string           $note      Note.
	 */
	public function decide( int $record_id, ValidationStatus $status, int $actor_id, string $note ): ?ValidationRecord {
		if ( ! in_array( $status, array( ValidationStatus::VALID, ValidationStatus::INVALID ), true ) ) {
			return null;
		}
		// The result before the decision is overwritten below; the audit log keeps it (PRV-011).
		$before = $this->repository->find( $record_id );
		$this->repository->decide( $record_id, $status, $actor_id, $note );
		$record = $this->repository->find( $record_id );
		if ( null !== $record ) {
			/**
			 * Fires after a manual decision on a VAT validation.
			 *
			 * @param ValidationRecord $record   Record.
			 * @param int              $actor_id Actor.
			 * @param string           $previous Status before the decision ('' when unknown).
			 */
			do_action( 'eurob2b_vat_decided', $record, $actor_id, null !== $before ? $before->status->value : '' );
		}
		return $record;
	}

	/**
	 * Links the evidence to an order and extends retention to the invoice period.
	 *
	 * @param ValidationRecord $record   Record.
	 * @param int              $order_id Order id.
	 * @return ValidationRecord The row now linked to the order (a copy when the record already belonged to another order).
	 */
	public function attach_order( ValidationRecord $record, int $order_id ): ValidationRecord {
		if ( ! $record->exists() ) {
			return $record;
		}
		if ( null !== $record->order_id && $record->order_id !== $order_id ) {
			// Already evidence for another order: this order gets its own row with the same answer.
			$record = $this->repository->find( $this->repository->copy_for( $record, $record->user_id ) ) ?? $record;
		}
		$this->repository->attach_order( $record->id, $order_id, self::order_retention_end( max( 1, (int) $this->settings->get( 'tax_retention_years' ) ) ) );
		return $this->repository->find( $record->id ) ?? $record;
	}

	/**
	 * End of the invoice retention period for evidence linked to an order today: retention periods
	 * such as Germany's run from the end of the calendar year (§ 147 (4) AO), so the evidence is
	 * kept until the end of the year in which the period ends, never only to the anniversary.
	 *
	 * @param int $years Retention period in years.
	 */
	public static function order_retention_end( int $years ): DateTimeImmutable {
		$now = self::now();
		return $now->setDate( (int) $now->format( 'Y' ) + $years, 12, 31 )->setTime( 23, 59, 59 );
	}

	/**
	 * Deletes expired records (daily maintenance): orderless rows after their review period,
	 * order-linked rows after the invoice retention period.
	 */
	public function purge_expired(): int {
		return $this->repository->purge_expired( self::now() );
	}

	/**
	 * Repository access for pages and providers.
	 */
	public function repository(): ValidationRepository {
		return $this->repository;
	}

	/**
	 * Downtime policy.
	 */
	public function policy(): string {
		$policy = (string) $this->settings->get( 'tax_downtime_policy' );
		return in_array( $policy, array( self::POLICY_REVIEW, self::POLICY_BLOCK, self::POLICY_PROVISION ), true ) ? $policy : self::POLICY_REVIEW;
	}

	/**
	 * Days a valid result stays fresh.
	 */
	public function revalidate_days(): int {
		return max( 1, (int) $this->settings->get( 'tax_revalidate_days' ) );
	}

	/**
	 * Validator for a prefix (core list plus add-ons).
	 *
	 * @param string $prefix Prefix.
	 */
	public function validator_for( string $prefix ): ?VatIdValidatorInterface {
		/**
		 * Filters the VAT ID validators (BZSt, Swiss UID, Brønnøysund add-ons).
		 *
		 * @param VatIdValidatorInterface[] $validators Validators in order of preference.
		 */
		$validators = apply_filters( 'eurob2b_vat_validators', $this->core_validators );
		foreach ( is_array( $validators ) ? $validators : array() as $validator ) {
			if ( ! $validator instanceof VatIdValidatorInterface || ( ViesValidator::ID === $validator->id() && ! $this->vies_enabled() ) ) {
				continue;
			}
			if ( $validator->supports( $prefix ) ) {
				return $validator;
			}
		}
		return null;
	}

	/**
	 * One service call; qualified validators get the company data.
	 *
	 * @param VatIdValidatorInterface $validator Validator.
	 * @param VatId                   $vat_id    Number.
	 * @param TraderDetails|null      $trader    Company data (caller's or resolved for the customer).
	 */
	private function ask( VatIdValidatorInterface $validator, VatId $vat_id, ?TraderDetails $trader ): ValidationResult {
		if ( $validator instanceof QualifiedVatIdValidatorInterface ) {
			return $validator->validate_qualified( $vat_id, $this->requester(), $trader );
		}
		return $validator->validate( $vat_id, $this->requester() );
	}

	/**
	 * When the Brønnøysund register answers that it removed an entity for legal reasons (HTTP 410),
	 * it asks that copies remove it too: the name and address earlier checks kept for the number go,
	 * in every row of that service including copies; the results themselves stay as evidence.
	 *
	 * @param ValidationResult $result Service answer.
	 */
	private function honour_removal( ValidationResult $result ): void {
		if ( ExternalServices::BRREG === $result->service && ExternalServices::REASON_REMOVED === $result->reason_code ) {
			$this->repository->forget_returned_data( $result->vat_id->country_code(), $result->vat_id->number, ExternalServices::BRREG );
		}
	}

	/**
	 * Whether a stored BZSt answer compared exactly this company data.
	 *
	 * @param ValidationRecord   $latest Stored row.
	 * @param TraderDetails|null $trader Company data of the current check.
	 */
	private function same_trader( ValidationRecord $latest, ?TraderDetails $trader ): bool {
		if ( null === $trader || ExternalServices::BZST !== $latest->service ) {
			return false;
		}
		$requested = ExternalServices::bzst_requested( $latest->response_address );
		return null !== $requested && ExternalServices::bzst_details( $trader ) === $requested;
	}

	/**
	 * Whether a reusable row may answer this check. VIES rows, manual decisions and pending rows
	 * always may. A BZSt row may unless a qualified confirmation is possible now and the row did not
	 * compare exactly this company data; then the BZSt is asked again. Copies for other customers
	 * never carry another customer's company data (ValidationRepository::copy_for()).
	 *
	 * @param ValidationRecord   $latest Stored row.
	 * @param TraderDetails|null $trader Company data of the current check.
	 * @param bool               $same   The row compared exactly this company data.
	 */
	private function may_reuse( ValidationRecord $latest, ?TraderDetails $trader, bool $same ): bool {
		if ( ExternalServices::BZST !== $latest->service || $latest->is_decided() || ValidationStatus::PENDING === $latest->status ) {
			return true;
		}
		/**
		 * Filters whether checks ask for a qualified confirmation with the company data now (EuroB2B
		 * Pro: the BZSt in its qualified mode). Then a stored BZSt answer that compared other company
		 * data is not reused, and the service is asked again.
		 *
		 * @param bool $qualified Whether a qualified confirmation is asked for (false without an add-on).
		 */
		if ( null === $trader || ! (bool) apply_filters( 'eurob2b_vat_qualified_confirmation', false ) ) {
			return true;
		}
		return $same;
	}

	/**
	 * Company data for a qualified confirmation of a registered customer (registration after the
	 * account exists, My Account, scheduled retries, admin re-checks): the customer's stored billing
	 * data, which EuroB2B Pro replaces with the billing address of the company that binds the
	 * customer (CMP-012). Never a guest session: guest checkouts pass their checkout data explicitly,
	 * and registrations before the account exists stay simple. Null when the company name or the city
	 * is missing.
	 *
	 * @param int|null $user_id Customer (null for guests).
	 */
	public function trader_for( ?int $user_id ): ?TraderDetails {
		if ( null === $user_id || $user_id <= 0 ) {
			return null;
		}
		$trader = null;
		if ( class_exists( 'WC_Customer' ) ) {
			$customer = new WC_Customer( $user_id );
			$trader   = TraderDetails::from( $customer->get_billing_company(), $customer->get_billing_city(), $customer->get_billing_postcode(), $customer->get_billing_address_1() );
		}
		/**
		 * Filters the company data of a registered customer for a qualified confirmation (EuroB2B
		 * Pro: the billing address of the company that binds the customer, CMP-012). Null means no
		 * company data (the confirmation stays simple).
		 *
		 * @param TraderDetails|null $trader  From the customer's billing data.
		 * @param int                $user_id Customer.
		 */
		$filtered = apply_filters( 'eurob2b_vat_trader_details', $trader, $user_id );
		return null === $filtered || $filtered instanceof TraderDetails ? $filtered : $trader;
	}

	/**
	 * Notifies and announces a final result that needs attention (invalid or unavailable).
	 *
	 * @param ValidationRecord $record Record.
	 */
	private function after_final( ValidationRecord $record ): void {
		/**
		 * Fires after a validation reached a final service result.
		 *
		 * @param ValidationRecord $record Record.
		 */
		do_action( 'eurob2b_vat_validated', $record );
		if ( ValidationStatus::VALID === $record->status || ! $this->settings->get( 'tax_notify_admin' ) ) {
			return;
		}
		$admin = (string) get_option( 'admin_email' );
		if ( '' === $admin ) {
			return;
		}
		$subject = sprintf(
			/* translators: 1: site name, 2: VAT ID */
			__( '[%1$s] VAT ID needs review: %2$s', 'eurob2b' ),
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
			$record->vat_id()
		);
		$body = sprintf(
			/* translators: 1: VAT ID, 2: status, 3: reason code, 4: admin URL */
			__( "The VAT ID %1\$s could not be confirmed (status: %2\$s, reason: %3\$s).\n\nReview it here: %4\$s", 'eurob2b' ),
			$record->vat_id(),
			$record->status->value,
			(string) $record->reason_code,
			admin_url( 'admin.php?page=eurob2b-vat-validations' )
		);
		wp_mail( $admin, $subject, $body );
	}

	/**
	 * Schedules a retry with exponential backoff (Action Scheduler when WooCommerce provides it, else WP-Cron).
	 *
	 * @param int      $record_id Row id.
	 * @param int      $attempts  Attempts so far.
	 * @param int|null $delay     Fixed delay in seconds (overrides the backoff).
	 */
	private function schedule_retry( int $record_id, int $attempts, ?int $delay = null ): void {
		$delay ??= min( 6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempts - 1 ) ) );
		$when    = time() + $delay;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, self::RETRY_HOOK, array( $record_id ), 'eurob2b' );
			return;
		}
		wp_schedule_single_event( $when, self::RETRY_HOOK, array( $record_id ) );
	}

	/**
	 * Whether retries can be scheduled at all.
	 */
	private function can_schedule(): bool {
		return function_exists( 'as_schedule_single_action' ) || function_exists( 'wp_schedule_single_event' );
	}

	/**
	 * Max attempts before a result is final "unavailable".
	 */
	private function max_attempts(): int {
		return max( 1, (int) $this->settings->get( 'tax_max_attempts' ) );
	}

	/**
	 * Retention end for a record without order.
	 */
	private function orderless_expiry(): DateTimeImmutable {
		$days = max( 1, (int) $this->settings->get( 'tax_retention_orderless_days' ) );
		return self::now()->modify( '+' . $days . ' days' );
	}

	/**
	 * An unsaved record (no service call happened).
	 *
	 * @param VatId            $vat_id  Number.
	 * @param int|null         $user_id Customer.
	 * @param ValidationStatus $status  Status.
	 * @param string|null      $reason  Reason.
	 */
	private function unsaved( VatId $vat_id, ?int $user_id, ValidationStatus $status, ?string $reason ): ValidationRecord {
		return new ValidationRecord( 0, $user_id, null, null, $vat_id->country_code(), $vat_id->number, $status, $reason, 'none', null, null, null, null, null, null, 0, self::now(), null );
	}

	/**
	 * Result object describing a record's current state (for repository updates without a service call).
	 *
	 * @param ValidationRecord $record Record.
	 * @param ValidationStatus $status Status.
	 * @param string           $reason Reason.
	 */
	private function result_for( ValidationRecord $record, ValidationStatus $status, string $reason ): ValidationResult {
		$vat_id = $this->normalizer->normalize( $record->vat_id() );
		if ( ! $vat_id instanceof VatId ) {
			$vat_id = new VatId( \EuroB2B\Modules\Countries\CountryRegistry::iso_to_prefix( $record->country_code ), $record->vat_number, $record->vat_id() );
		}
		return new ValidationResult( $vat_id, $status, $record->service, self::now(), $reason, $record->consultation_number, $record->requester_vat_id, $record->response_name, $record->response_address );
	}

	/**
	 * Current UTC time.
	 */
	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}

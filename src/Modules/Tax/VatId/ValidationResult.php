<?php
/**
 * Validation result.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId;

use DateTimeImmutable;

/**
 * Outcome of one validation attempt, including the evidence to store.
 */
final class ValidationResult {

	/**
	 * Constructor.
	 *
	 * @param VatId             $vat_id              The number that was checked.
	 * @param ValidationStatus  $status              Outcome.
	 * @param string            $service             Service id (vies, bzst, uid_ch, brreg, manual, syntax).
	 * @param DateTimeImmutable $checked_at          When the check happened (UTC).
	 * @param string|null       $reason_code         Machine-readable reason (e.g. MS_UNAVAILABLE, syntax).
	 * @param string|null       $consultation_number Evidence reference returned by the service, if any.
	 * @param string|null       $requester_vat_id    Requester VAT ID used to obtain the consultation number.
	 * @param string|null       $trader_name         Name returned by the service (may be "---" or null).
	 * @param string|null       $trader_address      Address returned by the service.
	 */
	public function __construct(
		public readonly VatId $vat_id,
		public readonly ValidationStatus $status,
		public readonly string $service,
		public readonly DateTimeImmutable $checked_at,
		public readonly ?string $reason_code = null,
		public readonly ?string $consultation_number = null,
		public readonly ?string $requester_vat_id = null,
		public readonly ?string $trader_name = null,
		public readonly ?string $trader_address = null
	) {}

	/**
	 * Whether the number was confirmed valid.
	 */
	public function is_valid(): bool {
		return ValidationStatus::VALID === $this->status;
	}
}

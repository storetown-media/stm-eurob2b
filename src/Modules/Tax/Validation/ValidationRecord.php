<?php
/**
 * Stored validation (one row of eurob2b_vat_validations).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Validation;

use DateTimeImmutable;
use EuroB2B\Modules\Countries\CountryRegistry;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;

/**
 * Evidence of a check: who asked for which number, what the service answered, how often we tried,
 * who decided manually and how long the row is kept (docs/privacy-and-data-flows.md D-03).
 */
final class ValidationRecord {

	/**
	 * Constructor.
	 *
	 * @param int                    $id                  Row id (0 = not stored).
	 * @param int|null               $user_id             Customer.
	 * @param int|null               $company_id          Company (later).
	 * @param int|null               $order_id            Order the evidence belongs to.
	 * @param string                 $country_code        ISO country of the VAT ID (EL → GR, XI → GB, CHE → CH).
	 * @param string                 $vat_number          Number without prefix.
	 * @param ValidationStatus       $status              Status (after manual decision when decided).
	 * @param string|null            $reason_code         Reason/fault code.
	 * @param string                 $service             Service id or "manual".
	 * @param string|null            $consultation_number Consultation number (requestIdentifier).
	 * @param string|null            $requester_vat_id    Shop's VAT ID sent along.
	 * @param string|null            $response_name       Trader name returned.
	 * @param string|null            $response_address    Trader address returned.
	 * @param int|null               $decided_by          Actor of a manual decision.
	 * @param string|null            $decision_note       Note of the decision.
	 * @param int                    $attempts            Attempts so far.
	 * @param DateTimeImmutable      $checked_at          Last check (UTC).
	 * @param DateTimeImmutable|null $expires_at          Retention end (UTC).
	 */
	public function __construct(
		public readonly int $id,
		public readonly ?int $user_id,
		public readonly ?int $company_id,
		public readonly ?int $order_id,
		public readonly string $country_code,
		public readonly string $vat_number,
		public readonly ValidationStatus $status,
		public readonly ?string $reason_code,
		public readonly string $service,
		public readonly ?string $consultation_number,
		public readonly ?string $requester_vat_id,
		public readonly ?string $response_name,
		public readonly ?string $response_address,
		public readonly ?int $decided_by,
		public readonly ?string $decision_note,
		public readonly int $attempts,
		public readonly DateTimeImmutable $checked_at,
		public readonly ?DateTimeImmutable $expires_at
	) {
	}

	/**
	 * VAT ID with prefix, as shown to people and written to orders.
	 */
	public function vat_id(): string {
		$prefix = 'CH' === $this->country_code ? 'CHE' : CountryRegistry::iso_to_prefix( $this->country_code );
		return $prefix . $this->vat_number;
	}

	/**
	 * Whether a person decided this record (overrides the service result).
	 */
	public function is_decided(): bool {
		return null !== $this->decided_by && $this->decided_by > 0;
	}

	/**
	 * Whether the record is stored.
	 */
	public function exists(): bool {
		return $this->id > 0;
	}

	/**
	 * Whether the result is older than the given number of days.
	 *
	 * @param int               $days Max age.
	 * @param DateTimeImmutable $now  Now (UTC).
	 */
	public function older_than( int $days, DateTimeImmutable $now ): bool {
		return $this->checked_at->getTimestamp() < $now->getTimestamp() - ( $days * DAY_IN_SECONDS );
	}
}

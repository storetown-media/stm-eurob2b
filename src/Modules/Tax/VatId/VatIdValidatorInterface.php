<?php
/**
 * Validator contract.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId;

/**
 * A service that can confirm whether a VAT ID is registered.
 *
 * Implementations: VIES (EU), BZSt (German qualified confirmation), Swiss UID
 * register, Norwegian Brønnøysund register. Each must map outages to
 * ValidationStatus::UNAVAILABLE and never throw for network problems.
 */
interface VatIdValidatorInterface {

	/**
	 * Service id used in logs and the validations table.
	 */
	public function id(): string;

	/**
	 * Whether this validator can check numbers with the given prefix.
	 *
	 * @param string $prefix VAT prefix (DE, EL, XI, CHE, NO ...).
	 */
	public function supports( string $prefix ): bool;

	/**
	 * Performs the check.
	 *
	 * @param VatId      $vat_id    Number to validate.
	 * @param VatId|null $requester Shop's own VAT ID to obtain a consultation number, if supported.
	 */
	public function validate( VatId $vat_id, ?VatId $requester = null ): ValidationResult;
}

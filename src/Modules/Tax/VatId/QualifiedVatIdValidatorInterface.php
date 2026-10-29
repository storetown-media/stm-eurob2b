<?php
/**
 * Validator that can also compare company name and address.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId;

/**
 * A validator offering a qualified confirmation (name and address compared with the member state's
 * records). ValidationService passes the company data it knows; without it the validator falls back
 * to a simple confirmation.
 */
interface QualifiedVatIdValidatorInterface extends VatIdValidatorInterface {

	/**
	 * Validates and, when company data is given and the validator is set up for it, compares it.
	 *
	 * @param VatId              $vat_id    Number to validate.
	 * @param VatId|null         $requester Shop's own VAT ID.
	 * @param TraderDetails|null $trader    Company data of the customer, if known.
	 */
	public function validate_qualified( VatId $vat_id, ?VatId $requester, ?TraderDetails $trader ): ValidationResult;
}

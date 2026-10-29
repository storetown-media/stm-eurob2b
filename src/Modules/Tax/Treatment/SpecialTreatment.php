<?php
/**
 * A special case of the tax treatment decision.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Treatment;

/**
 * A rule that takes precedence over the standard decision table of `TaxTreatmentDecider` for some
 * facts. The core ships none: without one, special territories count as their member state and
 * Northern Ireland as a part of the United Kingdom (third country), as with their settings off.
 * EuroB2B Pro adds the special territories (TAX-024) and Northern Ireland (TAX-034) through
 * `eurob2b_tax_special_treatments` (PLT-015). Implementations are pure: no WordPress calls.
 */
interface SpecialTreatment {

	/**
	 * The decision for these facts, or null to leave them to the next special case and then to the
	 * standard rules. Asked after the check that the shop is in the EU, before anything else.
	 *
	 * @param TreatmentInput $input   Facts.
	 * @param string         $shop    VAT country of the shop (Monaco as France).
	 * @param string         $billing VAT country of the billing address ('' when unknown).
	 */
	public function decide( TreatmentInput $input, string $shop, string $billing ): ?TreatmentDecision;

	/**
	 * The decision for an order with goods and services from the decisions of each part, or null for
	 * the standard combination.
	 *
	 * @param TreatmentDecision $goods       Decision for the goods.
	 * @param TreatmentDecision $services    Decision for the services.
	 * @param TreatmentInput    $goods_input Facts of the goods.
	 */
	public function combine( TreatmentDecision $goods, TreatmentDecision $services, TreatmentInput $goods_input ): ?TreatmentDecision;
}

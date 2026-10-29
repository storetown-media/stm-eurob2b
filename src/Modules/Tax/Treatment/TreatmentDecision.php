<?php
/**
 * Outcome of the tax treatment decision.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Treatment;

/**
 * Treatment plus the reason it was chosen; `needs_review` flags cases a person should look at.
 */
final class TreatmentDecision {

	/**
	 * Constructor.
	 *
	 * @param TaxTreatment $treatment    Treatment.
	 * @param string       $reason_code  Stable reason code (see TaxTreatmentDecider).
	 * @param bool         $needs_review Whether staff should look at the order.
	 * @param string|null  $territory    Id of the special territory the decision rests on (TAX-024), null for none.
	 */
	public function __construct(
		public readonly TaxTreatment $treatment,
		public readonly string $reason_code,
		public readonly bool $needs_review = false,
		public readonly ?string $territory = null
	) {
	}

	/**
	 * Whether the sale is exempt from the shop's VAT.
	 */
	public function is_exempt(): bool {
		return $this->treatment->is_exempt();
	}

	/**
	 * Array form for sessions and meta.
	 *
	 * @return array{treatment: string, exempt: bool, reason_code: string, needs_review: bool, territory: string|null}
	 */
	public function to_array(): array {
		return array(
			'treatment'    => $this->treatment->value,
			'exempt'       => $this->is_exempt(),
			'reason_code'  => $this->reason_code,
			'needs_review' => $this->needs_review,
			'territory'    => $this->territory,
		);
	}
}

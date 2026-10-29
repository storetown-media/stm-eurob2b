<?php
/**
 * VAT ID value object.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId;

/**
 * A normalised VAT identification number: prefix plus national part.
 *
 * The prefix is the VAT prefix (EL for Greece, XI for Northern Ireland, CHE
 * for Switzerland), not necessarily an ISO country code. The original input
 * is kept so the shop can show the customer what they typed.
 */
final class VatId {

	/**
	 * Constructor.
	 *
	 * @param string $prefix VAT prefix, upper case (DE, EL, XI, CHE, NO).
	 * @param string $number National part without separators, upper case.
	 * @param string $raw    Original input as typed.
	 */
	public function __construct(
		public readonly string $prefix,
		public readonly string $number,
		public readonly string $raw
	) {}

	/**
	 * Canonical form, e.g. "DE123456789" or "CHE123456789".
	 */
	public function canonical(): string {
		return $this->prefix . $this->number;
	}

	/**
	 * ISO 3166-1 alpha-2 country code where one exists (EL -> GR, CHE -> CH), XI stays XI.
	 */
	public function country_code(): string {
		return match ( $this->prefix ) {
			'EL' => 'GR',
			'CHE' => 'CH',
			default => $this->prefix,
		};
	}

	/**
	 * String form equals the canonical form.
	 */
	public function __toString(): string {
		return $this->canonical();
	}
}

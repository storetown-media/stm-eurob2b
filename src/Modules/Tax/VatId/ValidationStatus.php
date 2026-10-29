<?php
/**
 * Validation status.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId;

/**
 * The four states a VAT ID check can be in. They are never collapsed:
 * "service unavailable" is not "valid" and not "invalid".
 */
enum ValidationStatus: string {
	case VALID       = 'valid';
	case INVALID     = 'invalid';
	case UNAVAILABLE = 'unavailable';
	case PENDING     = 'pending';

	/**
	 * Whether the status is final (no retry will change it on its own).
	 */
	public function is_final(): bool {
		return self::VALID === $this || self::INVALID === $this;
	}
}

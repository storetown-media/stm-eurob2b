<?php
/**
 * Customer type.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

/**
 * Coarse classification every visitor falls into exactly once.
 *
 * Groups refine this: a group is either B2B or B2C. Guests are neither until
 * they register or identify themselves (e.g. quote request as guest).
 */
enum CustomerType: string {
	case B2B   = 'b2b';
	case B2C   = 'b2c';
	case GUEST = 'guest';

	/**
	 * Whether this type represents a business customer.
	 */
	public function is_business(): bool {
		return self::B2B === $this;
	}
}

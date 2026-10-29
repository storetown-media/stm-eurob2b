<?php
/**
 * Customer status.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

/**
 * Approval state of a customer account.
 *
 * Only approved customers get B2B treatment. Pending and rejected accounts
 * are blocked from logging in; if a session still exists, they are treated
 * like guests by the audience resolver.
 */
enum CustomerStatus: string {
	case PENDING  = 'pending';
	case APPROVED = 'approved';
	case REJECTED = 'rejected';

	/**
	 * Parses a stored value, defaulting to approved (plain WooCommerce customers).
	 *
	 * @param mixed $value Stored value.
	 */
	public static function from_meta( $value ): self {
		return is_string( $value ) ? ( self::tryFrom( $value ) ?? self::APPROVED ) : self::APPROVED;
	}

	/**
	 * Human readable label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::PENDING => __( 'Pending approval', 'eurob2b' ),
			self::APPROVED => __( 'Approved', 'eurob2b' ),
			self::REJECTED => __( 'Rejected', 'eurob2b' ),
		};
	}
}

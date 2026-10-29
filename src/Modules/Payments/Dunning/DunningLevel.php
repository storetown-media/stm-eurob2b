<?php
/**
 * Dunning levels.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments\Dunning;

/**
 * PAY-023: the three levels of the dunning of an overdue business order, in their order. The two
 * dunning letters show the claim of PAY-021 (default interest and flat fee) where it is computed;
 * the payment reminder shows the open amount only.
 */
enum DunningLevel: string {

	case REMINDER = 'reminder';
	case FIRST    = 'first';
	case FINAL    = 'final';

	/**
	 * Position in the sequence (1 to 3).
	 */
	public function rank(): int {
		return match ( $this ) {
			self::REMINDER => 1,
			self::FIRST    => 2,
			self::FINAL    => 3,
		};
	}

	/**
	 * The level that has to be sent before this one, null for the payment reminder.
	 */
	public function previous(): ?self {
		return match ( $this ) {
			self::REMINDER => null,
			self::FIRST    => self::REMINDER,
			self::FINAL    => self::FIRST,
		};
	}

	/**
	 * The level after this one, null after the final dunning letter.
	 */
	public function next(): ?self {
		return match ( $this ) {
			self::REMINDER => self::FIRST,
			self::FIRST    => self::FINAL,
			self::FINAL    => null,
		};
	}

	/**
	 * Whether the e-mail is a dunning letter that states the claim (interest and flat fee).
	 */
	public function is_letter(): bool {
		return self::REMINDER !== $this;
	}

	/**
	 * Key of the e-mail in WooCommerce's list of e-mail classes.
	 */
	public function email_key(): string {
		return match ( $this ) {
			self::REMINDER => 'EuroB2B_Dunning_Reminder',
			self::FIRST    => 'EuroB2B_Dunning_First',
			self::FINAL    => 'EuroB2B_Dunning_Final',
		};
	}

	/**
	 * Name for people to read.
	 */
	public function label(): string {
		return match ( $this ) {
			self::REMINDER => __( 'Payment reminder', 'eurob2b' ),
			self::FIRST    => __( 'First dunning letter', 'eurob2b' ),
			self::FINAL    => __( 'Final dunning letter', 'eurob2b' ),
		};
	}
}

<?php
/**
 * Status of the export evidence of an order.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Export;

/**
 * TAX-032: where the export evidence (Ausfuhrnachweis) of an export order stands. Stored as
 * `_eurob2b_export_status`; derived from the facts (ExportEvidenceState::status()), never set on its
 * own, and it never changes the tax of the order.
 */
enum ExportEvidenceStatus: string {

	case MISSING    = 'missing';
	case RECEIVED   = 'received';
	case ACCEPTED   = 'accepted';
	case NOT_NEEDED = 'not_needed';

	/**
	 * Still without any evidence.
	 */
	public function is_open(): bool {
		return self::MISSING === $this;
	}

	/**
	 * Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::MISSING    => __( 'Missing', 'eurob2b' ),
			self::RECEIVED   => __( 'Received', 'eurob2b' ),
			self::ACCEPTED   => __( 'Accepted', 'eurob2b' ),
			self::NOT_NEEDED => __( 'Not needed', 'eurob2b' ),
		};
	}
}

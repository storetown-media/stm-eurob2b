<?php
/**
 * Types of evidence documents.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Proof;

/**
 * TAX-030: what a document the shop uploads proves (§ 17b (2) and (3) UStDV, country-matrix §13).
 */
enum DocumentType: string {

	case SIGNED_CONFIRMATION = 'signed_confirmation';
	case CONSIGNMENT_NOTE    = 'consignment_note';
	case FORWARDER           = 'forwarder_certificate';
	case COURIER_TRACKING    = 'courier_tracking';
	case OTHER               = 'other';

	/**
	 * Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::SIGNED_CONFIRMATION => __( 'Signed confirmation of receipt', 'stm-eu-b2b' ),
			self::CONSIGNMENT_NOTE    => __( 'Consignment note (CMR)', 'stm-eu-b2b' ),
			self::FORWARDER           => __( 'Forwarder certificate', 'stm-eu-b2b' ),
			self::COURIER_TRACKING    => __( 'Courier tracking record', 'stm-eu-b2b' ),
			self::OTHER               => __( 'Other document', 'stm-eu-b2b' ),
		};
	}
}

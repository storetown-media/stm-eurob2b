<?php
/**
 * Types of export evidence.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Export;

/**
 * TAX-032: what proves the export (country-matrix §18): the electronic exit notice of the customs
 * office (Ausgangsvermerk, ATLAS export procedure, § 9 (1) no. 1 and § 10 (1) no. 1 UStDV), a carrier
 * or courier document (Versendungsbeleg, § 10 (1) no. 2 UStDV) or another document.
 */
enum ExportEvidenceType: string {

	case EXIT_NOTICE      = 'exit_notice';
	case CARRIER_DOCUMENT = 'carrier_document';
	case OTHER            = 'other';

	/**
	 * Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::EXIT_NOTICE      => __( 'Electronic exit notice (Ausgangsvermerk, ATLAS)', 'eurob2b' ),
			self::CARRIER_DOCUMENT => __( 'Carrier or courier document (Versendungsbeleg)', 'eurob2b' ),
			self::OTHER            => __( 'Other evidence', 'eurob2b' ),
		};
	}

	/**
	 * Whether the evidence needs the MRN (the exit notice belongs to an electronic export declaration).
	 */
	public function needs_mrn(): bool {
		return self::EXIT_NOTICE === $this;
	}
}

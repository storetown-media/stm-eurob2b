<?php
/**
 * Status of the proof of intra-Community supply.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Proof;

/**
 * TAX-030: where the proof of an exempt goods order stands. Stored as `_eurob2b_proof_status`; an
 * order without the key is `missing`. The status is derived from the facts (ProofState::status()),
 * never set on its own, and it never changes the tax of the order.
 */
enum ProofStatus: string {

	case MISSING   = 'missing';
	case REQUESTED = 'requested';
	case CONFIRMED = 'confirmed';
	case DOCUMENTS = 'documents';
	case ACCEPTED  = 'accepted';

	/**
	 * Still without any evidence.
	 */
	public function is_open(): bool {
		return self::MISSING === $this || self::REQUESTED === $this;
	}

	/**
	 * Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::MISSING   => __( 'Missing', 'eurob2b' ),
			self::REQUESTED => __( 'Requested', 'eurob2b' ),
			self::CONFIRMED => __( 'Confirmed by the customer', 'eurob2b' ),
			self::DOCUMENTS => __( 'Documents uploaded', 'eurob2b' ),
			self::ACCEPTED  => __( 'Accepted', 'eurob2b' ),
		};
	}
}

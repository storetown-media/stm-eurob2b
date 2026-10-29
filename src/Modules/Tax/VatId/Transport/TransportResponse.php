<?php
/**
 * HTTP response as seen by a validator.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId\Transport;

/**
 * Status code and body; nothing else is needed to interpret a validation service answer.
 */
final class TransportResponse {

	/**
	 * Constructor.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Response body.
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body
	) {
	}
}

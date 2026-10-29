<?php
/**
 * Stored ids and evidence of the checking services besides VIES.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId;

/**
 * The checking services besides VIES (the BZSt, the Swiss UID register and the Brønnøysund
 * register) are part of EuroB2B Pro (PLT-015). Their answers are rows of the validations table like
 * any other, which the core shows, reuses, copies, exports and deletes also while Pro is inactive:
 * this class holds the service ids, the reason codes the core acts on and the format of the stored
 * BZSt evidence. The Pro validators alias these constants. Pure: no WordPress calls.
 */
final class ExternalServices {

	/**
	 * Service id of the BZSt confirmation (TAX-025).
	 */
	public const BZST = 'bzst';

	/**
	 * Service id of the Swiss UID register (CTY-005).
	 */
	public const UID = 'uid_ch';

	/**
	 * Service id of the Brønnøysund register (CTY-005).
	 */
	public const BRREG = 'brreg';

	/**
	 * Prefix of Swiss VAT numbers.
	 */
	public const UID_PREFIX = 'CHE';

	/**
	 * Reason of the Swiss and Norwegian checks: the number exists but is no VAT number.
	 */
	public const REASON_NOT_VAT_REGISTERED = 'not_vat_registered';

	/**
	 * Reason of the Brønnøysund register: the entity was removed for legal reasons (HTTP 410), and the
	 * register asks that copies remove it too.
	 */
	public const REASON_REMOVED = 'removed';

	/**
	 * Company data a stored qualified BZSt answer was compared with, or null for a simple answer.
	 *
	 * @param string|null $evidence Stored response_address of a BZSt record.
	 * @return array{name: string, street: string, postcode: string, city: string}|null
	 */
	public static function bzst_requested( ?string $evidence ): ?array {
		$data = is_string( $evidence ) ? json_decode( $evidence, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['requested'] ) || ! is_array( $data['requested'] ) ) {
			return null;
		}
		$out = array();
		foreach ( array( 'name', 'street', 'postcode', 'city' ) as $field ) {
			$value         = $data['requested'][ $field ] ?? '';
			$out[ $field ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $out;
	}

	/**
	 * Company data in the shape of bzst_requested(), for comparisons.
	 *
	 * @param TraderDetails $trader Company data.
	 * @return array{name: string, street: string, postcode: string, city: string}
	 */
	public static function bzst_details( TraderDetails $trader ): array {
		return array(
			'name'     => $trader->name,
			'street'   => $trader->street,
			'postcode' => $trader->postcode,
			'city'     => $trader->city,
		);
	}

	/**
	 * Comparison codes of a stored qualified BZSt answer, for people ("name A, city B"); '' when the
	 * record holds no qualified answer.
	 *
	 * @param string|null $evidence Stored response_address of a BZSt record.
	 */
	public static function bzst_summary( ?string $evidence ): string {
		$data = is_string( $evidence ) ? json_decode( $evidence, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['result'] ) || ! is_array( $data['result'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( array( 'name', 'street', 'postcode', 'city' ) as $field ) {
			$code = $data['result'][ $field ] ?? '';
			if ( is_string( $code ) && '' !== $code ) {
				$parts[] = $field . ' ' . $code;
			}
		}
		return implode( ', ', $parts );
	}
}

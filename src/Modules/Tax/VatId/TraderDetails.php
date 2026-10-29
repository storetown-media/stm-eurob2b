<?php
/**
 * Company data for a qualified VAT ID confirmation.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId;

/**
 * Name and address a qualified confirmation compares with the member state's records
 * (BZSt: company name including legal form and city are required, postcode and street optional).
 */
final class TraderDetails {

	/**
	 * Constructor.
	 *
	 * @param string $name     Company name including legal form.
	 * @param string $city     City.
	 * @param string $postcode Postcode ('' = not sent).
	 * @param string $street   Street with house number ('' = not sent).
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $city,
		public readonly string $postcode = '',
		public readonly string $street = ''
	) {
	}

	/**
	 * Details from raw values, or null when the name or the city is missing (then only a simple
	 * confirmation is possible).
	 *
	 * @param string $name     Company name.
	 * @param string $city     City.
	 * @param string $postcode Postcode.
	 * @param string $street   Street.
	 */
	public static function from( string $name, string $city, string $postcode = '', string $street = '' ): ?self {
		$name = trim( $name );
		$city = trim( $city );
		if ( '' === $name || '' === $city ) {
			return null;
		}
		return new self( mb_substr( $name, 0, 255 ), mb_substr( $city, 0, 100 ), mb_substr( trim( $postcode ), 0, 20 ), mb_substr( trim( $street ), 0, 255 ) );
	}
}

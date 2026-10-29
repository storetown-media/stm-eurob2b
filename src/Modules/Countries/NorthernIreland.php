<?php
/**
 * Northern Ireland recognition.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Countries;

/**
 * WooCommerce has no country code for Northern Ireland; addresses there are `GB`. Under § 30 UStG
 * and the Windsor Framework goods to Northern Ireland are treated like goods to another member state
 * and services like services to a third country (docs/country-matrix.md NI-01 … NI-05). This class is
 * the one place that recognises Northern Ireland (TAX-034, TAX-032, TAX-033):
 *
 * - an address with the country code `XI` (some shops add it as a country), or `GB` with a postcode of
 *   the BT area (NI-03: the ONS names the Northern Ireland data "postcodes starting with BT"); the
 *   postcode is compared without any whitespace and in upper case, so `bt1 1aa`, `BT1 1AA` and
 *   `BT 1` all match, `BTX` and `B1` do not;
 * - a VAT ID with the prefix `XI` (§ 30 (2) UStG).
 */
final class NorthernIreland {

	/**
	 * VAT prefix of Northern Ireland (not an ISO code).
	 */
	public const PREFIX = CountryRegistry::VIES_NORTHERN_IRELAND;

	/**
	 * ISO country code of addresses in Northern Ireland.
	 */
	public const COUNTRY = 'GB';

	/**
	 * Pattern of a BT postcode after whitespace was removed and the letters upper-cased.
	 */
	public const POSTCODE_PATTERN = '/^BT\d/';

	/**
	 * Whether a postcode belongs to the BT area.
	 *
	 * @param string $postcode Postcode as typed.
	 */
	public static function is_postcode( string $postcode ): bool {
		$code = strtoupper( (string) preg_replace( '/\s+/u', '', $postcode ) );
		return 1 === preg_match( self::POSTCODE_PATTERN, $code );
	}

	/**
	 * Whether an address lies in Northern Ireland: country code XI, or GB with a BT postcode.
	 *
	 * @param string $country  ISO country (or XI).
	 * @param string $postcode Postcode as typed.
	 */
	public static function is_address( string $country, string $postcode ): bool {
		$country = strtoupper( trim( $country ) );
		return self::PREFIX === $country || ( self::COUNTRY === $country && self::is_postcode( $postcode ) );
	}

	/**
	 * Whether the destination of goods lies in Northern Ireland: the shipping address when it has a
	 * country, otherwise the billing address.
	 *
	 * @param string $billing_country   Billing country.
	 * @param string $billing_postcode  Billing postcode.
	 * @param string $shipping_country  Shipping country ('' = none).
	 * @param string $shipping_postcode Shipping postcode.
	 */
	public static function is_destination( string $billing_country, string $billing_postcode, string $shipping_country, string $shipping_postcode ): bool {
		return '' !== trim( $shipping_country )
			? self::is_address( $shipping_country, $shipping_postcode )
			: self::is_address( $billing_country, $billing_postcode );
	}

	/**
	 * Whether a VAT ID (or its country) carries the prefix XI.
	 *
	 * @param string|null $vat_id VAT ID in any spelling, or a VAT ID country.
	 */
	public static function is_vat_id( ?string $vat_id ): bool {
		return null !== $vat_id && str_starts_with( strtoupper( ltrim( $vat_id ) ), self::PREFIX );
	}
}

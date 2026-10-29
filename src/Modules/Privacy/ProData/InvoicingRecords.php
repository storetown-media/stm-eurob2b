<?php
/**
 * Stored e-invoicing data, for the privacy handling.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use WC_Order;

/**
 * The e-invoicing data on customers and orders (D-21) and the result of the Peppol Directory lookup
 * (D-30), read and removed for the privacy export and erasure, WooCommerce's order export and its
 * order anonymisation. EuroB2B Pro collects the data; this part of the core handles it also while
 * Pro is inactive or deleted (PLT-015). Pro uses the same keys: its classes alias the constants
 * below and read the register entry with the helpers below.
 */
final class InvoicingRecords {

	/**
	 * User meta (customer) and order meta (copies at purchase): Leitweg-ID, Peppol ID, the reference
	 * on invoices and the company register entry (JSON, CTY-006).
	 */
	public const META_LEITWEG   = '_eurob2b_leitweg_id';
	public const META_PEPPOL    = '_eurob2b_peppol_id';
	public const META_REFERENCE = '_eurob2b_buyer_reference';
	public const META_REGISTER  = '_eurob2b_company_register';

	/**
	 * User meta: the result of the Peppol Directory lookup (JSON, EIN-006).
	 */
	public const META_LOOKUP = '_eurob2b_peppol_lookup';

	/**
	 * Order meta of the Checkout block field "reference on invoices".
	 */
	public const META_BLOCK_REFERENCE = '_wc_other/eurob2b/buyer-reference';

	/**
	 * Statuses of a stored lookup.
	 */
	public const LOOKUP_FOUND      = 'found';
	public const LOOKUP_NOT_LISTED = 'not_listed';
	public const LOOKUP_FAILED     = 'failed';

	/**
	 * Register types written without the type in front of the number, and the Austrian Firmenbuch.
	 */
	public const REGISTER_OTHER = 'other';
	public const REGISTER_FN    = 'FN';

	/**
	 * Longest name of a lookup result, in characters.
	 */
	public const LOOKUP_MAX_NAME = 200;

	/**
	 * A customer's stored data.
	 *
	 * @param int $user_id User id.
	 * @return array{leitweg_id: string, peppol_id: string, buyer_reference: string, register: array<string, string>|null}
	 */
	public static function get( int $user_id ): array {
		return array(
			'leitweg_id'      => (string) get_user_meta( $user_id, self::META_LEITWEG, true ),
			'peppol_id'       => (string) get_user_meta( $user_id, self::META_PEPPOL, true ),
			'buyer_reference' => (string) get_user_meta( $user_id, self::META_REFERENCE, true ),
			'register'        => self::register_from_meta( get_user_meta( $user_id, self::META_REGISTER, true ) ),
		);
	}

	/**
	 * Deletes a customer's data with the lookup result.
	 *
	 * @param int $user_id User id.
	 */
	public static function delete( int $user_id ): void {
		foreach ( array( self::META_REGISTER, self::META_LEITWEG, self::META_PEPPOL, self::META_REFERENCE, self::META_LOOKUP ) as $key ) {
			delete_user_meta( $user_id, $key );
		}
	}

	/**
	 * A stored register entry (JSON), or null.
	 *
	 * @param mixed $raw Stored value.
	 * @return array<string, string>|null
	 */
	public static function register_from_meta( $raw ): ?array {
		$entry = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $entry ) || ! is_string( $entry['number'] ?? null ) || '' === $entry['number'] ) {
			return null;
		}
		$out = array();
		foreach ( array( 'type', 'number', 'court', 'country' ) as $key ) {
			$out[ $key ] = is_scalar( $entry[ $key ] ?? null ) ? (string) $entry[ $key ] : '';
		}
		return $out;
	}

	/**
	 * A register entry as one line: "HRB 12345, Amtsgericht Hamburg", "FN 100233 i", or the number of
	 * another register.
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	public static function register_display( array $entry ): string {
		$type   = (string) ( $entry['type'] ?? '' );
		$number = (string) ( $entry['number'] ?? '' );
		$court  = (string) ( $entry['court'] ?? '' );
		if ( '' === $number ) {
			return '';
		}
		if ( self::REGISTER_FN === $type ) {
			// Austrian notation: "FN 100233 i".
			$number = (string) preg_replace( '/^(\d+)([a-z])$/', '$1 $2', $number );
		}
		$text = self::REGISTER_OTHER === $type ? $number : $type . ' ' . $number;
		return '' !== $court ? $text . ', ' . $court : $text;
	}

	/**
	 * Normalised Peppol ID: without spaces and without the scheme prefix of the full form.
	 *
	 * @param string $value Input.
	 */
	public static function peppol_normalise( string $value ): string {
		$value = (string) preg_replace( '/\s+/', '', $value );
		if ( 0 === stripos( $value, 'iso6523-actorid-upis::' ) ) {
			$value = substr( $value, strlen( 'iso6523-actorid-upis::' ) );
		}
		return $value;
	}

	/**
	 * Whether a value has the form of a Peppol ID, "<four-digit scheme>:<identifier>".
	 *
	 * @param string $value Input (normalised or not).
	 */
	public static function peppol_is_valid( string $value ): bool {
		return 1 === preg_match( '/^\d{4}:[A-Za-z0-9][A-Za-z0-9._\-]{0,99}$/', self::peppol_normalise( $value ) );
	}

	/**
	 * Plain text of a name from the Peppol Directory: no tags, no control characters, single spaces,
	 * at most LOOKUP_MAX_NAME characters.
	 *
	 * @param string $name Name.
	 */
	public static function clean_lookup_name( string $name ): string {
		// Like wp_strip_all_tags(), without WordPress, so the parser stays unit-testable.
		$name = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $name );
		$name = strip_tags( $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- see above.
		$name = (string) preg_replace( '/[\x00-\x1F\x7F\s]+/u', ' ', $name );
		return trim( mb_substr( trim( $name ), 0, self::LOOKUP_MAX_NAME ) );
	}

	/**
	 * The stored lookup result of a customer, or null when missing or malformed.
	 *
	 * @param int $user_id User id.
	 * @return array{status: string, peppol_id: string, checked_at: int, name: string, country: string}|null
	 */
	public static function lookup( int $user_id ): ?array {
		$raw  = get_user_meta( $user_id, self::META_LOOKUP, true );
		$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return null;
		}
		$status = $data['status'] ?? null;
		$id     = $data['id'] ?? null;
		$time   = $data['checked_at'] ?? null;
		if ( ! in_array( $status, array( self::LOOKUP_FOUND, self::LOOKUP_NOT_LISTED, self::LOOKUP_FAILED ), true ) || ! is_string( $id ) || ! self::peppol_is_valid( $id ) || ! is_int( $time ) || $time <= 0 ) {
			return null;
		}
		$found   = self::LOOKUP_FOUND === $status;
		$name    = is_string( $data['name'] ?? null ) ? self::clean_lookup_name( $data['name'] ) : '';
		$country = is_string( $data['country'] ?? null ) && 1 === preg_match( '/^[A-Z]{2}$/', $data['country'] ) ? $data['country'] : '';
		return array(
			'status'     => $status,
			'peppol_id'  => self::peppol_normalise( $id ),
			'checked_at' => $time,
			'name'       => $found ? $name : '',
			'country'    => $found ? $country : '',
		);
	}

	/**
	 * Labels of the order meta (the shop's wording).
	 *
	 * @return array<string, string>
	 */
	private static function order_labels(): array {
		return array(
			self::META_REFERENCE => __( 'Buyer reference', 'eurob2b' ),
			self::META_LEITWEG   => __( 'Leitweg-ID', 'eurob2b' ),
			self::META_PEPPOL    => __( 'Peppol ID', 'eurob2b' ),
			self::META_REGISTER  => __( 'Company register', 'eurob2b' ),
		);
	}

	/**
	 * An order meta value as shown (the register entry as one line).
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Meta key.
	 */
	private static function order_value( WC_Order $order, string $key ): string {
		if ( self::META_REGISTER === $key ) {
			$entry = self::register_from_meta( $order->get_meta( $key, true ) );
			return null !== $entry ? self::register_display( $entry ) : '';
		}
		return (string) $order->get_meta( $key, true );
	}

	/**
	 * WooCommerce's personal data export of orders: the copies are listed with the other order data
	 * (WooCommerce exports only the order properties it is told about).
	 *
	 * @param mixed $props Property => label.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function export_props( $props, $order ) {
		if ( ! is_array( $props ) || ! $order instanceof WC_Order ) {
			return $props;
		}
		foreach ( self::order_labels() as $key => $label ) {
			if ( '' !== (string) $order->get_meta( $key, true ) ) {
				$props[ 'eurob2b' . $key ] = $label;
			}
		}
		return $props;
	}

	/**
	 * Value of one exported property (see export_props()).
	 *
	 * @param mixed $value Value so far.
	 * @param mixed $prop  Property.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function export_prop( $value, $prop, $order ) {
		if ( ! $order instanceof WC_Order || ! is_string( $prop ) || 0 !== strpos( $prop, 'eurob2b_eurob2b_' ) ) {
			return $value;
		}
		$key = substr( $prop, strlen( 'eurob2b' ) );
		return array_key_exists( $key, self::order_labels() ) ? self::order_value( $order, $key ) : $value;
	}

	/**
	 * WooCommerce order anonymisation: the copies go with the other customer data.
	 *
	 * @param mixed $order Order.
	 */
	public static function anonymise_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$changed = false;
		foreach ( array( self::META_REFERENCE, self::META_LEITWEG, self::META_PEPPOL, self::META_REGISTER, self::META_BLOCK_REFERENCE ) as $key ) {
			if ( $order->meta_exists( $key ) ) {
				$order->delete_meta_data( $key );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->save();
		}
	}
}

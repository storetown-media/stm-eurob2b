<?php
/**
 * Stored dunning history of orders: key, reading, privacy.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments\Dunning;

use WC_Order;

/**
 * The data model of the dunning of PAY-023 (D-28) as the core keeps it: the order meta
 * `_eurob2b_dunning` with the history (see DunningHistory), its lines for people to read, and its
 * place in WooCommerce's personal data export of an order and in WooCommerce's order anonymisation.
 * Sending the payment reminder and the dunning letters is part of EuroB2B Pro (`DunningService`,
 * PLT-015); the export and the anonymisation are part of the core and use this class, so a history
 * written with EuroB2B Pro is exported and removed also while Pro is inactive (product owner
 * decision of 2026-10-29). All order access through the CRUD API (HPOS-safe).
 */
final class DunningRecords {

	/**
	 * Order meta with the history (JSON, see DunningHistory).
	 */
	public const META_HISTORY = '_eurob2b_dunning';

	/**
	 * Key of the property in WooCommerce's personal data export of an order.
	 */
	public const EXPORT_PROP = 'eurob2b_dunning';

	/**
	 * Hooks: WooCommerce's order export and anonymisation.
	 */
	public static function hooks(): void {
		add_filter( 'woocommerce_privacy_export_order_personal_data_props', array( self::class, 'export_props' ), 10, 2 );
		add_filter( 'woocommerce_privacy_export_order_personal_data_prop', array( self::class, 'export_prop' ), 10, 3 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( self::class, 'anonymise_order' ) );
	}

	/**
	 * History of an order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function history( WC_Order $order ): DunningHistory {
		return DunningHistory::from_json( (string) $order->get_meta( self::META_HISTORY, true ) );
	}

	/**
	 * Order meta keys of the dunning.
	 *
	 * @return string[]
	 */
	public static function meta_keys(): array {
		return array( self::META_HISTORY );
	}

	/**
	 * Lines of the history for people to read: "Payment reminder · 28.10.2026 · sent · Jane".
	 *
	 * @param DunningHistory $history   History.
	 * @param bool           $with_user With the sending staff member (not in the customer's export).
	 * @return string[]
	 */
	public static function history_lines( DunningHistory $history, bool $with_user = true ): array {
		$lines = array();
		foreach ( $history->entries() as $entry ) {
			$level = DunningLevel::tryFrom( $entry['level'] );
			$parts = array(
				null !== $level ? $level->label() : $entry['level'],
				self::day( $entry['date'] ),
				DunningHistory::RESULT_SENT === $entry['result'] ? __( 'sent', 'eurob2b' ) : __( 'not sent (mail error)', 'eurob2b' ),
			);
			if ( $with_user && $entry['by'] > 0 ) {
				$user    = get_userdata( $entry['by'] );
				$parts[] = false !== $user ? (string) $user->display_name : '#' . $entry['by'];
			}
			$lines[] = implode( ' · ', $parts );
		}
		return $lines;
	}

	/**
	 * A stored Y-m-d day with the site's date format.
	 *
	 * @param string $ymd Day.
	 */
	private static function day( string $ymd ): string {
		$timestamp = strtotime( $ymd . ' 12:00:00' );
		return false === $timestamp ? $ymd : date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp );
	}

	/**
	 * WooCommerce's personal data export of an order: the dunning history (level, day, result;
	 * without the staff member).
	 *
	 * @param mixed $props Properties (key → label).
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function export_props( $props, $order ) {
		if ( is_array( $props ) && $order instanceof WC_Order && ! self::history( $order )->is_empty() ) {
			$props[ self::EXPORT_PROP ] = __( 'Dunning', 'eurob2b' );
		}
		return $props;
	}

	/**
	 * Value of the exported property (see export_props()).
	 *
	 * @param mixed $value Value so far.
	 * @param mixed $prop  Property.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function export_prop( $value, $prop, $order ) {
		if ( self::EXPORT_PROP !== $prop || ! $order instanceof WC_Order ) {
			return $value;
		}
		return implode( '; ', self::history_lines( self::history( $order ), false ) );
	}

	/**
	 * WooCommerce order anonymisation: the history goes with the other customer data of the order,
	 * like the other EuroB2B additions to orders (D-05).
	 *
	 * @param mixed $order Order.
	 */
	public static function anonymise_order( $order ): void {
		if ( $order instanceof WC_Order && $order->meta_exists( self::META_HISTORY ) ) {
			$order->delete_meta_data( self::META_HISTORY );
			$order->save();
		}
	}
}

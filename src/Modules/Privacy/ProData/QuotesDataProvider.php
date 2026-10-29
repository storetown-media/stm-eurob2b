<?php
/**
 * Privacy provider for quotes and offers.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;
use EuroB2B\Support\Money;

/**
 * Exports a customer's requests and offers and the requests sent from the e-mail address without a
 * customer account (D-06) and, on erasure, deletes those no order references and anonymises the
 * rest (the lines stay as the commercial record of the order). Part of the core: it reads the shared
 * tables, also while EuroB2B Pro, which records the quotes, is inactive (PLT-015).
 */
final class QuotesDataProvider implements PersonalDataProviderInterface {

	/**
	 * Stored quotes.
	 *
	 * @var QuoteRecords
	 */
	private QuoteRecords $records;

	/**
	 * Constructor.
	 *
	 * @param QuoteRecords $records Stored quotes.
	 */
	public function __construct( QuoteRecords $records ) {
		$this->records = $records;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'quotes';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B quotes and offers', 'eurob2b' );
	}

	/**
	 * Export callback: one item per quote.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user   = get_user_by( 'email', $email_address );
		$data   = array();
		$quotes = $this->records->for_guest_email( $email_address );
		if ( $user ) {
			$quotes = array_merge( $this->records->for_user( $user->ID ), $quotes );
		}
		foreach ( $quotes as $quote ) {
			$offer    = QuoteRecords::TYPE_OFFER === (string) $quote['type'];
			$currency = (string) $quote['currency'];
			$lines    = array();
			foreach ( $this->records->items( (int) $quote['id'] ) as $item ) {
				$price   = isset( $item['unit_price_minor'] ) ? (int) $item['unit_price_minor'] : null;
				$lines[] = (int) $item['quantity'] . ' × ' . (string) $item['description'] . ( null !== $price ? ' @ ' . self::price_text( $price, $currency ) : '' );
			}
			$fields = array(
				array(
					'name'  => __( 'Type', 'eurob2b' ),
					'value' => $offer ? __( 'Offer', 'eurob2b' ) : __( 'Request', 'eurob2b' ),
				),
				array(
					'name'  => __( 'Subject', 'eurob2b' ),
					'value' => (string) $quote['title'],
				),
				array(
					'name'  => __( 'Status', 'eurob2b' ),
					'value' => QuoteRecords::status_label( $quote ),
				),
				array(
					'name'  => __( 'Created', 'eurob2b' ),
					'value' => (string) $quote['created_at'],
				),
				array(
					'name'  => __( 'Lines', 'eurob2b' ),
					'value' => implode( "\n", $lines ),
				),
			);
			if ( QuoteRecords::is_guest( $quote ) ) {
				$fields[] = array(
					'name'  => __( 'Name', 'eurob2b' ),
					'value' => (string) ( $quote['guest_name'] ?? '' ),
				);
				$fields[] = array(
					'name'  => __( 'E-mail address', 'eurob2b' ),
					'value' => (string) ( $quote['guest_email'] ?? '' ),
				);
				if ( '' !== (string) ( $quote['guest_company'] ?? '' ) ) {
					$fields[] = array(
						'name'  => __( 'Company', 'eurob2b' ),
						'value' => (string) $quote['guest_company'],
					);
				}
			}
			if ( '' !== (string) ( $quote['message'] ?? '' ) ) {
				$fields[] = array(
					'name'  => __( 'Message', 'eurob2b' ),
					'value' => (string) $quote['message'],
				);
			}
			foreach ( QuoteRecords::decode_fields( $quote['field_values'] ?? null ) as $entry ) {
				$fields[] = array(
					'name'  => $entry['label'],
					'value' => QuoteRecords::field_display( $entry ),
				);
			}
			if ( '' !== (string) ( $quote['note'] ?? '' ) ) {
				$fields[] = array(
					'name'  => $offer ? __( 'Text for the customer', 'eurob2b' ) : __( 'Our reply', 'eurob2b' ),
					'value' => (string) $quote['note'],
				);
			}
			if ( (int) $quote['accepted_order_id'] > 0 ) {
				$fields[] = array(
					'name'  => _x( 'Order', 'shop order', 'eurob2b' ),
					'value' => (string) (int) $quote['accepted_order_id'],
				);
			}
			$data[] = array(
				'group_id'    => 'eurob2b-quotes',
				'group_label' => $this->label(),
				'item_id'     => 'eurob2b-quote-' . (int) $quote['id'],
				'data'        => $fields,
			);
		}//end foreach
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Price as plain text in the shop's number format with the currency symbol (the export is
	 * written as HTML and JSON, so no markup and no entities).
	 *
	 * @param int    $minor    Amount in minor units (shop decimals).
	 * @param string $currency Currency code.
	 */
	private static function price_text( int $minor, string $currency ): string {
		$decimal = Money::of_minor( $minor, get_woocommerce_currency(), wc_get_price_decimals() )->to_decimal();
		$html    = wc_price( (float) $decimal, array( 'currency' => $currency ) );
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Erase callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user               = get_user_by( 'email', $email_address );
		$result             = $user ? $this->records->erase_user( $user->ID ) : array(
			'deleted'    => 0,
			'anonymised' => 0,
		);
		$result['deleted'] += $this->records->erase_guest( $email_address );
		$messages           = array();
		if ( $result['anonymised'] > 0 ) {
			/* translators: %d: number of quotes */
			$messages[] = sprintf( __( '%d quote(s) referenced by orders were anonymised and kept as commercial records.', 'eurob2b' ), $result['anonymised'] );
		}
		return array(
			'items_removed'  => $result['deleted'] > 0,
			'items_retained' => $result['anonymised'] > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}

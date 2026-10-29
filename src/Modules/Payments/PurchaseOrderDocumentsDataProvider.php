<?php
/**
 * Privacy export and erasure for purchase order documents.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;
use WC_Order;

/**
 * D-25: the export lists, per order of the person (customer id or billing e-mail address), the
 * purchase order documents with name, type, size, upload time and source. An erasure request deletes
 * nothing while the order is kept: the documents go with the order (its deletion or WooCommerce's
 * anonymisation), so they are reported as retained, like other order evidence. Part of the core: it
 * runs also while EuroB2B Pro, which takes the uploads, is inactive (PLT-015).
 */
final class PurchaseOrderDocumentsDataProvider implements PersonalDataProviderInterface {

	/**
	 * Orders per page.
	 */
	private const PER_PAGE = 50;

	/**
	 * Documents.
	 *
	 * @var PurchaseOrderDocumentRecords
	 */
	private PurchaseOrderDocumentRecords $documents;

	/**
	 * Constructor.
	 *
	 * @param PurchaseOrderDocumentRecords $documents Stored documents.
	 */
	public function __construct( PurchaseOrderDocumentRecords $documents ) {
		$this->documents = $documents;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'purchase_order_documents';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B purchase order documents', 'eurob2b' );
	}

	/**
	 * Export.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$orders = $this->orders( $email_address, $page );
		$groups = array();
		foreach ( $orders as $order ) {
			$items = array();
			foreach ( $this->documents->documents( $order ) as $document ) {
				$items[] = array(
					'name'  => __( 'Purchase order document', 'eurob2b' ),
					'value' => $document['file']['original_name'] . ' (' . $document['file']['mime'] . ', ' . size_format( $document['file']['size'] ) . '), ' . $document['at'] . ' UTC, ' . PurchaseOrderDocumentRecords::source_label( $document['source'] ),
				);
			}
			if ( array() === $items ) {
				continue;
			}
			array_unshift(
				$items,
				array(
					'name'  => _x( 'Order', 'shop order', 'eurob2b' ),
					'value' => $order->get_order_number(),
				)
			);
			$groups[] = array(
				'group_id'    => 'eurob2b-po-documents',
				'group_label' => __( 'Purchase order documents', 'eurob2b' ),
				'item_id'     => 'eurob2b-po-documents-' . $order->get_id(),
				'data'        => $items,
			);
		}//end foreach
		return array(
			'data' => $groups,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * Erasure: nothing is deleted while the order is kept; the documents are reported as retained.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$orders   = $this->orders( $email_address, $page );
		$retained = 0;
		foreach ( $orders as $order ) {
			if ( array() !== $this->documents->documents( $order ) ) {
				++$retained;
			}
		}
		$messages = array();
		if ( $retained > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of orders */
				_n( 'The purchase order documents of %d order were kept with the order; they are deleted with the order or when WooCommerce anonymises it.', 'The purchase order documents of %d orders were kept with the orders; they are deleted with the order or when WooCommerce anonymises it.', $retained, 'eurob2b' ),
				$retained
			);
		}
		return array(
			'items_removed'  => false,
			'items_retained' => $retained > 0,
			'messages'       => $messages,
			'done'           => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * Orders of the person (customer id and billing e-mail address), one page, oldest first.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page (1-based).
	 * @return WC_Order[]
	 */
	private function orders( string $email, int $page ): array {
		if ( ! function_exists( 'wc_get_orders' ) || '' === $email ) {
			return array();
		}
		$user     = get_user_by( 'email', $email );
		$customer = array_values( array_filter( array( $email, $user ? (int) $user->ID : null ) ) );
		$orders   = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'customer' => $customer,
				'limit'    => self::PER_PAGE,
				'page'     => max( 1, $page ),
				'orderby'  => 'date',
				'order'    => 'ASC',
			)
		);
		return array_values( array_filter( is_array( $orders ) ? $orders : array(), static fn( $o ): bool => $o instanceof WC_Order ) );
	}
}

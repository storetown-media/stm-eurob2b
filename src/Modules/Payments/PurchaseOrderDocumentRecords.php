<?php
/**
 * Stored purchase order documents: keys, reading, deletion.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments;

use DateTimeImmutable;
use EuroB2B\Modules\Privacy\PrivateFiles;
use WC_Order;

/**
 * The data model of the purchase order documents (PAY-015, D-25) as the core keeps it: the order
 * meta `_eurob2b_po_documents` (JSON list of file id, uploading user, source and time), the files in
 * the private file storage (context `purchase_order`, owner 0 = the shop, key = order id; pending
 * checkout uploads in context `purchase_order_pending`) and their deletion with the order. The
 * uploads themselves (checkout, My Account, order screen, the setting) are part of EuroB2B Pro
 * (`PurchaseOrderDocuments`, PLT-015); the privacy export, the erasure answer, the retention task and
 * the deletion with the order or WooCommerce's anonymisation are part of the core and use this class,
 * so documents handed in with EuroB2B Pro are exported and deleted also while Pro is inactive
 * (product owner decision of 2026-10-29). All order access through the CRUD API (HPOS-safe).
 */
final class PurchaseOrderDocumentRecords {

	public const META_DOCUMENTS = '_eurob2b_po_documents';

	public const SOURCE_CHECKOUT = 'checkout';
	public const SOURCE_ACCOUNT  = 'account';
	public const SOURCE_ADMIN    = 'admin';

	/**
	 * Pending uploads older than this are removed by the retention task.
	 */
	public const PENDING_LIFETIME = DAY_IN_SECONDS;

	/**
	 * Private files.
	 *
	 * @var PrivateFiles
	 */
	private PrivateFiles $files;

	/**
	 * Constructor.
	 *
	 * @param PrivateFiles $files Private files.
	 */
	public function __construct( PrivateFiles $files ) {
		$this->files = $files;
	}

	/**
	 * Hooks: the documents go with the order.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_delete_order', array( $this, 'on_delete_order' ) );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( $this, 'on_anonymise_order' ) );
	}

	/**
	 * Stored entries (the file may be gone).
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{file_id: int, by: int, source: string, at: string}>
	 */
	public static function entries( WC_Order $order ): array {
		$raw     = json_decode( (string) $order->get_meta( self::META_DOCUMENTS, true ), true );
		$entries = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['file_id'] ) && (int) $entry['file_id'] > 0 ) {
				$entries[] = array(
					'file_id' => (int) $entry['file_id'],
					'by'      => (int) ( $entry['by'] ?? 0 ),
					'source'  => (string) ( $entry['source'] ?? '' ),
					'at'      => (string) ( $entry['at'] ?? '' ),
				);
			}
		}
		return $entries;
	}

	/**
	 * Document entries of the order whose file still exists, oldest first, each with its file record.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{file_id: int, by: int, source: string, at: string, file: array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}}>
	 */
	public function documents( WC_Order $order ): array {
		$out = array();
		foreach ( self::entries( $order ) as $entry ) {
			$file = $this->file( $order, $entry['file_id'] );
			if ( null !== $file ) {
				$entry['file'] = $file;
				$out[]         = $entry;
			}
		}
		return $out;
	}

	/**
	 * The record of a document of the order, or null when the id names another file.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $file_id File id.
	 * @return array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}|null
	 */
	public function file( WC_Order $order, int $file_id ): ?array {
		$file = $file_id > 0 ? $this->files->find( $file_id ) : null;
		if ( null === $file || PrivateFiles::CONTEXT_PURCHASE_ORDER !== $file['context'] || (string) $order->get_id() !== $file['field_key'] ) {
			return null;
		}
		return $file;
	}

	/**
	 * Label of an upload source.
	 *
	 * @param string $source Source.
	 */
	public static function source_label( string $source ): string {
		return match ( $source ) {
			self::SOURCE_CHECKOUT => __( 'uploaded at checkout', 'eurob2b' ),
			self::SOURCE_ACCOUNT  => __( 'uploaded by the customer in My Account', 'eurob2b' ),
			default               => __( 'uploaded by shop staff', 'eurob2b' ),
		};
	}

	/**
	 * Deletes the documents of an order (files and list). Returns the number of files.
	 *
	 * @param int $order_id Order id.
	 */
	public function delete_for_order( int $order_id ): int {
		$removed = 0;
		foreach ( $this->files->for_key( PrivateFiles::CONTEXT_PURCHASE_ORDER, (string) $order_id ) as $file ) {
			$this->files->delete( $file['id'] );
			++$removed;
		}
		return $removed;
	}

	/**
	 * A permanently deleted order takes its documents with it.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function on_delete_order( $order_id ): void {
		if ( is_numeric( $order_id ) && (int) $order_id > 0 ) {
			$this->delete_for_order( (int) $order_id );
		}
	}

	/**
	 * WooCommerce anonymises the order (its order retention or erasure setting): the documents go too.
	 * WooCommerce fires the action after its own save, so the order is saved here.
	 *
	 * @param mixed $order Order.
	 */
	public function on_anonymise_order( $order ): void {
		if ( ! $order instanceof WC_Order || ( array() === self::entries( $order ) && array() === $this->files->for_key( PrivateFiles::CONTEXT_PURCHASE_ORDER, (string) $order->get_id() ) ) ) {
			return;
		}
		$this->delete_for_order( (int) $order->get_id() );
		$order->delete_meta_data( self::META_DOCUMENTS );
		$order->save();
	}

	/**
	 * Retention: removes pending uploads older than a day and documents whose order no longer exists.
	 * Returns the number of files removed.
	 *
	 * @param DateTimeImmutable $now   Now (UTC).
	 * @param int               $limit At most this many.
	 */
	public function sweep( DateTimeImmutable $now, int $limit ): int {
		$removed = 0;
		$before  = $now->modify( '-' . self::PENDING_LIFETIME . ' seconds' )->format( 'Y-m-d H:i:s' );
		foreach ( $this->files->created_before( PrivateFiles::CONTEXT_PURCHASE_ORDER_PENDING, $before, $limit ) as $file ) {
			$this->files->delete( $file['id'] );
			++$removed;
		}
		foreach ( $this->files->field_keys( PrivateFiles::CONTEXT_PURCHASE_ORDER ) as $key ) {
			if ( $removed >= $limit ) {
				break;
			}
			if ( wc_get_order( (int) $key ) instanceof WC_Order ) {
				continue;
			}
			$removed += $this->delete_for_order( (int) $key );
		}
		return $removed;
	}
}

<?php
/**
 * Retention of purchase order documents.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments;

use DateTimeImmutable;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * D-25: purchase order documents have no period of their own; they go with the order. The daily task
 * removes pending checkout uploads older than a day and the documents of orders that no longer exist
 * (deleted without WooCommerce's delete hook, e.g. directly in the database). Part of the core: it
 * runs also while EuroB2B Pro, which takes the uploads, is inactive (PLT-015).
 */
final class PurchaseOrderDocumentsRetention implements RetentionTaskInterface {

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
	 * Runs the sweep.
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of files.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		$removed = function_exists( 'wc_get_order' ) ? $this->documents->sweep( $now, $limit ) : 0;
		return array(
			'removed' => $removed,
			'skipped' => 0,
			'more'    => $removed >= $limit,
		);
	}
}

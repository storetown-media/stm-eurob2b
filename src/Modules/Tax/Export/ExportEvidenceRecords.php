<?php
/**
 * Stored export evidence: keys, queries, deletion.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Export;

use DateTimeImmutable;
use EuroB2B\Modules\Privacy\PrivateFiles;
use EuroB2B\Modules\Tax\Proof\ProofRecords;
use WC_Order;

/**
 * The data model of the export evidence (TAX-032, D-29) as the core keeps it: the order meta keys,
 * the scope and event values, the reading of an evidence, the queries by person and by retention end,
 * and the deletion with its files. Recording the evidence is part of EuroB2B Pro (`ExportEvidence`,
 * PLT-015); the privacy export, the erasure answer, the retention task (`ExportEvidenceDataProvider`,
 * `ExportEvidenceRetention`) and the audit log labels are part of the core and use this class, so
 * data written with EuroB2B Pro is exported and deleted also while Pro is inactive (product owner
 * decision of 2026-10-29). All order access through the CRUD API (HPOS-safe).
 */
final class ExportEvidenceRecords {

	public const META_SCOPE        = '_eurob2b_export_scope';
	public const META_STATUS       = '_eurob2b_export_status';
	public const META_DETAILS      = '_eurob2b_export_details';
	public const META_DOCUMENTS    = '_eurob2b_export_documents';
	public const META_ACCEPTED_AT  = '_eurob2b_export_accepted_at';
	public const META_ACCEPTED_BY  = '_eurob2b_export_accepted_by';
	public const META_NOT_NEEDED   = '_eurob2b_export_not_needed';
	public const META_RETAIN_UNTIL = '_eurob2b_export_retain_until';

	public const SCOPE_GOODS   = 'goods';
	public const SCOPE_NONE    = 'none';
	public const SCOPE_EXPIRED = 'expired';

	/**
	 * Stored treatments that are exports of goods to a third country: the values of TaxTreatment::EXPORT
	 * and TaxTreatment::SWISS_AREA (literals, because PHP 8.1 allows no enum value in a constant
	 * expression; ExportEvidenceStateTest compares them).
	 */
	public const TREATMENTS = array( 'export', 'swiss_area' );

	/**
	 * Events passed to `eurob2b_export_evidence_changed` (and stored in the audit log).
	 */
	public const EVENT_DETAILS    = 'details_saved';
	public const EVENT_DOCUMENT   = 'document_added';
	public const EVENT_ACCEPTED   = 'accepted';
	public const EVENT_NOT_NEEDED = 'not_needed';
	public const EVENT_REOPENED   = 'reopened';

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
	 * Meta keys with evidence (the scope is not one: it holds no evidence).
	 *
	 * @return string[]
	 */
	public static function meta_keys(): array {
		return array( self::META_STATUS, self::META_DETAILS, self::META_DOCUMENTS, self::META_ACCEPTED_AT, self::META_ACCEPTED_BY, self::META_NOT_NEEDED, self::META_RETAIN_UNTIL );
	}

	/**
	 * Facts of the evidence.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function state( WC_Order $order ): ExportEvidenceState {
		return ExportEvidenceState::from_meta(
			(string) $order->get_meta( self::META_DETAILS, true ),
			(string) $order->get_meta( self::META_DOCUMENTS, true ),
			(string) $order->get_meta( self::META_ACCEPTED_AT, true ),
			(string) $order->get_meta( self::META_NOT_NEEDED, true )
		);
	}

	/**
	 * The record of a document of the order, or null when the file id names another file.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $file_id File id.
	 * @return array{id: int, owner_id: int, context: string, field_key: string, original_name: string, mime: string, size: int, stored_name: string, created_at: string}|null
	 */
	public function document_file( WC_Order $order, int $file_id ): ?array {
		$file = $file_id > 0 ? $this->files->find( $file_id ) : null;
		if ( null === $file || PrivateFiles::CONTEXT_EXPORT !== $file['context'] || (string) $order->get_id() !== $file['field_key'] ) {
			return null;
		}
		return $file;
	}

	/**
	 * Removes the evidence of an order (retention): files and meta; the scope becomes `expired`, so the
	 * order is not listed or scanned again. Returns the number of files.
	 *
	 * @param WC_Order $order Order.
	 */
	public function delete( WC_Order $order ): int {
		$removed = 0;
		foreach ( $this->files->for_key( PrivateFiles::CONTEXT_EXPORT, (string) $order->get_id() ) as $file ) {
			$this->files->delete( $file['id'] );
			++$removed;
		}
		foreach ( self::meta_keys() as $key ) {
			$order->delete_meta_data( $key );
		}
		$order->update_meta_data( self::META_SCOPE, self::SCOPE_EXPIRED );
		$order->save();
		$order->add_order_note( __( 'Export evidence: details and documents were deleted at the end of the retention period.', 'eurob2b' ) );
		return $removed;
	}

	/**
	 * Deletes files of orders that no longer exist. Returns the number of files.
	 *
	 * @param int $limit At most this many.
	 */
	public function sweep_files_without_order( int $limit ): int {
		return ProofRecords::sweep( $this->files, PrivateFiles::CONTEXT_EXPORT, $limit );
	}

	/**
	 * Orders of a person with export evidence entries (privacy export and erasure): by customer id and
	 * billing e-mail address.
	 *
	 * @param string $email   E-mail address.
	 * @param int    $user_id User or 0.
	 * @param int    $page    Page (1-based).
	 * @param int    $limit   Orders per page.
	 * @return WC_Order[]
	 */
	public function for_person( string $email, int $user_id, int $page, int $limit ): array {
		return ProofRecords::orders_of_person( self::META_STATUS, $email, $user_id, $page, $limit );
	}

	/**
	 * Orders whose evidence retention ended before $now.
	 *
	 * @param DateTimeImmutable $now   Now (UTC).
	 * @param int               $limit At most this many.
	 * @return WC_Order[]
	 */
	public function expired( DateTimeImmutable $now, int $limit ): array {
		return ProofRecords::orders_expired( self::META_RETAIN_UNTIL, $now, $limit );
	}
}

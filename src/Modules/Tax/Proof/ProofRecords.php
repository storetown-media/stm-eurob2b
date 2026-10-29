<?php
/**
 * Stored proofs of supply: keys, queries, deletion.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Proof;

use DateTimeImmutable;
use EuroB2B\Modules\Privacy\PrivateFiles;
use EuroB2B\Support\Hpos;
use WC_Order;

/**
 * The data model of the proof of intra-Community supply (TAX-030, D-23) as the core keeps it: the
 * order meta keys, the reading of a proof, the queries by person and by retention end, and the
 * deletion with its files. The proof itself (requests, confirmations, uploads, acceptance) is part
 * of EuroB2B Pro (`ProofOfSupply`, PLT-015); the privacy export, the erasure answer and the retention
 * task (`ProofDataProvider`, `ProofRetention`) are part of the core and use this class, so data
 * written with EuroB2B Pro is exported and deleted also while Pro is inactive (product owner
 * decision of 2026-10-29). All order access through the CRUD API (HPOS-safe).
 */
final class ProofRecords {

	public const META_STATUS       = '_eurob2b_proof_status';
	public const META_REQUESTED_AT = '_eurob2b_proof_requested_at';
	public const META_CONFIRMATION = '_eurob2b_proof_confirmation';
	public const META_CONFIRMED_AT = '_eurob2b_proof_confirmed_at';
	public const META_DOCUMENTS    = '_eurob2b_proof_documents';
	public const META_ACCEPTED_AT  = '_eurob2b_proof_accepted_at';
	public const META_ACCEPTED_BY  = '_eurob2b_proof_accepted_by';
	public const META_RETAIN_UNTIL = '_eurob2b_proof_retain_until';

	/**
	 * Variable of wc_get_orders() that the posts store turns into a meta query (see cpt_meta_query()).
	 */
	public const QUERY_VAR = 'eurob2b_proof_meta_query';

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
	 * Hooks: the meta queries of the posts store (also used by the export evidence and EuroB2B Pro).
	 */
	public function hooks(): void {
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'cpt_meta_query' ), 10, 2 );
	}

	/**
	 * Meta keys of the proof.
	 *
	 * @return string[]
	 */
	public static function meta_keys(): array {
		return array( self::META_STATUS, self::META_REQUESTED_AT, self::META_CONFIRMATION, self::META_CONFIRMED_AT, self::META_DOCUMENTS, self::META_ACCEPTED_AT, self::META_ACCEPTED_BY, self::META_RETAIN_UNTIL );
	}

	/**
	 * Facts of the proof.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function state( WC_Order $order ): ProofState {
		return ProofState::from_meta(
			(string) $order->get_meta( self::META_REQUESTED_AT, true ),
			(string) $order->get_meta( self::META_CONFIRMATION, true ),
			(string) $order->get_meta( self::META_DOCUMENTS, true ),
			(string) $order->get_meta( self::META_ACCEPTED_AT, true )
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
		if ( null === $file || PrivateFiles::CONTEXT_PROOF !== $file['context'] || (string) $order->get_id() !== $file['field_key'] ) {
			return null;
		}
		return $file;
	}

	/**
	 * Removes the proof of an order (retention): files and meta. Returns the number of files.
	 *
	 * @param WC_Order $order Order.
	 */
	public function delete( WC_Order $order ): int {
		$removed = 0;
		foreach ( $this->files->for_key( PrivateFiles::CONTEXT_PROOF, (string) $order->get_id() ) as $file ) {
			$this->files->delete( $file['id'] );
			++$removed;
		}
		foreach ( self::meta_keys() as $key ) {
			$order->delete_meta_data( $key );
		}
		$order->save();
		$order->add_order_note( __( 'Proof of supply: confirmation and documents were deleted at the end of the retention period.', 'eurob2b' ) );
		return $removed;
	}

	/**
	 * Deletes files of orders that no longer exist. Returns the number of files.
	 *
	 * @param int $limit At most this many.
	 */
	public function sweep_files_without_order( int $limit ): int {
		return self::sweep( $this->files, PrivateFiles::CONTEXT_PROOF, $limit );
	}

	/**
	 * Deletes the files of a context whose order no longer exists. Returns the number of files.
	 *
	 * @param PrivateFiles $files   Private files.
	 * @param string       $context File context (the key is the order id).
	 * @param int          $limit   At most this many.
	 */
	public static function sweep( PrivateFiles $files, string $context, int $limit ): int {
		$removed = 0;
		foreach ( $files->field_keys( $context ) as $key ) {
			if ( $removed >= $limit ) {
				break;
			}
			if ( wc_get_order( (int) $key ) instanceof WC_Order ) {
				continue;
			}
			foreach ( $files->for_key( $context, $key ) as $file ) {
				$files->delete( $file['id'] );
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Orders of a person with proof entries (privacy export and erasure): by customer id and billing
	 * e-mail address.
	 *
	 * @param string $email   E-mail address.
	 * @param int    $user_id User or 0.
	 * @param int    $page    Page (1-based).
	 * @param int    $limit   Orders per page.
	 * @return WC_Order[]
	 */
	public function for_person( string $email, int $user_id, int $page, int $limit ): array {
		return self::orders_of_person( self::META_STATUS, $email, $user_id, $page, $limit );
	}

	/**
	 * Orders whose proof retention ended before $now.
	 *
	 * @param DateTimeImmutable $now   Now (UTC).
	 * @param int               $limit At most this many.
	 * @return WC_Order[]
	 */
	public function expired( DateTimeImmutable $now, int $limit ): array {
		return self::orders_expired( self::META_RETAIN_UNTIL, $now, $limit );
	}

	/**
	 * Orders of a person (customer id or billing e-mail address) that have a meta key.
	 *
	 * @param string $key     Meta key.
	 * @param string $email   E-mail address.
	 * @param int    $user_id User or 0.
	 * @param int    $page    Page (1-based).
	 * @param int    $limit   Orders per page.
	 * @return WC_Order[]
	 */
	public static function orders_of_person( string $key, string $email, int $user_id, int $page, int $limit ): array {
		$customer = array_values( array_filter( array( $email, $user_id > 0 ? $user_id : null ) ) );
		if ( array() === $customer ) {
			return array();
		}
		$orders = self::orders(
			array(
				'type'     => 'shop_order',
				'customer' => $customer,
				'limit'    => $limit,
				'page'     => max( 1, $page ),
				'orderby'  => 'date',
				'order'    => 'ASC',
			),
			array(
				array(
					'key'     => $key,
					'compare' => 'EXISTS',
				),
			)
		);
		return array_values( array_filter( is_array( $orders ) ? $orders : array(), static fn( $o ): bool => $o instanceof WC_Order ) );
	}

	/**
	 * Orders whose retention end (a `Y-m-d H:i:s` meta value) lies before $now.
	 *
	 * @param string            $key   Meta key of the retention end.
	 * @param DateTimeImmutable $now   Now (UTC).
	 * @param int               $limit At most this many.
	 * @return WC_Order[]
	 */
	public static function orders_expired( string $key, DateTimeImmutable $now, int $limit ): array {
		$orders = self::orders(
			array(
				'type'    => 'shop_order',
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'ASC',
			),
			array(
				array(
					'key'     => $key,
					'value'   => $now->format( 'Y-m-d H:i:s' ),
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			)
		);
		return array_values( array_filter( is_array( $orders ) ? $orders : array(), static fn( $o ): bool => $o instanceof WC_Order ) );
	}

	/**
	 * Orders through wc_get_orders() with an additional meta query on both order stores.
	 *
	 * @param array<string, mixed>     $args       wc_get_orders() arguments.
	 * @param array<int|string, mixed> $meta_query Meta query clauses (AND).
	 * @return mixed
	 */
	public static function orders( array $args, array $meta_query ) {
		if ( Hpos::enabled() ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the proof keys of exempt orders, paginated.
		} else {
			// The posts store refuses meta_query (WooCommerce 9.2+); cpt_meta_query() adds it to WP_Query.
			$args[ self::QUERY_VAR ] = $meta_query;
		}
		return wc_get_orders( $args );
	}

	/**
	 * Posts store: turns the query variable into a WP_Query meta query.
	 *
	 * @param mixed $query      WP_Query arguments.
	 * @param mixed $query_vars wc_get_orders() arguments.
	 * @return mixed
	 */
	public function cpt_meta_query( $query, $query_vars ) {
		if ( ! is_array( $query ) || ! is_array( $query_vars ) || empty( $query_vars[ self::QUERY_VAR ] ) || ! is_array( $query_vars[ self::QUERY_VAR ] ) ) {
			return $query;
		}
		$existing            = isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ? $query['meta_query'] : array();
		$query['meta_query'] = array_merge( $existing, $query_vars[ self::QUERY_VAR ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $query;
	}

	/**
	 * Place with the country: "Lyon, Frankreich (FR)".
	 *
	 * @param string $place        Place.
	 * @param string $country      ISO country.
	 * @param string $country_name Country name.
	 */
	public static function place_label( string $place, string $country, string $country_name ): string {
		$suffix = '' !== $country_name && $country_name !== $country ? $country_name . ' (' . $country . ')' : '(' . $country . ')';
		return '' === $country ? $place : $place . ( '(' === $suffix[0] ? ' ' : ', ' ) . $suffix;
	}
}

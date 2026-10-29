<?php
/**
 * Privacy export and erasure of the e-invoicing data.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;

/**
 * EIN-004: exports and erases the e-invoicing data stored on a customer (data inventory D-21), with
 * the result of the Peppol Directory lookup (EIN-006, D-30). The copies on orders follow
 * WooCommerce's order export and anonymisation (`InvoicingRecords::export_props()`,
 * `InvoicingRecords::anonymise_order()`). Part of the core: it reads the user meta, also while
 * EuroB2B Pro, which collects the data, is inactive (PLT-015).
 */
final class InvoicingDataProvider implements PersonalDataProviderInterface {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'invoicing';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B e-invoicing data', 'eurob2b' );
	}

	/**
	 * Export callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user  = get_user_by( 'email', $email_address );
		$items = array();
		if ( $user ) {
			$data     = InvoicingRecords::get( $user->ID );
			$register = is_array( $data['register'] ) ? InvoicingRecords::register_display( $data['register'] ) : '';
			foreach ( array(
				'buyer_reference' => array( __( 'Reference on invoices', 'eurob2b' ), $data['buyer_reference'] ),
				'leitweg_id'      => array( __( 'Leitweg-ID', 'eurob2b' ), $data['leitweg_id'] ),
				'peppol_id'       => array( __( 'Peppol ID', 'eurob2b' ), $data['peppol_id'] ),
				'register'        => array( __( 'Company register', 'eurob2b' ), $register ),
			) as [ $label, $value ] ) {
				if ( '' !== $value ) {
					$items[] = array(
						'name'  => $label,
						'value' => $value,
					);
				}
			}
			$items = array_merge( $items, self::lookup_items( InvoicingRecords::lookup( $user->ID ) ) );
		}
		return array(
			'data' => array() === $items || ! $user ? array() : array(
				array(
					'group_id'    => 'eurob2b-invoicing',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-invoicing-' . $user->ID,
					'data'        => $items,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Erase callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email_address );
		$removed = false;
		if ( $user ) {
			$data    = InvoicingRecords::get( $user->ID );
			$removed = '' !== $data['leitweg_id'] || '' !== $data['peppol_id'] || '' !== $data['buyer_reference'] || null !== $data['register'] || '' !== (string) get_user_meta( $user->ID, InvoicingRecords::META_LOOKUP, true );
			// Also a malformed register entry or lookup result goes.
			InvoicingRecords::delete( $user->ID );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Export rows of the Peppol Directory lookup (EIN-006).
	 *
	 * @param array{status: string, peppol_id: string, checked_at: int, name: string, country: string}|null $result Stored result.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function lookup_items( ?array $result ): array {
		if ( null === $result ) {
			return array();
		}
		$status = array(
			InvoicingRecords::LOOKUP_FOUND      => __( 'listed', 'eurob2b' ),
			InvoicingRecords::LOOKUP_NOT_LISTED => __( 'not listed', 'eurob2b' ),
			InvoicingRecords::LOOKUP_FAILED     => __( 'lookup failed', 'eurob2b' ),
		);
		$items  = array(
			array(
				'name'  => __( 'Peppol Directory lookup', 'eurob2b' ),
				'value' => sprintf(
					/* translators: 1: result (listed, not listed, lookup failed), 2: Peppol ID, 3: date and time */
					__( '%1$s (ID %2$s, checked on %3$s)', 'eurob2b' ),
					$status[ $result['status'] ] ?? $status[ InvoicingRecords::LOOKUP_FAILED ],
					$result['peppol_id'],
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $result['checked_at'] )
				),
			),
		);
		if ( '' !== $result['name'] ) {
			$items[] = array(
				'name'  => __( 'Name in the Peppol Directory', 'eurob2b' ),
				'value' => $result['name'],
			);
		}
		if ( '' !== $result['country'] ) {
			$items[] = array(
				'name'  => __( 'Country in the Peppol Directory', 'eurob2b' ),
				'value' => $result['country'],
			);
		}
		return $items;
	}
}

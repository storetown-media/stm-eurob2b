<?php
/**
 * Privacy provider for purchase lists.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;
use WC_Product;

/**
 * Exports a customer's purchase lists and deletes them on erasure (LST-008, D-08). Part of the core:
 * it reads the shared tables, also while EuroB2B Pro, which offers the lists, is inactive (PLT-015).
 */
final class PurchaseListsDataProvider implements PersonalDataProviderInterface {

	/**
	 * Stored lists.
	 *
	 * @var PurchaseListRecords
	 */
	private PurchaseListRecords $records;

	/**
	 * Constructor.
	 *
	 * @param PurchaseListRecords $records Stored lists.
	 */
	public function __construct( PurchaseListRecords $records ) {
		$this->records = $records;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'purchase_lists';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B purchase lists', 'eurob2b' );
	}

	/**
	 * Export callback: one group item per list.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		$data = array();
		if ( $user ) {
			foreach ( $this->records->for_user( $user->ID ) as $list ) {
				$rows = array();
				foreach ( $this->records->items( (int) $list['id'] ) as $item ) {
					$target  = (int) $item['variation_id'] > 0 ? (int) $item['variation_id'] : (int) $item['product_id'];
					$product = wc_get_product( $target );
					$label   = $product instanceof WC_Product ? $product->get_name() : '#' . $target;
					$sku     = $product instanceof WC_Product ? $product->get_sku() : '';
					$rows[]  = $label . ( '' !== $sku ? ' (' . $sku . ')' : '' ) . ' × ' . (int) $item['quantity'];
				}
				$data[] = array(
					'group_id'    => 'eurob2b-purchase-lists',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-purchase-list-' . (int) $list['id'],
					'data'        => array(
						array(
							'name'  => __( 'Name', 'eurob2b' ),
							'value' => (string) $list['name'],
						),
						array(
							'name'  => __( 'Created (UTC)', 'eurob2b' ),
							'value' => (string) $list['created_at'],
						),
						array(
							'name'  => __( 'Last change (UTC)', 'eurob2b' ),
							'value' => (string) $list['updated_at'],
						),
						array(
							'name'  => __( 'Items', 'eurob2b' ),
							'value' => implode( "\n", $rows ),
						),
						array(
							'name'  => __( 'Shared with your company', 'eurob2b' ),
							'value' => isset( $list['company_id'] ) ? __( 'Yes', 'eurob2b' ) : __( 'No', 'eurob2b' ),
						),
					),
				);
			}//end foreach
		}//end if
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase callback: lists are deleted outright (no retention duty).
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email_address );
		$removed = $user ? $this->records->delete_for_user( $user->ID ) : 0;
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

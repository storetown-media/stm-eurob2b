<?php
/**
 * Privacy export/erasure for VAT ID data.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;
use EuroB2B\Modules\Tax\Admin\ValidationsPage;
use EuroB2B\Modules\Tax\Validation\ValidationRepository;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;

/**
 * D-03: the VAT ID on the user and the validation evidence. Orderless records are deleted on an
 * erasure request; records linked to an order lose the user link and the returned trader data but
 * stay as tax evidence, reported as retained.
 */
final class TaxDataProvider implements PersonalDataProviderInterface {

	/**
	 * Repository.
	 *
	 * @var ValidationRepository
	 */
	private ValidationRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param ValidationRepository $repository Repository.
	 */
	public function __construct( ValidationRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'tax';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B VAT ID data', 'eurob2b' );
	}

	/**
	 * Export.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$groups = array();
		$vat_id = (string) get_user_meta( $user->ID, VatIdField::META_VAT_ID, true );
		if ( '' !== $vat_id ) {
			$state    = (string) get_user_meta( $user->ID, VatIdField::META_VALIDATION_STATE, true );
			$status   = ValidationStatus::tryFrom( $state );
			$groups[] = array(
				'group_id'    => 'eurob2b-vat-id',
				'group_label' => __( 'VAT ID', 'eurob2b' ),
				'item_id'     => 'eurob2b-vat-id-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'VAT ID', 'eurob2b' ),
						'value' => $vat_id,
					),
					array(
						'name'  => __( 'Last check', 'eurob2b' ),
						'value' => null !== $status ? ValidationsPage::status_label( $status ) : $state,
					),
				),
			);
		}
		foreach ( $this->repository->for_user( $user->ID ) as $record ) {
			$items = array(
				array(
					'name'  => __( 'VAT ID', 'eurob2b' ),
					'value' => $record->vat_id(),
				),
				array(
					'name'  => __( 'Result', 'eurob2b' ),
					'value' => ValidationsPage::status_label( $record->status ),
				),
				array(
					'name'  => _x( 'Service', 'VAT ID validation service', 'eurob2b' ),
					'value' => ValidationsPage::service_label( $record->service ),
				),
				array(
					'name'  => __( 'Checked at (UTC)', 'eurob2b' ),
					'value' => $record->checked_at->format( 'Y-m-d H:i:s' ),
				),
			);
			foreach ( array(
				__( 'Consultation number', 'eurob2b' ) => $record->consultation_number,
				__( 'Name returned', 'eurob2b' )       => $record->response_name,
				__( 'Address returned', 'eurob2b' )    => $record->response_address,
				__( 'Decision note', 'eurob2b' )       => $record->decision_note,
			) as $name => $value ) {
				if ( null !== $value && '' !== $value ) {
					$items[] = array(
						'name'  => $name,
						'value' => $value,
					);
				}
			}
			if ( null !== $record->order_id ) {
				$items[] = array(
					'name'  => _x( 'Order', 'shop order', 'eurob2b' ),
					'value' => (string) $record->order_id,
				);
			}
			$groups[] = array(
				'group_id'    => 'eurob2b-vat-validations',
				'group_label' => __( 'VAT ID checks', 'eurob2b' ),
				'item_id'     => 'eurob2b-vat-validation-' . $record->id,
				'data'        => $items,
			);
		}//end foreach
		return array(
			'data' => $groups,
			'done' => true,
		);
	}

	/**
	 * Erasure.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$removed = false;
		// Also the values WooCommerce keeps on the customer for the Checkout block field (billing and
		// shipping address); WooCommerce's own eraser does not know its additional fields.
		foreach ( array( VatIdField::META_VAT_ID, VatIdField::META_VALIDATION_ID, VatIdField::META_VALIDATION_STATE, VatIdField::BLOCK_META, VatIdField::BLOCK_META_SHIPPING ) as $key ) {
			if ( '' !== (string) get_user_meta( $user->ID, $key, true ) ) {
				delete_user_meta( $user->ID, $key );
				$removed = true;
			}
		}
		$result   = $this->repository->erase_user( $user->ID );
		$removed  = $removed || $result['deleted'] > 0;
		$messages = array();
		if ( $result['retained'] > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of records */
				_n( '%d VAT ID check linked to an order was kept as tax evidence; the link to the user and the returned trader data were removed.', '%d VAT ID checks linked to orders were kept as tax evidence; the link to the user and the returned trader data were removed.', $result['retained'], 'eurob2b' ),
				$result['retained']
			);
		}
		return array(
			'items_removed'  => $removed || $result['retained'] > 0,
			'items_retained' => $result['retained'] > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}

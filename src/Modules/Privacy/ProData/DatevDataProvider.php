<?php
/**
 * Privacy export and erasure of a customer's debtor account.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;

/**
 * RPT-006: exports and erases the debtor account stored on a customer (data inventory D-31). The
 * DATEV file itself is generated on request and not stored. Part of the core: it reads the user
 * meta, also while EuroB2B Pro, which offers the accounting export, is inactive (PLT-015).
 */
final class DatevDataProvider implements PersonalDataProviderInterface {

	/**
	 * User meta: the customer's own debtor account (1 to 9 digits). EuroB2B Pro aliases it.
	 */
	public const META = '_eurob2b_datev_debtor';

	/**
	 * Id.
	 */
	public function id(): string {
		return 'datev';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B accounting export', 'eurob2b' );
	}

	/**
	 * The stored debtor account of a customer, '' when none or not an account number (1 to 9 digits,
	 * not only zeros; the rule of the accounting export).
	 *
	 * @param int $user_id User id.
	 */
	public static function debtor( int $user_id ): string {
		$value = trim( (string) get_user_meta( $user_id, self::META, true ) );
		return 1 === preg_match( '/^\d{1,9}$/', $value ) && '' !== ltrim( $value, '0' ) ? $value : '';
	}

	/**
	 * Export callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user   = get_user_by( 'email', $email_address );
		$debtor = $user ? self::debtor( $user->ID ) : '';
		return array(
			'data' => ! $user || '' === $debtor ? array() : array(
				array(
					'group_id'    => 'eurob2b-datev',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-datev-' . $user->ID,
					'data'        => array(
						array(
							'name'  => __( 'Debtor account (DATEV format)', 'eurob2b' ),
							'value' => $debtor,
						),
					),
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
		if ( $user && '' !== (string) get_user_meta( $user->ID, self::META, true ) ) {
			$removed = delete_user_meta( $user->ID, self::META );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

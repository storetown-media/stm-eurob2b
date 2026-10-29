<?php
/**
 * Personal data provider for per-customer payment and shipping method lists.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;

/**
 * Exports and erases the payment and shipping method lists the shop set for one customer
 * (inventory D-17 in docs/privacy-and-data-flows.md). Such a list can reveal a business
 * decision about the person (for example "no invoice payment"), so it is personal data.
 * After erasure the customer follows the methods of their group or customer type again.
 */
final class PaymentsDataProvider implements PersonalDataProviderInterface {

	/**
	 * Policy.
	 *
	 * @var MethodPolicy
	 */
	private MethodPolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param MethodPolicy $policy Policy.
	 */
	public function __construct( MethodPolicy $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'payments';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B payment and shipping methods', 'eurob2b' );
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
			$labels = array(
				MethodPolicy::KIND_PAYMENT  => __( 'Payment methods set for this customer', 'eurob2b' ),
				MethodPolicy::KIND_SHIPPING => __( 'Shipping methods set for this customer', 'eurob2b' ),
			);
			foreach ( $labels as $kind => $label ) {
				$list = $this->policy->user_override( $user->ID, $kind );
				if ( null !== $list ) {
					$items[] = array(
						'name'  => $label,
						'value' => array() === $list ? __( 'none', 'eurob2b' ) : implode( ', ', $list ),
					);
				}
			}
		}
		return array(
			'data' => array() === $items || ! $user ? array() : array(
				array(
					'group_id'    => 'eurob2b-payments',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-payments-' . $user->ID,
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
			foreach ( array( MethodPolicy::KIND_PAYMENT, MethodPolicy::KIND_SHIPPING ) as $kind ) {
				if ( null !== $this->policy->user_override( $user->ID, $kind ) ) {
					$this->policy->set_user_override( $user->ID, $kind, null );
					$removed = true;
				}
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

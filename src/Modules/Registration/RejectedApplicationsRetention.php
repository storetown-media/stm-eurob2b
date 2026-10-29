<?php
/**
 * Retention of rejected registrations.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use DateTimeImmutable;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * Deletes the accounts of rejected applications once the retention period is over (D-02, PRV-004).
 *
 * Only applications are touched: accounts that registered through EuroB2B, were rejected and
 * were never approved. A deactivated customer (approved once, rejected later) keeps the account.
 * An application with orders, or an account with any role besides "customer" or "subscriber",
 * is never deleted automatically; it is counted as kept so the shop can decide by hand.
 * Deleting the WordPress user also runs every module's "deleted_user" clean-up.
 */
final class RejectedApplicationsRetention implements RetentionTaskInterface {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'rejected_applications';
	}

	/**
	 * Removes up to $limit expired applications.
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of accounts to delete.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		$days = (int) $this->settings->get( 'privacy_rejected_days' );
		if ( $days <= 0 || $limit <= 0 ) {
			return array(
				'removed' => 0,
				'skipped' => 0,
				'more'    => false,
			);
		}
		$cutoff  = $now->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
		$removed = 0;
		$skipped = 0;
		do {
			// Kept accounts stay in the result set with the lowest ids, so they are skipped by offset.
			$ids  = $this->candidates( $cutoff, $limit, $skipped );
			$full = count( $ids ) === $limit;
			foreach ( $ids as $user_id ) {
				if ( $this->deletable( $user_id ) && $this->delete( $user_id ) ) {
					++$removed;
					if ( $removed >= $limit ) {
						return array(
							'removed' => $removed,
							'skipped' => $skipped,
							'more'    => true,
						);
					}
				} else {
					++$skipped;
				}
			}
		} while ( $full );

		return array(
			'removed' => $removed,
			'skipped' => $skipped,
			'more'    => false,
		);
	}

	/**
	 * Expired applications, lowest id first.
	 *
	 * @param string $cutoff Rejections before this time (UTC) are expired.
	 * @param int    $limit  Page size.
	 * @param int    $offset Offset.
	 * @return int[]
	 */
	private function candidates( string $cutoff, int $limit, int $offset ): array {
		$ids = get_users(
			array(
				'fields'      => 'ID',
				'number'      => $limit,
				'offset'      => $offset,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily batch job, bounded by $limit.
				'meta_query'  => array(
					'relation' => 'AND',
					array(
						'key'   => CustomersModule::META_STATUS,
						'value' => CustomerStatus::REJECTED->value,
					),
					array(
						'key'     => CustomersModule::META_REJECTED_AT,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => RegistrationService::META_REGISTERED_AT,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => CustomersModule::META_APPROVED_AT,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Plain customer account without orders.
	 *
	 * @param int $user_id User id.
	 */
	private function deletable( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || is_super_admin( $user_id ) || array() !== array_diff( (array) $user->roles, array( 'customer', 'subscriber' ) ) ) {
			return false;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return true;
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash', 'wc-checkout-draft' ) ),
				'limit'       => 1,
				'return'      => 'ids',
			)
		);
		return array() === (array) $orders;
	}

	/**
	 * Deletes the WordPress user.
	 *
	 * @param int $user_id User id.
	 */
	private function delete( int $user_id ): bool {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		return wp_delete_user( $user_id );
	}
}

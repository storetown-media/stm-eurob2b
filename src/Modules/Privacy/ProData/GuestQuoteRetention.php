<?php
/**
 * Retention job for quote requests from guests.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use DateTimeImmutable;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * Deletes quote requests from guests that were not changed for the configured number of days
 * (QUO-015, D-06; setting `quotes_guest_retention_days`, default 180 days, product owner decision of
 * 2026-10-25). It always runs, also while guest requests are switched off or EuroB2B Pro is inactive
 * (PLT-015), so stored requests do not stay. A request assigned to a customer account is no longer
 * a guest request and follows `quotes_retention_days`.
 */
final class GuestQuoteRetention implements RetentionTaskInterface {

	/**
	 * Setting with the period in days, and its documented default.
	 */
	public const SETTING = 'quotes_guest_retention_days';
	public const DEFAULT = 180;

	/**
	 * Stored quotes.
	 *
	 * @var QuoteRecords
	 */
	private QuoteRecords $records;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param QuoteRecords $records  Stored quotes.
	 * @param Settings     $settings Settings.
	 */
	public function __construct( QuoteRecords $records, Settings $settings ) {
		$this->records  = $records;
		$this->settings = $settings;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'guest_quotes';
	}

	/**
	 * Removes up to $limit guest requests past the period.
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of requests to delete.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		$days = max( 1, (int) $this->settings->stored( self::SETTING, self::DEFAULT ) );
		if ( $limit <= 0 ) {
			return array(
				'removed' => 0,
				'skipped' => 0,
				'more'    => false,
			);
		}
		$ids = $this->records->stale_guest_ids( $now->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' ), $limit );
		foreach ( $ids as $id ) {
			$this->records->delete( $id );
		}
		return array(
			'removed' => count( $ids ),
			'skipped' => 0,
			'more'    => count( $ids ) === $limit,
		);
	}
}

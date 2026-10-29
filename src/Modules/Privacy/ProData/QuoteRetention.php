<?php
/**
 * Retention of quote requests and offers.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use DateTimeImmutable;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * Deletes requests and offers that were not changed for the configured number of days (QUO-014, D-06;
 * setting `quotes_retention_days`).
 *
 * Off by default (0 days): whether offers that did not lead to an order count as business letters
 * with a statutory retention period is an open legal question (docs/privacy-and-data-flows.md §8),
 * so the shop owner decides. Quotes an order references are never deleted by this task. Part of the
 * core: the period is read as stored, so the job keeps running while EuroB2B Pro, which offers the
 * setting, is inactive (PLT-015).
 */
final class QuoteRetention implements RetentionTaskInterface {

	/**
	 * Setting with the period in days, and its documented default (0 = keep).
	 */
	public const SETTING = 'quotes_retention_days';
	public const DEFAULT = 0;

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
		return 'quotes';
	}

	/**
	 * Removes up to $limit expired quotes.
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of quotes to delete.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		$days = (int) $this->settings->stored( self::SETTING, self::DEFAULT );
		if ( $days <= 0 || $limit <= 0 ) {
			return array(
				'removed' => 0,
				'skipped' => 0,
				'more'    => false,
			);
		}
		$ids = $this->records->stale_ids( $now->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' ), $limit );
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

<?php
/**
 * Retention of conversations.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use DateTimeImmutable;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * Deletes conversations whose last message is older than the configured number of months (D-07;
 * setting `conversations_retention_months`, 24 months by product owner decision of 2026-10-23;
 * 0 keeps them). Part of the core: the period is read as stored, so the job keeps running while
 * EuroB2B Pro, which offers the setting, is inactive (PLT-015).
 */
final class ConversationRetention implements RetentionTaskInterface {

	/**
	 * Setting with the period in months, and its documented default.
	 */
	public const SETTING = 'conversations_retention_months';
	public const DEFAULT = 24;

	/**
	 * Stored conversations.
	 *
	 * @var ConversationRecords
	 */
	private ConversationRecords $records;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param ConversationRecords $records  Stored conversations.
	 * @param Settings            $settings Settings.
	 */
	public function __construct( ConversationRecords $records, Settings $settings ) {
		$this->records  = $records;
		$this->settings = $settings;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'conversations';
	}

	/**
	 * Removes up to $limit expired conversations.
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of conversations to delete.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		$months = (int) $this->settings->stored( self::SETTING, self::DEFAULT );
		if ( $months <= 0 || $limit <= 0 ) {
			return array(
				'removed' => 0,
				'skipped' => 0,
				'more'    => false,
			);
		}
		$ids = $this->records->stale_ids( $now->modify( '-' . $months . ' months' )->format( 'Y-m-d H:i:s' ), $limit );
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

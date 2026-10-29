<?php
/**
 * Retention of the audit log.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Audit;

use DateTimeImmutable;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * Deletes audit log entries older than `audit_retention_days` (D-20; default 365 days, product owner
 * decision of 2026-10-24). The period cannot be switched off: the setting allows 30 to 3650 days.
 */
final class AuditRetention implements RetentionTaskInterface {

	public const MIN_DAYS = 30;
	public const MAX_DAYS = 3650;

	/**
	 * Log.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param AuditLog $log      Log.
	 * @param Settings $settings Settings.
	 */
	public function __construct( AuditLog $log, Settings $settings ) {
		$this->log      = $log;
		$this->settings = $settings;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'audit_log';
	}

	/**
	 * The configured period in days, within the allowed range.
	 */
	public function days(): int {
		return min( self::MAX_DAYS, max( self::MIN_DAYS, (int) $this->settings->get( 'audit_retention_days' ) ) );
	}

	/**
	 * Removes up to $limit expired entries.
	 *
	 * @param DateTimeImmutable $now   Current time (UTC).
	 * @param int               $limit Maximum number of entries to delete.
	 * @return array{removed: int, skipped: int, more: bool}
	 */
	public function run( DateTimeImmutable $now, int $limit ): array {
		if ( $limit <= 0 ) {
			return array(
				'removed' => 0,
				'skipped' => 0,
				'more'    => false,
			);
		}
		$removed = $this->log->delete_before( $now->modify( '-' . $this->days() . ' days' )->format( 'Y-m-d H:i:s' ), $limit );
		return array(
			'removed' => $removed,
			'skipped' => 0,
			'more'    => $removed === $limit,
		);
	}
}

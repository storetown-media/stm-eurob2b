<?php
/**
 * Group upgrade rule.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers\Upgrades;

use DateTimeImmutable;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Support\Decimal;

/**
 * GRP-008 as decided by the product owner on 2026-10-23: no automatic group changes. A group may
 * name a target group, a net spend threshold and a period; customers who reach the threshold are
 * suggested for the target group, and staff move them by click. Stored in the source group's
 * settings under "upgrade".
 */
final class UpgradeRule {

	public const PERIOD_LIFETIME       = 'lifetime';
	public const PERIOD_LAST_12_MONTHS = 'last_12_months';
	public const PERIOD_PREVIOUS_YEAR  = 'previous_year';
	public const PERIOD_PREVIOUS_MONTH = 'previous_month';

	/**
	 * Constructor.
	 *
	 * @param int    $target_id Target group id.
	 * @param string $threshold Net spend threshold (decimal string with a point).
	 * @param string $period    One of the PERIOD_ constants.
	 */
	public function __construct(
		public readonly int $target_id,
		public readonly string $threshold,
		public readonly string $period
	) {
	}

	/**
	 * Periods in display order.
	 *
	 * @return string[]
	 */
	public static function periods(): array {
		return array( self::PERIOD_LAST_12_MONTHS, self::PERIOD_PREVIOUS_YEAR, self::PERIOD_PREVIOUS_MONTH, self::PERIOD_LIFETIME );
	}

	/**
	 * The rule a group carries, or null.
	 *
	 * @param Group $group Source group.
	 */
	public static function of( Group $group ): ?self {
		$data = $group->settings['upgrade'] ?? null;
		if ( ! is_array( $data ) ) {
			return null;
		}
		$target    = isset( $data['to'] ) && is_numeric( $data['to'] ) ? (int) $data['to'] : 0;
		$threshold = isset( $data['threshold'] ) && is_string( $data['threshold'] ) ? $data['threshold'] : '';
		$period    = isset( $data['period'] ) && is_string( $data['period'] ) && in_array( $data['period'], self::periods(), true ) ? $data['period'] : '';
		// A zero threshold would suggest every member: such a rule (stored before the form refused it) counts as none.
		if ( $target <= 0 || $target === $group->id || 1 !== preg_match( '/^\d+(\.\d+)?$/', $threshold ) || Decimal::compare( $threshold, '0' ) <= 0 || '' === $period ) {
			return null;
		}
		return new self( $target, $threshold, $period );
	}

	/**
	 * Settings of a group with this rule (or without any rule when $rule is null).
	 *
	 * @param array<string, mixed> $settings Group settings.
	 * @param UpgradeRule|null     $rule     Rule.
	 * @return array<string, mixed>
	 */
	public static function store( array $settings, ?self $rule ): array {
		unset( $settings['upgrade'] );
		if ( null !== $rule ) {
			$settings['upgrade'] = array(
				'to'        => $rule->target_id,
				'threshold' => $rule->threshold,
				'period'    => $rule->period,
			);
		}
		return $settings;
	}

	/**
	 * The days the spend counts in: [first day, last day], null for an open end. Calendar days in
	 * the time zone of $today.
	 *
	 * @param DateTimeImmutable $today Today.
	 * @return array{0: DateTimeImmutable|null, 1: DateTimeImmutable|null}
	 */
	public function range( DateTimeImmutable $today ): array {
		$today = $today->setTime( 0, 0 );
		switch ( $this->period ) {
			case self::PERIOD_LAST_12_MONTHS:
				return array( $today->modify( '-12 months' )->modify( '+1 day' ), $today );
			case self::PERIOD_PREVIOUS_YEAR:
				$year = (int) $today->format( 'Y' ) - 1;
				return array( $today->setDate( $year, 1, 1 ), $today->setDate( $year, 12, 31 ) );
			case self::PERIOD_PREVIOUS_MONTH:
				$first = $today->modify( 'first day of last month' );
				return array( $first, $first->modify( 'last day of this month' ) );
			default:
				return array( null, null );
		}
	}
}

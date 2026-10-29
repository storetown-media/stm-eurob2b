<?php
/**
 * Dunning history of one order.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments\Dunning;

/**
 * PAY-023 (D-28): every dunning e-mail that was sent or attempted for an order, oldest first, as
 * stored in the order meta `_eurob2b_dunning` (JSON list). An entry holds the level, the day in the
 * site's time zone (`date`, Y-m-d, the base of the gap rule), the time in UTC (`at`), the user id of
 * the staff member who sent it (`by`) and the result of the e-mail (`sent` or `failed`). Only sent
 * entries count as a level reached; a failed one can be sent again. Immutable.
 */
final class DunningHistory {

	public const RESULT_SENT   = 'sent';
	public const RESULT_FAILED = 'failed';

	/**
	 * Most entries kept (older failed attempts are dropped first; sent ones are at most three).
	 */
	public const MAX_ENTRIES = 20;

	/**
	 * Entries, oldest first.
	 *
	 * @var array<int, array{level: string, date: string, at: string, by: int, result: string}>
	 */
	private array $entries;

	/**
	 * Constructor.
	 *
	 * @param array<int, array{level: string, date: string, at: string, by: int, result: string}> $entries Entries, oldest first.
	 */
	public function __construct( array $entries = array() ) {
		$this->entries = array_values( $entries );
	}

	/**
	 * History from the stored JSON; malformed entries are skipped.
	 *
	 * @param string $json Stored value.
	 */
	public static function from_json( string $json ): self {
		$data    = '' === $json ? array() : json_decode( $json, true );
		$entries = array();
		foreach ( is_array( $data ) ? $data : array() as $entry ) {
			if ( ! is_array( $entry ) || null === DunningLevel::tryFrom( (string) ( $entry['level'] ?? '' ) ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $entry['date'] ?? '' ) ) ) {
				continue;
			}
			$entries[] = array(
				'level'  => (string) $entry['level'],
				'date'   => (string) $entry['date'],
				'at'     => (string) ( $entry['at'] ?? '' ),
				'by'     => (int) ( $entry['by'] ?? 0 ),
				'result' => self::RESULT_SENT === ( $entry['result'] ?? '' ) ? self::RESULT_SENT : self::RESULT_FAILED,
			);
		}
		return new self( $entries );
	}

	/**
	 * JSON for the order meta.
	 */
	public function to_json(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- plain data, unit-tested without WordPress.
		return (string) json_encode( $this->entries, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Entries, oldest first.
	 *
	 * @return array<int, array{level: string, date: string, at: string, by: int, result: string}>
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Whether nothing was sent or attempted yet.
	 */
	public function is_empty(): bool {
		return array() === $this->entries;
	}

	/**
	 * A copy with one more entry.
	 *
	 * @param DunningLevel $level  Level.
	 * @param string       $date   Day in the site's time zone (Y-m-d).
	 * @param string       $at     Time in UTC (Y-m-d H:i:s).
	 * @param int          $by     Sending user.
	 * @param bool         $sent   Whether the e-mail was handed to the mail system.
	 */
	public function with( DunningLevel $level, string $date, string $at, int $by, bool $sent ): self {
		$entries   = $this->entries;
		$entries[] = array(
			'level'  => $level->value,
			'date'   => $date,
			'at'     => $at,
			'by'     => $by,
			'result' => $sent ? self::RESULT_SENT : self::RESULT_FAILED,
		);
		$excess    = count( $entries ) - self::MAX_ENTRIES;
		for ( ; $excess > 0; $excess-- ) {
			$drop = 0;
			foreach ( $entries as $i => $entry ) {
				if ( self::RESULT_FAILED === $entry['result'] ) {
					$drop = $i;
					break;
				}
			}
			unset( $entries[ $drop ] );
			$entries = array_values( $entries );
		}
		return new self( $entries );
	}

	/**
	 * The newest sent entry, or null.
	 *
	 * @return array{level: string, date: string, at: string, by: int, result: string}|null
	 */
	public function last_sent(): ?array {
		for ( $i = count( $this->entries ) - 1; $i >= 0; $i-- ) {
			if ( self::RESULT_SENT === $this->entries[ $i ]['result'] ) {
				return $this->entries[ $i ];
			}
		}
		return null;
	}

	/**
	 * The highest level sent, or null when none was sent.
	 */
	public function level(): ?DunningLevel {
		$level = null;
		foreach ( $this->entries as $entry ) {
			$current = DunningLevel::tryFrom( $entry['level'] );
			if ( self::RESULT_SENT === $entry['result'] && null !== $current && ( null === $level || $current->rank() > $level->rank() ) ) {
				$level = $current;
			}
		}
		return $level;
	}

	/**
	 * Day (Y-m-d) a level was sent, or '' when it was not.
	 *
	 * @param DunningLevel $level Level.
	 */
	public function sent_on( DunningLevel $level ): string {
		foreach ( $this->entries as $entry ) {
			if ( self::RESULT_SENT === $entry['result'] && $level->value === $entry['level'] ) {
				return $entry['date'];
			}
		}
		return '';
	}

	/**
	 * The newest entry (sent or failed), or null.
	 *
	 * @return array{level: string, date: string, at: string, by: int, result: string}|null
	 */
	public function last(): ?array {
		return array() === $this->entries ? null : $this->entries[ count( $this->entries ) - 1 ];
	}
}

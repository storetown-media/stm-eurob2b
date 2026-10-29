<?php
/**
 * Migration lock.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration;

use EuroB2B\Infrastructure\Schema;

/**
 * Atomic, owner-bound, self-expiring lock on the options table.
 *
 * The lock row's value is "<owner token>|<unix timestamp>". Three operations,
 * each a single SQL statement, so two requests can never both believe they
 * own the lock:
 *
 *  - acquire:  INSERT IGNORE on the unique option_name (WordPress upgrader pattern);
 *              only the request whose insert affected a row owns the lock.
 *  - takeover: UPDATE ... WHERE option_value = <the stale value that was read>
 *              (compare-and-swap); only one of several concurrent takers wins.
 *  - release:  DELETE ... WHERE option_value = <own value>; a request can only
 *              remove its own lock, never one that was taken over meanwhile.
 *
 * A lock older than the TTL is stale and may be taken over, so a request that
 * died mid-migration does not block the site forever.
 */
final class MigrationLock {

	public const OPTION      = Schema::OPTION_MIGRATION_LOCK;
	public const DEFAULT_TTL = 300;

	/**
	 * Lock lifetime in seconds.
	 *
	 * @var int
	 */
	private int $ttl;

	/**
	 * Unique owner token of this instance.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Stored value while this instance owns the lock, null otherwise.
	 *
	 * @var string|null
	 */
	private ?string $owned_value = null;

	/**
	 * Constructor.
	 *
	 * @param int         $ttl   Seconds after which a lock is treated as stale.
	 * @param string|null $token Owner token (random by default; injectable for tests).
	 */
	public function __construct( int $ttl = self::DEFAULT_TTL, ?string $token = null ) {
		$this->ttl   = max( 1, $ttl );
		$this->token = $token ?? bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Tries to acquire the lock without waiting.
	 *
	 * @return bool True when this request now owns the lock.
	 */
	public function acquire(): bool {
		if ( null !== $this->owned_value ) {
			return true;
		}

		global $wpdb;

		if ( $this->try_insert( $wpdb ) ) {
			return true;
		}

		// Somebody holds it. Take it over only when it is stale, and only atomically.
		$current = $this->read_value( $wpdb );
		if ( null === $current ) {
			// The holder released between our insert and read; try once more.
			return $this->try_insert( $wpdb );
		}
		if ( $this->is_stale( $current ) ) {
			return $this->attempt_takeover( $current );
		}

		return false;
	}

	/**
	 * Compare-and-swap takeover of a lock whose value was read as $seen_value.
	 *
	 * Public so concurrency can be tested deterministically; production code
	 * goes through acquire(). Succeeds only if the stored value still equals
	 * $seen_value, i.e. nobody else took the lock over in the meantime.
	 *
	 * @param string $seen_value The stale value previously read.
	 */
	public function attempt_takeover( string $seen_value ): bool {
		if ( null !== $this->owned_value ) {
			return true;
		}

		global $wpdb;
		$new_value = $this->make_value( time() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				self::OPTION,
				$seen_value
			)
		);
		$this->flush_option_cache();

		if ( 1 === $result ) {
			$this->owned_value = $new_value;
			return true;
		}
		return false;
	}

	/**
	 * Releases the lock if, and only if, this instance still owns it.
	 */
	public function release(): void {
		if ( null === $this->owned_value ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$this->owned_value
			)
		);
		$this->flush_option_cache();
		$this->owned_value = null;
	}

	/**
	 * Whether this instance owns the lock.
	 */
	public function is_owned(): bool {
		return null !== $this->owned_value;
	}

	/**
	 * Owner token of this instance.
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * Stored value while owned (token|timestamp), null otherwise. For tests.
	 */
	public function owned_value(): ?string {
		return $this->owned_value;
	}

	/**
	 * Reads the raw lock value bypassing the object cache; null when no lock exists.
	 */
	public function current_value(): ?string {
		global $wpdb;
		return $this->read_value( $wpdb );
	}

	/**
	 * Builds the stored value for this owner.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public function make_value( int $timestamp ): string {
		return $this->token . '|' . $timestamp;
	}

	/**
	 * Whether a stored value is older than the TTL (or malformed).
	 *
	 * @param string $value Stored value.
	 */
	private function is_stale( string $value ): bool {
		$parts = explode( '|', $value, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[1] ) ) {
			return true;
			// Unknown format: treat as stale so it cannot block forever.
		}
		return ( time() - (int) $parts[1] ) > $this->ttl;
	}

	/**
	 * Attempts the atomic insert.
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	private function try_insert( \wpdb $wpdb ): bool {
		$value = $this->make_value( time() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				self::OPTION,
				$value
			)
		);
		// query() returns the number of affected rows; 0 means the row already existed.
		if ( 1 === $result ) {
			$this->flush_option_cache();
			$this->owned_value = $value;
			return true;
		}
		return false;
	}

	/**
	 * Reads the stored value directly from the database.
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	private function read_value( \wpdb $wpdb ): ?string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Keeps the options cache from serving a stale lock row.
	 */
	private function flush_option_cache(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}

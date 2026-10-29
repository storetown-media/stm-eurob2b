<?php
/**
 * Migration failure.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration;

use RuntimeException;

/**
 * Thrown when a migration reports a database error or fails verification.
 */
final class MigrationFailedException extends RuntimeException {

	/**
	 * Schema version that failed.
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * Constructor.
	 *
	 * @param int    $version Failed schema version.
	 * @param string $message Reason.
	 */
	public function __construct( int $version, string $message ) {
		parent::__construct( sprintf( 'EuroB2B migration %d failed: %s', $version, $message ) );
		$this->version = $version;
	}

	/**
	 * Failed schema version.
	 */
	public function version(): int {
		return $this->version;
	}
}

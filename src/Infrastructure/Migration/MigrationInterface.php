<?php
/**
 * Migration contract.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration;

/**
 * One versioned, forward-only schema step.
 *
 * Migrations must be idempotent (dbDelta is) and must not depend on plugin
 * services, because they also run from the activation hook. up() does the
 * work; verify() proves it worked. The schema version is only advanced when
 * up() produced no database error and verify() returns true.
 */
interface MigrationInterface {

	/**
	 * Target schema version this migration produces. Strictly increasing.
	 */
	public function version(): int;

	/**
	 * Short description for the migration log.
	 */
	public function description(): string;

	/**
	 * Applies the migration.
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	public function up( \wpdb $wpdb ): void;

	/**
	 * Checks that the result of up() is actually in place (tables, columns, data).
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	public function verify( \wpdb $wpdb ): bool;
}

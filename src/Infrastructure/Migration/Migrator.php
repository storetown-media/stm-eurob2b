<?php
/**
 * Runs migrations.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure\Migration;

use EuroB2B\Infrastructure\Schema;
use Throwable;

/**
 * Applies all migrations above the stored schema version, in order.
 *
 * The schema version advances only after a migration ran without a database
 * error and its verify() confirmed the result. A failure stops the run,
 * leaves the version at the last good step and records the error in an
 * option so the admin sees it and the next request retries. An atomic lock
 * prevents two requests from migrating at the same time.
 * Recovery procedure: see docs/architecture.md, section "Migrations".
 */
final class Migrator {

	/**
	 * Migrations sorted by version.
	 *
	 * @var MigrationInterface[]
	 */
	private array $migrations;

	/**
	 * Lock implementation.
	 *
	 * @var MigrationLock
	 */
	private MigrationLock $lock;

	/**
	 * Constructor.
	 *
	 * @param MigrationInterface[] $migrations Migrations to consider (any order).
	 * @param MigrationLock|null   $lock       Lock (default: options-table lock).
	 */
	public function __construct( array $migrations, ?MigrationLock $lock = null ) {
		usort( $migrations, static fn( MigrationInterface $a, MigrationInterface $b ): int => $a->version() <=> $b->version() );
		$this->migrations = $migrations;
		$this->lock       = $lock ?? new MigrationLock();
	}

	/**
	 * Default migration set shipped with the plugin.
	 */
	public static function with_default_migrations(): self {
		return new self(
			array(
				new Migrations\M0001CreateCoreTables(),
				new Migrations\M0002CreateRulesTable(),
				new Migrations\M0003CreatePurchaseListTables(),
				new Migrations\M0004CreateCompanyTables(),
				new Migrations\M0005CreateQuoteTables(),
				new Migrations\M0006AddQuoteFieldsAndAudience(),
				new Migrations\M0007CreateConversationTables(),
				new Migrations\M0008CreateAuditLogTable(),
				new Migrations\M0009AddGuestRequesterToQuotes(),
				new Migrations\M0010CreateFilesTable(),
				new Migrations\M0011CreateMigrationLogTable(),
				new Migrations\M0012AddLinkedProductToQuoteItems(),
			)
		);
	}

	/**
	 * Currently installed schema version (0 = fresh install).
	 */
	public function current_version(): int {
		return (int) get_option( Schema::OPTION_DB_VERSION, 0 );
	}

	/**
	 * Highest version this migrator knows.
	 */
	public function target_version(): int {
		if ( empty( $this->migrations ) ) {
			return 0;
		}
		return $this->migrations[ count( $this->migrations ) - 1 ]->version();
	}

	/**
	 * Whether migrations are pending.
	 */
	public function needs_migration(): bool {
		return $this->current_version() < $this->target_version();
	}

	/**
	 * Last recorded failure, or null.
	 *
	 * @return array{version: int, message: string, time: string}|null
	 */
	public static function last_error(): ?array {
		$error = get_option( Schema::OPTION_MIGRATION_ERROR );
		if ( ! is_array( $error ) || ! isset( $error['version'], $error['message'], $error['time'] ) ) {
			return null;
		}
		return array(
			'version' => (int) $error['version'],
			'message' => (string) $error['message'],
			'time'    => (string) $error['time'],
		);
	}

	/**
	 * Runs pending migrations in order and stops at the first failure.
	 *
	 * Returns the versions applied in this call. A failure is recorded in
	 * Schema::OPTION_MIGRATION_ERROR and does not advance the schema version.
	 * When another request holds the lock, nothing runs and an empty array is returned.
	 *
	 * @return int[]
	 */
	public function migrate(): array {
		if ( ! $this->needs_migration() ) {
			return array();
		}
		if ( ! $this->lock->acquire() ) {
			return array();
		}

		global $wpdb;
		$applied = array();

		try {
			foreach ( $this->migrations as $migration ) {
				if ( $migration->version() <= $this->current_version() ) {
					continue;
				}
				$this->apply( $migration, $wpdb );
				update_option( Schema::OPTION_DB_VERSION, $migration->version(), true );
				$applied[] = $migration->version();
			}
			delete_option( Schema::OPTION_MIGRATION_ERROR );
		} catch ( MigrationFailedException $e ) {
			$this->record_failure( $e->version(), $e->getMessage() );
		} finally {
			$this->lock->release();
		}

		return $applied;
	}

	/**
	 * Runs one migration and verifies it.
	 *
	 * @param MigrationInterface $migration Migration.
	 * @param \wpdb              $wpdb      Database handle.
	 * @throws MigrationFailedException On database error, exception or failed verification.
	 */
	private function apply( MigrationInterface $migration, \wpdb $wpdb ): void {
		$wpdb->last_error = '';

		try {
			$migration->up( $wpdb );
		} catch ( MigrationFailedException $e ) {
			throw $e;
		} catch ( Throwable $t ) {
			throw new MigrationFailedException( $migration->version(), $t->getMessage() );
		}

		if ( '' !== $wpdb->last_error ) {
			throw new MigrationFailedException( $migration->version(), $wpdb->last_error );
		}

		if ( ! $migration->verify( $wpdb ) ) {
			throw new MigrationFailedException( $migration->version(), 'verification failed: ' . $migration->description() );
		}
	}

	/**
	 * Stores the failure for the admin notice and the log.
	 *
	 * @param int    $version Failed version.
	 * @param string $message Reason.
	 */
	private function record_failure( int $version, string $message ): void {
		update_option(
			Schema::OPTION_MIGRATION_ERROR,
			array(
				'version' => $version,
				'message' => $message,
				'time'    => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'eurob2b' ) );
		}
	}
}

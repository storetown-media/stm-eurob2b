<?php
/**
 * Import from other B2B plugins.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerService;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Migration\Admin\ImportSection;
use EuroB2B\Modules\Migration\Cli\ImportCommand;
use EuroB2B\Modules\Migration\Sources\B2BKingRules;
use EuroB2B\Modules\Migration\Sources\B2BKingSource;
use EuroB2B\Modules\Migration\Sources\WholesaleSuiteSource;
use EuroB2B\Modules\Payments\MethodPolicy;
use EuroB2B\Modules\Registration\FieldRepository;
use EuroB2B\Modules\Registration\RegistrationTypeRepository;
use EuroB2B\Modules\Rules\Admin\RulesPage;
use EuroB2B\Modules\Rules\RuleRepository;

/**
 * MIG-001 to MIG-003: moves a shop from another B2B plugin to EuroB2B (docs/migration.md). Reads the
 * other plugin's data, never changes it; test run first, journaled import, undo. Data inventory
 * D-22 (the journal), retention task `migration_journal`.
 */
final class MigrationModule extends AbstractModule {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'migration';
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Service container.
	 */
	public function register( Container $c ): void {
		$c->set( Journal::class, static fn(): Journal => new Journal() );
		$c->set(
			ImportWriter::class,
			static fn( Container $c ): ImportWriter => new ImportWriter(
				$c->get( Journal::class ),
				$c->get( GroupRepository::class ),
				$c->get( RegistrationTypeRepository::class ),
				$c->get( FieldRepository::class ),
				$c->get( RuleRepository::class ),
				$c->get( RulesPage::class ),
				$c->get( MethodPolicy::class ),
				$c->get( CustomerService::class ),
				self::target( $c, CompanyTarget::class )
			)
		);
		$c->set(
			Reverter::class,
			static fn( Container $c ): Reverter => new Reverter(
				$c->get( GroupRepository::class ),
				$c->get( RegistrationTypeRepository::class ),
				$c->get( FieldRepository::class ),
				$c->get( RuleRepository::class ),
				$c->get( MethodPolicy::class ),
				$c->get( AudienceResolver::class ),
				self::target( $c, CompanyTarget::class )
			)
		);
		$c->set(
			B2BKingSource::class,
			static fn( Container $c ): B2BKingSource => new B2BKingSource(
				$c->get( Settings::class ),
				new B2BKingRules(),
				$c->has( CompanyTarget::class ),
				self::target( $c, VisibilityTarget::class ),
				self::target( $c, QuantityTarget::class ),
				self::target( $c, PriceTarget::class )
			)
		);
		$c->set(
			Importer::class,
			static function ( Container $c ): Importer {
				/**
				 * Sources the importer can read from (MIG-001).
				 *
				 * @param SourceInterface[] $sources Sources.
				 */
				$sources = apply_filters( 'eurob2b_migration_sources', array( $c->get( B2BKingSource::class ), new WholesaleSuiteSource() ) );
				$sources = array_filter( is_array( $sources ) ? $sources : array(), static fn( $s ): bool => $s instanceof SourceInterface );
				return new Importer( $sources, $c->get( ImportWriter::class ), $c->get( Reverter::class ), $c->get( Journal::class ) );
			}
		);
		$c->set( ImportSection::class, static fn( Container $c ): ImportSection => new ImportSection( $c->get( Importer::class ) ) );
		$c->set( JournalRetention::class, static fn( Container $c ): JournalRetention => new JournalRetention( $c->get( Journal::class ) ) );
		$c->set( MigrationDataProvider::class, static fn( Container $c ): MigrationDataProvider => new MigrationDataProvider( $c->get( Journal::class ) ) );
	}

	/**
	 * A target that EuroB2B Pro registers (company accounts, visibility, quantity limits, customer
	 * prices and tier sets), null without it: the import then leaves those parts out (PLT-015).
	 * Rules of EuroB2B Pro's types and modes are refused by the rules form without it.
	 *
	 * @template T of object
	 * @param Container $c  Service container.
	 * @param string    $id Interface of the target.
	 * @phpstan-param class-string<T> $id
	 * @return T|null
	 */
	private static function target( Container $c, string $id ): ?object {
		$target = $c->has( $id ) ? $c->get( $id ) : null;
		return $target instanceof $id ? $target : null;
	}

	/**
	 * Adds hooks.
	 *
	 * @param Container $c Service container.
	 */
	public function boot( Container $c ): void {
		add_action(
			Importer::STEP_ACTION,
			static function () use ( $c ): void {
				$c->get( Importer::class )->step();
			}
		);
		add_filter(
			'eurob2b_personal_data_providers',
			static function ( $providers ) use ( $c ) {
				$providers   = is_array( $providers ) ? $providers : array();
				$providers[] = $c->get( MigrationDataProvider::class );
				return $providers;
			}
		);
		add_filter(
			'eurob2b_retention_tasks',
			static function ( $tasks ) use ( $c ) {
				$tasks   = is_array( $tasks ) ? $tasks : array();
				$tasks[] = $c->get( JournalRetention::class );
				return $tasks;
			}
		);
		// A deleted account must not stay in the journal; an undo then leaves it alone.
		add_action(
			'deleted_user',
			static function ( $user_id ) use ( $c ): void {
				$c->get( Journal::class )->forget_user( (int) $user_id );
			}
		);
		if ( is_admin() ) {
			$c->get( ImportSection::class )->hooks();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'eurob2b import', new ImportCommand( $c->get( Importer::class ) ) );
		}
	}
}

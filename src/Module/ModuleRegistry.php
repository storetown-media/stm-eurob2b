<?php
/**
 * Module registry.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Module;

use EuroB2B\Container;
use InvalidArgumentException;

/**
 * Keeps the ordered list of modules and drives their two-phase start-up.
 */
final class ModuleRegistry {

	/**
	 * Modules by id, in registration order.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();

	/**
	 * Service container handed to modules.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Adds a module. Ids must be unique.
	 *
	 * Modules register and boot in the order they were added. An add-on (EuroB2B Pro) can place a
	 * module right after a module that is already there, so that its hooks run in the same order as
	 * if the module were part of the core list; when that module is not there, the new one goes last.
	 *
	 * @param ModuleInterface $module Module.
	 * @param string|null     $after  Id of the module to insert it after; null adds it at the end.
	 * @throws InvalidArgumentException On duplicate id.
	 */
	public function add( ModuleInterface $module, ?string $after = null ): void {
		$id = $module->id();
		if ( isset( $this->modules[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'EuroB2B: module "%s" is registered twice.', $id ) );
		}
		if ( null === $after || ! isset( $this->modules[ $after ] ) ) {
			$this->modules[ $id ] = $module;
			return;
		}
		$ordered = array();
		foreach ( $this->modules as $key => $existing ) {
			$ordered[ $key ] = $existing;
			if ( $key === $after ) {
				$ordered[ $id ] = $module;
			}
		}
		$this->modules = $ordered;
	}

	/**
	 * Whether a module with this id exists.
	 *
	 * @param string $id Module id.
	 */
	public function has( string $id ): bool {
		return isset( $this->modules[ $id ] );
	}

	/**
	 * Returns a module by id.
	 *
	 * @param string $id Module id.
	 */
	public function get( string $id ): ?ModuleInterface {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * All module ids in boot order.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->modules );
	}

	/**
	 * Phase one: service registration.
	 */
	public function register_all(): void {
		foreach ( $this->modules as $module ) {
			$module->register( $this->container );
		}
	}

	/**
	 * Phase two: hooks.
	 */
	public function boot_all(): void {
		foreach ( $this->modules as $module ) {
			$module->boot( $this->container );
		}
	}
}

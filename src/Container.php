<?php
/**
 * Minimal service container.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B;

use Closure;
use RuntimeException;

/**
 * Lazily instantiated, shared services keyed by class name or string id.
 *
 * Deliberately tiny: no autowiring, no reflection. Every service is registered
 * explicitly by its module so the dependency graph stays visible.
 */
final class Container {

	/**
	 * Service factories.
	 *
	 * @var array<string, Closure>
	 */
	private array $factories = array();

	/**
	 * Resolved shared instances.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Registers a shared service factory.
	 *
	 * @param string  $id      Service id, usually the class or interface name.
	 * @param Closure $factory Receives the container, returns the service.
	 */
	public function set( string $id, Closure $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Whether a service is known.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->instances );
	}

	/**
	 * Resolves a service, instantiating it on first access.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 * @throws RuntimeException When the service is unknown.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new RuntimeException( sprintf( 'EuroB2B: service "%s" is not registered.', $id ) );
		}
		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		return $this->instances[ $id ];
	}
}

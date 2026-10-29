<?php
/**
 * Module contract.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Module;

use EuroB2B\Container;

/**
 * A module owns one bounded area (customers, pricing, quotes, ...).
 *
 * The register() phase wires services into the container and must not add hooks.
 * The boot() phase adds hooks and may resolve services from other modules.
 */
interface ModuleInterface {

	/**
	 * Unique, stable id (lower snake case). Used for settings, filters and docs.
	 */
	public function id(): string;

	/**
	 * Registers services. Called for all modules before any boot().
	 *
	 * @param Container $container Service container.
	 */
	public function register( Container $container ): void;

	/**
	 * Adds hooks. Called after every module has registered.
	 *
	 * @param Container $container Service container.
	 */
	public function boot( Container $container ): void;
}

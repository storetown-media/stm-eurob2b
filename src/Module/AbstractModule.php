<?php
/**
 * Base module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Module;

use EuroB2B\Container;

/**
 * Convenience base class with no-op phases.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * Registers services (no-op by default).
	 *
	 * @param Container $container Service container.
	 */
	public function register( Container $container ): void {}

	/**
	 * Adds hooks (no-op by default).
	 *
	 * @param Container $container Service container.
	 */
	public function boot( Container $container ): void {}
}

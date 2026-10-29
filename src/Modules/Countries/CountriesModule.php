<?php
/**
 * Countries module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Countries;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;

/**
 * Provides country classification and, later, country profiles.
 */
final class CountriesModule extends AbstractModule {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'countries';
	}

	/**
	 * Registers the registry as a service so other modules can be given test doubles.
	 *
	 * @param Container $container Service container.
	 */
	public function register( Container $container ): void {
		$container->set( CountryRegistry::class, static fn() => new CountryRegistry() );
	}
}

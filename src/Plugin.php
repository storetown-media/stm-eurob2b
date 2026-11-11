<?php
/**
 * Plugin bootstrap.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B;

use EuroB2B\Infrastructure\Installer;
use EuroB2B\Module\ModuleInterface;
use EuroB2B\Module\ModuleRegistry;
use EuroB2B\Modules\Audit\AuditModule;
use EuroB2B\Modules\Admin\AdminModule;
use EuroB2B\Modules\Countries\CountriesModule;
use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Privacy\PrivacyModule;
use EuroB2B\Modules\Registration\RegistrationModule;
use EuroB2B\Modules\Pricing\PricingModule;
use EuroB2B\Modules\Rules\RulesModule;
use EuroB2B\Modules\Orders\OrdersModule;
use EuroB2B\Modules\Payments\PaymentsModule;
use EuroB2B\Modules\Migration\MigrationModule;
use EuroB2B\Modules\Tax\TaxModule;
use EuroB2B\Support\Assets;
use EuroB2B\Support\Ui;

/**
 * Central entry point. Builds the container, registers modules and boots them.
 *
 * Modules never talk to each other directly; they share services through the
 * container and communicate through WordPress hooks (see docs/architecture.md).
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Module registry.
	 *
	 * @var ModuleRegistry
	 */
	private ModuleRegistry $modules;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private: use instance().
	 */
	private function __construct() {
		$this->container = new Container();
		$this->modules   = new ModuleRegistry( $this->container );
	}

	/**
	 * Registers and boots all modules. Safe to call once per request.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->container->set( self::class, fn() => $this );

		foreach ( $this->default_modules() as $module ) {
			$this->modules->add( $module );
		}

		/**
		 * Allows add-ons to register additional modules before boot.
		 *
		 * @param ModuleRegistry $modules   Module registry.
		 * @param Container      $container Service container.
		 */
		do_action( 'eurob2b_register_modules', $this->modules, $this->container );

		$this->modules->register_all();

		// Run pending database migrations after a plugin update (the activation hook does not fire then).
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );

		// Translations come from translate.wordpress.org: WordPress loads the catalogue of the text
		// domain stm-eu-b2b just in time, and the JavaScript translations of the blocks through the
		// textdomain of their block.json; the plugin ships no catalogue and registers no folder.
		// The editor scripts of the plugin's blocks are versioned like every other plugin asset.
		add_filter( 'block_type_metadata', array( Assets::class, 'block_metadata' ) );

		$this->modules->boot_all();

		// Shared storefront stylesheet of all customer-facing forms and tables (docs/frontend.md).
		Ui::hooks();

		/**
		 * Fires once every module has been booted.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'eurob2b_loaded', $this );
	}

	/**
	 * Returns the service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Returns the module registry.
	 */
	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	/**
	 * Modules shipped with the core plugin (the free edition), in boot order. EU-B2B Pro adds its
	 * modules through "eurob2b_register_modules" (docs/architecture.md, section "Editions").
	 *
	 * @return ModuleInterface[]
	 */
	private function default_modules(): array {
		return array(
			new AdminModule(),
			new CountriesModule(),
			new CustomersModule(),
			new RegistrationModule(),
			new PricingModule(),
			new RulesModule(),
			new PaymentsModule(),
			new OrdersModule(),
			new TaxModule(),
			new PrivacyModule(),
			new AuditModule(),
			new MigrationModule(),
		);
	}
}

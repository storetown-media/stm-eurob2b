<?php
/**
 * Payments module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Payments\Admin\MethodsPage;
use EuroB2B\Modules\Payments\Admin\PaymentsTransferParts;
use EuroB2B\Modules\Payments\Admin\UserMethodFields;
use EuroB2B\Modules\Payments\Dunning\DunningRecords;
use EuroB2B\Modules\Pricing\PricingContext;
use EuroB2B\Modules\Privacy\PrivateFiles;

/**
 * M4.1/4.2 (core): payment gateways and shipping methods per audience and user (PAY-001…005,
 * SHP-001/002), with the method page, the user profile fields and their parts of the settings file.
 * The privacy handling of the data that EuroB2B Pro writes to orders stays here, so it works also
 * while Pro is inactive (PLT-015): the export, erasure answer, retention and deletion of the
 * purchase order documents (PAY-015, D-25) and the export and anonymisation of the dunning history
 * (PAY-023, D-28). The pay-later gateways, payment terms, purchase order number and documents,
 * payment windows, method surcharges, shipping costs per group, overdue orders and dunning are part
 * of EuroB2B Pro (`PaymentsProModule`).
 */
final class PaymentsModule extends AbstractModule {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'payments';
	}

	/**
	 * Services.
	 *
	 * @param Container $c Container.
	 */
	public function register( Container $c ): void {
		$c->set( MethodPolicy::class, static fn(): MethodPolicy => new MethodPolicy() );
		$c->set( MethodControl::class, static fn( Container $c ): MethodControl => new MethodControl( $c->get( MethodPolicy::class ), $c->get( AudienceResolver::class ), $c->get( PricingContext::class ) ) );
		$c->set( MethodsPage::class, static fn( Container $c ): MethodsPage => new MethodsPage( $c->get( MethodPolicy::class ), $c->get( GroupRepository::class ) ) );
		$c->set( UserMethodFields::class, static fn( Container $c ): UserMethodFields => new UserMethodFields( $c->get( MethodPolicy::class ), $c->get( MethodsPage::class ) ) );
		$c->set( PaymentsDataProvider::class, static fn( Container $c ): PaymentsDataProvider => new PaymentsDataProvider( $c->get( MethodPolicy::class ) ) );
		// PAY-015: purchase order documents in the private file storage (D-25); privacy handling only.
		$c->set( PurchaseOrderDocumentRecords::class, static fn( Container $c ): PurchaseOrderDocumentRecords => new PurchaseOrderDocumentRecords( $c->get( PrivateFiles::class ) ) );
		$c->set( PurchaseOrderDocumentsDataProvider::class, static fn( Container $c ): PurchaseOrderDocumentsDataProvider => new PurchaseOrderDocumentsDataProvider( $c->get( PurchaseOrderDocumentRecords::class ) ) );
		$c->set( PurchaseOrderDocumentsRetention::class, static fn( Container $c ): PurchaseOrderDocumentsRetention => new PurchaseOrderDocumentsRetention( $c->get( PurchaseOrderDocumentRecords::class ) ) );
	}

	/**
	 * Hooks.
	 *
	 * @param Container $c Container.
	 */
	public function boot( Container $c ): void {
		// The method policies in the settings file (PLT-012).
		add_filter( 'eurob2b_settings_transfer_parts', static fn( $parts ) => PaymentsTransferParts::add( $parts, $c ) );
		// Privacy export/erasure of per-customer method lists (also without WooCommerce: the meta exists).
		add_filter(
			'eurob2b_personal_data_providers',
			static function ( $providers ) use ( $c ) {
				$providers   = is_array( $providers ) ? $providers : array();
				$providers[] = $c->get( PaymentsDataProvider::class );
				if ( function_exists( 'wc_get_orders' ) ) {
					$providers[] = $c->get( PurchaseOrderDocumentsDataProvider::class );
				}
				return $providers;
			}
		);
		add_filter(
			'eurob2b_retention_tasks',
			static function ( $tasks ) use ( $c ) {
				$tasks   = is_array( $tasks ) ? $tasks : array();
				$tasks[] = $c->get( PurchaseOrderDocumentsRetention::class );
				return $tasks;
			}
		);
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		$c->get( MethodControl::class )->hooks();
		// PAY-023: the dunning history goes with WooCommerce's order export and anonymisation.
		DunningRecords::hooks();
		// PAY-015: the purchase order documents go with the order (deletion, anonymisation).
		$c->get( PurchaseOrderDocumentRecords::class )->hooks();
		if ( is_admin() ) {
			$page = $c->get( MethodsPage::class );
			add_action( 'eurob2b_admin_menu', array( $page, 'register_page' ) );
			add_action( 'admin_post_' . MethodsPage::ACTION_SAVE, array( $page, 'handle_save' ) );
			$c->get( UserMethodFields::class )->hooks();
		}
	}
}

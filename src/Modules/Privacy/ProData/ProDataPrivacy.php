<?php
/**
 * Privacy handling of the data EuroB2B Pro stores.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use EuroB2B\Container;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;
use EuroB2B\Modules\Privacy\RetentionTaskInterface;

/**
 * Wires the privacy handling of the data the features of EuroB2B Pro write into the shared schema
 * (product owner decision of 2026-10-29, PLT-015): personal data export and erasure, the retention
 * jobs, the clean-up when a WordPress user is deleted, WooCommerce's order export and order
 * anonymisation. It runs in the core, so a shop that deactivates or deletes EuroB2B Pro can still
 * answer requests and the retention periods keep running. EuroB2B Pro registers no second copy.
 *
 * Company accounts, order approval and acting as a member (D-04, D-05, D-14): `CompanyRecords`,
 * `CompanyDataProvider`. Quotes and offers (D-06): `QuoteRecords`, `QuotesDataProvider`,
 * `QuoteRetention`, `GuestQuoteRetention`. Conversations (D-07): `ConversationRecords`,
 * `ConversationsDataProvider`, `ConversationRetention`. Purchase lists (D-08): `PurchaseListRecords`,
 * `PurchaseListsDataProvider`. E-invoicing data and the Peppol Directory lookup (D-21, D-30):
 * `InvoicingRecords`, `InvoicingDataProvider`. The debtor account of the accounting export (D-31):
 * `DatevDataProvider`. The audit log entries Pro writes (order approval, acting as a member) are
 * handled by the Audit module with all other entries.
 */
final class ProDataPrivacy {

	/**
	 * Priority on `eurob2b_personal_data_providers` and `eurob2b_retention_tasks`: after the modules
	 * (10), so the entries can take the place they had when EuroB2B Pro registered them.
	 */
	public const PRIORITY = 20;

	/**
	 * Order of the providers as the modules register them with EuroB2B Pro active (module boot order).
	 * A provider of this class goes right after the nearest one before it in this list that is
	 * registered, so the exporters, erasers and the export file keep their order.
	 */
	private const PROVIDER_ORDER = array( 'company', 'customer', 'customer_prices', 'purchase_lists', 'payments', 'purchase_order_documents', 'quotes', 'conversations', 'tax', 'proof_of_supply', 'export_evidence', 'invoicing', 'audit_log', 'datev', 'migration' );

	/**
	 * Order of the retention tasks, likewise.
	 */
	private const TASK_ORDER = array( 'rejected_applications', 'registration_files', 'purchase_order_documents', 'quotes', 'guest_quotes', 'conversations', 'proof_of_supply', 'export_evidence', 'audit_log', 'migration_journal' );

	/**
	 * Registers the services.
	 *
	 * @param Container $c Service container.
	 */
	public static function register( Container $c ): void {
		$c->set( CompanyRecords::class, static fn(): CompanyRecords => new CompanyRecords() );
		$c->set( CompanyDataProvider::class, static fn( Container $c ): CompanyDataProvider => new CompanyDataProvider( $c->get( CompanyRecords::class ) ) );
		$c->set( QuoteRecords::class, static fn(): QuoteRecords => new QuoteRecords() );
		$c->set( QuotesDataProvider::class, static fn( Container $c ): QuotesDataProvider => new QuotesDataProvider( $c->get( QuoteRecords::class ) ) );
		$c->set( QuoteRetention::class, static fn( Container $c ): QuoteRetention => new QuoteRetention( $c->get( QuoteRecords::class ), $c->get( Settings::class ) ) );
		$c->set( GuestQuoteRetention::class, static fn( Container $c ): GuestQuoteRetention => new GuestQuoteRetention( $c->get( QuoteRecords::class ), $c->get( Settings::class ) ) );
		$c->set( ConversationRecords::class, static fn(): ConversationRecords => new ConversationRecords() );
		$c->set( ConversationsDataProvider::class, static fn( Container $c ): ConversationsDataProvider => new ConversationsDataProvider( $c->get( ConversationRecords::class ) ) );
		$c->set( ConversationRetention::class, static fn( Container $c ): ConversationRetention => new ConversationRetention( $c->get( ConversationRecords::class ), $c->get( Settings::class ) ) );
		$c->set( PurchaseListRecords::class, static fn(): PurchaseListRecords => new PurchaseListRecords() );
		$c->set( PurchaseListsDataProvider::class, static fn( Container $c ): PurchaseListsDataProvider => new PurchaseListsDataProvider( $c->get( PurchaseListRecords::class ) ) );
		$c->set( InvoicingDataProvider::class, static fn(): InvoicingDataProvider => new InvoicingDataProvider() );
		$c->set( DatevDataProvider::class, static fn(): DatevDataProvider => new DatevDataProvider() );
	}

	/**
	 * Adds the hooks.
	 *
	 * @param Container $c Service container.
	 */
	public static function hooks( Container $c ): void {
		// The purchase lists name their products and the order hooks need WooCommerce (as before the split).
		$products = class_exists( 'WC_Product' );
		add_filter(
			'eurob2b_personal_data_providers',
			static function ( $providers ) use ( $c, $products ) {
				$providers = is_array( $providers ) ? $providers : array();
				$ours      = array(
					$c->get( CompanyDataProvider::class ),
					$c->get( QuotesDataProvider::class ),
					$c->get( ConversationsDataProvider::class ),
					$c->get( InvoicingDataProvider::class ),
					$c->get( DatevDataProvider::class ),
				);
				if ( $products ) {
					$ours[] = $c->get( PurchaseListsDataProvider::class );
				}
				foreach ( $ours as $provider ) {
					$providers = self::insert( $providers, $provider, self::PROVIDER_ORDER );
				}
				return $providers;
			},
			self::PRIORITY
		);
		add_filter(
			'eurob2b_retention_tasks',
			static function ( $tasks ) use ( $c ) {
				$tasks = is_array( $tasks ) ? $tasks : array();
				foreach ( array( QuoteRetention::class, GuestQuoteRetention::class, ConversationRetention::class ) as $task ) {
					$tasks = self::insert( $tasks, $c->get( $task ), self::TASK_ORDER );
				}
				return $tasks;
			},
			self::PRIORITY
		);

		// A deleted WordPress user takes their memberships, quotes, conversations and purchase lists along.
		add_action( 'deleted_user', static fn( $user_id ) => $c->get( CompanyRecords::class )->on_deleted_user( $user_id ) );
		add_action( 'deleted_user', static fn( $user_id ) => $c->get( QuoteRecords::class )->erase_user( (int) $user_id ) );
		add_action( 'deleted_user', static fn( $user_id ) => $c->get( ConversationRecords::class )->erase_user( (int) $user_id ) );
		if ( $products ) {
			add_action( 'deleted_user', static fn( $user_id ) => $c->get( PurchaseListRecords::class )->delete_for_user( (int) $user_id ) );
			// Placed by, acted by, approver and approval note on orders (D-05).
			add_action( 'woocommerce_privacy_remove_order_personal_data', array( CompanyRecords::class, 'anonymise_order' ) );
		}
		if ( class_exists( 'WooCommerce' ) ) {
			// The e-invoicing copies on orders (D-21), independent of the setting: older orders still carry them.
			add_action( 'woocommerce_privacy_remove_order_personal_data', array( InvoicingRecords::class, 'anonymise_order' ) );
			add_filter( 'woocommerce_privacy_export_order_personal_data_props', array( InvoicingRecords::class, 'export_props' ), 10, 2 );
			add_filter( 'woocommerce_privacy_export_order_personal_data_prop', array( InvoicingRecords::class, 'export_prop' ), 10, 3 );
		}
	}

	/**
	 * Inserts a provider or task at its place: right after the nearest entry before it in $order that
	 * the list holds, at the start when there is none. An entry with the same id is not added twice.
	 *
	 * @param array<int|string, mixed>                             $entries Providers or tasks.
	 * @param PersonalDataProviderInterface|RetentionTaskInterface $item    Entry to add.
	 * @param string[]                                             $order   Ids in their order.
	 * @return array<int|string, mixed>
	 */
	private static function insert( array $entries, $item, array $order ): array {
		$entries = array_values( $entries );
		$ids     = array_map( static fn( $entry ): ?string => $entry instanceof PersonalDataProviderInterface || $entry instanceof RetentionTaskInterface ? $entry->id() : null, $entries );
		$index   = array_search( $item->id(), $order, true );
		if ( in_array( $item->id(), $ids, true ) ) {
			return $entries;
		}
		$at = false === $index ? count( $entries ) : 0;
		for ( $i = false === $index ? -1 : (int) $index - 1; $i >= 0; $i-- ) {
			$found = array_search( $order[ $i ], $ids, true );
			if ( false !== $found ) {
				$at = (int) $found + 1;
				break;
			}
		}
		array_splice( $entries, $at, 0, array( $item ) );
		return $entries;
	}
}

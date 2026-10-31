<?php
/**
 * Database schema definitions.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Infrastructure;

/**
 * Single place that knows every table and option the plugin owns.
 *
 * Migrations create and alter the tables; the uninstaller drops them.
 * Keep this list complete, it doubles as the data inventory index.
 */
final class Schema {

	public const OPTION_DB_VERSION      = 'eurob2b_db_version';
	public const OPTION_PLUGIN_VERSION  = 'eurob2b_version';
	public const OPTION_INSTALLED_AT    = 'eurob2b_installed_at';
	public const OPTION_SETTINGS        = 'eurob2b_settings';
	public const OPTION_REMOVE_DATA     = 'eurob2b_remove_data_on_uninstall';
	public const OPTION_MIGRATION_ERROR = 'eurob2b_migration_error';
	public const OPTION_MIGRATION_LOCK  = 'eurob2b_migration_lock';
	public const TRANSIENT_PREFIX       = 'eurob2b_';
	public const USER_META_PREFIX       = '_eurob2b_';
	public const ACTION_GROUP           = 'eurob2b';

	/**
	 * Settings with the controller details of the record of processing activities (PRV-010): contact
	 * details and names of people, so they never travel with a settings export and are removed whenever
	 * EuroB2B is deleted, also without the opt-in to remove all data (product owner decision of 2026-10-31).
	 */
	public const RECORD_CONTROLLER_SETTINGS = array( 'record_contact', 'record_contact_person', 'record_joint_controllers', 'record_eu_representative', 'record_dpo' );

	/**
	 * Table suffixes (without the WordPress prefix).
	 *
	 * @return string[]
	 */
	public static function table_suffixes(): array {
		return array(
			'eurob2b_groups',
			'eurob2b_vat_validations',
			'eurob2b_rules',
			'eurob2b_purchase_lists',
			'eurob2b_purchase_list_items',
			'eurob2b_companies',
			'eurob2b_company_members',
			'eurob2b_quotes',
			'eurob2b_quote_items',
			'eurob2b_conversations',
			'eurob2b_messages',
			'eurob2b_audit_log',
			'eurob2b_files',
			'eurob2b_migration_log',
		);
	}

	/**
	 * Fully prefixed table name.
	 *
	 * @param string $suffix One of table_suffixes().
	 */
	public static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . $suffix;
	}

	/**
	 * Options owned by the plugin.
	 *
	 * @return string[]
	 */
	public static function options(): array {
		return array(
			self::OPTION_DB_VERSION,
			self::OPTION_PLUGIN_VERSION,
			self::OPTION_INSTALLED_AT,
			self::OPTION_SETTINGS,
			self::OPTION_REMOVE_DATA,
			self::OPTION_MIGRATION_ERROR,
			self::OPTION_MIGRATION_LOCK,
			// Registration module (kept as literals: the uninstaller must not depend on module code).
			'eurob2b_registration_types',
			'eurob2b_registration_fields',
			'eurob2b_registration_fields_deleted',
			'eurob2b_registration_fields_retired_mappings',
			// Ordering module: remembers whether the My Account order form endpoint is registered.
			'eurob2b_order_form_tab',
			'eurob2b_csv_order_tab',
			'eurob2b_lists_tab',
			'eurob2b_company_tab',
			'eurob2b_approvals_tab',
			'eurob2b_quotes_tab',
			'eurob2b_conversations_tab',
			// Tax module: the My Account endpoint "Confirm receipt" (TAX-030).
			'eurob2b_receipt_tab',
			// Quotes module: custom request field definitions.
			'eurob2b_quote_fields',
			// Payments module: method policies per audience and payment terms.
			'eurob2b_payment_policy',
			'eurob2b_shipping_policy',
			'eurob2b_payment_adjustments',
			'eurob2b_shipping_costs',
			'eurob2b_payment_terms',
			// Admin module: the configuration as it was before the last settings import.
			'eurob2b_settings_backup',
			// Tax module: OSS threshold notices the shop dismissed, per year and level (TAX-033).
			'eurob2b_oss_notice_dismissed',
			// Tax module: the Northern Ireland notice was dismissed (TAX-034).
			'eurob2b_northern_ireland_notice_dismissed',
			// Privacy module: counts of the last retention run.
			'eurob2b_retention_last_run',
			// Migration module: state and report of the current import, source-to-EuroB2B id map, step lock.
			'eurob2b_migration_run',
			'eurob2b_migration_map',
			'eurob2b_migration_lock',
			// Reports module: accounts and tax keys of the DATEV export (RPT-006).
			'eurob2b_datev_mapping',
			// EuroB2B Pro: licence key and the last licence check (PLT-016; pro/uninstall.php removes them too).
			'eurob2b_pro_licence_key',
			'eurob2b_pro_licence_state',
		);
	}

	/**
	 * WP-Cron hooks the plugin schedules (literals: the uninstaller must not depend on module code).
	 *
	 * @return string[]
	 */
	public static function cron_hooks(): array {
		return array(
			'eurob2b_vat_maintenance',
			'eurob2b_vat_revalidate',
			'eurob2b_privacy_retention',
			'eurob2b_privacy_retention_more',
			'eurob2b_oss_monitor_refresh',
		);
	}

	/**
	 * Post types whose plugin meta goes with the opt-in uninstall. Orders are deliberately missing:
	 * they are WooCommerce's records (and live in posts when HPOS is off).
	 *
	 * @return string[]
	 */
	public static function meta_post_types(): array {
		return array( 'product', 'product_variation', 'shop_coupon' );
	}
}

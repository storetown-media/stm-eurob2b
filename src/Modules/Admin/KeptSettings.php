<?php
/**
 * Settings of EuroB2B Pro that a save without it keeps.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

/**
 * The ids of the settings fields that EuroB2B Pro registers (PLT-015, product owner decision of
 * 2026-10-29). Saving the settings while EuroB2B Pro is inactive keeps their stored values, so the
 * Pro configuration is still there when Pro is active again, and the privacy handling in the core
 * reads the retention periods the shop chose (`Settings::stored()`). Any other key the schema does
 * not know is dropped, as before. `ProSettingKeysTest` checks this list against the fields the Pro
 * modules register.
 */
final class KeptSettings {

	/**
	 * Field ids, sorted.
	 */
	public const KEYS = array(
		'access_b2b_only',
		'access_b2b_only_text',
		'access_guest_mode',
		'access_hidden_price_text',
		'access_notice_text',
		'access_public_pages',
		'access_restrict_all_pages',
		'approval_self_allowed',
		'company_enabled',
		'company_member_login',
		'conversations_audience',
		'conversations_enabled',
		'conversations_retention_months',
		'coupons_disabled_for_b2b',
		'coupons_group_values',
		'csv_order_account_tab',
		'csv_order_enabled',
		'csv_order_max_kb',
		'datev_account_length',
		'datev_chart',
		'datev_client_number',
		'datev_consultant_number',
		'datev_debtor_account',
		'datev_fiscal_year_start',
		'einvoice_enabled',
		'einvoice_peppol_lookup',
		'einvoice_reference_mode',
		'lists_audience',
		'lists_csv_delimiter',
		'lists_enabled',
		'order_form_account_tab',
		'order_form_audience',
		'order_form_enabled',
		'order_form_per_page',
		'order_form_rate_limit',
		'payments_base_rates',
		'payments_dunning_gap_days',
		'payments_po_audience',
		'payments_po_document',
		'payments_po_mode',
		'payments_terms_default',
		'pricing_rrp_audience',
		'pricing_rrp_source',
		'pricing_tier_basis',
		'pricing_tier_table',
		'quantity_rules_enabled',
		'quotes_audience',
		'quotes_enabled',
		'quotes_guest_retention_days',
		'quotes_guest_throttle_limit',
		'quotes_guests',
		'quotes_hide_prices',
		'quotes_pdf_attach',
		'quotes_print_logo',
		'quotes_retention_days',
		'reports_csv_delimiter',
		'reports_statuses',
		'stock_b2b_always_in_stock',
		'stock_b2b_no_reduction',
		'stock_quantity_display',
		'stock_separate_pools',
		'tax_brreg_enabled',
		'tax_bzst_mode',
		'tax_export_evidence_enabled',
		'tax_northern_ireland',
		'tax_special_territories',
		'tax_uid_enabled',
		'variation_table',
		'variation_table_audience',
		'visibility_control',
		'visibility_hidden_url',
		'visibility_mixed',
	);

	/**
	 * The stored values of the kept settings that the schema does not know, added to the values
	 * about to be stored.
	 *
	 * @param array<string, mixed> $clean    Values the schema sanitised.
	 * @param mixed                $previous The stored option before the save.
	 * @return array<string, mixed>
	 */
	public static function merge( array $clean, $previous ): array {
		if ( ! is_array( $previous ) ) {
			return $clean;
		}
		foreach ( self::KEYS as $id ) {
			if ( ! array_key_exists( $id, $clean ) && array_key_exists( $id, $previous ) ) {
				$clean[ $id ] = $previous[ $id ];
			}
		}
		return $clean;
	}
}

<?php
/**
 * Tax-rate warning and country profiles.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Countries\CountryProfile;
use EuroB2B\Modules\Countries\CountryProfiles;
use EuroB2B\Modules\Countries\CountryRegistry;
use EuroB2B\Modules\Countries\TaxArea;

/**
 * TAX-020: warning when an EU customer country has no tax rate (an exemption would be invisible).
 * CTY-004, CTY-005: the country profiles with their review state, VAT rates and thresholds on the
 * VAT IDs page. EuroB2B Pro adds the export of exempt sales (TAX-023), the special territories
 * (TAX-024) and the rate warning for Northern Ireland (TAX-034) (PLT-015).
 */
final class TaxReports {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * EU countries from the VAT ID field setting that have no standard tax rate in WooCommerce.
	 *
	 * @return string[] ISO codes.
	 */
	public function countries_without_tax_rate(): array {
		if ( ! wc_tax_enabled() || ! class_exists( 'WC_Tax' ) ) {
			return array();
		}
		$countries = $this->settings->get( 'tax_vat_field_countries' );
		$missing   = array();
		foreach ( is_array( $countries ) ? $countries : array() as $iso ) {
			$iso = strtoupper( (string) $iso );
			if ( in_array( CountryRegistry::tax_area( $iso ), array( TaxArea::SWISS_VAT_AREA, TaxArea::EEA_OWN_VAT ), true ) ) {
				// EuroB2B exempts nothing for these countries (CH-05, NO-02), so a missing rate hides nothing.
				continue;
			}
			$rates = \WC_Tax::find_rates(
				array(
					'country'   => $iso,
					'tax_class' => '',
				)
			);
			if ( empty( $rates ) ) {
				$missing[] = $iso;
			}
		}
		return $missing;
	}

	/**
	 * Admin notice on the plugin's pages when customer countries lack a tax rate.
	 */
	public function render_rate_warning(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || false === strpos( (string) $screen->id, 'eurob2b' ) || ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}
		$missing = $this->countries_without_tax_rate();
		if ( empty( $missing ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: comma-separated country codes */
				__( 'EuroB2B: no WooCommerce tax rate exists for these customer countries: %s. Without a rate, taxed and exempt orders look the same to the customer; add the rates under WooCommerce → Settings → Tax.', 'eurob2b' ),
				implode( ', ', $missing )
			)
		) . '</p></div>';
	}

	/**
	 * Country profiles with their review state (CTY-004).
	 */
	public function render_profiles(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}
		$labels = array(
			CountryProfile::REVIEW_REVIEWED     => __( 'Reviewed', 'eurob2b' ),
			CountryProfile::REVIEW_NEEDS_REVIEW => __( 'Researched, needs professional review', 'eurob2b' ),
			CountryProfile::REVIEW_UNVERIFIED   => __( 'Unverified', 'eurob2b' ),
		);
		echo '<h2>' . esc_html__( 'Country profiles', 'eurob2b' ) . '</h2>';
		echo '<p>' . esc_html__( 'Countries that are not reviewed are taxed and flagged instead of exempted, unless you accept unreviewed rules in the settings.', 'eurob2b' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Country', 'eurob2b' ) . '</th><th>' . esc_html__( 'Company identifier', 'eurob2b' ) . '</th><th>' . esc_html__( 'Domestic B2B e-invoicing', 'eurob2b' ) . '</th><th>' . esc_html__( 'Review', 'eurob2b' ) . '</th><th>' . esc_html__( 'Rules', 'eurob2b' ) . '</th></tr></thead><tbody>';
		foreach ( CountryProfiles::all() as $profile ) {
			echo '<tr><td>' . esc_html( self::country_label( $profile ) ) . '</td><td>' . esc_html( self::profile_text( $profile->company_identifier ) ) . '</td><td>' . esc_html( self::profile_text( $profile->einvoicing ) ) . '</td><td>' . esc_html( $labels[ $profile->review ] ?? $profile->review ) . '</td><td>' . esc_html( $profile->rules_version() . ' · ' . $profile->rules_checked_at() ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->render_non_eu_rules();
	}

	/**
	 * VAT rates and registration thresholds of the profiles outside the EU (CTY-005), with sources.
	 */
	private function render_non_eu_rules(): void {
		$profiles = array_filter( CountryProfiles::all(), static fn( CountryProfile $profile ): bool => array() !== $profile->vat_rates() || array() !== $profile->thresholds() );
		if ( array() === $profiles ) {
			return;
		}
		echo '<h3>' . esc_html__( 'VAT rates and registration thresholds outside the EU', 'eurob2b' ) . '</h3>';
		echo '<p>' . esc_html__( 'For your information: EuroB2B does not exempt sales to these countries; your WooCommerce tax rates decide. If your sales exceed a threshold, you may have to register for VAT there and charge its VAT; ask your tax adviser.', 'eurob2b' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Country', 'eurob2b' ) . '</th><th>' . esc_html__( 'VAT rates', 'eurob2b' ) . '</th><th>' . esc_html__( 'Thresholds', 'eurob2b' ) . '</th><th>' . esc_html__( 'Sources', 'eurob2b' ) . '</th></tr></thead><tbody>';
		foreach ( $profiles as $profile ) {
			$rates   = array();
			$sources = array();
			foreach ( $profile->vat_rates() as $rate ) {
				$rates[]                    = $rate['rate'] . ' % ' . self::rate_label( $rate['type'] ) . ' (' . $rate['valid_from'] . ')';
				$sources[ $rate['source'] ] = true;
			}
			$thresholds = array();
			foreach ( $profile->thresholds() as $threshold ) {
				$thresholds[]                    = $threshold['currency'] . ' ' . number_format_i18n( $threshold['amount'] ) . ': ' . self::threshold_basis( $threshold['basis'] );
				$sources[ $threshold['source'] ] = true;
			}
			$links = array();
			foreach ( array_keys( $sources ) as $url ) {
				$links[] = '<a href="' . esc_url( $url ) . '" rel="noreferrer">' . esc_html( (string) wp_parse_url( $url, PHP_URL_HOST ) ) . '</a>';
			}
			echo '<tr><td>' . esc_html( self::country_label( $profile ) ) . '</td><td>' . esc_html( implode( '; ', $rates ) ) . '</td><td>' . esc_html( implode( '; ', $thresholds ) ) . '</td><td>' . implode( ' ', $links ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links escaped above.
		}
		echo '</tbody></table>';
	}

	/**
	 * Name of a VAT rate type of a country profile.
	 *
	 * @param string $type Rate type.
	 */
	public static function rate_label( string $type ): string {
		return match ( $type ) {
			'standard'                => __( 'standard rate', 'eurob2b' ),
			'reduced'                 => __( 'reduced rate', 'eurob2b' ),
			'accommodation'           => __( 'accommodation', 'eurob2b' ),
			'food'                    => __( 'food', 'eurob2b' ),
			'transport_accommodation' => __( 'passenger transport and accommodation', 'eurob2b' ),
			default                   => $type,
		};
	}

	/**
	 * Translated explanation of a threshold. The profiles hold the English text (they make no
	 * WordPress calls); a text a filter added without a translation is shown as it is.
	 *
	 * @param string $basis English explanation from the country profile.
	 */
	public static function threshold_basis( string $basis ): string {
		return self::threshold_bases()[ $basis ] ?? $basis;
	}

	/**
	 * English explanation => translation, for every threshold of the shipped profiles
	 * (`TaxReportsTest` keeps this list and CountryProfiles in step).
	 *
	 * @return array<string, string>
	 */
	public static function threshold_bases(): array {
		return array(
			'Turnover per year from taxable and exempt supplies in Switzerland and abroad, for a business that supplies in Switzerland; a foreign business needs a tax representative' => __( 'Turnover per year from taxable and exempt supplies in Switzerland and abroad, for a business that supplies in Switzerland; a foreign business needs a tax representative', 'eurob2b' ),
			'Turnover per year from small consignments to Switzerland and Liechtenstein (import tax of at most CHF 5); from then on every consignment is a domestic supply' => __( 'Turnover per year from small consignments to Switzerland and Liechtenstein (import tax of at most CHF 5); from then on every consignment is a domestic supply', 'eurob2b' ),
			'Turnover per year in Liechtenstein and abroad from supplies that are not excluded from the tax under Art. 21(2) MWSTG (Art. 10(2)(a) MWSTG)' => __( 'Turnover per year in Liechtenstein and abroad from supplies that are not excluded from the tax under Art. 21(2) MWSTG (Art. 10(2)(a) MWSTG)', 'eurob2b' ),
			'Taxable sales over 12 months, excluding VAT (NOK 140,000 for charitable and non-profit organisations); a business without establishment in Norway registers through a representative unless it is resident in the United Kingdom or one of the listed EEA countries, which do not include Liechtenstein' => __( 'Taxable sales over 12 months, excluding VAT (NOK 140,000 for charitable and non-profit organisations); a business without establishment in Norway registers through a representative unless it is resident in the United Kingdom or one of the listed EEA countries, which do not include Liechtenstein', 'eurob2b' ),
		);
	}

	/**
	 * Country of a profile as the shop's WooCommerce shows it (translated), with its code. The
	 * profile's English name only where WooCommerce does not know the country.
	 *
	 * @param CountryProfile $profile Profile.
	 */
	public static function country_label( CountryProfile $profile ): string {
		$countries = did_action( 'woocommerce_init' ) ? WC()->countries->get_countries() : array();
		$name      = isset( $countries[ $profile->code ] ) ? html_entity_decode( (string) $countries[ $profile->code ], ENT_QUOTES, 'UTF-8' ) : $profile->name;
		return $name . ' (' . $profile->code . ')';
	}

	/**
	 * Translated company identifier or e-invoicing note of a country profile. The profiles hold the
	 * English text; a text a filter added without a translation is shown as it is.
	 *
	 * @param string|null $text English text from the country profile.
	 */
	public static function profile_text( ?string $text ): string {
		if ( null === $text ) {
			return '';
		}
		return self::profile_texts()[ $text ] ?? $text;
	}

	/**
	 * English text => translation, for the company identifiers and e-invoicing notes of the shipped
	 * profiles (`TaxReportsTest` keeps this list and CountryProfiles in step).
	 *
	 * @return array<string, string>
	 */
	public static function profile_texts(): array {
		return array(
			'Handelsregisternummer (HRA/HRB/GnR/PR/VR + number + register court)' => __( 'Handelsregisternummer (HRA/HRB/GnR/PR/VR + number + register court)', 'eurob2b' ),
			'Reception mandatory since 2025-01-01; issuing mandatory from 2028-01-01 (2027-01-01 for issuers above EUR 800k turnover); XRechnung/ZUGFeRD >= 2.0.1' => __( 'Reception mandatory since 2025-01-01; issuing mandatory from 2028-01-01 (2027-01-01 for issuers above EUR 800k turnover); XRechnung/ZUGFeRD >= 2.0.1', 'eurob2b' ),
			'Firmenbuchnummer (up to 6 digits + check letter)' => __( 'Firmenbuchnummer (up to 6 digits + check letter)', 'eurob2b' ),
			'B2G only (IKT-KonsG § 5)'                    => __( 'B2G only (IKT-KonsG § 5)', 'eurob2b' ),
			'Enterprise number (10 digits, equals the VAT number)' => __( 'Enterprise number (10 digits, equals the VAT number)', 'eurob2b' ),
			'Mandatory since 2026-01-01 (Peppol BIS)'     => __( 'Mandatory since 2026-01-01 (Peppol BIS)', 'eurob2b' ),
			'None adopted'                                => __( 'None adopted', 'eurob2b' ),
			'OIB (11 digits)'                             => __( 'OIB (11 digits)', 'eurob2b' ),
			'Fiskalizacija 2.0: VAT payers since 2026-01-01, others from 2027-01-01' => __( 'Fiskalizacija 2.0: VAT payers since 2026-01-01, others from 2027-01-01', 'eurob2b' ),
			'No mandate'                                  => __( 'No mandate', 'eurob2b' ),
			'IČO (8 digits)'                              => __( 'IČO (8 digits)', 'eurob2b' ),
			'None (B2G ISDOC)'                            => __( 'None (B2G ISDOC)', 'eurob2b' ),
			'CVR (8 digits)'                              => __( 'CVR (8 digits)', 'eurob2b' ),
			'Bookkeeping-system requirements 2027-03-01 / 2028-01-01; no general B2B duty' => __( 'Bookkeeping-system requirements 2027-03-01 / 2028-01-01; no general B2B duty', 'eurob2b' ),
			'Registry code (8 digits)'                    => __( 'Registry code (8 digits)', 'eurob2b' ),
			'Buyers may demand e-invoices since 2025-07-01; mandate proposed' => __( 'Buyers may demand e-invoices since 2025-07-01; mandate proposed', 'eurob2b' ),
			'Y-tunnus (7 digits + check digit)'           => __( 'Y-tunnus (7 digits + check digit)', 'eurob2b' ),
			'B2G; customers may require e-invoices'       => __( 'B2G; customers may require e-invoices', 'eurob2b' ),
			'SIREN (9 digits) / SIRET (14 digits)'        => __( 'SIREN (9 digits) / SIRET (14 digits)', 'eurob2b' ),
			'Since 2026-09-01 (reception all; issuing large/ETI), SMEs issue from 2027-09-01' => __( 'Since 2026-09-01 (reception all; issuing large/ETI), SMEs issue from 2027-09-01', 'eurob2b' ),
			'myDATA phases 2026 (dates conflict across sources)' => __( 'myDATA phases 2026 (dates conflict across sources)', 'eurob2b' ),
			'Tax number (8 digits)'                       => __( 'Tax number (8 digits)', 'eurob2b' ),
			'Real-time invoice data reporting; no general e-invoice mandate' => __( 'Real-time invoice data reporting; no general e-invoice mandate', 'eurob2b' ),
			'CRO number (5-6 digits)'                     => __( 'CRO number (5-6 digits)', 'eurob2b' ),
			'Large corporates issue from 2028-11-01; all must receive' => __( 'Large corporates issue from 2028-11-01; all must receive', 'eurob2b' ),
			'Partita IVA (11 digits); Codice Fiscale (11/16)' => __( 'Partita IVA (11 digits); Codice Fiscale (11/16)', 'eurob2b' ),
			'Mandatory since 2019-01-01 (SdI, FatturaPA)' => __( 'Mandatory since 2019-01-01 (SdI, FatturaPA)', 'eurob2b' ),
			'Registration number (11 digits)'             => __( 'Registration number (11 digits)', 'eurob2b' ),
			'Mandatory 2028-01-01 (Peppol/EN 16931)'      => __( 'Mandatory 2028-01-01 (Peppol/EN 16931)', 'eurob2b' ),
			'Company code (9 digits)'                     => __( 'Company code (9 digits)', 'eurob2b' ),
			'None before 2030'                            => __( 'None before 2030', 'eurob2b' ),
			'RCS number'                                  => __( 'RCS number', 'eurob2b' ),
			'Draft bill: receive 2028-01-01, issue 2028-07/2029-01' => __( 'Draft bill: receive 2028-01-01, issue 2028-07/2029-01', 'eurob2b' ),
			'KVK number (8 digits)'                       => __( 'KVK number (8 digits)', 'eurob2b' ),
			'No B2B mandate'                              => __( 'No B2B mandate', 'eurob2b' ),
			'NIP (10 digits, equals the VAT number); REGON 9/14; KRS 10' => __( 'NIP (10 digits, equals the VAT number); REGON 9/14; KRS 10', 'eurob2b' ),
			'KSeF: 2026-02-01 (> PLN 200m), 2026-04-01 others; small issuers may wait until 2026-12-31' => __( 'KSeF: 2026-02-01 (> PLN 200m), 2026-04-01 others; small issuers may wait until 2026-12-31', 'eurob2b' ),
			'NIF/NIPC (9 digits)'                         => __( 'NIF/NIPC (9 digits)', 'eurob2b' ),
			'No general B2B structured mandate; QES on PDF invoices from 2027-01-01' => __( 'No general B2B structured mandate; QES on PDF invoices from 2027-01-01', 'eurob2b' ),
			'CUI'                                         => __( 'CUI', 'eurob2b' ),
			'RO e-Factura mandatory since 2024-07-01'     => __( 'RO e-Factura mandatory since 2024-07-01', 'eurob2b' ),
			'Mandatory 2027-01-01 (Act 385/2025, Peppol)' => __( 'Mandatory 2027-01-01 (Act 385/2025, Peppol)', 'eurob2b' ),
			'Matična številka'                            => __( 'Matična številka', 'eurob2b' ),
			'ZIERDED adopted 2025-10-23; application 2028-01-01 (date unverified)' => __( 'ZIERDED adopted 2025-10-23; application 2028-01-01 (date unverified)', 'eurob2b' ),
			'NIF (legal-form letter + 7 digits + check character)' => __( 'NIF (legal-form letter + 7 digits + check character)', 'eurob2b' ),
			'Crea y Crece ~2027-10 (> EUR 8m) / ~2028-10 (others); Verifactu 2027' => __( 'Crea y Crece ~2027-10 (> EUR 8m) / ~2028-10 (others); Verifactu 2027', 'eurob2b' ),
			'Organisationsnummer (10 digits)'             => __( 'Organisationsnummer (10 digits)', 'eurob2b' ),
			'B2G only'                                    => __( 'B2G only', 'eurob2b' ),
			'UID (CHE-xxx.xxx.xxx, + MWST when VAT-registered)' => __( 'UID (CHE-xxx.xxx.xxx, + MWST when VAT-registered)', 'eurob2b' ),
			'Five-digit Liechtenstein VAT number; a CHE UID where the business has one; PEID with the administration (format unverified)' => __( 'Five-digit Liechtenstein VAT number; a CHE UID where the business has one; PEID with the administration (format unverified)', 'eurob2b' ),
			'Organisasjonsnummer (9 digits, + MVA)'       => __( 'Organisasjonsnummer (9 digits, + MVA)', 'eurob2b' ),
		);
	}
}

<?php
/**
 * Profile registry.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Countries;

/**
 * All country profiles (CTY-003). DE and AT carry primary sources; the other member states are
 * data profiles from docs/country-matrix.md §6 with its research sources. Nothing here is
 * professionally reviewed yet, which the Tax module respects (CTY-004).
 */
final class CountryProfiles {

	private const VERSION = '2026.10.1';
	private const CHECKED = '2026-10-18';
	private const MATRIX  = 'docs/country-matrix.md §6 (research: docs/research/countries-and-tax.md)';

	/**
	 * Rule set of the profiles CH, LI and NO with VAT rates and thresholds (CTY-005).
	 */
	private const NON_EU_VERSION = '2026.10.2';
	private const NON_EU_CHECKED = '2026-10-25';
	private const NON_EU_MATRIX  = 'docs/country-matrix.md §5 and §7';

	/**
	 * Cache.
	 *
	 * @var array<string, CountryProfile>|null
	 */
	private static ?array $profiles = null;

	/**
	 * Profile for a country, null when none exists.
	 *
	 * @param string $code ISO code.
	 */
	public static function get( string $code ): ?CountryProfile {
		return self::all()[ strtoupper( $code ) ] ?? null;
	}

	/**
	 * Every profile, keyed by ISO code.
	 *
	 * @return array<string, CountryProfile>
	 */
	public static function all(): array {
		if ( null !== self::$profiles ) {
			return self::$profiles;
		}
		$p     = array();
		$rules = self::non_eu_rules();
		foreach ( self::data() as $row ) {
			[ $code, $name, $review, $identifier, $identifier_pattern, $lookup, $einvoicing, $routing, $sources ] = $row;
			if ( isset( $rules[ $code ] ) ) {
				$p[ $code ] = new CountryProfile( $code, $name, $review, self::NON_EU_VERSION, self::NON_EU_CHECKED, $rules[ $code ]['pattern'], $identifier, $identifier_pattern, $lookup, $einvoicing, $routing, array_merge( $sources, array( self::NON_EU_MATRIX ) ), $rules[ $code ]['rates'], $rules[ $code ]['thresholds'] );
				continue;
			}
			$p[ $code ] = new CountryProfile( $code, $name, $review, self::VERSION, self::CHECKED, null, $identifier, $identifier_pattern, $lookup, $einvoicing, $routing, array_merge( $sources, array( self::MATRIX ) ) );
		}
		/**
		 * Filters the country profiles (add or replace a profile, e.g. after a professional review).
		 *
		 * @param array<string, CountryProfile> $profiles Profiles by ISO code.
		 */
		$filtered       = apply_filters( 'eurob2b_country_profiles', $p );
		self::$profiles = is_array( $filtered ) ? array_filter( $filtered, static fn( $x ): bool => $x instanceof CountryProfile ) : $p;
		return self::$profiles;
	}

	/**
	 * Drops the cache (tests, filters added late).
	 */
	public static function flush(): void {
		self::$profiles = null;
	}

	/**
	 * Raw rows: code, name, review, identifier, identifier regex, lookup, e-invoicing, routing ids, sources.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string, 3: ?string, 4: ?string, 5: ?string, 6: ?string, 7: string[], 8: string[]}>
	 */
	private static function data(): array {
		$nr = CountryProfile::REVIEW_NEEDS_REVIEW;
		$uv = CountryProfile::REVIEW_UNVERIFIED;
		return array(
			array( 'DE', 'Germany', $nr, 'Handelsregisternummer (HRA/HRB/GnR/PR/VR + number + register court)', '/^(HRA|HRB|GNR|PR|VR)\s?[0-9]{1,6}\s?[A-Z]{0,3}$/', 'handelsregister.de (no official API)', 'Reception mandatory since 2025-01-01; issuing mandatory from 2028-01-01 (2027-01-01 for issuers above EUR 800k turnover); XRechnung/ZUGFeRD >= 2.0.1', array( 'Leitweg-ID (B2G)', 'e-mail' ), array( 'https://www.gesetze-im-internet.de/ustg_1980/__14.html', 'https://www.gesetze-im-internet.de/ustg_1980/__27.html', 'https://www.gesetze-im-internet.de/ustg_1980/__14b.html', 'https://www.gesetze-im-internet.de/ao_1977/__147.html' ) ),
			array( 'AT', 'Austria', $nr, 'Firmenbuchnummer (up to 6 digits + check letter)', '/^(FN\s?)?[0-9]{1,6}\s?[A-Z]$/', 'JustizOnline (fee-based)', 'B2G only (IKT-KonsG § 5)', array(), array( 'https://www.ris.bka.gv.at/GeltendeFassung.wxe?Abfrage=Bundesnormen&Gesetzesnummer=20007897' ) ),
			array( 'BE', 'Belgium', $nr, 'Enterprise number (10 digits, equals the VAT number)', '/^[01][0-9]{9}$/', 'KBO/BCE public search', 'Mandatory since 2026-01-01 (Peppol BIS)', array( 'Peppol ID' ), array() ),
			array( 'BG', 'Bulgaria', $uv, null, null, null, 'None adopted', array(), array() ),
			array( 'HR', 'Croatia', $nr, 'OIB (11 digits)', '/^[0-9]{11}$/', null, 'Fiskalizacija 2.0: VAT payers since 2026-01-01, others from 2027-01-01', array( 'Access point ID' ), array() ),
			array( 'CY', 'Cyprus', $uv, null, null, null, 'No mandate', array(), array() ),
			array( 'CZ', 'Czechia', $nr, 'IČO (8 digits)', '/^[0-9]{8}$/', 'ARES REST (public)', 'None (B2G ISDOC)', array(), array() ),
			array( 'DK', 'Denmark', $nr, 'CVR (8 digits)', '/^[0-9]{8}$/', 'Virk CVR', 'Bookkeeping-system requirements 2027-03-01 / 2028-01-01; no general B2B duty', array( 'NemHandel/Peppol ID' ), array() ),
			array( 'EE', 'Estonia', $uv, 'Registry code (8 digits)', '/^[0-9]{8}$/', null, 'Buyers may demand e-invoices since 2025-07-01; mandate proposed', array( 'Peppol ID' ), array() ),
			array( 'FI', 'Finland', $nr, 'Y-tunnus (7 digits + check digit)', '/^[0-9]{7}-?[0-9]$/', 'PRH open data YTJ', 'B2G; customers may require e-invoices', array( 'Finvoice/Peppol address' ), array() ),
			array( 'FR', 'France', $nr, 'SIREN (9 digits) / SIRET (14 digits)', '/^([0-9]{9}|[0-9]{14})$/', 'API Recherche d\'entreprises', 'Since 2026-09-01 (reception all; issuing large/ETI), SMEs issue from 2027-09-01', array( 'SIRET', 'platform (PA)' ), array() ),
			array( 'GR', 'Greece', $uv, null, null, 'AADE', 'myDATA phases 2026 (dates conflict across sources)', array(), array() ),
			array( 'HU', 'Hungary', $uv, 'Tax number (8 digits)', '/^[0-9]{8}$/', 'NAV', 'Real-time invoice data reporting; no general e-invoice mandate', array(), array() ),
			array( 'IE', 'Ireland', $nr, 'CRO number (5-6 digits)', '/^[0-9]{5,6}$/', 'CRO Open Services', 'Large corporates issue from 2028-11-01; all must receive', array( 'Peppol ID' ), array() ),
			array( 'IT', 'Italy', $nr, 'Partita IVA (11 digits); Codice Fiscale (11/16)', '/^([0-9]{11}|[A-Z0-9]{16})$/', 'AdE Verifica Partita IVA (no API)', 'Mandatory since 2019-01-01 (SdI, FatturaPA)', array( 'Codice Destinatario', 'PEC' ), array() ),
			array( 'LV', 'Latvia', $nr, 'Registration number (11 digits)', '/^[0-9]{11}$/', null, 'Mandatory 2028-01-01 (Peppol/EN 16931)', array( 'Peppol ID' ), array() ),
			array( 'LT', 'Lithuania', $uv, 'Company code (9 digits)', '/^[0-9]{9}$/', null, 'None before 2030', array(), array() ),
			array( 'LU', 'Luxembourg', $uv, 'RCS number', '/^[A-Z][0-9]{1,6}$/', null, 'Draft bill: receive 2028-01-01, issue 2028-07/2029-01', array( 'Peppol ID' ), array() ),
			array( 'MT', 'Malta', $uv, null, null, null, 'No mandate', array(), array() ),
			array( 'NL', 'Netherlands', $nr, 'KVK number (8 digits)', '/^[0-9]{8}$/', 'KVK API (paid)', 'No B2B mandate', array( 'Peppol ID' ), array() ),
			array( 'PL', 'Poland', $nr, 'NIP (10 digits, equals the VAT number); REGON 9/14; KRS 10', '/^([0-9]{10}|[0-9]{9}|[0-9]{14})$/', 'Biała lista API wl-api.mf.gov.pl', 'KSeF: 2026-02-01 (> PLN 200m), 2026-04-01 others; small issuers may wait until 2026-12-31', array( 'NIP' ), array() ),
			array( 'PT', 'Portugal', $uv, 'NIF/NIPC (9 digits)', '/^[0-9]{9}$/', null, 'No general B2B structured mandate; QES on PDF invoices from 2027-01-01', array(), array() ),
			array( 'RO', 'Romania', $uv, 'CUI', '/^[0-9]{2,10}$/', null, 'RO e-Factura mandatory since 2024-07-01', array( 'SPV' ), array() ),
			array( 'SK', 'Slovakia', $nr, 'IČO (8 digits)', '/^[0-9]{8}$/', null, 'Mandatory 2027-01-01 (Act 385/2025, Peppol)', array( 'Peppol ID' ), array() ),
			array( 'SI', 'Slovenia', $nr, 'Matična številka', '/^[0-9]{7,10}$/', null, 'ZIERDED adopted 2025-10-23; application 2028-01-01 (date unverified)', array( 'e-route provider' ), array() ),
			array( 'ES', 'Spain', $nr, 'NIF (legal-form letter + 7 digits + check character)', '/^[A-Z][0-9]{7}[A-Z0-9]$/', 'AEAT census check (certificate needed)', 'Crea y Crece ~2027-10 (> EUR 8m) / ~2028-10 (others); Verifactu 2027', array(), array() ),
			array( 'SE', 'Sweden', $nr, 'Organisationsnummer (10 digits)', '/^[0-9]{6}-?[0-9]{4}$/', 'Bolagsverket open data', 'B2G only', array( 'Peppol ID' ), array() ),
			array( 'CH', 'Switzerland', $nr, 'UID (CHE-xxx.xxx.xxx, + MWST when VAT-registered)', '/^CHE[0-9]{9}(MWST|TVA|IVA)?$/', 'UID register of the Federal Statistical Office (public SOAP service ValidateVatNumber)', null, array(), array( 'https://www.fedlex.admin.ch/eli/cc/2011/81/de' ) ),
			array( 'LI', 'Liechtenstein', $nr, 'Five-digit Liechtenstein VAT number; a CHE UID where the business has one; PEID with the administration (format unverified)', null, 'None for the five-digit VAT number (format check only); CHE numbers via the Swiss UID register', null, array(), array( 'https://www.gesetze.li/konso/pdf/2012238000', 'https://www.llv.li/en/business/foundation-leadership/foreign-business/swiss-company-identification-number-uid-/faq' ) ),
			array( 'NO', 'Norway', $nr, 'Organisasjonsnummer (9 digits, + MVA)', '/^[0-9]{9}(MVA)?$/', 'Brønnøysund Unit Register, open API (registrertIMvaregisteret)', null, array(), array( 'https://www.skatteetaten.no/en/business-and-organisation/vat-and-duties/vat/', 'https://www.brreg.no/om-oss/registrene-vare/om-enhetsregisteret/organisasjonsnummeret/' ) ),
		);
	}

	/**
	 * National VAT number pattern (as in VatIdNormalizer::PATTERNS), rates and registration thresholds of the non-EU profiles, each with its source
	 * (docs/country-matrix.md §7, CH-02, CH-03, NO-01, NO-03; read on 2026-10-25). Rates are decimal
	 * strings, amounts whole currency units, so nothing is rounded. They describe the law for the
	 * shop owner; the tax treatment does not use them (WooCommerce rates decide, CH-05, NO-02).
	 *
	 * @return array<string, array{pattern: string, rates: array<int, array{type: string, rate: string, valid_from: string, source: string}>, thresholds: array<int, array{id: string, amount: int, currency: string, basis: string, source: string}>}>
	 */
	private static function non_eu_rules(): array {
		$estv_rates = 'https://www.estv.admin.ch/estv/de/home/mehrwertsteuer/mwst-steuersaetze.html';
		$li_law     = 'https://www.gesetze.li/konso/2009330000';
		$no_rates   = 'https://www.skatteetaten.no/en/rates/value-added-tax/';
		return array(
			'CH' => array(
				'pattern'    => '/^[0-9]{9}$/',
				'rates'      => array(
					array(
						'type'       => 'standard',
						'rate'       => '8.1',
						'valid_from' => '2024-01-01',
						'source'     => $estv_rates,
					),
					array(
						'type'       => 'reduced',
						'rate'       => '2.6',
						'valid_from' => '2024-01-01',
						'source'     => $estv_rates,
					),
					array(
						'type'       => 'accommodation',
						'rate'       => '3.8',
						'valid_from' => '2024-01-01',
						'source'     => $estv_rates,
					),
				),
				'thresholds' => array(
					array(
						'id'       => 'registration',
						'amount'   => 100000,
						'currency' => 'CHF',
						'basis'    => 'Turnover per year from taxable and exempt supplies in Switzerland and abroad, for a business that supplies in Switzerland; a foreign business needs a tax representative',
						'source'   => 'https://www.estv.admin.ch/de/mwst-steuerpflicht-auslaendische-unternehmen',
					),
					array(
						'id'       => 'mail_order',
						'amount'   => 100000,
						'currency' => 'CHF',
						'basis'    => 'Turnover per year from small consignments to Switzerland and Liechtenstein (import tax of at most CHF 5); from then on every consignment is a domestic supply',
						'source'   => 'https://www.estv.admin.ch/de/mwst-versandhandel-und-plattformbesteuerung',
					),
				),
			),
			'LI' => array(
				'pattern'    => '/^[0-9]{5}$/',
				'rates'      => array(
					array(
						'type'       => 'standard',
						'rate'       => '8.1',
						'valid_from' => '2024-01-01',
						'source'     => $li_law,
					),
					array(
						'type'       => 'reduced',
						'rate'       => '2.6',
						'valid_from' => '2024-01-01',
						'source'     => $li_law,
					),
					array(
						'type'       => 'accommodation',
						'rate'       => '3.8',
						'valid_from' => '2024-01-01',
						'source'     => $li_law,
					),
				),
				'thresholds' => array(
					array(
						'id'       => 'registration',
						'amount'   => 100000,
						'currency' => 'CHF',
						'basis'    => 'Turnover per year in Liechtenstein and abroad from supplies that are not excluded from the tax under Art. 21(2) MWSTG (Art. 10(2)(a) MWSTG)',
						'source'   => $li_law,
					),
				),
			),
			'NO' => array(
				'pattern'    => '/^[0-9]{9}$/',
				'rates'      => array(
					array(
						'type'       => 'standard',
						'rate'       => '25',
						'valid_from' => '2026-01-01',
						'source'     => $no_rates,
					),
					array(
						'type'       => 'food',
						'rate'       => '15',
						'valid_from' => '2026-01-01',
						'source'     => $no_rates,
					),
					array(
						'type'       => 'transport_accommodation',
						'rate'       => '12',
						'valid_from' => '2026-01-01',
						'source'     => $no_rates,
					),
				),
				'thresholds' => array(
					array(
						'id'       => 'registration',
						'amount'   => 50000,
						'currency' => 'NOK',
						'basis'    => 'Taxable sales over 12 months, excluding VAT (NOK 140,000 for charitable and non-profit organisations); a business without establishment in Norway registers through a representative unless it is resident in the United Kingdom or one of the listed EEA countries, which do not include Liechtenstein',
						'source'   => 'https://www.skatteetaten.no/en/business-and-organisation/vat-and-duties/vat/register/',
					),
				),
			),
		);
	}
}

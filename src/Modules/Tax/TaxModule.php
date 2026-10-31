<?php
/**
 * Tax module.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax;

use EuroB2B\Container;
use EuroB2B\Module\AbstractModule;
use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Admin\SettingsSchema;
use EuroB2B\Modules\Countries\CountryRegistry;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Registration\RegistrationTypeRepository;
use EuroB2B\Modules\Pricing\PricingContext;
use EuroB2B\Modules\Privacy\PrivateFiles;
use EuroB2B\Modules\Tax\Admin\ProductSupplyTypeField;
use EuroB2B\Modules\Tax\Admin\TaxReports;
use EuroB2B\Modules\Tax\Admin\TaxReviewAdmin;
use EuroB2B\Modules\Tax\Admin\ValidationsPage;
use EuroB2B\Modules\Tax\Export\ExportEvidenceDataProvider;
use EuroB2B\Modules\Tax\Export\ExportEvidenceRecords;
use EuroB2B\Modules\Tax\Export\ExportEvidenceRetention;
use EuroB2B\Modules\Tax\Proof\ProofDataProvider;
use EuroB2B\Modules\Tax\Proof\ProofRecords;
use EuroB2B\Modules\Tax\Proof\ProofRetention;
use EuroB2B\Modules\Tax\Treatment\CartTreatment;
use EuroB2B\Modules\Tax\Treatment\SpecialTreatment;
use EuroB2B\Modules\Tax\Treatment\SupplyType;
use EuroB2B\Modules\Tax\Treatment\TaxDisplay;
use EuroB2B\Modules\Tax\Treatment\TaxTreatmentDecider;
use EuroB2B\Modules\Tax\Validation\ValidationRepository;
use EuroB2B\Modules\Tax\Validation\ValidationService;
use EuroB2B\Modules\Tax\VatId\Transport\TransportInterface;
use EuroB2B\Modules\Tax\VatId\Transport\WpHttpTransport;
use EuroB2B\Modules\Tax\VatId\ViesValidator;
use EuroB2B\Modules\Tax\VatId\VatIdNormalizer;

/**
 * VAT ID normalisation and validation (VIES), evidence storage, manual decisions, the VAT ID field
 * in registration/account/checkouts, the tax treatment with the intra-Community exemption, the price
 * display per audience and the country profiles. The privacy export, erasure and retention of the
 * proofs of supply and the export evidence are here too, because EuroB2B Pro writes that data and it
 * must be handled also while Pro is inactive. Everything else of the tax features (BZSt, UID and
 * Brønnøysund checks, special territories, Northern Ireland, reports, proof of supply, export
 * evidence, OSS monitor, audit file) is added by `TaxProModule` of EuroB2B Pro (PLT-015).
 */
final class TaxModule extends AbstractModule {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'tax';
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Service container.
	 */
	public function register( Container $c ): void {
		$c->set( VatIdNormalizer::class, static fn(): VatIdNormalizer => new VatIdNormalizer() );
		$c->set( TransportInterface::class, static fn(): TransportInterface => new WpHttpTransport() );
		$c->set( ViesValidator::class, static fn( Container $c ): ViesValidator => new ViesValidator( $c->get( TransportInterface::class ), true, 10 ) );
		$c->set( ValidationRepository::class, static fn(): ValidationRepository => new ValidationRepository() );
		$c->set(
			ValidationService::class,
			static fn( Container $c ): ValidationService => new ValidationService(
				$c->get( ValidationRepository::class ),
				$c->get( VatIdNormalizer::class ),
				$c->get( Settings::class ),
				$c->get( AudienceResolver::class ),
				array( $c->get( ViesValidator::class ) )
			)
		);
		$c->set( VatIdField::class, static fn( Container $c ): VatIdField => new VatIdField( $c->get( ValidationService::class ), $c->get( Settings::class ), $c->get( AudienceResolver::class ), $c->get( RegistrationTypeRepository::class ) ) );
		$c->set( ValidationsPage::class, static fn( Container $c ): ValidationsPage => new ValidationsPage( $c->get( ValidationService::class ) ) );
		$c->set( TaxDataProvider::class, static fn( Container $c ): TaxDataProvider => new TaxDataProvider( $c->get( ValidationRepository::class ) ) );
		$c->set( TaxTreatmentDecider::class, static fn(): TaxTreatmentDecider => new TaxTreatmentDecider( self::special_treatments() ) );
		$c->set( CartTreatment::class, static fn( Container $c ): CartTreatment => new CartTreatment( $c->get( TaxTreatmentDecider::class ), $c->get( ValidationService::class ), $c->get( AudienceResolver::class ), $c->get( Settings::class ) ) );
		$c->set( TaxDisplay::class, static fn( Container $c ): TaxDisplay => new TaxDisplay( $c->get( Settings::class ), $c->get( AudienceResolver::class ), $c->get( PricingContext::class ) ) );
		$c->set( TaxReports::class, static fn( Container $c ): TaxReports => new TaxReports( $c->get( Settings::class ) ) );
		$c->set( ProductSupplyTypeField::class, static fn(): ProductSupplyTypeField => new ProductSupplyTypeField() );
		// Proofs of supply (TAX-030) and export evidence (TAX-032) that EuroB2B Pro records: privacy
		// export, erasure and retention stay in the core (product owner decision of 2026-10-29).
		$c->set( ProofRecords::class, static fn( Container $c ): ProofRecords => new ProofRecords( $c->get( PrivateFiles::class ) ) );
		$c->set( ProofRetention::class, static fn( Container $c ): ProofRetention => new ProofRetention( $c->get( ProofRecords::class ) ) );
		$c->set( ProofDataProvider::class, static fn( Container $c ): ProofDataProvider => new ProofDataProvider( $c->get( ProofRecords::class ) ) );
		$c->set( ExportEvidenceRecords::class, static fn( Container $c ): ExportEvidenceRecords => new ExportEvidenceRecords( $c->get( PrivateFiles::class ) ) );
		$c->set( ExportEvidenceRetention::class, static fn( Container $c ): ExportEvidenceRetention => new ExportEvidenceRetention( $c->get( ExportEvidenceRecords::class ) ) );
		$c->set( ExportEvidenceDataProvider::class, static fn( Container $c ): ExportEvidenceDataProvider => new ExportEvidenceDataProvider( $c->get( ExportEvidenceRecords::class ) ) );

		add_action( 'eurob2b_settings_schema', array( self::class, 'settings_schema' ) );
	}

	/**
	 * Adds hooks.
	 *
	 * @param Container $c Service container.
	 */
	public function boot( Container $c ): void {
		$service = $c->get( ValidationService::class );
		add_action( ValidationService::RETRY_HOOK, array( $service, 'retry' ) );
		add_action( ValidationService::MAINTENANCE_HOOK, array( $service, 'purge_expired' ) );
		if ( ! wp_next_scheduled( ValidationService::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', ValidationService::MAINTENANCE_HOOK );
		}
		add_action(
			'eurob2b_deactivated',
			static function (): void {
				wp_unschedule_hook( ValidationService::MAINTENANCE_HOOK );
			}
		);

		add_filter(
			'eurob2b_personal_data_providers',
			static function ( array $providers ) use ( $c ): array {
				$providers[] = $c->get( TaxDataProvider::class );
				$providers[] = $c->get( ProofDataProvider::class );
				$providers[] = $c->get( ExportEvidenceDataProvider::class );
				return $providers;
			}
		);
		add_filter(
			'eurob2b_retention_tasks',
			static function ( array $tasks ) use ( $c ): array {
				$tasks[] = $c->get( ProofRetention::class );
				$tasks[] = $c->get( ExportEvidenceRetention::class );
				return $tasks;
			}
		);

		if ( class_exists( 'WooCommerce' ) ) {
			// Independent of the field mode: orders from times the field was on still carry the number.
			add_action( 'woocommerce_privacy_remove_order_personal_data', static fn( $order ) => $c->get( VatIdField::class )->anonymise_order( $order ) );
			$c->get( VatIdField::class )->hooks();
			$c->get( CartTreatment::class )->hooks();
			$c->get( TaxDisplay::class )->hooks();
			// The meta queries of the posts store, for the privacy handling here and for EuroB2B Pro.
			$c->get( ProofRecords::class )->hooks();
			// RPT-006: the supply type at the time of the order, kept on each line (classic and Store API checkout).
			add_action( 'woocommerce_checkout_create_order_line_item', array( SupplyType::class, 'record' ) );
		}

		if ( is_admin() ) {
			add_action( 'eurob2b_admin_menu', static fn( $menu ) => $c->get( ValidationsPage::class )->register_page( $menu ) );
			add_filter( 'eurob2b_overview_items', static fn( $items ) => $c->get( ValidationsPage::class )->overview_items( (array) $items ) );
			add_action( 'admin_post_' . ValidationsPage::ACTION_DECIDE, static fn() => $c->get( ValidationsPage::class )->handle_decide() );
			add_action( 'admin_post_' . ValidationsPage::ACTION_RECHECK, static fn() => $c->get( ValidationsPage::class )->handle_recheck() );
			add_action( 'eurob2b_vat_validations_page_end', static fn() => $c->get( TaxReports::class )->render_profiles(), 20 );
			add_action( 'admin_notices', static fn() => $c->get( TaxReports::class )->render_rate_warning() );
			$c->get( ProductSupplyTypeField::class )->hooks();
			( new TaxReviewAdmin() )->hooks();
		}
		// The staff member who confirmed a tax review goes with WooCommerce's order anonymisation.
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( TaxReviewAdmin::class, 'anonymise_order' ) );
	}

	/**
	 * Special cases of the tax treatment decision (read once, when the decider is built).
	 *
	 * @return array<int, mixed>
	 */
	public static function special_treatments(): array {
		/**
		 * Filters the special cases of the tax treatment decision, asked in this order before the
		 * standard rules (`Treatment\SpecialTreatment`; EuroB2B Pro: the special territories, TAX-024,
		 * and Northern Ireland, TAX-034). Without them those addresses count as their country.
		 *
		 * @param array<int, SpecialTreatment> $cases Special cases (none in the core).
		 */
		$cases = apply_filters( 'eurob2b_tax_special_treatments', array() );
		return is_array( $cases ) ? $cases : array();
	}

	/**
	 * Settings section (EuroB2B Pro adds the fields of its tax features at the end of it).
	 *
	 * @param SettingsSchema $schema Schema.
	 */
	public static function settings_schema( SettingsSchema $schema ): void {
		$schema->add_section( 'tax', __( 'Tax and VAT IDs', 'eurob2b' ), 30 );
		$schema->add_field(
			array(
				'id'             => 'tax_vies_enabled',
				'section'        => 'tax',
				'label'          => __( 'Validate EU VAT IDs via VIES', 'eurob2b' ),
				'checkbox_label' => __( 'Send VAT IDs to the European Commission\'s VIES service to confirm they are registered', 'eurob2b' ),
				'description'    => __( 'External connection (see the privacy documentation). Off: numbers are only checked for syntax.', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => false,
			)
		);
		$schema->add_field(
			array(
				'id'          => 'tax_shop_vat_id',
				'section'     => 'tax',
				'label'       => __( 'Your own VAT ID', 'eurob2b' ),
				'description' => __( 'Sent along with each VIES check so the consultation number is returned as proof (recommended).', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_TEXT,
				'default'     => '',
			)
		);
		$schema->add_field(
			array(
				'id'          => 'tax_vat_field_mode',
				'section'     => 'tax',
				'label'       => __( 'VAT ID field', 'eurob2b' ),
				'description' => __( 'Shown at registration (business types), in My Account and at checkout for the countries below.', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_SELECT,
				'default'     => VatIdField::MODE_OPTIONAL,
				'options'     => array(
					VatIdField::MODE_OFF          => __( 'Off', 'eurob2b' ),
					VatIdField::MODE_OPTIONAL     => __( 'Optional', 'eurob2b' ),
					VatIdField::MODE_REQUIRED_B2B => __( 'Required for business customers', 'eurob2b' ),
				),
			)
		);
		$schema->add_field(
			array(
				'id'          => 'tax_vat_field_countries',
				'section'     => 'tax',
				'label'       => __( 'Countries whose VAT IDs are checked at checkout', 'eurob2b' ),
				'description' => __( 'The field is shown at checkout for every billing country; a number is checked (and required, where the field is mandatory) only for these billing countries.', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_MULTISELECT,
				'default'     => self::default_countries(),
				'options'     => array( self::class, 'country_options' ),
			)
		);
		$schema->add_field(
			array(
				'id'             => 'tax_validate_at_registration',
				'section'        => 'tax',
				'label'          => __( 'Check at registration', 'eurob2b' ),
				'checkbox_label' => __( 'Check the VAT ID online when a business customer registers', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => true,
			)
		);
		$schema->add_field(
			array(
				'id'             => 'tax_block_invalid',
				'section'        => 'tax',
				'label'          => __( 'Invalid numbers', 'eurob2b' ),
				'checkbox_label' => __( 'Block registration and checkout when the checking service reports the number as not registered', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => true,
			)
		);
		$schema->add_field(
			array(
				'id'          => 'tax_downtime_policy',
				'section'     => 'tax',
				'label'       => __( 'When the checking service is unavailable', 'eurob2b' ),
				'description' => __( 'The number is retried in the background either way. This decides what happens with the action the customer is taking.', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_SELECT,
				'default'     => ValidationService::POLICY_REVIEW,
				'options'     => array(
					ValidationService::POLICY_REVIEW    => __( 'Proceed with VAT and add the number to the review list', 'eurob2b' ),
					ValidationService::POLICY_BLOCK     => __( 'Block the action until the check succeeds', 'eurob2b' ),
					ValidationService::POLICY_PROVISION => __( 'Provisionally accept approved customers with a recent valid result', 'eurob2b' ),
				),
			)
		);
		$schema->add_field(
			array(
				'id'      => 'tax_provisional_days',
				'section' => 'tax',
				'label'   => __( 'Provisional acceptance: max age of the last valid result (days)', 'eurob2b' ),
				'type'    => SettingsSchema::TYPE_NUMBER,
				'default' => 90,
				'min'     => 1,
				'max'     => 3650,
			)
		);
		$schema->add_field(
			array(
				'id'      => 'tax_revalidate_days',
				'section' => 'tax',
				'label'   => __( 'Re-check a valid number after (days)', 'eurob2b' ),
				'type'    => SettingsSchema::TYPE_NUMBER,
				'default' => 30,
				'min'     => 1,
				'max'     => 3650,
			)
		);
		$schema->add_field(
			array(
				'id'      => 'tax_max_attempts',
				'section' => 'tax',
				'label'   => __( 'Retries when the service is unavailable', 'eurob2b' ),
				'type'    => SettingsSchema::TYPE_NUMBER,
				'default' => 5,
				'min'     => 1,
				'max'     => 20,
			)
		);
		$schema->add_field(
			array(
				'id'          => 'tax_retention_years',
				'section'     => 'tax',
				'label'       => __( 'Keep validation evidence linked to orders (years)', 'eurob2b' ),
				'description' => __( 'Invoice retention period of your country (Germany: 8 years from the end of the year; 10 is a safe default).', 'eurob2b' ),
				'type'        => SettingsSchema::TYPE_NUMBER,
				'default'     => 10,
				'min'         => 1,
				'max'         => 30,
			)
		);
		$schema->add_field(
			array(
				'id'      => 'tax_retention_orderless_days',
				'section' => 'tax',
				'label'   => __( 'Keep validation records without an order (days)', 'eurob2b' ),
				'type'    => SettingsSchema::TYPE_NUMBER,
				'default' => 365,
				'min'     => 1,
				'max'     => 3650,
			)
		);
		$schema->add_field(
			array(
				'id'             => 'tax_exemption_enabled',
				'section'        => 'tax',
				'label'          => __( 'Intra-Community exemption', 'eurob2b' ),
				'checkbox_label' => __( 'Exempt business customers in other EU member states from VAT when all conditions hold (valid VAT ID from another member state, delivery or establishment outside the shop country, no self-collection)', 'eurob2b' ),
				'description'    => __( 'Domestic customers, consumers, invalid or unchecked numbers and pick-up orders are always taxed. Have the rules reviewed by your tax adviser before enabling.', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => false,
			)
		);
		$schema->add_field(
			array(
				'id'             => 'tax_exempt_with_unreviewed_rules',
				'section'        => 'tax',
				'label'          => __( 'Unreviewed country rules', 'eurob2b' ),
				'checkbox_label' => __( 'Apply the exemption also for countries whose profile has not been signed off by a tax professional (your responsibility)', 'eurob2b' ),
				'description'    => __( 'Off: such orders are taxed and flagged for review. The review state of every country is listed on the VAT IDs page.', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => false,
			)
		);
		$schema->add_field(
			array(
				'id'             => 'tax_display_per_audience',
				'section'        => 'tax',
				'label'          => __( 'Price display per audience', 'eurob2b' ),
				'checkbox_label' => __( 'Show prices excluding tax to business customers and including tax to consumers (tax is added as a line in cart and checkout)', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => false,
			)
		);
		$schema->add_field(
			array(
				'id'      => 'tax_suffix_b2b',
				'section' => 'tax',
				'label'   => __( 'Price suffix for business customers', 'eurob2b' ),
				'type'    => SettingsSchema::TYPE_TEXT,
				'default' => __( 'excl. VAT', 'eurob2b' ),
			)
		);
		$schema->add_field(
			array(
				'id'      => 'tax_suffix_b2c',
				'section' => 'tax',
				'label'   => __( 'Price suffix for consumers', 'eurob2b' ),
				'type'    => SettingsSchema::TYPE_TEXT,
				'default' => __( 'incl. VAT', 'eurob2b' ),
			)
		);
		$schema->add_field(
			array(
				'id'             => 'tax_notify_admin',
				'section'        => 'tax',
				'label'          => __( 'Notifications', 'eurob2b' ),
				'checkbox_label' => __( 'E-mail the shop administrator when a check ends invalid or unavailable', 'eurob2b' ),
				'type'           => SettingsSchema::TYPE_BOOLEAN,
				'default'        => true,
			)
		);
	}

	/**
	 * Countries VIES covers (EU member states and the United Kingdom for Northern Ireland).
	 *
	 * @return string[]
	 */
	public static function default_countries(): array {
		return array_values( array_merge( CountryRegistry::EU_MEMBER_STATES, array( 'GB' ) ) );
	}

	/**
	 * Countries the shop can choose for the checkout check: the default ones plus Monaco (French
	 * VAT numbers, Art. 7 (1) VAT Directive), the Swiss VAT area and Norway (CTY-005). Those are not in the default list, because a Swiss or Norwegian
	 * business below the registration threshold has no VAT number and a mandatory field would turn
	 * it away.
	 *
	 * @return string[]
	 */
	public static function selectable_countries(): array {
		return array_values( array_merge( self::default_countries(), array_keys( CountryRegistry::TREATED_AS_MEMBER_STATE ), CountryRegistry::SWISS_VAT_TERRITORY, array( 'NO' ) ) );
	}

	/**
	 * Options for the country multiselect (WooCommerce names when available).
	 *
	 * @return array<string, string>
	 */
	public static function country_options(): array {
		$names   = function_exists( 'WC' ) && WC()->countries instanceof \WC_Countries ? WC()->countries->get_countries() : array();
		$options = array();
		foreach ( self::selectable_countries() as $iso ) {
			$label = isset( $names[ $iso ] ) ? (string) $names[ $iso ] : $iso;
			if ( 'GB' === $iso ) {
				$label .= ' (' . __( 'Northern Ireland, XI', 'eurob2b' ) . ')';
			}
			$options[ $iso ] = $label;
		}
		return $options;
	}
}

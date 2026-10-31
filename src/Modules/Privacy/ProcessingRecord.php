<?php
/**
 * Draft of the record of processing activities.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy;

use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Admin\SettingsSchema;
use EuroB2B\Modules\Registration\FieldDefinition;
use EuroB2B\Modules\Registration\FieldRepository;
use EuroB2B\Modules\Tax\VatIdField;

/**
 * Builds a draft of the record of processing activities (Art. 30(1) GDPR, PRV-010) for the
 * processing EuroB2B adds to a shop.
 *
 * One activity per purpose, laid out like the template of the German data protection authorities
 * (DSK, 2018). Each activity names the rows of the data inventory (docs/privacy-and-data-flows.md §2)
 * it covers. Periods, external recipients and whether a feature is in use follow the current
 * settings. Legal bases appear only as candidates for the shop owner's review. Nothing is stored:
 * the draft is built on each request from the settings and the WooCommerce store address.
 */
final class ProcessingRecord {

	/**
	 * Data inventory rows per activity.
	 */
	public const INVENTORY = array(
		'EB-01' => array( 'D-01', 'D-02', 'D-09' ),
		'EB-02' => array( 'D-13', 'D-17', 'D-18' ),
		'EB-03' => array( 'D-03', 'D-23', 'D-29' ),
		'EB-04' => array( 'D-04', 'D-14', 'D-19' ),
		'EB-05' => array( 'D-05', 'D-25', 'D-28' ),
		'EB-06' => array( 'D-06' ),
		'EB-07' => array( 'D-08' ),
		'EB-08' => array( 'D-10' ),
		'EB-09' => array( 'D-15', 'D-16' ),
		'EB-10' => array( 'D-20' ),
		'EB-11' => array( 'D-07' ),
		'EB-12' => array( 'D-11' ),
		'EB-13' => array( 'D-21', 'D-30' ),
		'EB-14' => array( 'D-31' ),
		'EB-15' => array( 'D-33' ),
	);

	/**
	 * Implemented inventory rows without an activity, with the reason (checked by a unit test).
	 */
	public const NOT_LISTED = array(
		'D-12' => 'The logs hold no personal data yet: migration errors and the counts of the retention job.',
		'D-22' => 'The import journal is no purpose of its own: for at most 30 days it keeps the earlier values of data listed under EB-01, EB-02 and EB-04 that an import from another plugin replaced, only to undo that import.',
		'D-24' => 'The overdue list stores nothing: it shows, on request, WooCommerce order data that the shop processes to collect its own invoices anyway, with the calculated interest; EuroB2B adds no data of its own.',
		'D-26' => 'The OSS threshold totals hold no personal data: sums and order counts per year and a shop-wide list of hidden notices.',
		'D-27' => 'The audit file stores nothing: it compiles, on request, records already listed under EB-03 (including the proof of supply and the export evidence), EB-05 and EB-10 for the tax audit of an order; the temporary ZIP is deleted right after the download.',
		'D-32' => 'The Northern Ireland notice holds no personal data: a shop-wide yes/no whether such orders or customers were found and whether the notice was hidden.',
		'D-34' => 'The controller details are the cover sheet of the record itself (Art. 30(1)(a) GDPR), not a processing of the customers of the shop.',
	);

	/**
	 * Keys of an activity that hold lists of text.
	 */
	private const LISTS = array( 'purposes', 'subjects', 'data', 'internal', 'external', 'retention', 'legal_basis' );

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
	 * Settings fields for the controller details the shop does not know by itself (Art. 30(1)(a) GDPR),
	 * in the section *Privacy and data*. Kept on this site only (`Schema::RECORD_CONTROLLER_SETTINGS`).
	 *
	 * @param SettingsSchema $schema Schema.
	 */
	public static function settings_schema( SettingsSchema $schema ): void {
		$fields = array(
			'record_contact'           => array( __( 'Phone and e-mail address', 'eurob2b' ), SettingsSchema::TYPE_TEXTAREA, __( 'For the controller sheet of the record of processing activities (EuroB2B → Settings → Processing record); empty fields are marked "please add" there, write "none" where something does not apply. These details stay on this site: they are not part of the settings export and are deleted when EuroB2B is deleted.', 'eurob2b' ) ),
			'record_contact_person'    => array( __( 'Contact person', 'eurob2b' ), SettingsSchema::TYPE_TEXT, '' ),
			'record_joint_controllers' => array( __( 'Joint controllers (Art. 26 GDPR)', 'eurob2b' ), SettingsSchema::TYPE_TEXTAREA, '' ),
			'record_eu_representative' => array( __( 'Representative in the EU (Art. 27 GDPR)', 'eurob2b' ), SettingsSchema::TYPE_TEXTAREA, '' ),
			'record_dpo'               => array( __( 'Data protection officer (Art. 37 GDPR)', 'eurob2b' ), SettingsSchema::TYPE_TEXTAREA, __( 'Name and contact details.', 'eurob2b' ) ),
		);
		foreach ( $fields as $id => list( $label, $type, $description ) ) {
			$schema->add_field(
				array(
					'id'          => $id,
					'section'     => 'privacy',
					'label'       => $label,
					'description' => $description,
					'type'        => $type,
					'default'     => '',
				)
			);
		}
	}

	/**
	 * The activities, EuroB2B's own first, then those added through the filter.
	 *
	 * @return array<int, array{id: string, name: string, inventory: string[], in_use: bool, off_reason: string, purposes: string[], subjects: string[], data: string[], internal: string[], external: string[], retention: string[], legal_basis: string[]}>
	 */
	public function activities(): array {
		/**
		 * Adds or changes activities of the record of processing activities (PRV-010).
		 *
		 * An activity is an array with `id`, `name` and `purposes`, and optionally `inventory`,
		 * `in_use` (default true), `off_reason`, `subjects`, `data`, `internal`, `external`,
		 * `retention` and `legal_basis` (lists of plain text). Entries without id, name or purpose
		 * are dropped.
		 *
		 * @param array<int, array<string, mixed>> $activities Activities.
		 */
		$filtered = apply_filters( 'eurob2b_processing_activities', $this->own() );
		$out      = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $raw ) {
			$activity = is_array( $raw ) ? self::normalise( $raw ) : null;
			if ( null !== $activity ) {
				$out[] = $activity;
			}
		}
		return $out;
	}

	/**
	 * A clean activity, or null when it lacks id, name or purpose.
	 *
	 * @param array<mixed> $raw Activity as given.
	 * @return array{id: string, name: string, inventory: string[], in_use: bool, off_reason: string, purposes: string[], subjects: string[], data: string[], internal: string[], external: string[], retention: string[], legal_basis: string[]}|null
	 */
	private static function normalise( array $raw ): ?array {
		$text     = static fn( $value ): string => is_scalar( $value ) ? trim( (string) $value ) : '';
		$list     = static fn( $value ): array => is_array( $value ) ? array_values( array_filter( array_map( $text, $value ), static fn( string $item ): bool => '' !== $item ) ) : array();
		$activity = array(
			'id'         => $text( $raw['id'] ?? '' ),
			'name'       => $text( $raw['name'] ?? '' ),
			'inventory'  => $list( $raw['inventory'] ?? array() ),
			'in_use'     => (bool) ( $raw['in_use'] ?? true ),
			'off_reason' => $text( $raw['off_reason'] ?? '' ),
		);
		foreach ( self::LISTS as $key ) {
			$activity[ $key ] = $list( $raw[ $key ] ?? array() );
		}
		if ( '' === $activity['id'] || '' === $activity['name'] || array() === $activity['purposes'] ) {
			return null;
		}
		return $activity;
	}

	/**
	 * EuroB2B's own activities. EuroB2B Pro adds the activities of its modules (EB-04, EB-06 to
	 * EB-08, EB-11 to EB-14) and completes EB-09 through the filter (PLT-015).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function own(): array {
		return array(
			$this->registration(),
			$this->conditions(),
			$this->vat_checks(),
			$this->orders(),
			$this->abuse_protection(),
			$this->audit_log(),
		);
	}

	/**
	 * EB-01: business customer registration and approval (D-01, D-02).
	 *
	 * @return array<string, mixed>
	 */
	private function registration(): array {
		$days      = (int) $this->settings->get( 'privacy_rejected_days' );
		$data      = array(
			__( 'Customer type, customer group, approval status with time and approving staff member', 'eurob2b' ),
			__( 'Registration type, the values of the registration form fields set up by the shop, time the terms were accepted', 'eurob2b' ),
			__( 'Rejection note, time of the rejection and rejecting staff member', 'eurob2b' ),
		);
		$internal  = array(
			__( 'Shop staff with the capability to manage customers (eurob2b_manage_customers), in the admin and through the REST API (classification by user ID only)', 'eurob2b' ),
			__( 'Shop staff and extensions with access to orders (copies of registration fields on orders)', 'eurob2b' ),
		);
		$retention = array(
			__( 'As long as the customer account exists.', 'eurob2b' ),
			$days > 0
				/* translators: %d: number of days */
				? sprintf( _n( 'Rejected applications that were never approved: deleted %d day after the rejection, unless orders were placed with the account or it has other roles.', 'Rejected applications that were never approved: deleted %d days after the rejection, unless orders were placed with the account or it has other roles.', $days, 'eurob2b' ), $days )
				: __( 'Rejected applications: kept until deleted manually (automatic deletion is switched off in the settings).', 'eurob2b' ),
			__( 'Copies of registration fields on orders: with the order; removed when WooCommerce anonymises the order.', 'eurob2b' ),
		);
		// Documents uploaded with the registration (REG-016, D-09) while a file field is set up.
		if ( self::has_file_field() ) {
			$file_days   = max( 0, (int) $this->settings->get( 'registration_file_retention_days' ) );
			$data[]      = __( 'Documents uploaded with the registration (for example a trade licence): the file, its original name, type and size, and the time of the upload', 'eurob2b' );
			$internal[]  = __( 'Shop staff with the capability to manage customers, through a protected download link; the files cannot be downloaded publicly', 'eurob2b' );
			$retention[] = $file_days > 0
				/* translators: %d: number of days */
				? sprintf( _n( 'Uploaded documents: deleted %d day after the approval or rejection, at the latest with the customer account or on an erasure request.', 'Uploaded documents: deleted %d days after the approval or rejection, at the latest with the customer account or on an erasure request.', $file_days, 'eurob2b' ), $file_days )
				: __( 'Uploaded documents: kept as long as the customer account exists; deleted with it or on an erasure request.', 'eurob2b' );
		}
		return array(
			'id'          => 'EB-01',
			'inventory'   => self::INVENTORY['EB-01'],
			'name'        => __( 'Business customer registration and approval', 'eurob2b' ),
			'purposes'    => array(
				__( 'Reviewing applications for a business customer account and approving or rejecting them.', 'eurob2b' ),
				__( 'Classifying customers as business or consumer customers and assigning them to a customer group, which decides prices, payment methods and visible products.', 'eurob2b' ),
				__( 'Recording who approved or rejected an account and when.', 'eurob2b' ),
			),
			'subjects'    => array(
				__( 'Applicants for a business customer account (sole traders and contact persons of companies)', 'eurob2b' ),
				__( 'Customers with a customer account', 'eurob2b' ),
				__( 'Shop staff who approve or reject applications (user ID only)', 'eurob2b' ),
			),
			'data'        => $data,
			'internal'    => $internal,
			'retention'   => $retention,
			'legal_basis' => array(
				__( 'Art. 6(1)(b) GDPR: contract and steps prior to entering into a contract at the request of the applicant', 'eurob2b' ),
			),
		);
	}

	/**
	 * Whether the registration form has a file field (REG-016).
	 */
	public static function has_file_field(): bool {
		foreach ( (array) get_option( FieldRepository::OPTION, array() ) as $field ) {
			if ( is_array( $field ) && FieldDefinition::TYPE_FILE === ( $field['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * EB-02: conditions agreed with one customer (D-13, D-17, D-18).
	 *
	 * @return array<string, mixed>
	 */
	private function conditions(): array {
		return array(
			'id'          => 'EB-02',
			'inventory'   => self::INVENTORY['EB-02'],
			'name'        => __( 'Conditions agreed with individual customers', 'eurob2b' ),
			'purposes'    => array(
				__( 'Applying the prices and conditions agreed with one customer: own prices and tier prices, allowed payment and shipping methods, pricing and checkout rules addressed to that customer.', 'eurob2b' ),
			),
			'subjects'    => array(
				__( 'Customers with individual conditions, in particular sole traders', 'eurob2b' ),
			),
			'data'        => array(
				__( 'Own regular, sale and tier prices per product or variation', 'eurob2b' ),
				__( 'Lists of the payment and shipping methods allowed for the customer', 'eurob2b' ),
				__( 'References to the customer (user ID) in pricing and checkout rules', 'eurob2b' ),
			),
			'internal'    => array(
				__( 'Shop staff on the product screen, on the user profile and in the rules (capability eurob2b_manage_rules)', 'eurob2b' ),
				__( 'The customer sees their own prices in the shop', 'eurob2b' ),
			),
			'retention'   => array(
				__( 'Own prices and allowed payment and shipping methods: as long as the customer account exists; deleted on an erasure request and when the account is deleted. Orders keep the prices they were placed at.', 'eurob2b' ),
				__( 'References to the customer in rules: until the rule is changed or the account is deleted. An erasure request does not remove them, because the rule still applies to the existing account.', 'eurob2b' ),
			),
			'legal_basis' => array(
				__( 'Art. 6(1)(b) GDPR: conditions agreed with the customer', 'eurob2b' ),
				__( 'Art. 6(1)(f) GDPR: legitimate interest in payment risk decisions and in the configuration of the shop', 'eurob2b' ),
			),
		);
	}

	/**
	 * EB-03: VAT ID checks and evidence (D-03). EuroB2B Pro adds its checking services, the proof of
	 * intra-Community supply (D-23) and the export evidence (D-29) through
	 * `eurob2b_processing_record_vat_parts`.
	 *
	 * @return array<string, mixed>
	 */
	private function vat_checks(): array {
		$vies     = (bool) $this->settings->get( 'tax_vies_enabled' );
		$shop_vat = strtoupper( trim( (string) $this->settings->get( 'tax_shop_vat_id' ) ) );
		$years    = max( 1, (int) $this->settings->get( 'tax_retention_years' ) );
		$days     = max( 1, (int) $this->settings->get( 'tax_retention_orderless_days' ) );

		$data = array(
			__( 'VAT ID and country, check result with reason, checking service, consultation number and times', 'eurob2b' ),
			__( 'Manual decision with the deciding staff member and note', 'eurob2b' ),
			__( 'Copy of the VAT ID and of the tax treatment on orders', 'eurob2b' ),
		);
		if ( $vies ) {
			$data[] = __( 'Name and address of the trader as returned by VIES', 'eurob2b' );
		}
		$external = array();
		if ( $vies ) {
			$external[] = '' !== $shop_vat
				? __( 'European Commission, VIES service: the VAT ID to check and the shop\'s own VAT ID', 'eurob2b' )
				: __( 'European Commission, VIES service: the VAT ID to check', 'eurob2b' );
		}
		$parts = self::vat_parts(
			array(
				'inventory'   => array( 'D-03' ),
				'purposes'    => array(
					__( 'Checking VAT identification numbers to decide the tax treatment of orders (tax-exempt intra-Community supplies, reverse charge).', 'eurob2b' ),
					__( 'Keeping the check results as evidence of due diligence.', 'eurob2b' ),
				),
				'subjects'    => array(
					__( 'Business customers who enter a VAT ID (for sole traders the number is personal data)', 'eurob2b' ),
					__( 'Shop staff who decide on a check manually (user ID only)', 'eurob2b' ),
				),
				'data'        => $data,
				'internal'    => array(
					__( 'Shop staff with the capability to decide on VAT ID checks (eurob2b_decide_validation)', 'eurob2b' ),
				),
				'external'    => $external,
				'retention'   => array(
					/* translators: %d: number of years */
					sprintf( _n( 'Check results that belong to an order: %d year, counted from the end of the calendar year of the order.', 'Check results that belong to an order: %d years, counted from the end of the calendar year of the order.', $years, 'eurob2b' ), $years ),
					/* translators: %d: number of days */
					sprintf( _n( 'Other check results: deleted after %d day.', 'Other check results: deleted after %d days.', $days, 'eurob2b' ), $days ),
					__( 'On an erasure request, results that belong to an order keep the number and the result as tax evidence without the link to the person.', 'eurob2b' ),
				),
				'legal_basis' => array( __( 'Art. 6(1)(c) GDPR in connection with the tax evidence duties for tax-exempt supplies, or Art. 6(1)(f) GDPR for other checks; this is an open legal question', 'eurob2b' ) ),
			)
		);

		return array(
			'id'          => 'EB-03',
			'inventory'   => $parts['inventory'],
			'name'        => __( 'VAT ID checks and tax evidence', 'eurob2b' ),
			// Rechecks from the VAT IDs screen and scheduled retries run while a checking service is on,
			// even when the field no longer collects new numbers.
			'in_use'      => VatIdField::MODE_OFF !== (string) $this->settings->get( 'tax_vat_field_mode' ) || $vies || $parts['in_use'],
			'off_reason'  => __( 'The VAT ID field and all checking services are switched off.', 'eurob2b' ),
			'purposes'    => $parts['purposes'],
			'subjects'    => $parts['subjects'],
			'data'        => $parts['data'],
			'internal'    => $parts['internal'],
			'external'    => array() !== $parts['external'] ? $parts['external'] : array( __( 'None: VAT IDs are only checked for their format, because no checking service is switched on.', 'eurob2b' ) ),
			'retention'   => $parts['retention'],
			'legal_basis' => $parts['legal_basis'],
		);
	}

	/**
	 * The lists of EB-03 with what an add-on appends to them (EuroB2B Pro, PLT-015).
	 *
	 * @param array<string, string[]> $lists The core's lists (inventory, purposes, subjects, data, internal, external, retention, legal_basis).
	 * @return array{in_use: bool, inventory: string[], purposes: string[], subjects: string[], data: string[], internal: string[], external: string[], retention: string[], legal_basis: string[]}
	 */
	private static function vat_parts( array $lists ): array {
		$keys  = array( 'inventory', 'purposes', 'subjects', 'data', 'internal', 'external', 'retention', 'legal_basis' );
		$empty = array_fill_keys( $keys, array() );
		$base  = array_merge( array( 'in_use' => false ), $empty, $lists );
		/**
		 * Filters the lists of the activity EB-03 (VAT ID checks and tax evidence) of the record.
		 * EuroB2B Pro appends its checking services (BZSt, UID register, Brønnøysund), the proof of
		 * intra-Community supply and the export evidence, and sets `in_use` while one of them is on
		 * (PLT-015). Add to the end of each list; lists hold plain text.
		 *
		 * @param array<string, mixed> $parts `in_use` (bool, the core's switches aside) and the lists inventory, purposes, subjects, data, internal, external, retention, legal_basis.
		 */
		$filtered = apply_filters( 'eurob2b_processing_record_vat_parts', $base );
		$filtered = is_array( $filtered ) ? $filtered : $base;
		$out      = array( 'in_use' => (bool) ( $filtered['in_use'] ?? false ) );
		foreach ( $keys as $key ) {
			$value       = $filtered[ $key ] ?? array();
			$out[ $key ] = is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
		}
		return $out;
	}

	/**
	 * EB-05: additions to orders (D-05). EuroB2B Pro adds the dunning history (D-28), the company
	 * members and the purchase order documents (D-25) through `eurob2b_processing_activities`.
	 *
	 * @return array<string, mixed>
	 */
	private function orders(): array {
		return array(
			'id'          => 'EB-05',
			'inventory'   => array( 'D-05' ),
			'name'        => __( 'Business details on orders', 'eurob2b' ),
			'purposes'    => array(
				__( 'Recording on each order the customer classification and the tax treatment, for order processing and invoicing.', 'eurob2b' ),
			),
			'subjects'    => array(
				__( 'Customers who place orders', 'eurob2b' ),
			),
			'data'        => array(
				__( 'Copy of the VAT ID, tax treatment and reason for a tax exemption', 'eurob2b' ),
				__( 'Customer type and customer group', 'eurob2b' ),
			),
			'internal'    => array(
				__( 'Shop staff who process orders, and extensions with access to orders (for example invoicing plugins)', 'eurob2b' ),
			),
			'retention'   => array(
				__( 'With the order, following the retention of orders in WooCommerce and the statutory retention periods for business records.', 'eurob2b' ),
				__( 'When WooCommerce anonymises an order, these details are removed; on orders with a tax exemption the VAT ID stays as tax evidence.', 'eurob2b' ),
			),
			'legal_basis' => array(
				__( 'Art. 6(1)(b) GDPR: performance of the contract', 'eurob2b' ),
				__( 'Art. 6(1)(c) GDPR: statutory retention duties', 'eurob2b' ),
			),
		);
	}

	/**
	 * EB-10: audit log of administrative decisions (D-20).
	 *
	 * @return array<string, mixed>
	 */
	private function audit_log(): array {
		$days = min( 3650, max( 30, (int) $this->settings->get( 'audit_retention_days' ) ) );
		return array(
			'id'          => 'EB-10',
			'inventory'   => self::INVENTORY['EB-10'],
			'name'        => __( 'Audit log of administrative decisions', 'eurob2b' ),
			'purposes'    => array(
				__( 'Tracing who decided on registrations, customer status, type and group and VAT ID checks, and when, as evidence of accountability and for internal control.', 'eurob2b' ),
			),
			'subjects'    => array(
				__( 'Customers concerned by a decision', 'eurob2b' ),
				__( 'Shop staff who decide (user ID only)', 'eurob2b' ),
			),
			'data'        => array(
				__( 'Time, action, user IDs of the deciding and the concerned person, order or check number, previous and new value (status, customer type, customer group, check result); no IP addresses, no notes, no names', 'eurob2b' ),
			),
			'internal'    => array(
				__( 'Shop staff with the capability to manage settings (eurob2b_manage_settings), read only', 'eurob2b' ),
			),
			'retention'   => array(
				/* translators: %d: number of days */
				sprintf( _n( 'Deleted %d day after the entry was written, by a daily job.', 'Deleted %d days after the entry was written, by a daily job.', $days, 'eurob2b' ), $days ),
				__( 'On an erasure request and when an account is deleted, the reference to the person is removed; the entry stays without it.', 'eurob2b' ),
			),
			'legal_basis' => array(
				__( 'Art. 6(1)(f) GDPR: legitimate interest in tracing decisions and in internal control, as part of the accountability under Art. 5(2) GDPR', 'eurob2b' ),
			),
		);
	}

	/**
	 * EB-09: abuse protection of the registration (D-15). EuroB2B Pro replaces it with the activity
	 * that also covers the order form (D-16) and quote requests from guests.
	 *
	 * @return array<string, mixed>
	 */
	private function abuse_protection(): array {
		$registration = (int) $this->settings->get( 'registration_throttle_limit' ) > 0;
		return array(
			'id'          => 'EB-09',
			'inventory'   => array( 'D-15' ),
			'name'        => __( 'Protection of the registration against abuse', 'eurob2b' ),
			'in_use'      => $registration,
			'off_reason'  => __( 'All request limits are switched off.', 'eurob2b' ),
			'purposes'    => array(
				__( 'Limiting the registrations per connection and hour, against automated mass registrations.', 'eurob2b' ),
			),
			'subjects'    => array(
				__( 'Visitors who register', 'eurob2b' ),
			),
			'data'        => array(
				__( 'A salted hash of the IP address that cannot be turned back into the address, and a request count', 'eurob2b' ),
			),
			'internal'    => array(
				__( 'None; the entries are not shown in the admin', 'eurob2b' ),
			),
			'retention'   => array(
				__( 'Registration: 1 hour, then the entry expires.', 'eurob2b' ),
			),
			'legal_basis' => array(
				__( 'Art. 6(1)(f) GDPR: legitimate interest in protecting the shop against automated abuse', 'eurob2b' ),
			),
		);
	}

	/**
	 * Name and contact details of the controller (Art. 30(1)(a)): name, address and website from the
	 * site and the WooCommerce store, the rest from the settings of *Privacy and data* (empty when not set).
	 *
	 * @return array{name: string, address: string, website: string, contact: string, contact_person: string, joint_controllers: string, eu_representative: string, dpo: string}
	 */
	public function controller(): array {
		$address = '';
		if ( function_exists( 'WC' ) && null !== WC()->countries ) {
			$countries = WC()->countries;
			$address   = (string) $countries->get_formatted_address(
				array(
					'address_1' => $countries->get_base_address(),
					'address_2' => $countries->get_base_address_2(),
					'city'      => $countries->get_base_city(),
					'state'     => $countries->get_base_state(),
					'postcode'  => $countries->get_base_postcode(),
					'country'   => $countries->get_base_country(),
				),
				"\n"
			);
		}
		// Plain text: WordPress stores the site title HTML-escaped; html() escapes once on output.
		return array(
			'name'              => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'address'           => trim( wp_specialchars_decode( $address, ENT_QUOTES ) ),
			'website'           => (string) home_url( '/' ),
			'contact'           => trim( (string) $this->settings->get( 'record_contact' ) ),
			'contact_person'    => trim( (string) $this->settings->get( 'record_contact_person' ) ),
			'joint_controllers' => trim( (string) $this->settings->get( 'record_joint_controllers' ) ),
			'eu_representative' => trim( (string) $this->settings->get( 'record_eu_representative' ) ),
			'dpo'               => trim( (string) $this->settings->get( 'record_dpo' ) ),
		);
	}

	/**
	 * The record as HTML (inside an element with the class "eurob2b-record").
	 */
	public function html(): string {
		$activities = $this->activities();
		$in_use     = array_values( array_filter( $activities, static fn( array $a ): bool => $a['in_use'] ) );
		$not_in_use = array_values( array_filter( $activities, static fn( array $a ): bool => ! $a['in_use'] ) );
		$controller = $this->controller();
		$missing    = '<span class="eurob2b-record__todo">' . esc_html__( 'please add', 'eurob2b' ) . '</span>';
		$text       = static fn( string $value ): string => '' !== $value ? nl2br( esc_html( $value ), false ) : $missing;

		$html  = '<div class="eurob2b-record">';
		$html .= '<p class="eurob2b-record__scope">' . esc_html__( 'This draft covers only the processing that EuroB2B adds to the shop. Your record also needs the processing of WordPress, WooCommerce and other plugins, of your hosting, e-mail delivery, payment and shipping services and of your business outside the shop. Check every entry, add what is missing and remove what does not apply. Legal bases are candidates that you should review with your data protection advisor. Whether you must keep a record follows from Art. 30(5) GDPR.', 'eurob2b' ) . '</p>';

		$html .= '<section class="eurob2b-record__cover"><h2>' . esc_html__( 'Controller (Art. 30(1)(a) GDPR)', 'eurob2b' ) . '</h2><table>';
		$rows  = array(
			__( 'Name', 'eurob2b' )                     => '' !== $controller['name'] ? esc_html( $controller['name'] ) : $missing,
			__( 'Address', 'eurob2b' )                  => '' !== $controller['address'] ? nl2br( esc_html( $controller['address'] ), false ) : $missing,
			__( 'Website', 'eurob2b' )                  => esc_html( $controller['website'] ),
			__( 'Phone and e-mail address', 'eurob2b' ) => $text( $controller['contact'] ),
			__( 'Contact person', 'eurob2b' )           => $text( $controller['contact_person'] ),
			__( 'Joint controllers (Art. 26 GDPR)', 'eurob2b' ) => $text( $controller['joint_controllers'] ),
			__( 'Representative in the EU (Art. 27 GDPR)', 'eurob2b' ) => $text( $controller['eu_representative'] ),
			__( 'Data protection officer (Art. 37 GDPR)', 'eurob2b' ) => $text( $controller['dpo'] ),
		);
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
		}
		$html .= '</table></section>';

		foreach ( $in_use as $activity ) {
			$html .= $this->activity_html( $activity );
		}

		$html .= '<section class="eurob2b-record__general"><h2>' . esc_html__( 'For all activities above', 'eurob2b' ) . '</h2>';
		$html .= '<h3>' . esc_html__( 'Special categories of personal data (Art. 9 and 10 GDPR)', 'eurob2b' ) . '</h3>';
		/**
		 * Filters the sentence on special categories of personal data (Art. 9 and 10 GDPR) in the
		 * record. EuroB2B Pro adds its quote request forms.
		 *
		 * @param string $sentence Plain text.
		 */
		$special = apply_filters( 'eurob2b_processing_record_special_categories', __( 'EuroB2B itself processes no special categories of personal data and no data on criminal convictions. Fields you add to the registration form can change this.', 'eurob2b' ) );
		$html   .= '<p>' . esc_html( is_string( $special ) ? $special : '' ) . '</p>';
		$html   .= '<h3>' . esc_html__( 'Transfers to third countries (Art. 30(1)(e) GDPR)', 'eurob2b' ) . '</h3>';
		/**
		 * Filters the sentences on transfers to third countries (Art. 30(1)(e) GDPR) in the record,
		 * plain text joined by spaces. The first is the core's statement; EuroB2B Pro replaces it
		 * while the Swiss UID check is on and adds one on Norway while the Brønnøysund check is on
		 * (docs/privacy-and-data-flows.md D-03).
		 *
		 * @param string[] $sentences Sentences.
		 */
		$transfers = apply_filters( 'eurob2b_processing_record_transfers', array( __( 'EuroB2B transfers no personal data to third countries or international organisations. Add the transfers of your hosting, e-mail delivery and other services.', 'eurob2b' ) ) );

		$html .= '<p>' . implode( ' ', array_map( 'esc_html', is_array( $transfers ) ? array_filter( $transfers, 'is_string' ) : array() ) ) . '</p>';
		$html .= '<h3>' . esc_html__( 'Technical and organisational measures (Art. 30(1)(g) and Art. 32(1) GDPR)', 'eurob2b' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'Measures of EuroB2B; add those of your hosting and your organisation (for example encryption in transit, backups, access to the server, staff instructions):', 'eurob2b' ) . '</p><ul>';
		/**
		 * Filters the technical and organisational measures of the record (Art. 30(1)(g) GDPR), plain
		 * text keyed by topic: access, input, abuse, retention, connections, telemetry. EuroB2B Pro adds
		 * `companies` after `input` and names the Peppol Directory lookup in `connections` (PLT-015).
		 *
		 * @param array<string, string> $measures Measures in their order.
		 */
		$measures = apply_filters(
			'eurob2b_processing_record_measures',
			array(
				'access'      => __( 'Admin screens, exports and the REST API require role-based capabilities; forms and downloads are protected against cross-site request forgery (nonces).', 'eurob2b' ),
				'input'       => __( 'Input is validated on the server, output is escaped, and database queries use prepared statements.', 'eurob2b' ),
				'abuse'       => __( 'The abuse protection stores IP addresses only as salted hashes that cannot be turned back into the address.', 'eurob2b' ),
				'retention'   => __( 'Retention periods are applied by a daily job; requests for access and erasure are answered with the WordPress tools Export Personal Data and Erase Personal Data, which include EuroB2B\'s data.', 'eurob2b' ),
				'connections' => __( 'Connections to external services exist only for VAT ID checks, only after they are switched on, and use HTTPS.', 'eurob2b' ),
				'telemetry'   => __( 'EuroB2B has no telemetry and sends no data to its developers. Uninstalling deletes data only after an explicit opt-in.', 'eurob2b' ),
			)
		);
		foreach ( is_array( $measures ) ? $measures : array() as $measure ) {
			if ( is_string( $measure ) && '' !== $measure ) {
				$html .= '<li>' . esc_html( $measure ) . '</li>';
			}
		}
		$html .= '</ul></section>';

		if ( array() !== $not_in_use ) {
			$html .= '<section class="eurob2b-record__unused"><h2>' . esc_html__( 'Not in use on this shop', 'eurob2b' ) . '</h2>';
			$html .= '<p>' . esc_html__( 'These activities are not part of the record while their feature is switched off. Data stored while it was on stays until its retention period ends.', 'eurob2b' ) . '</p><ul>';
			foreach ( $not_in_use as $activity ) {
				$html .= '<li>' . esc_html( $activity['id'] . ' ' . $activity['name'] ) . ( '' !== $activity['off_reason'] ? ': ' . esc_html( $activity['off_reason'] ) : '' ) . '</li>';
			}
			$html .= '</ul></section>';
		}

		$html .= '<p class="eurob2b-record__footer">' . esc_html(
			sprintf(
				/* translators: 1: date, 2: plugin version */
				__( 'Generated on %1$s by EuroB2B %2$s from the current settings. Changes to the settings change this draft; keep dated copies as the history of your record.', 'eurob2b' ),
				wp_date( (string) get_option( 'date_format' ) ),
				EUROB2B_VERSION
			)
		) . '</p>';
		return $html . '</div>';
	}

	/**
	 * One activity as a table in the order of Art. 30(1) GDPR.
	 *
	 * @param array{id: string, name: string, inventory: string[], in_use: bool, off_reason: string, purposes: string[], subjects: string[], data: string[], internal: string[], external: string[], retention: string[], legal_basis: string[]} $activity Activity.
	 */
	private function activity_html( array $activity ): string {
		$none  = array( _x( 'None', 'recipients', 'eurob2b' ) );
		$rows  = array(
			__( 'Purposes (Art. 30(1)(b))', 'eurob2b' ) => $activity['purposes'],
			__( 'Categories of data subjects (Art. 30(1)(c))', 'eurob2b' ) => $activity['subjects'],
			__( 'Categories of personal data (Art. 30(1)(c))', 'eurob2b' ) => $activity['data'],
			__( 'Recipients inside the shop (persons with access)', 'eurob2b' ) => array() !== $activity['internal'] ? $activity['internal'] : $none,
			__( 'External recipients (Art. 30(1)(d))', 'eurob2b' ) => array() !== $activity['external'] ? $activity['external'] : $none,
			__( 'Time limits for erasure (Art. 30(1)(f))', 'eurob2b' ) => $activity['retention'],
			__( 'Legal basis (candidates to review)', 'eurob2b' ) => $activity['legal_basis'],
		);
		$html  = '<section class="eurob2b-record__activity" id="' . esc_attr( 'eurob2b-record-' . sanitize_title( $activity['id'] ) ) . '">';
		$html .= '<h2>' . esc_html( $activity['id'] . ' ' . $activity['name'] ) . '</h2><table>';
		foreach ( $rows as $label => $items ) {
			if ( array() === $items ) {
				continue;
			}
			$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			$html .= 1 === count( $items ) ? esc_html( $items[0] ) : '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
			$html .= '</td></tr>';
		}
		if ( array() !== $activity['inventory'] ) {
			$html .= '<tr><th scope="row">' . esc_html__( 'EuroB2B data inventory', 'eurob2b' ) . '</th><td>' . esc_html( implode( ', ', $activity['inventory'] ) ) . '</td></tr>';
		}
		return $html . '</table></section>';
	}
}

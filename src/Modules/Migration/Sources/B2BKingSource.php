<?php
/**
 * Import source: B2BKing.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration\Sources;

use EuroB2B\Modules\Admin\Settings;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Migration\ImportWriter;
use EuroB2B\Modules\Migration\PriceTarget;
use EuroB2B\Modules\Migration\QuantityTarget;
use EuroB2B\Modules\Migration\Run;
use EuroB2B\Modules\Migration\SourceInterface;
use EuroB2B\Modules\Migration\VisibilityTarget;
use EuroB2B\Modules\Payments\MethodPolicy;
use EuroB2B\Modules\Pricing\GroupPrices;
use EuroB2B\Modules\Registration\FieldDefinition;
use EuroB2B\Modules\Registration\RegistrationType;
use EuroB2B\Support\Decimal;
use InvalidArgumentException;

/**
 * Reads B2BKing's data (MIG-002) as B2BKing Core 5.2.61 stores and reads it; B2BKing Pro uses the
 * same keys (Core contains the code that reads Pro's data). Only reads: B2BKing's posts, meta and
 * options stay as they are, and B2BKing may already be deactivated. Tax data, offers,
 * conversations and purchase lists are not imported (docs/migration.md).
 */
final class B2BKingSource implements SourceInterface {

	public const PART_GROUPS       = 'groups';
	public const PART_REGISTRATION = 'registration';
	public const PART_CUSTOMERS    = 'customers';
	public const PART_COMPANIES    = 'companies';
	public const PART_PRICES       = 'prices';
	public const PART_METHODS      = 'methods';
	public const PART_RULES        = 'rules';
	public const PART_VISIBILITY   = 'visibility';

	/**
	 * Field types B2BKing and EuroB2B share.
	 */
	private const FIELD_TYPES = array( 'text', 'textarea', 'number', 'tel', 'select', 'checkbox', 'email', 'file', 'date' );

	/**
	 * Post statuses that count (not trash, not auto drafts).
	 */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Product price keys (LIKE patterns, escaped at use).
	 */
	private const PRICE_PREFIXES = array( 'b2bking_regular_product_price_group_', 'b2bking_sale_product_price_group_', 'b2bking_product_pricetiers_', 'b2bking_regular_price_user_', 'b2bking_sale_price_user_', 'b2bking_quantity_product_' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Rule mapping.
	 *
	 * @var B2BKingRules
	 */
	private B2BKingRules $rules;

	/**
	 * Whether company accounts can be imported (EuroB2B Pro).
	 *
	 * @var bool
	 */
	private bool $companies;

	/**
	 * Visibility rules (EuroB2B Pro); null without it.
	 *
	 * @var VisibilityTarget|null
	 */
	private ?VisibilityTarget $visibility;

	/**
	 * Quantity limits (EuroB2B Pro); null without it.
	 *
	 * @var QuantityTarget|null
	 */
	private ?QuantityTarget $quantities;

	/**
	 * Customer prices and tier sets (EuroB2B Pro); null without it.
	 *
	 * @var PriceTarget|null
	 */
	private ?PriceTarget $prices;

	/**
	 * Customer type per registration role (request cache).
	 *
	 * @var array<int, CustomerType>
	 */
	private array $role_types = array();

	/**
	 * Field type per B2BKing field (request cache).
	 *
	 * @var array<int, string>
	 */
	private array $field_types = array();

	/**
	 * Constructor.
	 *
	 * The company accounts, the visibility, the quantity rules, the customer prices and the tier
	 * prices of EuroB2B Pro are imported only when it is active (PLT-015).
	 *
	 * @param Settings              $settings   Settings.
	 * @param B2BKingRules          $rules      Rule mapping.
	 * @param bool                  $companies  Whether company accounts can be imported.
	 * @param VisibilityTarget|null $visibility Visibility rules.
	 * @param QuantityTarget|null   $quantities Quantity limits.
	 * @param PriceTarget|null      $prices     Customer prices and tier sets.
	 */
	public function __construct( Settings $settings, B2BKingRules $rules, bool $companies = false, ?VisibilityTarget $visibility = null, ?QuantityTarget $quantities = null, ?PriceTarget $prices = null ) {
		$this->settings   = $settings;
		$this->rules      = $rules;
		$this->companies  = $companies;
		$this->visibility = $visibility;
		$this->quantities = $quantities;
		$this->prices     = $prices;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'b2bking';
	}

	/**
	 * Name.
	 */
	public function label(): string {
		return 'B2BKing';
	}

	/**
	 * Whether B2BKing data exists.
	 */
	public function detected(): bool {
		return $this->count_posts( 'b2bking_group' ) > 0 || $this->count_users( 'b2bking_customergroup' ) > 0;
	}

	/**
	 * Whether B2BKing Core or Pro is loaded.
	 */
	public function active(): bool {
		return defined( 'B2BKINGCORE_DIR' ) || defined( 'B2BKING_DIR' );
	}

	/**
	 * What was found.
	 *
	 * @return array<string, int>
	 */
	public function summary(): array {
		return array(
			__( 'Customer groups', 'eurob2b' )     => $this->count_posts( 'b2bking_group' ),
			__( 'Customers with a group or approval status', 'eurob2b' ) => $this->count_customers(),
			__( 'Registration roles', 'eurob2b' )  => $this->count_posts( 'b2bking_custom_role' ) - $this->count_placeholder_roles(),
			__( 'Registration fields', 'eurob2b' ) => $this->count_posts( 'b2bking_custom_field' ),
			__( 'Products and variations with B2B prices', 'eurob2b' ) => $this->count_priced_products(),
			__( 'Rules', 'eurob2b' )               => $this->count_posts( 'b2bking_rule' ),
			__( 'Sub-accounts', 'eurob2b' )        => $this->count_users( 'b2bking_account_type', 'subaccount' ),
		);
	}

	/**
	 * Parts in import order.
	 *
	 * @return array<string, string>
	 */
	public function parts(): array {
		return array(
			self::PART_GROUPS       => __( 'Customer groups', 'eurob2b' ),
			self::PART_REGISTRATION => __( 'Registration roles and fields', 'eurob2b' ),
			self::PART_CUSTOMERS    => __( 'Customers: type, group, approval status, registration values', 'eurob2b' ),
			self::PART_COMPANIES    => __( 'Sub-accounts as company accounts', 'eurob2b' ),
			self::PART_PRICES       => __( 'Group and customer prices, tier prices, quantity limits', 'eurob2b' ),
			self::PART_METHODS      => __( 'Payment and shipping methods per group and customer', 'eurob2b' ),
			self::PART_RULES        => __( 'Rules', 'eurob2b' ),
			self::PART_VISIBILITY   => __( 'Product and category visibility', 'eurob2b' ),
		);
	}

	/**
	 * Notes before the first part.
	 *
	 * @return array<int, array{level: string, text: string}>
	 */
	public function notes(): array {
		global $wpdb;
		$notes = array();
		$add   = static function ( string $level, string $text ) use ( &$notes ): void {
			$notes[] = array(
				'level' => $level,
				'text'  => $text,
			);
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only counts of another plugin's data.
		$vat_customers = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", 'b2bking_user_vat_status', 'validated_vat' ) );
		$tax_rules     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value IN (%s, %s, %s, %s)",
				'b2bking_rule',
				'b2bking_rule_what',
				'tax_exemption',
				'tax_exemption_user',
				'add_tax_percentage',
				'add_tax_amount'
			)
		);
		$info_rows     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ('product', 'product_variation') AND m.meta_key LIKE %s AND m.meta_value <> ''", $wpdb->esc_like( 'b2bking_product_customrows_' ) . '%' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $vat_customers > 0 || $tax_rules > 0 ) {
			/* translators: 1: number of customers, 2: number of rules */
			$add( Run::LEVEL_WARNING, sprintf( __( 'Tax is not imported (customers with a VAT ID that B2BKing validated: %1$d; tax rules: %2$d). These customers pay VAT in EuroB2B until EuroB2B has checked their VAT ID (Settings → Tax and VAT IDs).', 'eurob2b' ), $vat_customers, $tax_rules ) );
		}
		$offers        = $this->count_posts( 'b2bking_offer' );
		$conversations = $this->count_posts( 'b2bking_conversation' );
		$lists         = $this->count_posts( 'b2bking_list' );
		if ( $offers + $conversations + $lists > 0 ) {
			/* translators: 1: number of offers, 2: number of conversations, 3: number of purchase lists */
			$add( Run::LEVEL_INFO, sprintf( __( 'Not imported from B2BKing: offers (%1$d), conversations (%2$d), purchase lists (%3$d).', 'eurob2b' ), $offers, $conversations, $lists ) );
		}
		if ( $info_rows > 0 ) {
			/* translators: %d: number of products */
			$add( Run::LEVEL_INFO, sprintf( __( 'Not imported: the extra information rows of products (%d products); EuroB2B has no such rows.', 'eurob2b' ), $info_rows ) );
		}
		$subaccounts = $this->count_users( 'b2bking_account_type', 'subaccount' ) > 0;
		if ( $subaccounts && ! $this->companies ) {
			$add( Run::LEVEL_WARNING, __( 'B2BKing has sub-accounts; company accounts are a feature of EuroB2B Pro, so they are not imported.', 'eurob2b' ) );
		} elseif ( $subaccounts && ! $this->settings->get( 'company_enabled' ) ) {
			$add( Run::LEVEL_WARNING, __( 'B2BKing has sub-accounts, but company accounts are switched off in EuroB2B (Settings → Company accounts). The companies are created; switch them on to use them.', 'eurob2b' ) );
		}
		if ( $this->visibility_in_use() && null === $this->visibility ) {
			$add( Run::LEVEL_WARNING, __( 'B2BKing hides products from groups; product visibility is a feature of EuroB2B Pro, so it is not imported and the products are visible to everyone.', 'eurob2b' ) );
		} elseif ( $this->visibility_in_use() && ! $this->settings->get( 'visibility_control' ) ) {
			$add( Run::LEVEL_WARNING, __( 'B2BKing hides products from groups, but product visibility is switched off in EuroB2B (Settings → Guest access). Switch it on after the import, or the products are visible to everyone.', 'eurob2b' ) );
		}
		if ( $this->quantities_in_use() && null === $this->quantities ) {
			$add( Run::LEVEL_WARNING, __( 'B2BKing has quantity limits per product; quantity rules are a feature of EuroB2B Pro, so they are not imported.', 'eurob2b' ) );
		} elseif ( $this->quantities_in_use() && ! $this->settings->get( 'quantity_rules_enabled' ) ) {
			$add( Run::LEVEL_WARNING, __( 'B2BKing has quantity limits per product, but quantity rules are switched off in EuroB2B (Settings → Ordering).', 'eurob2b' ) );
		}
		if ( null === $this->prices && $this->has_product_meta( array( 'b2bking_regular_price_user_', 'b2bking_sale_price_user_' ) ) ) {
			$add( Run::LEVEL_WARNING, __( 'B2BKing has prices for single customers; customer prices are a feature of EuroB2B Pro, so they are not imported.', 'eurob2b' ) );
		}
		if ( null === $this->prices && $this->has_product_meta( array( 'b2bking_product_pricetiers_' ) ) ) {
			$add( Run::LEVEL_WARNING, __( 'B2BKing has tier prices; tier prices are a feature of EuroB2B Pro, so they are not imported.', 'eurob2b' ) );
		}
		$mode = (string) get_option( 'b2bking_plugin_status_setting', 'b2b' );
		/* translators: %s: B2BKing shop mode (b2b or hybrid) */
		$add( Run::LEVEL_INFO, sprintf( __( 'Settings are not imported. B2BKing ran in the mode "%s"; compare guest access, registration and approval in the EuroB2B settings by hand.', 'eurob2b' ), $mode ) );
		$add( Run::LEVEL_INFO, __( 'B2BKing subtracts a discount that is not shown in the shop as a fee in the cart; EuroB2B applies every discount to the price.', 'eurob2b' ) );
		return $notes;
	}

	/**
	 * Handles one page of a part.
	 *
	 * @param string       $part   Part.
	 * @param int          $offset Items handled before.
	 * @param int          $limit  Page size.
	 * @param ImportWriter $writer Writer.
	 */
	public function import_page( string $part, int $offset, int $limit, ImportWriter $writer ): int {
		switch ( $part ) {
			case self::PART_GROUPS:
				return $this->import_groups( $offset, $writer );
			case self::PART_REGISTRATION:
				return $this->import_registration( $offset, $writer );
			case self::PART_CUSTOMERS:
				return $this->import_customers( $offset, $limit, $writer );
			case self::PART_COMPANIES:
				return $this->import_companies( $offset, $limit, $writer );
			case self::PART_PRICES:
				return $this->import_prices( $offset, $limit, $writer );
			case self::PART_METHODS:
				return $this->import_methods( $offset, $limit, $writer );
			case self::PART_RULES:
				return $this->import_rules( $offset, $limit, $writer );
			case self::PART_VISIBILITY:
				return $this->import_visibility( $offset, $limit, $writer );
		}
		return 0;
	}

	/**
	 * EuroB2B group of a B2BKing group id: its id (negative in a test run for a new group), or null
	 * when the group was not imported.
	 *
	 * @param ImportWriter $writer     Writer.
	 * @param int          $b2bking_id B2BKing group post id.
	 */
	public static function group_of( ImportWriter $writer, int $b2bking_id ): ?int {
		$target = $writer->target( 'group', 'group:' . $b2bking_id );
		return null !== $target && 0 !== (int) $target ? (int) $target : null;
	}

	/**
	 * Groups (one page: shops have few groups).
	 *
	 * @param int          $offset Items handled before.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_groups( int $offset, ImportWriter $writer ): int {
		if ( $offset > 0 ) {
			return 0;
		}
		$posts = $this->posts( 'b2bking_group', 0, 1000 );
		foreach ( $posts as $post ) {
			$name = trim( wp_strip_all_tags( $post['title'] ) );
			/* translators: %d: B2BKing group id */
			$name = '' !== $name ? mb_substr( $name, 0, 190 ) : sprintf( __( 'B2BKing group %d', 'eurob2b' ), $post['id'] );
			$slug = substr( sanitize_title( $name ), 0, 64 );
			$slug = 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug ) ? $slug : 'b2bking-' . $post['id'];
			$writer->group( 'group:' . $post['id'], $name, $slug, 'b2bking-' . $post['id'] );
		}
		return count( $posts );
	}

	/**
	 * Registration roles, then fields (one page).
	 *
	 * @param int          $offset Items handled before.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_registration( int $offset, ImportWriter $writer ): int {
		if ( $offset > 0 ) {
			return 0;
		}
		$roles = $this->posts( 'b2bking_custom_role', 0, 1000, true );
		foreach ( $roles as $post ) {
			$meta = $this->post_meta( $post['id'] );
			if ( '1' === ( $meta['b2bking_non_selectable'] ?? '' ) ) {
				continue;
			}
			$group    = $this->role_group( $writer, $meta );
			$approval = 'manual' === ( $meta['b2bking_custom_role_approval'] ?? '' ) ? RegistrationType::APPROVAL_MANUAL : RegistrationType::APPROVAL_AUTOMATIC;
			/* translators: %d: B2BKing role id */
			$label = '' !== trim( $post['title'] ) ? trim( wp_strip_all_tags( $post['title'] ) ) : sprintf( __( 'B2BKing registration role %d', 'eurob2b' ), $post['id'] );
			$type  = new RegistrationType(
				'b2bking-' . $post['id'],
				$label,
				$this->role_type( $post['id'] ),
				$approval,
				$group,
				'publish' === $post['status'] && '0' !== ( $meta['b2bking_custom_role_status'] ?? '1' ),
				$post['order'],
				''
			);
			$writer->registration_type( 'role:' . $post['id'], $type );
		}//end foreach
		$fields = $this->posts( 'b2bking_custom_field', 0, 1000 );
		foreach ( $fields as $post ) {
			$this->import_field( $post, $writer );
		}
		return count( $roles ) + count( $fields );
	}

	/**
	 * One registration field (a checkbox field with several choices becomes one checkbox per choice).
	 *
	 * @param array{id: int, title: string, status: string, order: int} $post   Field post.
	 * @param ImportWriter                                              $writer Writer.
	 */
	private function import_field( array $post, ImportWriter $writer ): void {
		$meta  = $this->post_meta( $post['id'] );
		$label = trim( wp_strip_all_tags( '' !== ( $meta['b2bking_custom_field_field_label'] ?? '' ) ? $meta['b2bking_custom_field_field_label'] : $post['title'] ) );
		/* translators: %d: B2BKing field id */
		$label = '' !== $label ? $label : sprintf( __( 'B2BKing field %d', 'eurob2b' ), $post['id'] );
		if ( 'billing_vat' === ( $meta['b2bking_custom_field_billing_connection'] ?? '' ) || '1' === ( $meta['b2bking_custom_field_VAT_VIES_validation'] ?? '' ) ) {
			/* translators: %s: field label */
			$writer->skip( sprintf( __( 'Field "%s" is the VAT ID field; EuroB2B asks for the VAT ID itself (tax is not imported).', 'eurob2b' ), $label ) );
			return;
		}
		if ( 'publish' !== $post['status'] || '0' === ( $meta['b2bking_custom_field_status'] ?? '1' ) ) {
			/* translators: %s: field label */
			$writer->skip( sprintf( __( 'Field "%s" is switched off in B2BKing and was not imported.', 'eurob2b' ), $label ) );
			return;
		}
		$types = $this->field_roles( $meta, $writer );
		if ( null === $types ) {
			/* translators: %s: field label */
			$writer->skip( sprintf( __( 'Field "%s" belongs to registration roles that were not imported.', 'eurob2b' ), $label ) );
			return;
		}
		$type    = $this->field_type( $post['id'] );
		$map_to  = (string) ( $meta['b2bking_custom_field_billing_connection'] ?? '' );
		$base    = array(
			'key'                => 'bk_' . $post['id'],
			'label'              => $label,
			'type'               => $type,
			'required'           => '1' === ( $meta['b2bking_custom_field_required'] ?? '' ),
			'registration_types' => $types,
			'placeholder'        => (string) ( $meta['b2bking_custom_field_field_placeholder'] ?? '' ),
			'show_in_checkout'   => '1' === ( $meta['b2bking_custom_field_add_to_billing'] ?? '' ),
			'show_in_account'    => '0' !== ( $meta['b2bking_custom_field_editable'] ?? '1' ),
			'map_to'             => in_array( $map_to, FieldDefinition::mappable_billing_keys(), true ) ? $map_to : '',
			'order'              => (int) ( $meta['b2bking_custom_field_sort_number'] ?? $post['order'] ),
		);
		$choices = B2BKingFormat::choices( (string) ( $meta['b2bking_custom_field_user_choices'] ?? '' ) );
		$defs    = array();
		if ( 'select' === $type ) {
			$base['options'] = array_combine( $choices, $choices );
			$defs['']        = $base;
		} elseif ( 'checkbox' === $type && count( $choices ) > 1 ) {
			foreach ( $choices as $i => $choice ) {
				$defs[ ':' . ( $i + 1 ) ] = array_merge(
					$base,
					array(
						'key'    => 'bk_' . $post['id'] . '_' . ( $i + 1 ),
						'label'  => $label . ': ' . $choice,
						'map_to' => '',
					)
				);
			}
		} else {
			$defs[''] = $base;
		}
		foreach ( $defs as $suffix => $data ) {
			try {
				$field = FieldDefinition::from_array( $data );
			} catch ( InvalidArgumentException $e ) {
				/* translators: 1: field label, 2: error message */
				$writer->skip( sprintf( __( 'Field "%1$s" was not imported: %2$s', 'eurob2b' ), $label, $e->getMessage() ) );
				continue;
			}
			$writer->field( 'field:' . $post['id'] . $suffix, $field );
		}
	}

	/**
	 * Registration types of a field: [] for every type, null when its roles were not imported.
	 *
	 * @param array<string, string> $meta   Field meta.
	 * @param ImportWriter          $writer Writer.
	 * @return string[]|null
	 */
	private function field_roles( array $meta, ImportWriter $writer ): ?array {
		$setting = (string) ( $meta['b2bking_custom_field_registration_role'] ?? 'allroles' );
		if ( 'allroles' === $setting || '' === $setting ) {
			return array();
		}
		$raw   = 'multipleroles' === $setting ? (string) ( $meta['b2bking_custom_field_multiple_roles'] ?? '' ) : $setting;
		$slugs = array();
		foreach ( B2BKingFormat::tokens( $raw )['role'] ?? array() as $role_id ) {
			$slug = (string) $writer->target( 'registration_type', 'role:' . $role_id );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		return empty( $slugs ) ? null : $slugs;
	}

	/**
	 * Customers: classification, registration type and field values.
	 *
	 * @param int          $offset Items handled before.
	 * @param int          $limit  Page size.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_customers( int $offset, int $limit, ImportWriter $writer ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only paging over another plugin's data.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s, %s, %s) ORDER BY user_id ASC LIMIT %d OFFSET %d", 'b2bking_customergroup', 'b2bking_b2buser', 'b2bking_account_approved', 'b2bking_registration_role', $limit, $offset ) );
		foreach ( $ids as $id ) {
			$user_id = (int) $id;
			if ( ! get_userdata( $user_id ) ) {
				continue;
			}
			$meta = $this->user_meta( $user_id );
			$role = 1 === preg_match( '/^role_(\d+)$/', (string) ( $meta['b2bking_registration_role'] ?? '' ), $m ) ? (int) $m[1] : 0;
			$this->import_classification( $user_id, $meta, $role, $writer );
			if ( $role > 0 ) {
				$slug = (string) $writer->target( 'registration_type', 'role:' . $role );
				if ( '' !== $slug ) {
					$writer->meta( 'user', $user_id, '_eurob2b_registration_type', $slug );
				}
			}
			$this->import_field_values( $user_id, $meta, $writer );
		}
		return count( $ids );
	}

	/**
	 * Type, approval status and group of one customer.
	 *
	 * @param int                   $user_id User id.
	 * @param array<string, string> $meta    The user's B2BKing meta.
	 * @param int                   $role    B2BKing registration role id (0 = none).
	 * @param ImportWriter          $writer  Writer.
	 */
	private function import_classification( int $user_id, array $meta, int $role, ImportWriter $writer ): void {
		$status = 'no' === ( $meta['b2bking_account_approved'] ?? '' ) ? CustomerStatus::PENDING : CustomerStatus::APPROVED;
		$group  = (string) ( $meta['b2bking_customergroup'] ?? '' );
		if ( 1 === preg_match( '/^\d+$/', $group ) && (int) $group > 0 ) {
			$target = self::group_of( $writer, (int) $group );
			if ( null === $target ) {
				/* translators: 1: user id, 2: B2BKing group id */
				$writer->note( Run::LEVEL_WARNING, sprintf( __( 'Customer #%1$d: B2BKing group %2$d was not imported; the customer is put into the default business group.', 'eurob2b' ), $user_id, (int) $group ) );
			}
			$writer->customer( $user_id, CustomerType::B2B, $status, $target );
			return;
		}
		if ( 'yes' === ( $meta['b2bking_b2buser'] ?? '' ) && 'no' !== $group ) {
			$writer->customer( $user_id, CustomerType::B2B, $status, null );
			return;
		}
		if ( CustomerStatus::PENDING === $status ) {
			// An application waits for approval: its type comes from the role it registered with.
			$writer->customer( $user_id, $role > 0 ? $this->role_type( $role ) : CustomerType::B2B, $status, null );
			return;
		}
		$writer->customer( $user_id, CustomerType::B2C, $status, null );
	}

	/**
	 * Values of the custom registration fields (files stay where B2BKing put them).
	 *
	 * @param int                   $user_id User id.
	 * @param array<string, string> $meta    The user's B2BKing meta.
	 * @param ImportWriter          $writer  Writer.
	 */
	private function import_field_values( int $user_id, array $meta, ImportWriter $writer ): void {
		foreach ( $meta as $key => $value ) {
			if ( 1 !== preg_match( '/^b2bking_custom_field_(\d+)(?:_option_(\d+))?$/', $key, $m ) || '' === trim( $value ) ) {
				continue;
			}
			$field_id = (int) $m[1];
			$type     = $this->field_type( $field_id );
			if ( 'file' === $type ) {
				/* translators: 1: user id, 2: B2BKing field id */
				$writer->skip( sprintf( __( 'Customer #%1$d: the file of field %2$d stays in the media library; files are not moved.', 'eurob2b' ), $user_id, $field_id ) );
				continue;
			}
			$option = isset( $m[2] ) ? (int) $m[2] : 0;
			if ( $option > 0 ) {
				// A ticked choice of a checkbox field: its own checkbox, or the single checkbox.
				$target = $writer->target( 'field', 'field:' . $field_id . ':' . $option ) ?? ( 1 === $option ? $writer->target( 'field', 'field:' . $field_id ) : null );
				$value  = '1';
			} else {
				$target = 'checkbox' === $type ? null : $writer->target( 'field', 'field:' . $field_id );
				$value  = 'textarea' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
			if ( null !== $target && '' !== (string) $target ) {
				$writer->meta( 'user', $user_id, FieldDefinition::META_PREFIX . $target, $value );
			}
		}//end foreach
	}

	/**
	 * Companies from main accounts and their sub-accounts (two levels become one company).
	 *
	 * @param int          $offset Items handled before.
	 * @param int          $limit  Page size.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_companies( int $offset, int $limit, ImportWriter $writer ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only, another plugin's data.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT t.user_id, p.meta_value AS parent FROM {$wpdb->usermeta} t JOIN {$wpdb->usermeta} p ON p.user_id = t.user_id AND p.meta_key = %s WHERE t.meta_key = %s AND t.meta_value = %s ORDER BY t.user_id ASC", 'b2bking_account_parent', 'b2bking_account_type', 'subaccount' ), ARRAY_A );
		$parent = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( (int) $row['parent'] > 0 && (int) $row['parent'] !== (int) $row['user_id'] ) {
				$parent[ (int) $row['user_id'] ] = (int) $row['parent'];
			}
		}
		$members = array();
		foreach ( array_keys( $parent ) as $user_id ) {
			$top = $user_id;
			for ( $i = 0; $i < 5 && isset( $parent[ $top ] ); $i++ ) {
				$top = $parent[ $top ];
			}
			if ( $top !== $user_id && ! isset( $parent[ $top ] ) ) {
				$members[ $top ][] = $user_id;
			}
		}
		ksort( $members );
		$page = array_slice( $members, $offset, $limit, true );
		foreach ( $page as $owner => $list ) {
			$user = get_userdata( $owner );
			if ( ! $user ) {
				/* translators: %d: user id */
				$writer->skip( sprintf( __( 'Main account #%d does not exist; its sub-accounts were not linked.', 'eurob2b' ), $owner ) );
				continue;
			}
			$name     = trim( (string) get_user_meta( $owner, 'billing_company', true ) );
			$name     = '' !== $name ? $name : ( '' !== $user->display_name ? $user->display_name : $user->user_login );
			$group    = (string) get_user_meta( $owner, 'b2bking_customergroup', true );
			$group_id = 1 === preg_match( '/^\d+$/', $group ) ? self::group_of( $writer, (int) $group ) : null;
			$existing = array_values( array_filter( $list, static fn( int $id ): bool => false !== get_userdata( $id ) ) );
			$writer->company( 'company:' . $owner, mb_substr( $name, 0, 190 ), $owner, $group_id, $existing );
		}
		return count( $page );
	}

	/**
	 * Prices, tiers and quantity limits of products and variations.
	 *
	 * @param int          $offset Items handled before.
	 * @param int          $limit  Page size.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_prices( int $offset, int $limit, ImportWriter $writer ): int {
		$ids        = $this->priced_products( $offset, $limit );
		$quantities = null !== $this->quantities && $this->quantities_in_use();
		$percent    = '1' === (string) get_option( 'b2bking_enter_percentage_tiered_setting', '0' );
		foreach ( $ids as $id ) {
			$meta    = $this->post_meta( $id );
			$regular = B2BKingFormat::decimal( (string) get_post_meta( $id, '_regular_price', true ) );
			$limits  = array();
			foreach ( $meta as $key => $value ) {
				if ( '' === trim( $value ) ) {
					continue;
				}
				if ( 1 === preg_match( '/^b2bking_regular_product_price_group_(\d+)$/', $key, $m ) ) {
					$this->import_group_price( $id, (int) $m[1], $value, (string) ( $meta[ 'b2bking_sale_product_price_group_' . $m[1] ] ?? '' ), $regular, $writer );
				} elseif ( null !== $this->prices && 1 === preg_match( '/^b2bking_(regular|sale)_price_user_(.+)$/', $key, $m ) ) {
					$this->import_customer_price( $this->prices, $id, $m[1], $m[2], $value, $meta, $writer );
				} elseif ( null !== $this->prices && 1 === preg_match( '/^b2bking_product_pricetiers_(group|user)_(.+)$/', $key, $m ) ) {
					$this->import_tiers( $this->prices, $id, $m[1], $m[2], $value, $percent, $writer );
				} elseif ( $quantities && 1 === preg_match( '/^b2bking_quantity_product_(min|max|step)_(\d+|b2c)$/', $key, $m ) ) {
					$limits[ $m[2] ][ $m[1] ] = (int) $value;
				}
			}
			foreach ( $limits as $audience => $values ) {
				$group = 'b2c' === $audience ? null : self::group_of( $writer, (int) $audience );
				if ( 'b2c' !== $audience && null === $group ) {
					continue;
				}
				$value = null === $this->quantities ? null : $this->quantities->value( ( $values['min'] ?? 0 ) >= 1 ? $values['min'] : null, ( $values['max'] ?? 0 ) >= 1 ? $values['max'] : null, ( $values['step'] ?? 0 ) >= 2 ? $values['step'] : null );
				if ( null !== $this->quantities && null !== $value ) {
					$writer->meta( 'post', $id, $this->quantities->meta_key( $group ), $value );
				}
			}
			if ( null !== $this->prices && 'no' === ( $meta['b2bking_show_pricing_table'] ?? '' ) ) {
				list( $table_key, $table_off ) = $this->prices->hidden_table();
				$writer->meta( 'post', $id, $table_key, $table_off );
			}
		}//end foreach
		return count( $ids );
	}

	/**
	 * Regular and sale price of one group on one product or variation.
	 *
	 * @param int          $id         Product or variation.
	 * @param int          $b2bking_id B2BKing group id.
	 * @param string       $raw        Regular price as stored.
	 * @param string       $raw_sale   Sale price as stored.
	 * @param string       $base       WooCommerce regular price (for the plausibility check).
	 * @param ImportWriter $writer     Writer.
	 */
	private function import_group_price( int $id, int $b2bking_id, string $raw, string $raw_sale, string $base, ImportWriter $writer ): void {
		$group = self::group_of( $writer, $b2bking_id );
		$price = B2BKingFormat::decimal( $raw );
		if ( null === $group || '' === $price ) {
			if ( '' === $price ) {
				/* translators: 1: product id, 2: stored value */
				$writer->skip( sprintf( __( 'Product #%1$d: the group price "%2$s" is not a number.', 'eurob2b' ), $id, $raw ) );
			}
			return;
		}
		if ( '' !== $base && Decimal::compare( $price, $base ) > 0 ) {
			/* translators: 1: product id, 2: group price, 3: regular price */
			$writer->note( Run::LEVEL_WARNING, sprintf( __( 'Product #%1$d: the group price %2$s is above the regular price %3$s. Please check it.', 'eurob2b' ), $id, $price, $base ) );
		}
		$writer->meta( 'post', $id, GroupPrices::regular_key( $group ), $price );
		$sale = B2BKingFormat::decimal( $raw_sale );
		if ( '' === $sale ) {
			return;
		}
		if ( Decimal::compare( $sale, $price ) >= 0 ) {
			/* translators: 1: product id, 2: sale price */
			$writer->skip( sprintf( __( 'Product #%1$d: the group sale price %2$s is not below the group price and was not imported.', 'eurob2b' ), $id, $sale ) );
			return;
		}
		$writer->meta( 'post', $id, GroupPrices::sale_key( $group ), $sale );
	}

	/**
	 * A customer's own price on one product or variation (older B2BKing versions keyed it by login).
	 *
	 * @param PriceTarget           $target Customer prices (EuroB2B Pro).
	 * @param int                   $id     Product or variation.
	 * @param string                $kind   regular or sale.
	 * @param string                $who    User id or login.
	 * @param string                $raw    Stored value.
	 * @param array<string, string> $meta   The product's B2BKing meta.
	 * @param ImportWriter          $writer Writer.
	 */
	private function import_customer_price( PriceTarget $target, int $id, string $kind, string $who, string $raw, array $meta, ImportWriter $writer ): void {
		$user_id = self::user_id( $who );
		$price   = B2BKingFormat::decimal( $raw );
		if ( $user_id <= 0 || '' === $price ) {
			return;
		}
		if ( 'sale' === $kind ) {
			$regular = B2BKingFormat::decimal( (string) ( $meta[ 'b2bking_regular_price_user_' . $who ] ?? '' ) );
			if ( '' === $regular || Decimal::compare( $price, $regular ) >= 0 ) {
				return;
			}
		}
		$writer->meta( 'post', $id, $target->customer_price_key( $user_id, 'sale' === $kind ), $price );
	}

	/**
	 * A tier set: stored on the product, or as a tiered percentage rule when B2BKing reads the
	 * amounts as percentages.
	 *
	 * @param PriceTarget  $target  Tier sets (EuroB2B Pro).
	 * @param int          $id      Product or variation.
	 * @param string       $kind    group or user.
	 * @param string       $who     Group id, `b2c`, user id or login.
	 * @param string       $raw     Stored tiers.
	 * @param bool         $percent Amounts are discounts in percent.
	 * @param ImportWriter $writer  Writer.
	 */
	private function import_tiers( PriceTarget $target, int $id, string $kind, string $who, string $raw, bool $percent, ImportWriter $writer ): void {
		$rows = B2BKingFormat::tiers( $raw );
		if ( empty( $rows ) ) {
			return;
		}
		$tiers = $target->tiers_value( $rows );
		if ( 'user' === $kind ) {
			$user_id = self::user_id( $who );
			if ( $user_id <= 0 ) {
				/* translators: 1: product id, 2: user id or login */
				$writer->skip( sprintf( __( 'Product #%1$d: the tier set of customer "%2$s" belongs to no existing account and was not imported.', 'eurob2b' ), $id, $who ) );
				return;
			}
			$audience = array( 'audience_users' => array( $user_id ) );
			$key      = $target->customer_tiers_key( $user_id );
			$who      = 'user:' . $user_id;
		} else {
			$group = 'b2c' === $who ? null : self::group_of( $writer, (int) $who );
			if ( 'b2c' !== $who && null === $group ) {
				return;
			}
			$audience = null === $group ? array( 'audience_tokens' => array( 'b2c', 'guest' ) ) : array( 'audience_groups' => array( $group ) );
			$key      = $target->tiers_key( $group );
		}
		if ( ! $percent ) {
			$writer->meta( 'post', $id, $key, $tiers );
			return;
		}
		// Percentages cannot be stored as a tier set (it holds prices): a percentage rule per set.
		$form = array_merge(
			array(
				/* translators: %d: product id */
				'name'               => sprintf( __( 'B2BKing tier discounts of product %d', 'eurob2b' ), $id ),
				'type'               => 'tiered_price',
				'enabled'            => '1',
				'priority'           => 10,
				'scope_kind'         => 'product_variation' === get_post_type( $id ) ? 'variations' : 'products',
				'scope_products'     => array( $id ),
				'action_tier_mode'   => 'percent',
				'action_tier_min'    => array_column( $rows, 'min' ),
				'action_tier_amount' => array_column( $rows, 'price' ),
			),
			$audience
		);
		$writer->rule( 'tiers:' . $id . ':' . $who, $form );
	}

	/**
	 * Payment and shipping methods: first the lists per group, private customers and guests, then
	 * the customers with their own lists.
	 *
	 * @param int          $offset Items handled before.
	 * @param int          $limit  Page size.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_methods( int $offset, int $limit, ImportWriter $writer ): int {
		global $wpdb;
		$gateways = self::gateways();
		$rates    = self::rates();
		$payment  = '1' !== (string) get_option( 'b2bking_disable_shipping_payment_control_setting', '0' ) && '1' !== (string) get_option( 'b2bking_disable_payment_control_setting', '0' );
		$shipping = '1' !== (string) get_option( 'b2bking_disable_shipping_payment_control_setting', '0' ) && '1' !== (string) get_option( 'b2bking_disable_shipping_control_setting', '0' );
		if ( 0 === $offset ) {
			$lists = array(
				MethodPolicy::KIND_PAYMENT  => array(),
				MethodPolicy::KIND_SHIPPING => array(),
			);
			foreach ( $this->posts( 'b2bking_group', 0, 1000 ) as $post ) {
				$group = self::group_of( $writer, $post['id'] );
				if ( null === $group ) {
					continue;
				}
				$meta = $this->post_meta( $post['id'] );
				$lists[ MethodPolicy::KIND_PAYMENT ][ 'group:' . $group ]  = self::allowed_payment( $gateways, static fn( string $gw ): ?string => $meta[ 'b2bking_group_payment_method_' . $gw ] ?? null );
				$lists[ MethodPolicy::KIND_SHIPPING ][ 'group:' . $group ] = self::allowed_shipping( $rates, static fn( string $key ): ?string => $meta[ 'b2bking_group_shipping_method_' . $key ] ?? null );
			}
			foreach ( array(
				'b2c'   => 'b2bking_b2c_users_',
				'guest' => 'b2bking_logged_out_users_',
			) as $audience => $prefix ) {
				$lists[ MethodPolicy::KIND_PAYMENT ][ $audience ]  = self::allowed_payment( $gateways, static fn( string $gw ): ?string => self::option( $prefix . 'payment_method_' . $gw ) );
				$lists[ MethodPolicy::KIND_SHIPPING ][ $audience ] = self::allowed_shipping( $rates, static fn( string $key ): ?string => self::option( $prefix . 'shipping_method_' . $key ) );
			}
			foreach ( array(
				MethodPolicy::KIND_PAYMENT  => array( $payment, $gateways ),
				MethodPolicy::KIND_SHIPPING => array( $shipping, $rates ),
			) as $kind => $setup ) {
				// Only audiences B2BKing actually restricts get a list; the others stay unrestricted.
				$restricted = array_filter( $lists[ $kind ], static fn( array $allowed ): bool => count( $allowed ) < count( $setup[1] ) );
				if ( $setup[0] && ! empty( $restricted ) ) {
					$writer->policy( $kind, $restricted, $setup[1] );
				}
			}
			return 1;
		}//end if
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only paging over another plugin's data.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s ORDER BY user_id ASC LIMIT %d OFFSET %d", 'b2bking_user_shipping_payment_methods_override', 'manual', $limit, $offset - 1 ) );
		foreach ( $ids as $id ) {
			$user_id = (int) $id;
			$meta    = $this->user_meta( $user_id );
			// EuroB2B's list per customer allows only what it names, B2BKing allows every method without
			// a stored value; so a list is written only when B2BKing blocks one of today's methods.
			$allowed = self::allowed_payment( $gateways, static fn( string $gw ): ?string => $meta[ 'b2bking_user_payment_method_' . $gw ] ?? null );
			if ( $payment && count( $allowed ) < count( $gateways ) ) {
				$writer->meta( 'user', $user_id, MethodPolicy::META_PAYMENT, (string) wp_json_encode( $allowed ) );
			}
			$allowed = self::allowed_shipping( $rates, static fn( string $key ): ?string => $meta[ 'b2bking_user_shipping_method_' . $key ] ?? null );
			if ( $shipping && count( $allowed ) < count( $rates ) ) {
				$writer->meta( 'user', $user_id, MethodPolicy::META_SHIPPING, (string) wp_json_encode( $allowed ) );
			}
		}
		return count( $ids );
	}

	/**
	 * Rules.
	 *
	 * @param int          $offset Items handled before.
	 * @param int          $limit  Page size.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_rules( int $offset, int $limit, ImportWriter $writer ): int {
		$posts = $this->posts( 'b2bking_rule', $offset, $limit );
		foreach ( $posts as $post ) {
			$this->rules->import( $post, $this->post_meta( $post['id'] ), $writer );
		}
		return count( $posts );
	}

	/**
	 * Visibility of products (own setting in B2BKing), then of categories; only when B2BKing hides
	 * anything at all.
	 *
	 * @param int          $offset Items handled before.
	 * @param int          $limit  Page size.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_visibility( int $offset, int $limit, ImportWriter $writer ): int {
		global $wpdb;
		if ( ! $this->visibility_in_use() ) {
			if ( 0 === $offset ) {
				$writer->note( Run::LEVEL_INFO, __( 'B2BKing shows every product to everyone; there is no visibility to import.', 'eurob2b' ) );
			}
			return 0;
		}
		$target = $this->visibility;
		if ( null === $target ) {
			// Without EuroB2B Pro there is no visibility to write; notes() said so before the import.
			return 0;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only paging over another plugin's data.
		$products = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", 'b2bking_product_visibility_override', 'manual' ) );
		if ( $offset < $products ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC LIMIT %d OFFSET %d", 'b2bking_product_visibility_override', 'manual', $limit, $offset ) );
			foreach ( $ids as $id ) {
				$tokens = $this->visible_to( $this->post_meta( (int) $id ), $writer );
				if ( null !== $tokens ) {
					$writer->meta( 'post', (int) $id, $target->meta_key(), $target->audiences_value( $tokens ) );
				}
			}
			return count( $ids );
		}
		$terms = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.term_id FROM {$wpdb->termmeta} m JOIN {$wpdb->term_taxonomy} t ON t.term_id = m.term_id AND t.taxonomy = %s WHERE m.meta_key LIKE %s ORDER BY m.term_id ASC LIMIT %d OFFSET %d", 'product_cat', $wpdb->esc_like( 'b2bking_group_' ) . '%', $limit, $offset - $products ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $terms as $term_id ) {
			$meta = array();
			foreach ( get_term_meta( (int) $term_id ) as $key => $values ) {
				$meta[ (string) $key ] = is_array( $values ) ? (string) reset( $values ) : '';
			}
			$tokens = $this->visible_to( $meta, $writer );
			if ( null !== $tokens ) {
				$writer->meta( 'term', (int) $term_id, $target->meta_key(), $target->audiences_value( $tokens ) );
			}
		}
		return count( $terms );
	}

	/**
	 * Audiences that may see a product or category, or null when B2BKing hides it from nobody.
	 *
	 * @param array<string, string> $meta   B2BKing meta of the product or term.
	 * @param ImportWriter          $writer Writer.
	 * @return string[]|null
	 */
	private function visible_to( array $meta, ImportWriter $writer ): ?array {
		$tokens = array();
		$hidden = false;
		foreach ( $meta as $key => $value ) {
			if ( 1 !== preg_match( '/^b2bking_group_(\d+|b2c)$/', $key, $m ) ) {
				continue;
			}
			$visible = '1' === $value;
			$hidden  = $hidden || ! $visible;
			if ( ! $visible ) {
				continue;
			}
			if ( 'b2c' === $m[1] ) {
				$tokens[] = 'b2c';
			} elseif ( '0' === $m[1] ) {
				$tokens[] = 'guest';
			} else {
				$group = self::group_of( $writer, (int) $m[1] );
				if ( null !== $group ) {
					$tokens[] = 'group:' . $group;
				}
			}
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $meta['b2bking_category_users_textarea'] ?? '' ) ) ) ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				$tokens[] = 'user:' . $user->ID;
			}
		}
		return $hidden ? array_values( array_unique( $tokens ) ) : null;
	}

	/**
	 * Whether B2BKing hides products from anyone.
	 */
	private function visibility_in_use(): bool {
		return '0' === (string) get_option( 'b2bking_all_products_visible_all_users_setting', '1' );
	}

	/**
	 * Whether B2BKing applies its quantity limits per product.
	 */
	private function quantities_in_use(): bool {
		return '1' !== (string) get_option( 'b2bking_disable_product_level_minmaxstep_setting', '1' );
	}

	/**
	 * Whether a product or variation has a non-empty B2BKing value under one of the key prefixes.
	 *
	 * @param string[] $prefixes Meta key prefixes.
	 */
	private function has_product_meta( array $prefixes ): bool {
		global $wpdb;
		foreach ( $prefixes as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only check of another plugin's data.
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT m.meta_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ('product', 'product_variation') AND m.meta_key LIKE %s AND m.meta_value <> '' LIMIT 1", $wpdb->esc_like( $prefix ) . '%' ) );
			if ( null !== $found ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * EuroB2B group of a registration role (group_<id> of automatic approval), or null.
	 *
	 * @param ImportWriter          $writer Writer.
	 * @param array<string, string> $meta   Role meta.
	 */
	private function role_group( ImportWriter $writer, array $meta ): ?int {
		$raw = (string) ( $meta['b2bking_custom_role_automatic_approval_group'] ?? '' );
		return 1 === preg_match( '/^group_(\d+)$/', $raw, $m ) ? self::group_of( $writer, (int) $m[1] ) : null;
	}

	/**
	 * Customer type of a registration role: business when it assigns a group or needs approval.
	 *
	 * @param int $role_id Role post id.
	 */
	private function role_type( int $role_id ): CustomerType {
		if ( ! isset( $this->role_types[ $role_id ] ) ) {
			$meta                         = $this->post_meta( $role_id );
			$group                        = (string) ( $meta['b2bking_custom_role_automatic_approval_group'] ?? '' );
			$business                     = str_starts_with( $group, 'group_' ) || 'manual' === ( $meta['b2bking_custom_role_approval'] ?? '' );
			$this->role_types[ $role_id ] = $business ? CustomerType::B2B : CustomerType::B2C;
		}
		return $this->role_types[ $role_id ];
	}

	/**
	 * EuroB2B type of a B2BKing field.
	 *
	 * @param int $field_id Field post id.
	 */
	private function field_type( int $field_id ): string {
		if ( ! isset( $this->field_types[ $field_id ] ) ) {
			$type                           = (string) get_post_meta( $field_id, 'b2bking_custom_field_field_type', true );
			$this->field_types[ $field_id ] = in_array( $type, self::FIELD_TYPES, true ) ? $type : 'text';
		}
		return $this->field_types[ $field_id ];
	}

	/**
	 * Allowed gateways.
	 *
	 * @param string[]                 $gateways Gateway ids.
	 * @param callable(string):?string $value    Stored value for a gateway.
	 * @return string[]
	 */
	private static function allowed_payment( array $gateways, callable $value ): array {
		return array_values( array_filter( $gateways, static fn( string $gw ): bool => B2BKingFormat::allowed( $value( $gw ) ) ) );
	}

	/**
	 * Allowed shipping rates (B2BKing writes `method_id` + `instance_id` without a separator, older
	 * versions only the method id).
	 *
	 * @param string[]                 $rates Rate keys (method_id:instance_id).
	 * @param callable(string):?string $value Stored value for a B2BKing key.
	 * @return string[]
	 */
	private static function allowed_shipping( array $rates, callable $value ): array {
		$out = array();
		foreach ( $rates as $rate ) {
			$stored = $value( str_replace( ':', '', $rate ) ) ?? $value( explode( ':', $rate )[0] );
			if ( B2BKingFormat::allowed( $stored ) ) {
				$out[] = $rate;
			}
		}
		return $out;
	}

	/**
	 * Ids of the shop's payment gateways.
	 *
	 * @return string[]
	 */
	private static function gateways(): array {
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return array();
		}
		return array_values( array_map( 'strval', array_keys( WC()->payment_gateways()->payment_gateways() ) ) );
	}

	/**
	 * Rate keys of every shipping method in a zone (method_id:instance_id).
	 *
	 * @return string[]
	 */
	private static function rates(): array {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}
		$zones   = \WC_Shipping_Zones::get_zones();
		$zones[] = array( 'zone_id' => 0 );
		$out     = array();
		foreach ( $zones as $zone ) {
			foreach ( \WC_Shipping_Zones::get_zone( (int) $zone['zone_id'] )->get_shipping_methods() as $method ) {
				$out[] = $method->id . ':' . (int) $method->instance_id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * An option's value, null when it does not exist.
	 *
	 * @param string $name Option name.
	 */
	private static function option( string $name ): ?string {
		$value = get_option( $name, null );
		return null === $value ? null : (string) $value;
	}

	/**
	 * User id from B2BKing's key suffix: an id, or a login in older versions.
	 *
	 * @param string $who Suffix.
	 */
	private static function user_id( string $who ): int {
		if ( 1 === preg_match( '/^\d+$/', $who ) ) {
			return get_userdata( (int) $who ) ? (int) $who : 0;
		}
		$user = get_user_by( 'login', $who );
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Posts of a B2BKing post type, ordered by id (or menu order).
	 *
	 * @param string $type     Post type.
	 * @param int    $offset   Offset.
	 * @param int    $limit    Limit.
	 * @param bool   $by_order Order by menu order first.
	 * @return array<int, array{id: int, title: string, status: string, order: int}>
	 */
	private function posts( string $type, int $offset, int $limit, bool $by_order = false ): array {
		global $wpdb;
		$order = $by_order ? 'menu_order ASC, ID ASC' : 'ID ASC';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders filled from one array; read-only paging over another plugin's posts; $order is one of two literals.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status, menu_order FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN (%s, %s, %s, %s, %s) ORDER BY {$order} LIMIT %d OFFSET %d",
				array_merge( array( $type ), self::STATUSES, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'     => (int) $row['ID'],
				'title'  => (string) $row['post_title'],
				'status' => (string) $row['post_status'],
				'order'  => (int) $row['menu_order'],
			);
		}
		return $out;
	}

	/**
	 * Number of posts of a type.
	 *
	 * @param string $type Post type.
	 */
	private function count_posts( string $type ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- read-only count; placeholders filled from one array.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN (%s, %s, %s, %s, %s)", array_merge( array( $type ), self::STATUSES ) ) );
	}

	/**
	 * Number of registration roles that are only the "please choose" entry of B2BKing's form.
	 */
	private function count_placeholder_roles(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only count.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = %s", 'b2bking_non_selectable', '1', 'b2bking_custom_role' ) );
	}

	/**
	 * Number of users with a meta key (and value).
	 *
	 * @param string      $key   Meta key.
	 * @param string|null $value Value or null for any.
	 */
	private function count_users( string $key, ?string $value = null ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only count.
		return null === $value
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s", $key ) )
			: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", $key, $value ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Number of users with B2BKing classification data.
	 */
	private function count_customers(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only count.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s, %s, %s)", 'b2bking_customergroup', 'b2bking_b2buser', 'b2bking_account_approved', 'b2bking_registration_role' ) );
	}

	/**
	 * WHERE part matching B2BKing's price keys, with its arguments.
	 *
	 * @return array{0: string, 1: string[]}
	 */
	private static function price_keys_sql(): array {
		global $wpdb;
		$likes = array();
		$args  = array();
		foreach ( self::PRICE_PREFIXES as $prefix ) {
			$likes[] = 'm.meta_key LIKE %s';
			$args[]  = $wpdb->esc_like( $prefix ) . '%';
		}
		return array( '(' . implode( ' OR ', $likes ) . ')', $args );
	}

	/**
	 * Number of products and variations with B2BKing prices, tiers or limits.
	 */
	private function count_priced_products(): int {
		global $wpdb;
		list( $where, $args ) = self::price_keys_sql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only count; $where is built from placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ('product', 'product_variation') AND m.meta_value <> '' AND {$where}", $args ) );
	}

	/**
	 * A page of products and variations with B2BKing prices, tiers or limits.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int[]
	 */
	private function priced_products( int $offset, int $limit ): array {
		global $wpdb;
		list( $where, $args ) = self::price_keys_sql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- read-only paging; $where is built from placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ('product', 'product_variation') AND m.meta_value <> '' AND {$where} ORDER BY m.post_id ASC LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * B2BKing meta of a post (first value per key).
	 *
	 * @param int $post_id Post id.
	 * @return array<string, string>
	 */
	private function post_meta( int $post_id ): array {
		$out = array();
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( str_starts_with( (string) $key, 'b2bking_' ) && is_array( $values ) ) {
				$out[ (string) $key ] = (string) reset( $values );
			}
		}
		return $out;
	}

	/**
	 * B2BKing meta of a user (first value per key).
	 *
	 * @param int $user_id User id.
	 * @return array<string, string>
	 */
	private function user_meta( int $user_id ): array {
		$out = array();
		foreach ( get_user_meta( $user_id ) as $key => $values ) {
			if ( str_starts_with( (string) $key, 'b2bking_' ) && is_array( $values ) ) {
				$out[ (string) $key ] = (string) reset( $values );
			}
		}
		return $out;
	}
}

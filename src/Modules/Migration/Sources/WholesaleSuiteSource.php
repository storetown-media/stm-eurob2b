<?php
/**
 * Import source: Wholesale Suite (Wholesale Prices).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration\Sources;

use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Migration\ImportWriter;
use EuroB2B\Modules\Migration\Run;
use EuroB2B\Modules\Migration\SourceInterface;
use EuroB2B\Modules\Pricing\GroupPrices;
use EuroB2B\Support\Decimal;

/**
 * Reads the data of Wholesale Suite's Wholesale Prices plugin (MIG-003) as version 2.3.0 stores it:
 * the wholesale roles (an option, serialised twice), the customers' WordPress roles, the wholesale
 * prices of products and variations and the general discount per role. Only reads: the plugin's
 * options, roles and meta stay as they are, and it may already be deactivated. Data that only the
 * paid Premium add-ons store (category discounts, quantity tiers and limits, visibility, payment
 * and shipping methods per role, registration) is not imported: its storage is not documented in
 * the free plugin (docs/migration.md).
 */
final class WholesaleSuiteSource implements SourceInterface {

	public const PART_GROUPS    = 'groups';
	public const PART_CUSTOMERS = 'customers';
	public const PART_PRICES    = 'prices';
	public const PART_RULES     = 'rules';

	/**
	 * Option with the wholesale roles.
	 */
	private const OPTION_ROLES = 'wwp_options_registered_custom_roles';

	/**
	 * Option with the general discount per role (written by Premium).
	 */
	private const OPTION_DISCOUNTS = 'wwpp_option_wholesale_role_general_discount_mapping';

	/**
	 * Id.
	 */
	public function id(): string {
		return 'wholesale-suite';
	}

	/**
	 * Name.
	 */
	public function label(): string {
		return 'Wholesale Suite';
	}

	/**
	 * Whether Wholesale Suite data exists.
	 */
	public function detected(): bool {
		return ! empty( self::roles() );
	}

	/**
	 * Whether Wholesale Prices is loaded.
	 */
	public function active(): bool {
		return defined( 'WWP_PLUGIN_FILE' );
	}

	/**
	 * What was found.
	 *
	 * @return array<string, int>
	 */
	public function summary(): array {
		$roles = array_keys( self::roles() );
		return array(
			__( 'Wholesale roles', 'eurob2b' ) => count( $roles ),
			__( 'Customers with a wholesale role', 'eurob2b' ) => $this->count_customers( $roles ),
			__( 'Products and variations with wholesale prices', 'eurob2b' ) => $this->count_priced( $roles ),
		);
	}

	/**
	 * Parts in import order.
	 *
	 * @return array<string, string>
	 */
	public function parts(): array {
		return array(
			self::PART_GROUPS    => __( 'Wholesale roles as customer groups', 'eurob2b' ),
			self::PART_CUSTOMERS => __( 'Customers with a wholesale role', 'eurob2b' ),
			self::PART_PRICES    => __( 'Wholesale prices of products and variations', 'eurob2b' ),
			self::PART_RULES     => __( 'General discount per role', 'eurob2b' ),
		);
	}

	/**
	 * Notes before the first part.
	 *
	 * @return array<int, array{level: string, text: string}>
	 */
	public function notes(): array {
		$notes = array(
			array(
				'level' => Run::LEVEL_INFO,
				'text'  => __( 'Not imported: data that only the paid Wholesale Suite add-ons store (discounts per category, quantity tiers and minimum quantities, visibility, payment and shipping methods and tax per role, registration). Set them up in EuroB2B by hand.', 'eurob2b' ),
			),
			array(
				'level' => Run::LEVEL_INFO,
				'text'  => __( 'Settings are not imported; orders keep their wholesale marks. The customers keep their WordPress role.', 'eurob2b' ),
			),
		);
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only count of another plugin's data.
		$percentages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_value <> ''", '%' . $wpdb->esc_like( '_wholesale_percentage_discount' ) ) );
		if ( $percentages > 0 ) {
			$notes[] = array(
				'level' => Run::LEVEL_INFO,
				/* translators: %d: number of products and variations */
				'text'  => sprintf( __( 'Wholesale prices entered as a percentage (%d products and variations) are imported as the price Wholesale Suite stored; EuroB2B keeps it when the regular price changes.', 'eurob2b' ), $percentages ),
			);
		}
		if ( 'yes' === get_option( 'wwp_hide_price_add_to_cart', 'no' ) ) {
			$notes[] = array(
				'level' => Run::LEVEL_WARNING,
				'text'  => __( 'Wholesale Suite hides prices and the cart button from guests. EuroB2B does this with Settings → Guest access → hide prices; it is not switched on by the import.', 'eurob2b' ),
			);
		}
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
			case self::PART_CUSTOMERS:
				return $this->import_customers( $offset, $limit, $writer );
			case self::PART_PRICES:
				return $this->import_prices( $offset, $limit, $writer );
			case self::PART_RULES:
				return $this->import_rules( $offset, $writer );
		}
		return 0;
	}

	/**
	 * The wholesale roles: role key => name. The option is serialised twice by Wholesale Suite (a
	 * serialised string inside the option), sometimes more often; every layer is unwrapped.
	 *
	 * @return array<string, string>
	 */
	public static function roles(): array {
		$value  = get_option( self::OPTION_ROLES, array() );
		$layers = 0;
		while ( $layers < 5 && is_string( $value ) && is_serialized( $value ) ) {
			// Another plugin's data: no objects, only arrays and scalars.
			$value = unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the stored format of Wholesale Suite, without objects.
			++$layers;
		}
		$roles = array();
		foreach ( is_array( $value ) ? $value : array() as $key => $role ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$name          = is_array( $role ) && isset( $role['roleName'] ) && is_string( $role['roleName'] ) ? trim( wp_strip_all_tags( $role['roleName'] ) ) : '';
			$roles[ $key ] = '' !== $name ? $name : $key;
		}
		return $roles;
	}

	/**
	 * EuroB2B group of a wholesale role, or null when it was not imported.
	 *
	 * @param ImportWriter $writer Writer.
	 * @param string       $role   Role key.
	 */
	private static function group_of( ImportWriter $writer, string $role ): ?int {
		$target = $writer->target( 'group', 'role:' . $role );
		return null !== $target && 0 !== (int) $target ? (int) $target : null;
	}

	/**
	 * Roles as business customer groups (one page).
	 *
	 * @param int          $offset Items handled before.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_groups( int $offset, ImportWriter $writer ): int {
		if ( $offset > 0 ) {
			return 0;
		}
		$roles = self::roles();
		foreach ( $roles as $key => $name ) {
			$slug = substr( str_replace( '_', '-', $key ), 0, 64 );
			$slug = 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug ) ? $slug : 'wholesale-' . substr( md5( $key ), 0, 8 );
			$writer->group( 'role:' . $key, mb_substr( $name, 0, 190 ), $slug, substr( 'wws-' . $slug, 0, 64 ) );
		}
		return count( $roles );
	}

	/**
	 * Customers with a wholesale role: business customers of that group, approved (the free plugin
	 * has no approval).
	 *
	 * @param int          $offset Items handled before.
	 * @param int          $limit  Page size.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_customers( int $offset, int $limit, ImportWriter $writer ): int {
		global $wpdb;
		$roles = array_keys( self::roles() );
		if ( empty( $roles ) ) {
			return 0;
		}
		list( $where, $args ) = self::role_filter( $roles );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- read-only paging; $where is built from placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE {$where} ORDER BY user_id ASC LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) ) );
		foreach ( $ids as $id ) {
			$user = get_userdata( (int) $id );
			if ( ! $user ) {
				continue;
			}
			// The first wholesale role counts, as in Wholesale Suite.
			$role = '';
			foreach ( $roles as $key ) {
				if ( in_array( $key, (array) $user->roles, true ) || ! empty( $user->caps[ $key ] ) ) {
					$role = $key;
					break;
				}
			}
			if ( '' === $role ) {
				continue;
			}
			$writer->customer( $user->ID, CustomerType::B2B, CustomerStatus::APPROVED, self::group_of( $writer, $role ) );
		}
		return count( $ids );
	}

	/**
	 * Wholesale prices of simple products and variations. The stored price is the price Wholesale
	 * Suite charges, also when it was entered as a percentage of the regular price.
	 *
	 * @param int          $offset Items handled before.
	 * @param int          $limit  Page size.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_prices( int $offset, int $limit, ImportWriter $writer ): int {
		$roles = array_keys( self::roles() );
		$ids   = $this->priced( $roles, $offset, $limit );
		foreach ( $ids as $id ) {
			$regular = (string) get_post_meta( $id, '_regular_price', true );
			foreach ( $roles as $role ) {
				$raw = (string) get_post_meta( $id, $role . '_wholesale_price', true );
				if ( '' === trim( $raw ) ) {
					continue;
				}
				$group = self::group_of( $writer, $role );
				$price = SourceValues::decimal( $raw );
				if ( null === $group ) {
					continue;
				}
				if ( '' === $price ) {
					/* translators: 1: product id, 2: stored value */
					$writer->skip( sprintf( __( 'Product #%1$d: the wholesale price "%2$s" is not a number.', 'eurob2b' ), $id, $raw ) );
					continue;
				}
				$base = SourceValues::decimal( $regular );
				if ( '' !== $base && Decimal::compare( $price, $base ) > 0 ) {
					/* translators: 1: product id, 2: wholesale price, 3: regular price */
					$writer->note( Run::LEVEL_WARNING, sprintf( __( 'Product #%1$d: the wholesale price %2$s is above the regular price %3$s. Please check it (a decimal comma typed into a shop that uses a decimal point is stored as a much higher number).', 'eurob2b' ), $id, $price, $base ) );
				}
				$writer->meta( 'post', $id, GroupPrices::regular_key( $group ), $price );
				$sale = SourceValues::decimal( (string) get_post_meta( $id, $role . '_wholesale_sale_price', true ) );
				if ( '' !== $sale && Decimal::compare( $sale, $price ) < 0 ) {
					$writer->meta( 'post', $id, GroupPrices::sale_key( $group ), $sale );
				}
			}//end foreach
		}//end foreach
		return count( $ids );
	}

	/**
	 * The general discount per role as a discount rule, switched off: Wholesale Suite gives it only
	 * on products without a wholesale price, EuroB2B would also take it off the group prices.
	 *
	 * @param int          $offset Items handled before.
	 * @param ImportWriter $writer Writer.
	 */
	private function import_rules( int $offset, ImportWriter $writer ): int {
		if ( $offset > 0 ) {
			return 0;
		}
		$discounts = get_option( self::OPTION_DISCOUNTS, array() );
		$roles     = self::roles();
		$handled   = 0;
		foreach ( is_array( $discounts ) ? $discounts : array() as $role => $percent ) {
			$role    = sanitize_key( (string) $role );
			$percent = SourceValues::decimal( is_scalar( $percent ) ? (string) $percent : '' );
			$group   = self::group_of( $writer, $role );
			if ( ! isset( $roles[ $role ] ) || null === $group || '' === $percent || 0 === Decimal::compare( $percent, '0' ) ) {
				continue;
			}
			++$handled;
			/* translators: %s: name of the wholesale role */
			$name = sprintf( __( 'Wholesale Suite general discount: %s', 'eurob2b' ), $roles[ $role ] );
			/* translators: %s: rule name */
			$writer->note( Run::LEVEL_WARNING, sprintf( __( 'Rule "%s" is imported switched off: Wholesale Suite gives the general discount only on products without a wholesale price, EuroB2B would also take it off the group prices. Switch it on only if that is what you want, or limit it to categories.', 'eurob2b' ), $name ) );
			$writer->rule(
				'discount:' . $role,
				array(
					'name'            => $name,
					'type'            => 'discount',
					'enabled'         => '',
					'priority'        => 10,
					'audience_groups' => array( $group ),
					'scope_kind'      => 'all',
					'action_mode'     => 'percent',
					'action_amount'   => $percent,
				)
			);
		}//end foreach
		return $handled;
	}

	/**
	 * WHERE part matching the capabilities of users with one of the roles, with its arguments.
	 *
	 * @param string[] $roles Role keys.
	 * @return array{0: string, 1: string[]}
	 */
	private static function role_filter( array $roles ): array {
		global $wpdb;
		$likes = array();
		$args  = array( $wpdb->get_blog_prefix() . 'capabilities' );
		foreach ( $roles as $role ) {
			$likes[] = 'meta_value LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
		}
		return array( 'meta_key = %s AND (' . implode( ' OR ', $likes ) . ')', $args );
	}

	/**
	 * Number of users with a wholesale role.
	 *
	 * @param string[] $roles Role keys.
	 */
	private function count_customers( array $roles ): int {
		global $wpdb;
		if ( empty( $roles ) ) {
			return 0;
		}
		list( $where, $args ) = self::role_filter( $roles );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only count; $where is built from placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE {$where}", $args ) );
	}

	/**
	 * WHERE part matching the wholesale price keys of the roles, with its arguments.
	 *
	 * @param string[] $roles Role keys.
	 * @return array{0: string, 1: string[]}
	 */
	private static function price_filter( array $roles ): array {
		$keys = array();
		foreach ( $roles as $role ) {
			$keys[] = $role . '_wholesale_price';
		}
		return array( 'm.meta_key IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys );
	}

	/**
	 * Number of products and variations with a wholesale price.
	 *
	 * @param string[] $roles Role keys.
	 */
	private function count_priced( array $roles ): int {
		global $wpdb;
		if ( empty( $roles ) ) {
			return 0;
		}
		list( $where, $args ) = self::price_filter( $roles );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only count; $where is built from placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ('product', 'product_variation') AND m.meta_value <> '' AND {$where}", $args ) );
	}

	/**
	 * A page of products and variations with a wholesale price.
	 *
	 * @param string[] $roles  Role keys.
	 * @param int      $offset Offset.
	 * @param int      $limit  Limit.
	 * @return int[]
	 */
	private function priced( array $roles, int $offset, int $limit ): array {
		global $wpdb;
		if ( empty( $roles ) ) {
			return array();
		}
		list( $where, $args ) = self::price_filter( $roles );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- read-only paging; $where is built from placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ('product', 'product_variation') AND m.meta_value <> '' AND {$where} ORDER BY m.post_id ASC LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) ) );
		return array_map( 'intval', $ids );
	}
}

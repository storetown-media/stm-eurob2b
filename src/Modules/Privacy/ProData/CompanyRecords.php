<?php
/**
 * Stored company accounts, for the privacy handling.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use DateTimeImmutable;
use DateTimeZone;
use EuroB2B\Infrastructure\Schema;
use EuroB2B\Modules\Orders\OrderAttributes;
use WC_Order;

/**
 * The company accounts of the shared schema (tables `eurob2b_companies` and `eurob2b_company_members`,
 * migration 4) and their traces on users and orders (D-04, D-05, D-14), read and changed for the
 * privacy export and erasure, user deletion and WooCommerce's order anonymisation. EuroB2B Pro
 * writes the data (company accounts, order approval, acting as a member); this part of the core
 * handles it also while Pro is inactive or deleted (PLT-015). Pro uses the same keys: its classes
 * alias the constants and labels below.
 */
final class CompanyRecords {

	/**
	 * Tables (without the prefix).
	 */
	public const TABLE_COMPANIES = 'eurob2b_companies';
	public const TABLE_MEMBERS   = 'eurob2b_company_members';

	/**
	 * User meta: the classification a user had before joining a company (JSON), restored on leaving.
	 */
	public const META_PREVIOUS = '_eurob2b_pre_company';

	/**
	 * Order meta of the order approval: the deciding user and the decision note.
	 */
	public const META_APPROVAL_DECIDED_BY = '_eurob2b_approved_by';
	public const META_APPROVAL_NOTE       = '_eurob2b_approval_note';

	/**
	 * Order meta: the owner or administrator who placed the order while acting as the member (CMP-011).
	 */
	public const META_ACTED_BY = '_eurob2b_acted_by';

	/**
	 * Roles of a membership.
	 */
	public const ROLE_OWNER  = 'owner';
	public const ROLE_ADMIN  = 'admin';
	public const ROLE_MEMBER = 'member';

	/**
	 * Permission flags of a membership (CMP-005, CMP-007).
	 */
	public const PERMISSION_PLACE_ORDERS       = 'place_orders';
	public const PERMISSION_VIEW_ORDERS        = 'view_orders';
	public const PERMISSION_VIEW_OFFERS        = 'view_offers';
	public const PERMISSION_VIEW_CONVERSATIONS = 'view_conversations';
	public const PERMISSION_VIEW_LISTS         = 'view_lists';
	public const PERMISSION_MANAGE_MEMBERS     = 'manage_members';
	public const PERMISSION_APPROVE_ORDERS     = 'approve_orders';

	/**
	 * Every flag, in display order.
	 */
	public const PERMISSIONS = array(
		self::PERMISSION_PLACE_ORDERS,
		self::PERMISSION_VIEW_ORDERS,
		self::PERMISSION_VIEW_OFFERS,
		self::PERMISSION_VIEW_CONVERSATIONS,
		self::PERMISSION_VIEW_LISTS,
		self::PERMISSION_MANAGE_MEMBERS,
		self::PERMISSION_APPROVE_ORDERS,
	);

	/**
	 * Billing address keys of a company (the company line is the company name).
	 */
	public const BILLING_FIELDS = array( 'address_1', 'address_2', 'postcode', 'city', 'country', 'state' );

	/**
	 * Labels of the permission flags.
	 *
	 * @return array<string, string>
	 */
	public static function permission_labels(): array {
		return array(
			self::PERMISSION_PLACE_ORDERS       => __( 'Place orders', 'eurob2b' ),
			self::PERMISSION_VIEW_ORDERS        => __( 'View company orders', 'eurob2b' ),
			self::PERMISSION_VIEW_OFFERS        => __( 'View company offers', 'eurob2b' ),
			self::PERMISSION_VIEW_CONVERSATIONS => __( 'View company conversations', 'eurob2b' ),
			self::PERMISSION_VIEW_LISTS         => __( 'View company purchase lists', 'eurob2b' ),
			self::PERMISSION_MANAGE_MEMBERS     => __( 'Manage members', 'eurob2b' ),
			self::PERMISSION_APPROVE_ORDERS     => __( 'Approve orders', 'eurob2b' ),
		);
	}

	/**
	 * Labels of the roles.
	 *
	 * @return array<string, string>
	 */
	public static function role_labels(): array {
		return array(
			self::ROLE_OWNER  => __( 'Owner', 'eurob2b' ),
			self::ROLE_ADMIN  => __( 'Administrator', 'eurob2b' ),
			self::ROLE_MEMBER => __( 'Member', 'eurob2b' ),
		);
	}

	/**
	 * Known flags only, sorted, unique.
	 *
	 * @param mixed $permissions Raw list.
	 * @return string[]
	 */
	public static function normalise_permissions( $permissions ): array {
		if ( ! is_array( $permissions ) ) {
			return array();
		}
		$known = array_values( array_intersect( self::PERMISSIONS, array_map( static fn( $p ): string => sanitize_key( (string) $p ), $permissions ) ) );
		sort( $known );
		return $known;
	}

	/**
	 * The membership of a user, or null.
	 *
	 * @param int $user_id User id.
	 * @return array{id: int, company_id: int, user_id: int, role: string, permissions: string[], requires_approval: bool, created_by: int, created_at: string}|null
	 */
	public function membership( int $user_id ): ?array {
		global $wpdb;
		$table = Schema::table( self::TABLE_MEMBERS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$permissions = json_decode( (string) ( $row['permissions'] ?? '[]' ), true );
		return array(
			'id'                => (int) $row['id'],
			'company_id'        => (int) $row['company_id'],
			'user_id'           => (int) $row['user_id'],
			'role'              => (string) $row['role'],
			'permissions'       => self::normalise_permissions( is_array( $permissions ) ? $permissions : array() ),
			'requires_approval' => 1 === (int) $row['requires_approval'],
			'created_by'        => (int) $row['created_by'],
			'created_at'        => (string) $row['created_at'],
		);
	}

	/**
	 * The flags a membership holds (owners hold every flag).
	 *
	 * @param array{role: string, permissions: string[]} $membership Membership.
	 * @return string[]
	 */
	public static function effective_permissions( array $membership ): array {
		return self::ROLE_OWNER === $membership['role'] ? self::PERMISSIONS : self::normalise_permissions( $membership['permissions'] );
	}

	/**
	 * One company, or null.
	 *
	 * @param int $id Company id.
	 * @return array{id: int, name: string, owner_user_id: int, settings: array<string, mixed>}|null
	 */
	public function company( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( self::TABLE_COMPANIES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * All companies by name.
	 *
	 * @return array<int, array{id: int, name: string, owner_user_id: int, settings: array<string, mixed>}>
	 */
	public function companies(): array {
		global $wpdb;
		$table = Schema::table( self::TABLE_COMPANIES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC, id ASC", ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Billing address of a company: every key present, '' when unset.
	 *
	 * @param array{settings: array<string, mixed>} $company Company.
	 * @return array<string, string>
	 */
	public static function billing( array $company ): array {
		$stored = isset( $company['settings']['billing'] ) && is_array( $company['settings']['billing'] ) ? $company['settings']['billing'] : array();
		$out    = array();
		foreach ( self::BILLING_FIELDS as $key ) {
			$out[ $key ] = isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
		}
		return $out;
	}

	/**
	 * Whether a usable billing address is stored (country, street and city at least).
	 *
	 * @param array{settings: array<string, mixed>} $company Company.
	 */
	public static function has_billing_address( array $company ): bool {
		$billing = self::billing( $company );
		return '' !== $billing['country'] && '' !== $billing['address_1'] && '' !== $billing['city'];
	}

	/**
	 * Company e-mail address ('' when unset).
	 *
	 * @param array{settings: array<string, mixed>} $company Company.
	 */
	public static function email( array $company ): string {
		return isset( $company['settings']['email'] ) && is_scalar( $company['settings']['email'] ) ? (string) $company['settings']['email'] : '';
	}

	/**
	 * Whether the company e-mail receives a copy of the members' order e-mails.
	 *
	 * @param array{settings: array<string, mixed>} $company Company.
	 */
	public static function copies_order_emails( array $company ): bool {
		return ! empty( $company['settings']['email_copy'] ) && '' !== self::email( $company );
	}

	/**
	 * Removes the company e-mail address and switches the order e-mail copy off; the billing address stays.
	 *
	 * @param array{id: int, settings: array<string, mixed>} $company Company.
	 */
	public function clear_contact( array $company ): void {
		$settings               = $company['settings'];
		$settings['billing']    = self::billing( $company );
		$settings['email']      = '';
		$settings['email_copy'] = false;
		$this->update_company(
			$company['id'],
			array( 'settings' => (string) wp_json_encode( $settings ) ),
			array( '%s' )
		);
	}

	/**
	 * Removes the owner reference of a company.
	 *
	 * @param int $company_id Company id.
	 */
	public function clear_owner( int $company_id ): void {
		$this->update_company( $company_id, array( 'owner_user_id' => 0 ), array( '%d' ) );
	}

	/**
	 * Memberships a user created (their id sits in `created_by`).
	 *
	 * @param int $user_id User id.
	 */
	public function count_created_by( int $user_id ): int {
		global $wpdb;
		$table = Schema::table( self::TABLE_MEMBERS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_by = %d", $user_id ) );
	}

	/**
	 * Clears a user's id from `created_by` everywhere.
	 *
	 * @param int $user_id User id.
	 * @return int Rows changed.
	 */
	public function anonymise_creator( int $user_id ): int {
		global $wpdb;
		if ( $user_id < 1 ) {
			return 0;
		}
		$table = Schema::table( self::TABLE_MEMBERS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_by = 0 WHERE created_by = %d", $user_id ) );
	}

	/**
	 * Removes a user's membership.
	 *
	 * @param int $user_id User id.
	 */
	public function remove_membership( int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( self::TABLE_MEMBERS ), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * A deleted WordPress user (`deleted_user`): their `created_by` references are cleared, the stored
	 * previous classification goes, a company they owned loses the owner reference and their
	 * membership is removed.
	 *
	 * @param mixed $user_id User id.
	 */
	public function on_deleted_user( $user_id ): void {
		$user_id = (int) $user_id;
		$this->anonymise_creator( $user_id );
		delete_user_meta( $user_id, self::META_PREVIOUS );
		$membership = $this->membership( $user_id );
		if ( null === $membership ) {
			return;
		}
		$company = $this->company( $membership['company_id'] );
		if ( null !== $company && $company['owner_user_id'] === $user_id ) {
			$this->clear_owner( $company['id'] );
		}
		$this->remove_membership( $user_id );
	}

	/**
	 * WooCommerce order anonymisation (privacy tools, scheduled cleanup): the placing user, the user
	 * who acted as the member, and the deciding user and note of the approval go; the approval state
	 * and dates carry no personal data and stay.
	 *
	 * @param mixed $order Order.
	 */
	public static function anonymise_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$changed = false;
		if ( '' !== (string) $order->get_meta( self::META_APPROVAL_DECIDED_BY, true ) || '' !== (string) $order->get_meta( self::META_APPROVAL_NOTE, true ) ) {
			$order->delete_meta_data( self::META_APPROVAL_DECIDED_BY );
			$order->delete_meta_data( self::META_APPROVAL_NOTE );
			$changed = true;
		}
		foreach ( array( OrderAttributes::META_PLACED_BY, self::META_ACTED_BY ) as $key ) {
			if ( '' !== (string) $order->get_meta( $key, true ) ) {
				$order->delete_meta_data( $key );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Updates columns of a company and its change time.
	 *
	 * @param int                  $id      Company id.
	 * @param array<string, mixed> $data    Columns.
	 * @param string[]             $formats Formats of the columns.
	 */
	private function update_company( int $id, array $data, array $formats ): void {
		global $wpdb;
		$data['updated_at'] = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
		$formats[]          = '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( self::TABLE_COMPANIES ), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Row to array.
	 *
	 * @param array<string, mixed> $row Table row.
	 * @return array{id: int, name: string, owner_user_id: int, settings: array<string, mixed>}
	 */
	private static function hydrate( array $row ): array {
		$settings = isset( $row['settings'] ) && is_string( $row['settings'] ) && '' !== $row['settings'] ? json_decode( $row['settings'], true ) : array();
		return array(
			'id'            => (int) $row['id'],
			'name'          => (string) $row['name'],
			'owner_user_id' => (int) $row['owner_user_id'],
			'settings'      => is_array( $settings ) ? $settings : array(),
		);
	}
}

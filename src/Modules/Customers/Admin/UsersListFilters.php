<?php
/**
 * Users list filters and columns.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\GroupRepository;

/**
 * Adds customer type, status and group as filters and columns to Users → All users.
 */
final class UsersListFilters {

	/**
	 * Repository.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Constructor.
	 *
	 * @param GroupRepository $groups Repository.
	 */
	public function __construct( GroupRepository $groups ) {
		$this->groups = $groups;
	}

	/**
	 * Renders the filter dropdowns (restrict_manage_users).
	 *
	 * @param string $which "top" or "bottom".
	 */
	public function render( string $which ): void {
		if ( 'top' !== $which || ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$type   = isset( $_GET['eurob2b_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['eurob2b_type'] ) ) : '';
		$status = isset( $_GET['eurob2b_status'] ) ? sanitize_key( (string) wp_unslash( $_GET['eurob2b_status'] ) ) : '';
		$group  = isset( $_GET['eurob2b_group'] ) ? absint( $_GET['eurob2b_group'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<select name="eurob2b_type"><option value="">' . esc_html__( 'All customer types', 'eurob2b' ) . '</option>';
		foreach ( array( CustomerType::B2B, CustomerType::B2C ) as $option ) {
			echo '<option value="' . esc_attr( $option->value ) . '" ' . selected( $type, $option->value, false ) . '>' . esc_html( GroupsPage::type_label( $option ) ) . '</option>';
		}
		echo '</select> ';

		echo '<select name="eurob2b_status"><option value="">' . esc_html__( 'All statuses', 'eurob2b' ) . '</option>';
		foreach ( CustomerStatus::cases() as $option ) {
			echo '<option value="' . esc_attr( $option->value ) . '" ' . selected( $status, $option->value, false ) . '>' . esc_html( $option->label() ) . '</option>';
		}
		echo '</select> ';

		echo '<select name="eurob2b_group"><option value="0">' . esc_html__( 'All groups', 'eurob2b' ) . '</option>';
		foreach ( $this->groups->all() as $option ) {
			echo '<option value="' . esc_attr( (string) $option->id ) . '" ' . selected( $group, $option->id, false ) . '>' . esc_html( $option->name ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'eurob2b' ), '', 'eurob2b_filter', false );
	}

	/**
	 * Applies the filters (pre_get_users).
	 *
	 * @param \WP_User_Query $query Query.
	 */
	public function apply( \WP_User_Query $query ): void {
		if ( ! is_admin() || ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$type   = isset( $_GET['eurob2b_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['eurob2b_type'] ) ) : '';
		$status = isset( $_GET['eurob2b_status'] ) ? sanitize_key( (string) wp_unslash( $_GET['eurob2b_status'] ) ) : '';
		$group  = isset( $_GET['eurob2b_group'] ) ? absint( $_GET['eurob2b_group'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$entity      = $group > 0 ? $this->groups->find( $group ) : null;
		$default_for = null !== $entity && $entity->is_default ? $entity->customer_type->value : null;
		$meta_query  = self::meta_query_for( $type, $status, $group, $default_for );
		if ( empty( $meta_query ) ) {
			return;
		}
		$existing = $query->get( 'meta_query' );
		$existing = is_array( $existing ) ? $existing : array();
		// Another plugin's query keeps its own relation: both become nested clauses under AND.
		$combined = empty( $existing ) ? $meta_query : array(
			'relation' => 'AND',
			$existing,
			$meta_query,
		);
		$query->set( 'meta_query', $combined ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	/**
	 * Builds the meta query for the filters. Missing meta means B2C / approved (the same
	 * defaults AudienceResolver and CustomerStatus::from_meta() use), so those filters match
	 * plain WooCommerce customers too.
	 *
	 * A default group also matches users without a group meta whose (implicit) customer type is the
	 * group's type, because AudienceResolver places them in that default group.
	 *
	 * @param string      $type        Customer type value or "".
	 * @param string      $status      Status value or "".
	 * @param int         $group       Group id or 0.
	 * @param string|null $default_for Customer type value when $group is that type's default group.
	 * @return array<int|string, mixed> WP_Meta_Query clauses (empty when nothing is filtered).
	 */
	public static function meta_query_for( string $type, string $status, int $group, ?string $default_for = null ): array {
		$clauses = array();

		if ( null !== CustomerType::tryFrom( $type ) ) {
			$clauses[] = self::type_clause( $type );
		}

		if ( null !== CustomerStatus::tryFrom( $status ) ) {
			$clause = array(
				'key'   => CustomersModule::META_STATUS,
				'value' => $status,
			);
			if ( CustomerStatus::APPROVED->value === $status ) {
				$clause = array(
					'relation' => 'OR',
					$clause,
					array(
						'key'     => CustomersModule::META_STATUS,
						'compare' => 'NOT EXISTS',
					),
				);
			}
			$clauses[] = $clause;
		}

		if ( $group > 0 ) {
			$clause = array(
				'key'   => CustomersModule::META_GROUP_ID,
				'value' => (string) $group,
			);
			if ( null !== CustomerType::tryFrom( (string) $default_for ) ) {
				// Implicit members are customer accounts only (customer role or a stored type), the same
				// predicate GroupRepository::member_counts() uses; staff without meta is not a member.
				$clause = array(
					'relation' => 'OR',
					$clause,
					array(
						'relation' => 'AND',
						array(
							'key'     => CustomersModule::META_GROUP_ID,
							'compare' => 'NOT EXISTS',
						),
						self::type_clause( (string) $default_for ),
						self::customer_account_clause(),
					),
				);
			}
			$clauses[] = $clause;
		}//end if

		if ( empty( $clauses ) ) {
			return array();
		}
		$clauses['relation'] = 'AND';
		return $clauses;
	}

	/**
	 * Clause matching customer accounts: a stored customer type or the customer role (also used by
	 * the REST API's customer list).
	 *
	 * @return array<int|string, mixed>
	 */
	public static function customer_account_clause(): array {
		global $wpdb;
		return array(
			'relation' => 'OR',
			array(
				'key'     => CustomersModule::META_CUSTOMER_TYPE,
				'compare' => 'EXISTS',
			),
			array(
				'key'     => $wpdb->get_blog_prefix() . 'capabilities',
				'value'   => '"customer"',
				'compare' => 'LIKE',
			),
		);
	}

	/**
	 * Clause for a customer type; missing meta counts as B2C.
	 *
	 * @param string $type Customer type value.
	 * @return array<int|string, mixed>
	 */
	private static function type_clause( string $type ): array {
		$clause = array(
			'key'   => CustomersModule::META_CUSTOMER_TYPE,
			'value' => $type,
		);
		if ( CustomerType::B2C->value !== $type ) {
			return $clause;
		}
		return array(
			'relation' => 'OR',
			$clause,
			array(
				'key'     => CustomersModule::META_CUSTOMER_TYPE,
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Adds the column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		if ( current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			$columns['eurob2b'] = __( 'B2B', 'eurob2b' );
		}
		return $columns;
	}

	/**
	 * Column content.
	 *
	 * @param string $output      Existing output.
	 * @param string $column_name Column.
	 * @param int    $user_id     User id.
	 */
	public function column_value( string $output, string $column_name, int $user_id ): string {
		if ( 'eurob2b' !== $column_name ) {
			return $output;
		}
		$type     = get_user_meta( $user_id, CustomersModule::META_CUSTOMER_TYPE, true );
		$status   = CustomerStatus::from_meta( get_user_meta( $user_id, CustomersModule::META_STATUS, true ) );
		$group_id = (int) get_user_meta( $user_id, CustomersModule::META_GROUP_ID, true );
		$group    = $group_id > 0 ? $this->groups->find( $group_id ) : null;

		$parts = array();
		if ( CustomerType::B2B->value === $type ) {
			$parts[] = __( 'B2B', 'eurob2b' );
		}
		if ( CustomerStatus::APPROVED !== $status ) {
			$parts[] = $status->label();
		}
		if ( null !== $group ) {
			$parts[] = $group->name;
		}
		return esc_html( implode( ' · ', $parts ) );
	}
}

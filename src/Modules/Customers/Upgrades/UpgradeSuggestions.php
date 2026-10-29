<?php
/**
 * Group upgrade suggestions.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers\Upgrades;

use EuroB2B\Infrastructure\Schema;
use EuroB2B\Modules\Customers\Admin\UsersListFilters;
use EuroB2B\Modules\Customers\AudienceResolver;
use EuroB2B\Modules\Customers\CustomerService;
use EuroB2B\Modules\Customers\CustomersModule;
use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Support\Decimal;
use EuroB2B\Support\Money;
use InvalidArgumentException;
use WC_Order;

/**
 * GRP-008: which approved members of a group reached its upgrade threshold, and the two actions
 * staff take on a suggestion: move the customer, or keep the customer in the group for good
 * (a "manual lock" on the user, `_eurob2b_group_hold`). Nothing changes a group automatically.
 *
 * Net sales: order totals without tax and shipping, after refunds (without their tax), of paid
 * orders (WooCommerce's paid statuses) in the shop currency, by the order's creation date.
 *
 * Members of a company account are left out (counted, and named on the page): with company accounts
 * on, a company's group applies to all its members (CompanyAudience), so moving one member's own
 * group would change nothing they see. Their upgrade belongs on the company.
 */
final class UpgradeSuggestions {

	/**
	 * Orders per query.
	 */
	public const BATCH = 200;

	/**
	 * Customers per order query.
	 */
	public const CUSTOMERS_PER_QUERY = 500;

	/**
	 * How long a calculated list is kept (seconds).
	 */
	public const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Transient prefix (Schema::TRANSIENT_PREFIX, so the uninstaller removes it).
	 */
	public const CACHE_PREFIX = Schema::TRANSIENT_PREFIX . 'upgrade_';

	/**
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Customer service.
	 *
	 * @var CustomerService
	 */
	private CustomerService $customers;

	/**
	 * Audience resolver (tells company members apart).
	 *
	 * @var AudienceResolver
	 */
	private AudienceResolver $audiences;

	/**
	 * Constructor.
	 *
	 * @param GroupRepository  $groups    Groups.
	 * @param CustomerService  $customers Customer service.
	 * @param AudienceResolver $audiences Audience resolver.
	 */
	public function __construct( GroupRepository $groups, CustomerService $customers, AudienceResolver $audiences ) {
		$this->groups    = $groups;
		$this->customers = $customers;
		$this->audiences = $audiences;
	}

	/**
	 * Groups with a usable rule: the target exists and has the same customer type.
	 *
	 * @return array<int, array{source: Group, target: Group, rule: UpgradeRule}>
	 */
	public function rules(): array {
		$out = array();
		foreach ( $this->groups->all() as $group ) {
			$rule   = UpgradeRule::of( $group );
			$target = null !== $rule ? $this->groups->find( $rule->target_id ) : null;
			if ( null !== $rule && null !== $target && $target->customer_type === $group->customer_type ) {
				$out[] = array(
					'source' => $group,
					'target' => $target,
					'rule'   => $rule,
				);
			}
		}
		return $out;
	}

	/**
	 * Customers of a group who reached its threshold, largest net sales first, and how many company
	 * members of the group were left out.
	 *
	 * @param Group       $source Source group.
	 * @param UpgradeRule $rule   Its rule.
	 * @param bool        $fresh  Recalculate even when a cached list exists.
	 * @return array{generated_at: int, customers: array<int, array{user_id: int, net: string, orders: int, held: bool}>, company_members: int}
	 */
	public function candidates( Group $source, UpgradeRule $rule, bool $fresh = false ): array {
		$key = $this->cache_key( $source, $rule );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['generated_at'], $cached['customers'], $cached['company_members'] ) && is_array( $cached['customers'] ) ) {
				return $cached;
			}
		}
		list( $members, $company ) = $this->split( $source );
		list( $from, $to )         = $rule->range( current_datetime() );
		$spend                     = $this->spend( $members, null !== $from ? $from->format( 'Y-m-d' ) : '', null !== $to ? $to->format( 'Y-m-d' ) : '' );
		$threshold                 = self::amount( $rule->threshold, (string) get_woocommerce_currency(), max( 0, min( 4, (int) wc_get_price_decimals() ) ) );
		$customers                 = array();
		foreach ( $spend as $user_id => $row ) {
			if ( $row['net']->compare( $threshold ) >= 0 ) {
				$customers[] = array(
					'user_id' => $user_id,
					'net'     => $row['net']->to_decimal(),
					'orders'  => $row['orders'],
					'held'    => '' !== (string) get_user_meta( $user_id, CustomersModule::META_GROUP_HOLD, true ),
				);
			}
		}
		usort( $customers, static fn( array $a, array $b ): int => (float) $b['net'] <=> (float) $a['net'] );
		$result = array(
			'generated_at'    => time(),
			'customers'       => $customers,
			'company_members' => count( $company ),
		);
		set_transient( $key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Drops the cached list of a group (after a move or a hold).
	 *
	 * @param Group       $source Source group.
	 * @param UpgradeRule $rule   Its rule.
	 */
	public function forget( Group $source, UpgradeRule $rule ): void {
		delete_transient( $this->cache_key( $source, $rule ) );
	}

	/**
	 * Moves a suggested customer to the target group. The customer must still be an approved member
	 * of the source group.
	 *
	 * @param int   $user_id User id.
	 * @param Group $source  Source group.
	 * @param Group $target  Target group of the source's rule.
	 * @param int   $actor   Staff user id.
	 * @throws InvalidArgumentException When the customer is no longer in the source group.
	 */
	public function move( int $user_id, Group $source, Group $target, int $actor ): void {
		$this->assert_member( $user_id, $source );
		$this->customers->assign( $user_id, $source->customer_type, CustomerStatus::APPROVED, $target->id, $actor );
	}

	/**
	 * Refuses a customer who is not (any more) a suggestion of the group: not an approved member of it,
	 * or a member of a company account. Every action on a suggestion checks this first, because the
	 * page that posted it may be stale.
	 *
	 * @param int   $user_id User id.
	 * @param Group $source  Source group.
	 * @throws InvalidArgumentException When the customer is not a suggestion of the group.
	 */
	public function assert_member( int $user_id, Group $source ): void {
		if ( $this->in_company( $user_id ) ) {
			throw new InvalidArgumentException( __( 'The customer is a member of a company account; the company\'s group applies, so change it on the company.', 'eurob2b' ) );
		}
		if ( ! in_array( $user_id, $this->members( $source ), true ) ) {
			throw new InvalidArgumentException( __( 'The customer is no longer an approved member of this group.', 'eurob2b' ) );
		}
	}

	/**
	 * Keeps a customer in the group (no more suggestions) or lifts that again.
	 *
	 * @param int  $user_id User id.
	 * @param bool $hold    Keep.
	 */
	public static function hold( int $user_id, bool $hold ): void {
		if ( $hold ) {
			update_user_meta( $user_id, CustomersModule::META_GROUP_HOLD, gmdate( 'Y-m-d H:i:s' ) );
		} else {
			delete_user_meta( $user_id, CustomersModule::META_GROUP_HOLD );
		}
	}

	/**
	 * Approved members of a group, including the implicit members of a default group, without the
	 * members of company accounts.
	 *
	 * @param Group $source Group.
	 * @return int[]
	 */
	public function members( Group $source ): array {
		return $this->split( $source )[0];
	}

	/**
	 * Approved members of a group by their own group (user meta), split into customers on their own
	 * and members of a company account.
	 *
	 * @param Group $source Group.
	 * @return array{0: int[], 1: int[]} Own customers, company members.
	 */
	private function split( Group $source ): array {
		$ids = get_users(
			array(
				'fields'     => 'ID',
				'number'     => -1,
				'meta_query' => UsersListFilters::meta_query_for( '', CustomerStatus::APPROVED->value, $source->id, $source->is_default ? $source->customer_type->value : null ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );
		if ( ! empty( $ids ) ) {
			update_meta_cache( 'user', $ids );
		}
		$own     = array();
		$company = array();
		foreach ( $ids as $id ) {
			if ( $this->in_company( $id ) ) {
				$company[] = $id;
			} else {
				$own[] = $id;
			}
		}
		return array( $own, $company );
	}

	/**
	 * Whether the user's prices and group follow a company account (company accounts on, member of a
	 * company): the audience context then carries the company.
	 *
	 * @param int $user_id User id.
	 */
	private function in_company( int $user_id ): bool {
		return null !== $this->audiences->for_user( $user_id )->company_id;
	}

	/**
	 * An amount from WooCommerce: decimal strings (order totals, the stored threshold) are read exactly,
	 * rounded to the shop's decimals without a float; only real floats (refund totals) go through
	 * Money::of_float(). A float cast lost cents on large totals (Codex P1 on #80).
	 *
	 * @param mixed  $value    Amount.
	 * @param string $currency Currency.
	 * @param int    $scale    Decimals.
	 */
	public static function amount( $value, string $currency, int $scale ): Money {
		if ( is_string( $value ) && 1 === preg_match( '/^-?\d+(\.\d+)?$/', trim( $value ) ) ) {
			return Money::of_decimal( Decimal::round( trim( $value ), $scale ), $currency, $scale );
		}
		return Money::of_float( is_numeric( $value ) ? (float) $value : 0.0, $currency, $scale );
	}

	/**
	 * Net sales and order count per customer.
	 *
	 * @param int[]  $user_ids Customers.
	 * @param string $from     First day (Y-m-d) or "" for no limit.
	 * @param string $to       Last day (Y-m-d) or "" for no limit.
	 * @return array<int, array{net: Money, orders: int}>
	 */
	private function spend( array $user_ids, string $from, string $to ): array {
		$currency = (string) get_woocommerce_currency();
		$scale    = max( 0, min( 4, (int) wc_get_price_decimals() ) );
		$money    = static fn( $value ): Money => self::amount( $value, $currency, $scale );
		$statuses = array_map( static fn( $status ): string => 'wc-' . $status, wc_get_is_paid_statuses() );
		$out      = array();
		foreach ( array_chunk( $user_ids, self::CUSTOMERS_PER_QUERY ) as $chunk ) {
			$page = 1;
			do {
				$args = array(
					'type'     => 'shop_order',
					'customer' => $chunk,
					'status'   => $statuses,
					'limit'    => self::BATCH,
					'page'     => $page,
					'orderby'  => 'ID',
					'order'    => 'ASC',
					'return'   => 'objects',
				);
				if ( '' !== $from || '' !== $to ) {
					$args['date_created'] = '' === $to ? '>=' . $from : $from . '...' . $to;
				}
				$batch = wc_get_orders( $args );
				$batch = is_array( $batch ) ? $batch : array();
				foreach ( $batch as $order ) {
					if ( ! $order instanceof WC_Order || $order->get_currency() !== $currency || $order->get_customer_id() <= 0 ) {
						continue;
					}
					$net = $money( $order->get_total() )->subtract( $money( $order->get_total_tax() ) )->subtract( $money( $order->get_shipping_total() ) )
						->subtract( $money( $order->get_total_refunded() ) )->add( $money( $order->get_total_tax_refunded() ) );
					$id  = $order->get_customer_id();
					if ( ! isset( $out[ $id ] ) ) {
						$out[ $id ] = array(
							'net'    => Money::zero( $currency, $scale ),
							'orders' => 0,
						);
					}
					$out[ $id ]['net'] = $out[ $id ]['net']->add( $net );
					++$out[ $id ]['orders'];
				}
				$more = count( $batch ) === self::BATCH;
				++$page;
			} while ( $more );
		}//end foreach
		return $out;
	}

	/**
	 * Cache key of a group's list (changes with the rule, the day and the currency).
	 *
	 * @param Group       $source Source group.
	 * @param UpgradeRule $rule   Rule.
	 */
	private function cache_key( Group $source, UpgradeRule $rule ): string {
		return self::CACHE_PREFIX . md5( (string) wp_json_encode( array( 2, $source->id, $rule->target_id, $rule->threshold, $rule->period, current_datetime()->format( 'Y-m-d' ), get_woocommerce_currency() ) ) );
	}
}

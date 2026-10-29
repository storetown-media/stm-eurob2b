<?php
/**
 * Allow-lists for payment gateways and shipping methods per audience.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments;

use EuroB2B\Modules\Customers\AudienceContext;
use InvalidArgumentException;

/**
 * PAY-001…004, SHP-001/002. One policy per kind (payment, shipping), stored as an option with the
 * keys `default_new` ("allowed" | "blocked": what a method nobody has decided on yet gets), `known`
 * (method ids that were on the screen when the policy was saved), `audiences` (audience key →
 * allowed method ids; keys group:<id>, b2b, b2c, guest) and `non_zone` (shipping only: whether
 * methods outside zones are controlled too, SHP-002).
 *
 * An audience without an entry is unrestricted (switching the feature on hides nothing until
 * configured). A user override (user meta, JSON list) replaces the audience list for that user.
 */
final class MethodPolicy {

	public const KIND_PAYMENT  = 'payment';
	public const KIND_SHIPPING = 'shipping';

	public const OPTION_PAYMENT  = 'eurob2b_payment_policy';
	public const OPTION_SHIPPING = 'eurob2b_shipping_policy';
	public const META_PAYMENT    = '_eurob2b_payment_methods';
	public const META_SHIPPING   = '_eurob2b_shipping_methods';

	public const DEFAULT_ALLOWED = 'allowed';
	public const DEFAULT_BLOCKED = 'blocked';

	/**
	 * Per-request cache.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cache = array();

	/**
	 * Option name for a kind.
	 *
	 * @param string $kind Kind.
	 * @throws InvalidArgumentException Unknown kind.
	 */
	public static function option( string $kind ): string {
		if ( self::KIND_PAYMENT === $kind ) {
			return self::OPTION_PAYMENT;
		}
		if ( self::KIND_SHIPPING === $kind ) {
			return self::OPTION_SHIPPING;
		}
		throw new InvalidArgumentException( sprintf( 'Unknown method kind "%s".', $kind ) );
	}

	/**
	 * User meta key for a kind.
	 *
	 * @param string $kind Kind.
	 */
	public static function meta( string $kind ): string {
		return self::KIND_PAYMENT === $kind ? self::META_PAYMENT : self::META_SHIPPING;
	}

	/**
	 * The stored policy (normalised).
	 *
	 * @param string $kind Kind.
	 * @return array{default_new: string, known: string[], audiences: array<string, string[]>, non_zone: bool}
	 */
	public function load( string $kind ): array {
		if ( ! isset( $this->cache[ $kind ] ) ) {
			$raw                  = get_option( self::option( $kind ), array() );
			$this->cache[ $kind ] = self::normalise( is_array( $raw ) ? $raw : array() );
		}
		return $this->cache[ $kind ];
	}

	/**
	 * Stores a policy.
	 *
	 * @param string               $kind   Kind.
	 * @param array<string, mixed> $policy Policy (any shape; normalised here).
	 */
	public function save( string $kind, array $policy ): void {
		$clean = self::normalise( $policy );
		update_option( self::option( $kind ), $clean, false );
		$this->cache[ $kind ] = $clean;
		self::forget_shipping_rates( $kind );
	}

	/**
	 * WooCommerce keeps the shipping rates of every cart in the session and recalculates them only
	 * when the package changes; a new shipping cache version makes all carts recalculate, so a
	 * changed policy or user override applies at once.
	 *
	 * @param string $kind Kind that changed.
	 */
	private static function forget_shipping_rates( string $kind ): void {
		if ( self::KIND_SHIPPING === $kind && class_exists( 'WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/**
	 * Drops the cache.
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * Whether an audience (or the user's override) may use a method.
	 *
	 * @param string          $kind      Kind.
	 * @param AudienceContext $audience  Audience.
	 * @param string          $method_id Gateway id, or shipping rate key (method_id:instance_id, or method_id for non-zone methods).
	 */
	public function allowed( string $kind, AudienceContext $audience, string $method_id ): bool {
		if ( null !== $audience->user_id ) {
			$override = $this->user_override( $audience->user_id, $kind );
			if ( null !== $override ) {
				return in_array( $method_id, $override, true );
			}
		}
		$policy = $this->load( $kind );
		$list   = $policy['audiences'][ self::audience_key( $audience ) ] ?? null;
		if ( null === $list ) {
			return true;
		}
		if ( ! in_array( $method_id, $policy['known'], true ) ) {
			return self::DEFAULT_ALLOWED === $policy['default_new'];
		}
		return in_array( $method_id, $list, true );
	}

	/**
	 * Audience key: the group when there is one, else the customer type.
	 *
	 * @param AudienceContext $audience Audience.
	 */
	public static function audience_key( AudienceContext $audience ): string {
		return null !== $audience->group_id() ? 'group:' . $audience->group_id() : $audience->customer_type->value;
	}

	/**
	 * A user's manual list, or null when the user inherits from the audience.
	 *
	 * @param int    $user_id User id.
	 * @param string $kind    Kind.
	 * @return string[]|null
	 */
	public function user_override( int $user_id, string $kind ): ?array {
		$raw = get_user_meta( $user_id, self::meta( $kind ), true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$list = json_decode( $raw, true );
		return is_array( $list ) ? self::ids( $list ) : null;
	}

	/**
	 * Sets or clears a user's override.
	 *
	 * @param int           $user_id User id.
	 * @param string        $kind    Kind.
	 * @param string[]|null $methods Manual list, null to inherit.
	 */
	public function set_user_override( int $user_id, string $kind, ?array $methods ): void {
		if ( null === $methods ) {
			delete_user_meta( $user_id, self::meta( $kind ) );
		} else {
			update_user_meta( $user_id, self::meta( $kind ), (string) wp_json_encode( self::ids( $methods ) ) );
		}
		self::forget_shipping_rates( $kind );
	}

	/**
	 * Normalises a policy array.
	 *
	 * @param array<string, mixed> $raw Raw policy.
	 * @return array{default_new: string, known: string[], audiences: array<string, string[]>, non_zone: bool}
	 */
	public static function normalise( array $raw ): array {
		$audiences = array();
		foreach ( isset( $raw['audiences'] ) && is_array( $raw['audiences'] ) ? $raw['audiences'] : array() as $key => $list ) {
			$key = (string) $key;
			if ( 1 === preg_match( '/^(group:\d+|b2b|b2c|guest)$/', $key ) && is_array( $list ) ) {
				$audiences[ $key ] = self::ids( $list );
			}
		}
		return array(
			'default_new' => isset( $raw['default_new'] ) && self::DEFAULT_BLOCKED === $raw['default_new'] ? self::DEFAULT_BLOCKED : self::DEFAULT_ALLOWED,
			'known'       => self::ids( isset( $raw['known'] ) && is_array( $raw['known'] ) ? $raw['known'] : array() ),
			'audiences'   => $audiences,
			'non_zone'    => ! empty( $raw['non_zone'] ),
		);
	}

	/**
	 * Sanitised method id list.
	 *
	 * @param array<int|string, mixed> $values Raw list.
	 * @return string[]
	 */
	public static function ids( array $values ): array {
		$out = array();
		foreach ( $values as $id ) {
			if ( is_scalar( $id ) && 1 === preg_match( '/^[a-z0-9_\-]+(:\d+)?$/i', (string) $id ) ) {
				$out[] = (string) $id;
			}
		}
		return array_values( array_unique( $out ) );
	}
}

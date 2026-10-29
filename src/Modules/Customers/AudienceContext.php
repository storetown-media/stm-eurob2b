<?php
/**
 * Audience context.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

/**
 * Who the current visitor is, commercially. Resolved once per request.
 *
 * Every module asks this object instead of re-deriving the audience from
 * user meta. Company fields are reserved for the company module (M4).
 */
final class AudienceContext {

	/**
	 * Constructor.
	 *
	 * @param CustomerType $customer_type B2B, B2C or guest.
	 * @param int|null     $user_id       User id, null for guests.
	 * @param Group|null   $group         Assigned (or default) group, null for guests.
	 * @param int|null     $company_id    Company account id (reserved).
	 * @param string[]     $permissions   Company permissions (reserved).
	 */
	public function __construct(
		public readonly CustomerType $customer_type,
		public readonly ?int $user_id = null,
		public readonly ?Group $group = null,
		public readonly ?int $company_id = null,
		public readonly array $permissions = array()
	) {}

	/**
	 * A guest context.
	 */
	public static function guest(): self {
		return new self( CustomerType::GUEST );
	}

	/**
	 * Whether the visitor is a business customer.
	 */
	public function is_b2b(): bool {
		return CustomerType::B2B === $this->customer_type;
	}

	/**
	 * Whether the visitor is a logged-in private customer.
	 */
	public function is_b2c(): bool {
		return CustomerType::B2C === $this->customer_type;
	}

	/**
	 * Whether the visitor is not logged in (or not entitled to customer treatment).
	 */
	public function is_guest(): bool {
		return CustomerType::GUEST === $this->customer_type;
	}

	/**
	 * Group id or null.
	 */
	public function group_id(): ?int {
		return null === $this->group ? null : $this->group->id;
	}

	/**
	 * The currency this audience pays in when its group has one of its own (CUR-001), '' for the
	 * shop currency. Guests and customers of a group without an own currency pay in the shop
	 * currency.
	 */
	public function currency(): string {
		return null === $this->group || $this->is_guest() ? '' : \EuroB2B\Support\Currency::of_group( $this->group );
	}

	/**
	 * Whether the context matches an audience token.
	 *
	 * Tokens: all, registered, b2b, b2c, guest, group:<id>, user:<id>, company:<id>.
	 *
	 * @param string $token Audience token.
	 */
	public function matches( string $token ): bool {
		$token = strtolower( trim( $token ) );
		if ( '' === $token ) {
			return false;
		}
		if ( 'all' === $token ) {
			return true;
		}
		if ( 'registered' === $token ) {
			return null !== $this->user_id && ! $this->is_guest();
		}
		if ( in_array( $token, array( 'b2b', 'b2c', 'guest' ), true ) ) {
			return $this->customer_type->value === $token;
		}
		if ( str_starts_with( $token, 'group:' ) ) {
			return null !== $this->group && substr( $token, 6 ) === (string) $this->group->id;
		}
		if ( str_starts_with( $token, 'user:' ) ) {
			return null !== $this->user_id && substr( $token, 5 ) === (string) $this->user_id;
		}
		if ( str_starts_with( $token, 'company:' ) ) {
			return null !== $this->company_id && substr( $token, 8 ) === (string) $this->company_id;
		}
		return false;
	}

	/**
	 * Whether any of the tokens matches (OR).
	 *
	 * @param string[] $tokens Tokens.
	 */
	public function matches_any( array $tokens ): bool {
		foreach ( $tokens as $token ) {
			if ( $this->matches( (string) $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stable string that changes whenever audience matching could differ (cache keys).
	 *
	 * Includes the user id and permissions because rules may target user:<id>
	 * and company permissions; caches keyed by this hash are therefore per user.
	 */
	public function hash(): string {
		$permissions = $this->permissions;
		sort( $permissions );
		return md5( implode( '|', array( $this->customer_type->value, (string) $this->group_id(), (string) $this->company_id, (string) $this->user_id, implode( ',', $permissions ) ) ) );
	}
}

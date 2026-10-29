<?php
/**
 * Rule entity.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules;

use DateTimeImmutable;
use EuroB2B\Modules\Customers\AudienceContext;
use EuroB2B\Support\Currency;
use InvalidArgumentException;

/**
 * A rule: type, audience (tokens, OR), scope, conditions (AND, or ANY), date window and a
 * type-specific action payload. Rules are immutable value objects; the repository persists them.
 */
final class Rule {

	public const TYPE_DISCOUNT          = 'discount';
	public const TYPE_MARKUP            = 'markup';
	public const TYPE_FIXED_PRICE       = 'fixed_price';
	public const TYPE_HIDDEN_PRICE      = 'hidden_price';
	public const TYPE_NON_PURCHASABLE   = 'non_purchasable';
	public const TYPE_TIERED_PRICE      = 'tiered_price';
	public const TYPE_MIN_ORDER         = 'min_order';
	public const TYPE_MAX_ORDER         = 'max_order';
	public const TYPE_REQUIRED_MULTIPLE = 'required_multiple';
	public const TYPE_PAYMENT_WINDOW    = 'payment_window';
	public const TYPE_FEE               = 'fee';
	public const TYPE_FREE_SHIPPING     = 'free_shipping';

	/**
	 * PRC-018: a discount lowers the price shown with the product (sale price; default, also for
	 * every rule stored before the setting existed) …
	 */
	public const DISPLAY_PRICE = 'price';

	/**
	 * … or the product keeps its price and the cart shows the discount as its own line.
	 */
	public const DISPLAY_CART = 'cart';

	/**
	 * Longest name of a cart discount line.
	 */
	public const MAX_CART_LABEL = 100;

	public const CORE_TYPES = array( self::TYPE_DISCOUNT, self::TYPE_MARKUP, self::TYPE_FIXED_PRICE, self::TYPE_HIDDEN_PRICE, self::TYPE_NON_PURCHASABLE, self::TYPE_TIERED_PRICE, self::TYPE_MIN_ORDER, self::TYPE_MAX_ORDER, self::TYPE_REQUIRED_MULTIPLE, self::TYPE_PAYMENT_WINDOW, self::TYPE_FEE, self::TYPE_FREE_SHIPPING );

	/**
	 * Constructor.
	 *
	 * @param int                    $id            0 for a new rule.
	 * @param string                 $type          Rule type.
	 * @param string                 $name          Internal name.
	 * @param bool                   $enabled       Switch.
	 * @param int                    $priority      Higher wins.
	 * @param string[]               $audience      Audience tokens (OR); empty matches nobody.
	 * @param Scope                  $scope         Scope.
	 * @param Condition[]            $conditions    Conditions.
	 * @param bool                   $any_condition True: one condition suffices; false: all must hold.
	 * @param array<string, mixed>   $action        Type-specific payload.
	 * @param DateTimeImmutable|null $starts_at     Window start (UTC) or null.
	 * @param DateTimeImmutable|null $ends_at       Window end (UTC) or null.
	 * @throws InvalidArgumentException Empty type or name.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $name,
		public readonly bool $enabled,
		public readonly int $priority,
		public readonly array $audience,
		public readonly Scope $scope,
		public readonly array $conditions,
		public readonly bool $any_condition,
		public readonly array $action,
		public readonly ?DateTimeImmutable $starts_at = null,
		public readonly ?DateTimeImmutable $ends_at = null
	) {
		if ( '' === trim( $type ) ) {
			throw new InvalidArgumentException( 'A rule needs a type.' );
		}
		if ( '' === trim( $name ) ) {
			throw new InvalidArgumentException( 'A rule needs a name.' );
		}
	}

	/**
	 * Whether the rule is stored.
	 */
	public function exists(): bool {
		return $this->id > 0;
	}

	/**
	 * Enabled and inside the date window.
	 *
	 * @param DateTimeImmutable $now Now (UTC).
	 */
	public function is_active_at( DateTimeImmutable $now ): bool {
		if ( ! $this->enabled ) {
			return false;
		}
		if ( null !== $this->starts_at && $now < $this->starts_at ) {
			return false;
		}
		if ( null !== $this->ends_at && $now >= $this->ends_at ) {
			return false;
		}
		return true;
	}

	/**
	 * Copy with an id.
	 *
	 * @param int $id Id.
	 */
	public function with_id( int $id ): self {
		return new self( $id, $this->type, $this->name, $this->enabled, $this->priority, $this->audience, $this->scope, $this->conditions, $this->any_condition, $this->action, $this->starts_at, $this->ends_at );
	}

	/**
	 * Action value as string ('' when missing).
	 *
	 * @param string $key Key.
	 */
	public function action_string( string $key ): string {
		return isset( $this->action[ $key ] ) && is_scalar( $this->action[ $key ] ) ? (string) $this->action[ $key ] : '';
	}

	/**
	 * Action flag.
	 *
	 * @param string $key Key.
	 */
	public function action_flag( string $key ): bool {
		return ! empty( $this->action[ $key ] );
	}

	/**
	 * How a discount is shown (PRC-018): `cart` only for a discount rule whose `action.display` says
	 * so and that has a name for the cart line; everything else lowers the price (`price`).
	 */
	public function display(): string {
		return self::TYPE_DISCOUNT === $this->type && self::DISPLAY_CART === $this->action_string( 'display' ) && '' !== $this->cart_label() ? self::DISPLAY_CART : self::DISPLAY_PRICE;
	}

	/**
	 * Name of the cart discount line (PRC-018), '' when none is stored.
	 */
	public function cart_label(): string {
		return trim( $this->action_string( 'label' ) );
	}

	/**
	 * Currency of the rule's amounts (CUR-001, CUR-003): an ISO code from `action.currency`, '' for
	 * the shop currency.
	 */
	public function currency(): string {
		$currency = Currency::normalise( $this->action['currency'] ?? '' );
		return '' === $currency || Currency::is_shop( $currency ) ? '' : $currency;
	}

	/**
	 * Whether the rule works with money: a price, a fixed amount or a cart value threshold. Such a
	 * rule applies only to audiences that pay in the rule's currency; percentages and quantities
	 * apply in every currency.
	 */
	public function uses_amounts(): bool {
		foreach ( $this->conditions as $condition ) {
			if ( Condition::CART_VALUE === $condition->type ) {
				return true;
			}
		}
		$mode  = $this->action_string( 'mode' );
		$basis = $this->action_string( 'basis' );
		switch ( $this->type ) {
			case self::TYPE_FIXED_PRICE:
				return true;
			case self::TYPE_DISCOUNT:
			case self::TYPE_MARKUP:
				return 'fixed' === $mode;
			case self::TYPE_TIERED_PRICE:
			case self::TYPE_FEE:
				return 'percent' !== $mode;
			case self::TYPE_MIN_ORDER:
			case self::TYPE_MAX_ORDER:
			case self::TYPE_PAYMENT_WINDOW:
				return 'quantity' !== $basis;
		}
		return false;
	}

	/**
	 * Whether the rule may apply to an audience as far as money is concerned: always for rules
	 * without amounts, otherwise only when the audience pays in the rule's currency. An order that is
	 * paid later is measured in its own currency, which may differ from the audience's today.
	 *
	 * @param AudienceContext $audience Audience.
	 * @param string|null     $currency Currency of the amounts measured ('' = the shop currency), null
	 *                                  for the currency the audience pays in.
	 */
	public function fits_currency( AudienceContext $audience, ?string $currency = null ): bool {
		return ! $this->uses_amounts() || Currency::same( $this->currency(), $currency ?? $audience->currency() );
	}

	/**
	 * How specific the audience is: a rule for one user beats a group rule, which beats a customer
	 * type rule, which beats "everyone" (used where only one rule of a type may apply). With an
	 * audience given, only the tokens that match it count.
	 *
	 * @param AudienceContext|null $audience Audience the rule matched, or null for all tokens.
	 */
	public function audience_specificity( ?AudienceContext $audience = null ): int {
		$best = 0;
		foreach ( $this->audience as $token ) {
			if ( null !== $audience && ! $audience->matches( $token ) ) {
				continue;
			}
			if ( str_starts_with( $token, 'user:' ) ) {
				$best = max( $best, 50 );
			} elseif ( str_starts_with( $token, 'company:' ) ) {
				$best = max( $best, 40 );
			} elseif ( str_starts_with( $token, 'group:' ) ) {
				$best = max( $best, 30 );
			} elseif ( in_array( $token, array( 'b2b', 'b2c', 'guest', 'registered' ), true ) ) {
				$best = max( $best, 20 );
			} elseif ( 'all' === $token ) {
				$best = max( $best, 10 );
			}
		}
		return $best;
	}

	/**
	 * Labels of the rule types offered: the core offers the discount (PLT-015), EuroB2B Pro and
	 * other modules add theirs through the filter.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		$types = array(
			self::TYPE_DISCOUNT => __( 'Discount', 'eurob2b' ),
		);
		/**
		 * Filters the rule types offered in the admin (id => label). Modules that add a type also
		 * provide its evaluation through the RuleEvaluator. EuroB2B Pro adds its types with
		 * priority 5, right after the discount.
		 *
		 * @param array<string, string> $types Types.
		 */
		$filtered = apply_filters( 'eurob2b_rule_types', $types );
		return is_array( $filtered ) ? array_filter( $filtered, 'is_string' ) : $types;
	}
}

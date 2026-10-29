<?php
/**
 * Rule evaluation.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules;

use DateTimeImmutable;
use DateTimeZone;
use EuroB2B\Modules\Customers\AudienceContext;
use WC_Product;

/**
 * The single place that decides which rules apply to a product for an audience. Order: priority
 * descending, then id ascending (older rule wins a tie). Checks, in this order: whether the rule
 * needs EuroB2B Pro while it is missing (`RuleSupport`, PLT-015), enabled and date window, audience
 * token (OR), currency (a rule with amounts only for audiences that pay in its currency, CUR-001),
 * scope, conditions (AND, or ANY when the rule says so; without a condition checker a rule with
 * conditions never applies).
 */
final class RuleEvaluator {

	/**
	 * Rules.
	 *
	 * @var RuleRepository
	 */
	private RuleRepository $rules;

	/**
	 * Decides conditions (EuroB2B Pro); null without it.
	 *
	 * @var ConditionChecker|null
	 */
	private ?ConditionChecker $conditions;

	/**
	 * Constructor.
	 *
	 * @param RuleRepository        $rules      Repository.
	 * @param ConditionChecker|null $conditions Condition checker, when EuroB2B Pro is active.
	 */
	public function __construct( RuleRepository $rules, ?ConditionChecker $conditions = null ) {
		$this->rules      = $rules;
		$this->conditions = $conditions;
	}

	/**
	 * Matching rules of a type for a product and audience.
	 *
	 * @param string          $type     Rule type.
	 * @param WC_Product      $product  Product or variation.
	 * @param AudienceContext $audience Audience.
	 * @param int             $quantity Quantity of the subject (line quantity, 1 on catalog pages).
	 * @return Rule[]
	 */
	public function rules_for( string $type, WC_Product $product, AudienceContext $audience, int $quantity = 1 ): array {
		$matched = array();
		$now     = null;
		foreach ( $this->rules->enabled_of_type( $type ) as $rule ) {
			$now = $now ?? self::now();
			if ( ! RuleSupport::supported( $rule ) || ! $rule->is_active_at( $now ) || ! $audience->matches_any( $rule->audience ) || ! $rule->fits_currency( $audience ) || ! $rule->scope->matches( $product ) ) {
				continue;
			}
			if ( ! empty( $rule->conditions ) ) {
				if ( null === $this->conditions || ! $this->conditions->holds( $rule, max( 1, $quantity ), $product->get_id(), $product->get_parent_id() ) ) {
					continue;
				}
			}
			$matched[] = $rule;
		}
		/**
		 * Filters the rules that apply to a product.
		 *
		 * @param Rule[]          $matched  Matching rules, highest priority first.
		 * @param string          $type     Rule type.
		 * @param WC_Product      $product  Product.
		 * @param AudienceContext $audience Audience.
		 * @param int             $quantity Quantity.
		 */
		$filtered = apply_filters( 'eurob2b_rules_for', $matched, $type, $product, $audience, $quantity );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, static fn( $r ): bool => $r instanceof Rule ) ) : $matched;
	}

	/**
	 * Matching rules of a cart-level type (no product scope check: the caller applies the scope to the
	 * cart lines). Conditions are decided for the cart with quantity 1.
	 *
	 * @param string          $type     Rule type.
	 * @param AudienceContext $audience Audience.
	 * @param string|null     $currency Currency of the amounts measured ('' = the shop currency), null
	 *                                  for the currency the audience pays in (an order being paid
	 *                                  keeps its own).
	 * @return Rule[]
	 */
	public function rules_for_cart( string $type, AudienceContext $audience, ?string $currency = null ): array {
		$matched = array();
		$now     = null;
		foreach ( $this->rules->enabled_of_type( $type ) as $rule ) {
			$now = $now ?? self::now();
			if ( ! RuleSupport::supported( $rule ) || ! $rule->is_active_at( $now ) || ! $audience->matches_any( $rule->audience ) || ! $rule->fits_currency( $audience, $currency ) ) {
				continue;
			}
			if ( ! empty( $rule->conditions ) ) {
				if ( null === $this->conditions || ! $this->conditions->holds( $rule, 1 ) ) {
					continue;
				}
			}
			$matched[] = $rule;
		}
		return $matched;
	}

	/**
	 * The winning rule of a type, or null.
	 *
	 * @param string          $type     Rule type.
	 * @param WC_Product      $product  Product.
	 * @param AudienceContext $audience Audience.
	 * @param int             $quantity Quantity.
	 */
	public function first( string $type, WC_Product $product, AudienceContext $audience, int $quantity = 1 ): ?Rule {
		$rules = $this->rules_for( $type, $product, $audience, $quantity );
		return $rules[0] ?? null;
	}

	/**
	 * Now, in UTC.
	 */
	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}

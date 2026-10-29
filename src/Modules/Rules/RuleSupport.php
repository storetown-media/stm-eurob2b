<?php
/**
 * Which stored rules the core applies.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules;

/**
 * The core applies percentage discounts with an audience, a scope, a priority and a date window
 * (PLT-015). Every other rule type, the fixed-amount mode, conditions and the discount line in the
 * cart are features of EuroB2B Pro. A stored rule that uses one of them stays stored, but without
 * EuroB2B Pro it never applies, and the rules page cannot edit it, so it is never applied or saved
 * differently from what it says (a discount with a condition never becomes an unconditional one).
 * Rule types that other plugins add (`eurob2b_rule_types`) are theirs to apply.
 *
 * The core contains no behaviour for those parts: its adjustment provider takes percentage discounts
 * only, and conditions are decided by a `ConditionChecker` that only EuroB2B Pro provides. The flag
 * `eurob2b_rules_extended` therefore only says that another module handles them: the evaluator then
 * hands such rules to their consumers and the rules page opens and accepts them.
 */
final class RuleSupport {

	/**
	 * Rule types of EuroB2B Pro.
	 */
	public const EXTENDED_TYPES = array(
		Rule::TYPE_MARKUP,
		Rule::TYPE_FIXED_PRICE,
		Rule::TYPE_HIDDEN_PRICE,
		Rule::TYPE_NON_PURCHASABLE,
		Rule::TYPE_TIERED_PRICE,
		Rule::TYPE_MIN_ORDER,
		Rule::TYPE_MAX_ORDER,
		Rule::TYPE_REQUIRED_MULTIPLE,
		Rule::TYPE_PAYMENT_WINDOW,
		Rule::TYPE_FEE,
		Rule::TYPE_FREE_SHIPPING,
	);

	/**
	 * Whether the rule uses a rule type, mode, condition or display of EuroB2B Pro.
	 *
	 * @param Rule $rule Rule.
	 */
	public static function needs_extension( Rule $rule ): bool {
		if ( in_array( $rule->type, self::EXTENDED_TYPES, true ) || ! empty( $rule->conditions ) ) {
			return true;
		}
		return Rule::TYPE_DISCOUNT === $rule->type && ( 'fixed' === $rule->action_string( 'mode' ) || Rule::DISPLAY_CART === $rule->action_string( 'display' ) );
	}

	/**
	 * Whether the rule may apply and be edited: always when it needs nothing of EuroB2B Pro,
	 * otherwise only next to it.
	 *
	 * @param Rule $rule Rule.
	 */
	public static function supported( Rule $rule ): bool {
		return ! self::needs_extension( $rule ) || self::extended();
	}

	/**
	 * Whether the rule types, modes, conditions and displays beyond the percentage discount are
	 * available.
	 */
	public static function extended(): bool {
		/**
		 * Filters whether a module handles the rule types, the fixed-amount mode, the conditions and
		 * the discount line in the cart (EuroB2B Pro returns true; return true only from the module
		 * that applies them and adds their form parts). It switches no behaviour on by itself.
		 * Without it, rules that use them stay stored but never apply, and the rules page lists them
		 * without editing them.
		 *
		 * @param bool $extended Default false.
		 */
		return true === apply_filters( 'eurob2b_rules_extended', false );
	}
}

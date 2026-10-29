<?php
/**
 * Who decides whether the conditions of a rule hold.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules;

/**
 * Conditions are a feature of EuroB2B Pro (PLT-015): the core stores them (`Condition` is data),
 * EuroB2B Pro registers this checker in the container and decides them against the cart. Without a
 * checker the evaluator cannot decide a condition, so a rule with conditions never applies.
 */
interface ConditionChecker {

	/**
	 * Whether the rule's conditions hold (all, or any when the rule says so) for the current cart.
	 *
	 * @param Rule $rule       Rule with at least one condition.
	 * @param int  $quantity   Quantity of the subject (line quantity, 1 on catalog pages and for the cart).
	 * @param int  $product_id Subject product id, 0 for the cart.
	 * @param int  $parent_id  Subject parent id.
	 */
	public function holds( Rule $rule, int $quantity, int $product_id = 0, int $parent_id = 0 ): bool;
}

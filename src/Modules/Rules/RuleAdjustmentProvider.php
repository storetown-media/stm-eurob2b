<?php
/**
 * Percentage discount rules as price adjustments.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules;

use EuroB2B\Modules\Customers\AudienceContext;
use EuroB2B\Modules\Pricing\Adjustment;
use EuroB2B\Modules\Pricing\AdjustmentProviderInterface;
use InvalidArgumentException;
use WC_Product;

/**
 * Feeds percentage discount rules into the price pipeline; the pipeline applies the highest
 * priority only or stacks them, per the shop setting. A discount that needs EuroB2B Pro (a fixed
 * amount, a condition, a line in the cart) is left out here in any case; EuroB2B Pro puts its own
 * provider for every type and mode in the place of this one (PLT-015).
 */
final class RuleAdjustmentProvider implements AdjustmentProviderInterface {

	/**
	 * Evaluator.
	 *
	 * @var RuleEvaluator
	 */
	private RuleEvaluator $evaluator;

	/**
	 * Constructor.
	 *
	 * @param RuleEvaluator $evaluator Evaluator.
	 */
	public function __construct( RuleEvaluator $evaluator ) {
		$this->evaluator = $evaluator;
	}

	/**
	 * Adjustments from matching percentage discount rules.
	 *
	 * @param WC_Product      $product  Product.
	 * @param AudienceContext $audience Audience.
	 * @param int             $quantity Quantity.
	 * @return Adjustment[]
	 */
	public function adjustments( WC_Product $product, AudienceContext $audience, int $quantity ): array {
		$rules = $this->evaluator->rules_for( Rule::TYPE_DISCOUNT, $product, $audience, $quantity );
		// Priority descending, then the lower id (older rule); the pipeline keeps this order for ties.
		usort( $rules, static fn( Rule $a, Rule $b ): int => $b->priority <=> $a->priority ?: $a->id <=> $b->id );
		$adjustments = array();
		foreach ( $rules as $rule ) {
			$amount = $rule->action_string( 'amount' );
			if ( RuleSupport::needs_extension( $rule ) || 1 !== preg_match( '/^\d+(\.\d+)?$/', $amount ) ) {
				continue;
			}
			try {
				$adjustments[] = new Adjustment( Adjustment::TYPE_PERCENT, '-' . $amount, $rule->priority, $rule->name, false, 'rule:' . $rule->id );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
		}
		return $adjustments;
	}
}

<?php
/**
 * Result of a tier provider.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Pricing;

/**
 * A unit price for the quantity being priced, with its source and how it ranks against other tier
 * providers (product tiers 200 beat tiered rules 100; priority breaks ties within a specificity).
 */
final class TierPrice {

	public const SPECIFICITY_RULE    = 100;
	public const SPECIFICITY_PRODUCT = 200;

	/**
	 * Constructor.
	 *
	 * @param string $price       Unit price (decimal string).
	 * @param string $source      Source label (tier:product, tier:group:<id>, tier:rule:<id>).
	 * @param int    $specificity Ranking across providers.
	 * @param int    $priority    Ranking within a specificity (higher wins).
	 */
	public function __construct(
		public readonly string $price,
		public readonly string $source,
		public readonly int $specificity,
		public readonly int $priority = 0
	) {
	}

	/**
	 * Picks the winning tier price.
	 *
	 * @param TierPrice[] $candidates Candidates.
	 */
	public static function select( array $candidates ): ?TierPrice {
		$winner = null;
		foreach ( $candidates as $candidate ) {
			if ( null === $winner || $candidate->specificity > $winner->specificity || ( $candidate->specificity === $winner->specificity && $candidate->priority > $winner->priority ) ) {
				$winner = $candidate;
			}
		}
		return $winner;
	}
}

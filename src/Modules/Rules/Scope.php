<?php
/**
 * Rule scope: which products a rule targets.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules;

use InvalidArgumentException;
use WC_Product;

/**
 * All products, a list of products (covering their variations), a list of variations, categories
 * (including descendants: a rule on "Machines" also covers products in its sub-categories) or tags.
 */
final class Scope {

	public const ALL        = 'all';
	public const PRODUCTS   = 'products';
	public const VARIATIONS = 'variations';
	public const CATEGORIES = 'categories';
	public const TAGS       = 'tags';

	public const KINDS = array( self::ALL, self::PRODUCTS, self::VARIATIONS, self::CATEGORIES, self::TAGS );

	/**
	 * Per-request cache of term ids (with ancestors) per product and taxonomy.
	 *
	 * @var array<string, int[]>
	 */
	private static array $terms = array();

	/**
	 * Constructor.
	 *
	 * @param string $kind One of KINDS.
	 * @param int[]  $ids  Product, variation or term ids (empty for "all").
	 * @throws InvalidArgumentException Unknown kind.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly array $ids = array()
	) {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown scope "%s".', $kind ) );
		}
	}

	/**
	 * Scope covering every product.
	 */
	public static function all(): self {
		return new self( self::ALL );
	}

	/**
	 * Whether a product (or variation) is inside the scope.
	 *
	 * @param WC_Product $product Product or variation.
	 */
	public function matches( WC_Product $product ): bool {
		$id     = $product->get_id();
		$parent = $product->get_parent_id();
		switch ( $this->kind ) {
			case self::ALL:
				return true;
			case self::PRODUCTS:
				return in_array( $id, $this->ids, true ) || ( $parent > 0 && in_array( $parent, $this->ids, true ) );
			case self::VARIATIONS:
				return $parent > 0 && in_array( $id, $this->ids, true );
			case self::CATEGORIES:
				return array() !== array_intersect( self::terms_of( $parent > 0 ? $parent : $id, 'product_cat' ), $this->ids );
			case self::TAGS:
				return array() !== array_intersect( self::terms_of( $parent > 0 ? $parent : $id, 'product_tag' ), $this->ids );
		}
		return false;
	}

	/**
	 * Term ids of a product in a taxonomy, including every ancestor term.
	 *
	 * @param int    $product_id Product (parent for variations).
	 * @param string $taxonomy   Taxonomy.
	 * @return int[]
	 */
	public static function terms_of( int $product_id, string $taxonomy ): array {
		$key = $product_id . '|' . $taxonomy;
		if ( ! isset( self::$terms[ $key ] ) ) {
			$ids = array_map( 'intval', wc_get_product_term_ids( $product_id, $taxonomy ) );
			foreach ( $ids as $term_id ) {
				foreach ( get_ancestors( $term_id, $taxonomy, 'taxonomy' ) as $ancestor ) {
					$ids[] = (int) $ancestor;
				}
			}
			self::$terms[ $key ] = array_values( array_unique( $ids ) );
		}
		return self::$terms[ $key ];
	}

	/**
	 * Clears the term cache (after term or product changes).
	 */
	public static function flush(): void {
		self::$terms = array();
	}

	/**
	 * Storage form.
	 *
	 * @return array{kind: string, ids: int[]}
	 */
	public function to_array(): array {
		return array(
			'kind' => $this->kind,
			'ids'  => array_values( $this->ids ),
		);
	}

	/**
	 * From storage; unknown or malformed data falls back to an empty product scope (matches nothing).
	 *
	 * @param mixed $data Decoded JSON.
	 */
	public static function from_array( $data ): self {
		$kind = is_array( $data ) && isset( $data['kind'] ) && is_string( $data['kind'] ) ? $data['kind'] : '';
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new self( self::PRODUCTS, array() );
		}
		$ids = is_array( $data ) && isset( $data['ids'] ) && is_array( $data['ids'] ) ? $data['ids'] : array();
		return new self( $kind, self::ALL === $kind ? array() : array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ) );
	}
}

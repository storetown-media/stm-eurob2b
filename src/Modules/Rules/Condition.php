<?php
/**
 * Rule condition.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules;

use InvalidArgumentException;

/**
 * One condition over the cart or checkout, as data: numeric conditions compare an amount or
 * quantity, list conditions test membership (country, payment method, shipping method). The core
 * stores and validates them; EuroB2B Pro decides them against the cart (`ConditionChecker`, PLT-015).
 */
final class Condition {

	public const CART_VALUE        = 'cart_value';
	public const CART_QUANTITY     = 'cart_quantity';
	public const PRODUCT_QUANTITY  = 'product_quantity';
	public const CATEGORY_QUANTITY = 'category_quantity';
	public const BILLING_COUNTRY   = 'billing_country';
	public const SHIPPING_COUNTRY  = 'shipping_country';
	public const PAYMENT_METHOD    = 'payment_method';
	public const SHIPPING_METHOD   = 'shipping_method';

	public const NUMERIC_TYPES = array( self::CART_VALUE, self::CART_QUANTITY, self::PRODUCT_QUANTITY, self::CATEGORY_QUANTITY );
	public const LIST_TYPES    = array( self::BILLING_COUNTRY, self::SHIPPING_COUNTRY, self::PAYMENT_METHOD, self::SHIPPING_METHOD );
	/** Conditions that only hold inside a cart. */
	public const CART_TYPES = array( self::CART_VALUE, self::CART_QUANTITY, self::CATEGORY_QUANTITY );

	public const OP_GTE    = 'gte';
	public const OP_LTE    = 'lte';
	public const OP_GT     = 'gt';
	public const OP_LT     = 'lt';
	public const OP_EQ     = 'eq';
	public const OP_IN     = 'in';
	public const OP_NOT_IN = 'not_in';

	public const NUMERIC_OPERATORS = array( self::OP_GTE, self::OP_LTE, self::OP_GT, self::OP_LT, self::OP_EQ );
	public const LIST_OPERATORS    = array( self::OP_IN, self::OP_NOT_IN );

	/**
	 * Constructor.
	 *
	 * @param string   $type     Condition type.
	 * @param string   $operator Operator fitting the type.
	 * @param string   $value    Numeric threshold (decimal string) for numeric types, '' for list types.
	 * @param string[] $values   Accepted values for list types (ISO countries, gateway ids, shipping method ids).
	 * @param int[]    $term_ids Category ids for CATEGORY_QUANTITY.
	 * @throws InvalidArgumentException Unknown type/operator or malformed value.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $operator,
		public readonly string $value = '',
		public readonly array $values = array(),
		public readonly array $term_ids = array()
	) {
		if ( in_array( $type, self::NUMERIC_TYPES, true ) ) {
			if ( ! in_array( $operator, self::NUMERIC_OPERATORS, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Operator "%s" does not fit a numeric condition.', $operator ) );
			}
			if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
				throw new InvalidArgumentException( sprintf( 'Malformed condition value "%s".', $value ) );
			}
		} elseif ( in_array( $type, self::LIST_TYPES, true ) ) {
			if ( ! in_array( $operator, self::LIST_OPERATORS, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Operator "%s" does not fit a list condition.', $operator ) );
			}
		} else {
			throw new InvalidArgumentException( sprintf( 'Unknown condition "%s".', $type ) );
		}
	}

	/**
	 * Storage form.
	 *
	 * @return array{type: string, operator: string, value: string, values: string[], term_ids: int[]}
	 */
	public function to_array(): array {
		return array(
			'type'     => $this->type,
			'operator' => $this->operator,
			'value'    => $this->value,
			'values'   => array_values( $this->values ),
			'term_ids' => array_values( $this->term_ids ),
		);
	}

	/**
	 * From storage; malformed entries are dropped by the caller (returns null).
	 *
	 * @param mixed $data Decoded JSON.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['type'], $data['operator'] ) || ! is_string( $data['type'] ) || ! is_string( $data['operator'] ) ) {
			return null;
		}
		try {
			return new self(
				$data['type'],
				$data['operator'],
				isset( $data['value'] ) && is_scalar( $data['value'] ) ? (string) $data['value'] : '',
				isset( $data['values'] ) && is_array( $data['values'] ) ? array_values( array_map( 'strval', $data['values'] ) ) : array(),
				isset( $data['term_ids'] ) && is_array( $data['term_ids'] ) ? array_values( array_map( 'intval', $data['term_ids'] ) ) : array()
			);
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}
}

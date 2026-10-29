<?php
/**
 * Value formats of B2BKing.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration\Sources;

use EuroB2B\Modules\Rules\Condition;

/**
 * Parsers for the value formats B2BKing stores (MIG-002), as observed in B2BKing Core 5.2.61 and its
 * reading code (docs/migration.md). Pure functions: no database, no WordPress state.
 */
final class B2BKingFormat {

	/**
	 * Condition names of B2BKing → EuroB2B condition types. The "value" conditions per category or
	 * product have no counterpart.
	 */
	private const CONDITIONS = array(
		'cart_total_quantity'       => Condition::CART_QUANTITY,
		'cart_total_value'          => Condition::CART_VALUE,
		'category_product_quantity' => Condition::CATEGORY_QUANTITY,
		'product_quantity'          => Condition::PRODUCT_QUANTITY,
	);

	/**
	 * Operators of B2BKing → EuroB2B.
	 */
	private const OPERATORS = array(
		'greater' => Condition::OP_GT,
		'equal'   => Condition::OP_EQ,
		'smaller' => Condition::OP_LT,
	);

	/**
	 * A decimal as a string with a dot, digits as stored ('' when it is not a non-negative number).
	 * B2BKing's price fields are number inputs, so a dot is the decimal separator; a comma is read as
	 * one too, never as a thousands separator.
	 *
	 * @param string $raw Stored value.
	 */
	public static function decimal( string $raw ): string {
		return SourceValues::decimal( $raw );
	}

	/**
	 * Tiers stored as `quantity:amount;quantity:amount;`. Rows that are not a positive whole
	 * quantity and a non-negative amount are dropped; a quantity given twice keeps the last amount.
	 *
	 * @param string $raw Stored value.
	 * @return array<int, array{min: int, price: string}> Sorted by quantity.
	 */
	public static function tiers( string $raw ): array {
		$out = array();
		foreach ( explode( ';', $raw ) as $pair ) {
			$parts = explode( ':', trim( $pair ) );
			if ( 2 !== count( $parts ) || 1 !== preg_match( '/^\d+$/', trim( $parts[0] ) ) ) {
				continue;
			}
			$min    = (int) trim( $parts[0] );
			$amount = self::decimal( $parts[1] );
			if ( $min < 1 || '' === $amount ) {
				continue;
			}
			$out[ $min ] = array(
				'min'   => $min,
				'price' => $amount,
			);
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Conditions stored as `name;operator;value|…`, in the shape of the rules screen's form rows.
	 * Returns null when a condition has no EuroB2B counterpart.
	 *
	 * @param string $raw          Stored value.
	 * @param int[]  $category_ids Categories of the rule's scope (a category condition counts those).
	 * @return array<int, array{type: string, operator: string, value: string, terms?: int[]}>|null
	 */
	public static function conditions( string $raw, array $category_ids ): ?array {
		$rows = array();
		foreach ( explode( '|', $raw ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			$parts = explode( ';', $item );
			if ( 3 !== count( $parts ) ) {
				return null;
			}
			$type     = self::CONDITIONS[ trim( $parts[0] ) ] ?? null;
			$operator = self::OPERATORS[ trim( $parts[1] ) ] ?? null;
			$value    = self::decimal( $parts[2] );
			if ( null === $type || null === $operator || '' === $value ) {
				return null;
			}
			$row = array(
				'type'     => $type,
				'operator' => $operator,
				'value'    => $value,
			);
			if ( Condition::CATEGORY_QUANTITY === $type ) {
				if ( empty( $category_ids ) ) {
					return null;
				}
				$row['terms'] = $category_ids;
			}
			$rows[] = $row;
		}//end foreach
		return $rows;
	}

	/**
	 * Choices of a select or checkbox field: a comma list where a literal comma is written `[,]`.
	 *
	 * @param string $raw Stored value.
	 * @return string[]
	 */
	public static function choices( string $raw ): array {
		$marker = "\u{001F}";
		$list   = explode( ',', str_replace( '[,]', $marker, $raw ) );
		$out    = array();
		foreach ( $list as $choice ) {
			$choice = trim( str_replace( $marker, ',', $choice ) );
			if ( '' !== $choice ) {
				$out[] = $choice;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Ids in a comma list (`group_3,user_5` style tokens are handled by tokens()).
	 *
	 * @param string $raw Stored value.
	 * @return int[]
	 */
	public static function ids( string $raw ): array {
		$out = array();
		foreach ( explode( ',', $raw ) as $part ) {
			$part = trim( $part );
			if ( 1 === preg_match( '/^\d+$/', $part ) && (int) $part > 0 ) {
				$out[] = (int) $part;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Splits `prefix_123` tokens of a comma list by prefix.
	 *
	 * @param string $raw Stored value, e.g. `group_3,user_5,everyone_registered_b2b`.
	 * @return array<string, array<int, int|string>> prefix => ids, and `other` => the tokens without an id.
	 */
	public static function tokens( string $raw ): array {
		$out = array();
		foreach ( explode( ',', $raw ) as $token ) {
			$token = trim( $token );
			if ( '' === $token ) {
				continue;
			}
			if ( 1 === preg_match( '/^([a-z]+)_(\d+)$/', $token, $m ) ) {
				$out[ $m[1] ][] = (int) $m[2];
			} else {
				$out['other'][] = $token;
			}
		}
		return $out;
	}

	/**
	 * Splits B2BKing's shipping key (method id and instance id without a separator, e.g.
	 * `flat_rate3`) with the known zone methods into EuroB2B's rate key (`flat_rate:3`).
	 *
	 * @param string   $suffix  Key suffix after `…_shipping_method_`.
	 * @param string[] $methods EuroB2B rate keys of the shop (method_id:instance_id or method_id).
	 */
	public static function shipping_key( string $suffix, array $methods ): string {
		foreach ( $methods as $method ) {
			if ( str_replace( ':', '', $method ) === $suffix ) {
				return $method;
			}
		}
		return '';
	}

	/**
	 * Whether B2BKing allows a payment or shipping method: a missing value means allowed, a stored
	 * one only when it is 1 (an unticked box is stored as empty and blocks the method).
	 *
	 * @param string|null $value Stored value, null when there is none.
	 */
	public static function allowed( ?string $value ): bool {
		return null === $value || 1 === (int) $value;
	}
}

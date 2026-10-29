<?php
/**
 * Value formats shared by the import sources.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration\Sources;

/**
 * Pure helpers for values other plugins store (MIG-001).
 */
final class SourceValues {

	/**
	 * A decimal as a string with a dot, digits as stored ('' when it is not a non-negative number).
	 * A comma is read as a decimal separator, never as a thousands separator: other plugins store
	 * prices from number inputs or through `wc_format_decimal()`, older versions with the shop's
	 * decimal comma.
	 *
	 * @param string $raw Stored value.
	 */
	public static function decimal( string $raw ): string {
		$value = str_replace( array( ' ', ',' ), array( '', '.' ), trim( $raw ) );
		return 1 === preg_match( '/^\d+(\.\d+)?$/', $value ) ? $value : '';
	}
}

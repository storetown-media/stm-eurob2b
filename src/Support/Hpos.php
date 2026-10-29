<?php
/**
 * Which order store WooCommerce uses.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * High-Performance Order Storage: queries that add their own meta conditions need to know which
 * store answers them.
 */
final class Hpos {

	/**
	 * Whether HPOS is the authoritative order store.
	 */
	public static function enabled(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}
}

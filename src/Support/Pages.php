<?php
/**
 * Pages as setting options.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

/**
 * The shop's pages for select fields of the settings (legal texts, public pages of the guest
 * restrictions).
 */
final class Pages {

	/**
	 * Published pages as select options, resolved when the schema is read so new pages appear (id → title; PHP stores numeric string keys as integers).
	 *
	 * @return array<int|string, string>
	 */
	public static function options(): array {
		$options = array();
		foreach ( get_pages( array( 'number' => 500 ) ) ?: array() as $page ) {
			$options[ (string) $page->ID ] = '' !== $page->post_title ? $page->post_title : '#' . $page->ID;
		}
		return $options;
	}
}

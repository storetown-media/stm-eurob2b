<?php
/**
 * Admin menu.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use EuroB2B\Infrastructure\Capabilities;

/**
 * One top-level "EuroB2B" menu; modules register their pages here.
 *
 * Pages are registered with the capability they require, so WordPress hides
 * them for users without it and the page callbacks re-check it anyway.
 */
final class Menu {

	public const SLUG = 'eurob2b';

	/**
	 * Registered pages.
	 *
	 * @var array<string, array{title: string, menu_title: string, capability: string, callback: callable, order: int}>
	 */
	private array $pages = array();

	/**
	 * Registers a submenu page.
	 *
	 * @param string   $slug       Page slug (appended to "eurob2b-").
	 * @param string   $title      Page title.
	 * @param string   $menu_title Menu label.
	 * @param string   $capability Required capability.
	 * @param callable $callback   Renders the page.
	 * @param int      $order      Sort order.
	 */
	public function add_page( string $slug, string $title, string $menu_title, string $capability, callable $callback, int $order = 10 ): void {
		$this->pages[ $slug ] = array(
			'title'      => $title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'callback'   => $callback,
			'order'      => $order,
		);
	}

	/**
	 * Full admin page slug for a registered page.
	 *
	 * @param string $slug Page slug.
	 */
	public static function page_slug( string $slug ): string {
		return self::SLUG . '-' . $slug;
	}

	/**
	 * Admin URL of a page.
	 *
	 * @param string               $slug Page slug.
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::page_slug( $slug ) ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Hooked to admin_menu.
	 */
	public function register(): void {
		/**
		 * Lets modules register admin pages.
		 *
		 * @param Menu $menu Menu.
		 */
		do_action( 'eurob2b_admin_menu', $this );

		if ( empty( $this->pages ) ) {
			return;
		}

		$pages = $this->pages;
		uasort( $pages, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
		// The parent entry takes the first page the current user may open, so a role with only
		// one of the capabilities still sees the menu (and lands on a page it can use).
		$first = array_key_first( $pages );
		foreach ( $pages as $slug => $page ) {
			if ( current_user_can( $page['capability'] ) ) {
				$first = $slug;
				break;
			}
		}

		add_menu_page(
			__( 'EuroB2B', 'eurob2b' ),
			__( 'EuroB2B', 'eurob2b' ),
			$pages[ $first ]['capability'],
			self::page_slug( $first ),
			$pages[ $first ]['callback'],
			'dashicons-building',
			56
		);

		foreach ( $pages as $slug => $page ) {
			add_submenu_page(
				self::page_slug( $first ),
				$page['title'],
				$page['menu_title'],
				$page['capability'],
				self::page_slug( $slug ),
				$page['callback']
			);
		}
	}
}

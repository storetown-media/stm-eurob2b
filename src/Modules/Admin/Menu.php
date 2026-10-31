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
 *
 * Since PLT-017 the menu shows one entry per area (customers, prices and rules, quotes, tax and
 * evidence, accounting, settings) instead of one per page. The other pages of an area stay
 * registered under their old slug without a menu entry, so every URL keeps working, and appear
 * as tabs in the page header (PageHeader). A page a module adds that is not in AREAS gets its
 * own menu entry, as before.
 */
final class Menu {

	public const SLUG = 'eurob2b';

	/**
	 * Areas in menu order, each with its pages in tab order.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const AREAS = array(
		'overview'   => array( 'overview' ),
		'customers'  => array( 'approvals', 'groups', 'group-suggestions', 'companies', 'registration' ),
		'prices'     => array( 'rules', 'price-lists', 'methods' ),
		'quotes'     => array( 'quotes', 'conversations', 'quote-fields' ),
		'tax'        => array( 'vat-validations', 'proof-of-supply', 'audit-files' ),
		'accounting' => array( 'reports', 'accounting-export', 'overdue' ),
		'settings'   => array( 'settings', 'processing-record', 'audit-log', 'settings-transfer', 'licence' ),
	);

	/**
	 * Registered pages.
	 *
	 * @var array<string, array{title: string, menu_title: string, capability: string, callback: callable, order: int}>
	 */
	private array $pages = array();

	/**
	 * Areas as registered for the current user, for the page header: area => label and the pages the
	 * user may open (slug => label, count).
	 *
	 * @var array<string, array{label: string, count: int, pages: array<string, array{label: string, count: int}>}>
	 */
	private static array $registered = array();

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
	 * Label of an area.
	 *
	 * @param string $area Key of AREAS.
	 */
	public static function area_label( string $area ): string {
		$labels = array(
			'overview'   => __( 'Overview', 'eurob2b' ),
			'customers'  => __( 'Customers', 'eurob2b' ),
			'prices'     => __( 'Prices & rules', 'eurob2b' ),
			'quotes'     => __( 'Quotes', 'eurob2b' ),
			'tax'        => __( 'Tax & evidence', 'eurob2b' ),
			'accounting' => __( 'Accounting', 'eurob2b' ),
			'settings'   => __( 'Settings', 'eurob2b' ),
		);
		return $labels[ $area ] ?? '';
	}

	/**
	 * The area of a page as registered for the current user, or null for a page outside the areas.
	 *
	 * @param string $slug Page slug without the "eurob2b-" prefix.
	 * @return array{key: string, label: string, count: int, pages: array<string, array{label: string, count: int}>}|null
	 */
	public static function area_of( string $slug ): ?array {
		foreach ( self::$registered as $key => $area ) {
			if ( isset( $area['pages'][ $slug ] ) ) {
				return array( 'key' => $key ) + $area;
			}
		}
		return null;
	}

	/**
	 * Number in a WordPress count bubble of a menu label (`<span class="awaiting-mod count-3">`), else 0.
	 *
	 * @param string $menu_title Menu label.
	 */
	public static function count_in( string $menu_title ): int {
		return 1 === preg_match( '/\bcount-(\d+)\b/', $menu_title, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Groups the registered pages: area => slug => page, areas in menu order, pages in tab order;
	 * a page outside AREAS forms an area of its own (key "page:<slug>") after the others, ordered
	 * by its sort order.
	 *
	 * @param array<string, array{title: string, menu_title: string, capability: string, callback: callable, order: int}> $pages Pages.
	 * @return array<string, array<string, array{title: string, menu_title: string, capability: string, callback: callable, order: int}>>
	 */
	public static function group( array $pages ): array {
		$groups = array();
		foreach ( self::AREAS as $area => $slugs ) {
			foreach ( $slugs as $slug ) {
				if ( isset( $pages[ $slug ] ) ) {
					$groups[ $area ][ $slug ] = $pages[ $slug ];
					unset( $pages[ $slug ] );
				}
			}
		}
		uasort( $pages, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
		foreach ( $pages as $slug => $page ) {
			$groups[ 'page:' . $slug ] = array( $slug => $page );
		}
		return $groups;
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

		$groups = self::group( $this->pages );
		// Per area the first page the current user may open (else the first page; WordPress then hides
		// the entry). The parent entry takes the first of these that the user may open, so a role with
		// only one of the capabilities still sees the menu and lands on a page it can use.
		$entries          = array();
		self::$registered = array();
		foreach ( $groups as $key => $pages ) {
			$entries[ $key ] = (string) array_key_first( $pages );
			foreach ( $pages as $slug => $page ) {
				if ( ! current_user_can( $page['capability'] ) ) {
					continue;
				}
				if ( ! isset( self::$registered[ $key ] ) ) {
					$entries[ $key ]          = $slug;
					self::$registered[ $key ] = array(
						'label' => str_starts_with( $key, 'page:' ) ? trim( wp_strip_all_tags( $page['menu_title'] ) ) : self::area_label( $key ),
						'count' => 0,
						'pages' => array(),
					);
				}
				$page_count                                 = self::count_in( $page['menu_title'] );
				self::$registered[ $key ]['pages'][ $slug ] = array(
					'label' => trim( (string) preg_replace( '/<span class="awaiting-mod.*$/s', '', $page['menu_title'] ) ),
					'count' => $page_count,
				);
				self::$registered[ $key ]['count']         += $page_count;
			}
		}//end foreach
		$parent = $entries[ (string) array_key_first( $groups ) ];
		foreach ( $entries as $key => $slug ) {
			if ( isset( self::$registered[ $key ] ) ) {
				$parent = $slug;
				break;
			}
		}

		add_menu_page(
			__( 'EuroB2B', 'eurob2b' ),
			__( 'EuroB2B', 'eurob2b' ),
			$this->pages[ $parent ]['capability'],
			self::page_slug( $parent ),
			$this->pages[ $parent ]['callback'],
			'dashicons-building',
			56
		);

		foreach ( $groups as $key => $pages ) {
			foreach ( $pages as $slug => $page ) {
				if ( $slug === $entries[ $key ] ) {
					$label = str_starts_with( $key, 'page:' ) ? $page['menu_title'] : esc_html( self::area_label( $key ) ) . self::bubble( self::$registered[ $key ]['count'] ?? 0 );
					add_submenu_page( self::page_slug( $parent ), $page['title'], $label, $page['capability'], self::page_slug( $slug ), $page['callback'] );
				} else {
					// No menu entry: reachable under its old URL and as a tab of its area.
					add_submenu_page( '', $page['title'], $page['menu_title'], $page['capability'], self::page_slug( $slug ), $page['callback'] );
				}
			}
		}

		$top = self::page_slug( $parent );
		add_action(
			'admin_init',
			static function () use ( $top, $entries ): void {
				if ( ! PageHeader::is_eurob2b_page() || in_array( PageHeader::current_slug(), $entries, true ) ) {
					return;
				}
				// A page without menu entry has the parent '' in $submenu. WordPress recomputes the parent
				// (get_admin_page_parent(), after the parent_file filter) for the menu highlight and the
				// <title>; mapping '' to the EuroB2B menu, only on EuroB2B requests and after admin_menu
				// (so no later add_submenu_page( '' … ) of another plugin moves into this menu), makes both
				// work. Other plugins' pages without parent are not the current page then.
				$GLOBALS['_wp_real_parent_file'][''] = $top; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- see above.
			}
		);
		add_filter(
			'submenu_file',
			static function ( $file ) use ( $entries ) {
				$area = self::area_of( PageHeader::current_slug() );
				return null !== $area && isset( $entries[ $area['key'] ] ) ? self::page_slug( $entries[ $area['key'] ] ) : $file;
			}
		);
	}

	/**
	 * WordPress count bubble for a menu label, or '' for zero.
	 *
	 * @param int $count Count.
	 */
	private static function bubble( int $count ): string {
		return $count > 0 ? ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . $count . '</span></span>' : '';
	}
}

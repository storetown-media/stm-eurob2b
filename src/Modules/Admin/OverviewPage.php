<?php
/**
 * Overview page: what is waiting for the shop (PLT-017).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use EuroB2B\Infrastructure\Capabilities;

/**
 * EuroB2B → Overview: one line per kind of open work (registrations to approve, VAT ID checks to look
 * at, proofs of supply missing, overdue invoices …), each with its number and a button to the page
 * where it is decided. The modules add their lines through the filter `eurob2b_overview_items`; a
 * line is shown only when its number is above zero and the user has its capability. Nothing is stored.
 */
final class OverviewPage {

	public const SLUG = 'overview';

	/**
	 * Capabilities that open the page; the page takes the first the user has, so every member of
	 * staff with an EuroB2B capability lands on it (the lines are filtered per capability).
	 */
	private const CAPABILITIES = array(
		Capabilities::MANAGE_CUSTOMERS,
		Capabilities::DECIDE_VALIDATION,
		Capabilities::MANAGE_QUOTES,
		Capabilities::VIEW_REPORTS,
		Capabilities::MANAGE_COMPANIES,
		Capabilities::MANAGE_GROUPS,
		Capabilities::MANAGE_RULES,
		Capabilities::MANAGE_SETTINGS,
	);

	/**
	 * Registers the page.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'Overview', 'eurob2b' ), __( 'Overview', 'eurob2b' ), self::capability(), array( $this, 'render' ), 1 );
	}

	/**
	 * The first capability of CAPABILITIES the current user has, else the settings capability.
	 */
	public static function capability(): string {
		foreach ( self::CAPABILITIES as $capability ) {
			if ( current_user_can( $capability ) ) {
				return $capability;
			}
		}
		return Capabilities::MANAGE_SETTINGS;
	}

	/**
	 * Lines with open work for the current user, in the order the modules added them.
	 *
	 * @return array<int, array{count: int, title: string, text: string, url: string, action: string}>
	 */
	public static function items(): array {
		/**
		 * Lines of the overview page. Each line: count (int), title, text (plain text), url, action
		 * (button label) and capability (needed to see the line). Lines with a count of zero are hidden.
		 *
		 * @param array<int, array<string, mixed>> $items Lines.
		 */
		$items = apply_filters( 'eurob2b_overview_items', array() );
		$lines = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_array( $item ) || (int) ( $item['count'] ?? 0 ) <= 0 || ! current_user_can( (string) ( $item['capability'] ?? Capabilities::MANAGE_SETTINGS ) ) ) {
				continue;
			}
			$lines[] = array(
				'count'  => (int) $item['count'],
				'title'  => (string) ( $item['title'] ?? '' ),
				'text'   => (string) ( $item['text'] ?? '' ),
				'url'    => (string) ( $item['url'] ?? '' ),
				'action' => (string) ( $item['action'] ?? _x( 'Open', 'button on the overview page', 'eurob2b' ) ),
			);
		}
		return $lines;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		$lines = self::items();
		PageHeader::open( 'eurob2b-overview', __( 'Overview', 'eurob2b' ) );
		echo '<p class="description">' . esc_html__( 'What is waiting for you. Each line leads to the page where you decide.', 'eurob2b' ) . '</p>';
		echo '<section class="eurob2b-panel" aria-labelledby="eurob2b-overview-todo"><header class="eurob2b-panel__head"><h2 id="eurob2b-overview-todo">' . esc_html__( 'To do', 'eurob2b' ) . '</h2>';
		/* translators: %s: date and time */
		echo '<span class="eurob2b-panel__note">' . esc_html( sprintf( __( 'As of %s', 'eurob2b' ), wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ) ) ) . '</span></header>';
		if ( array() === $lines ) {
			echo '<p class="eurob2b-panel__empty">' . esc_html__( 'Nothing is waiting. New registrations, checks to look at and overdue invoices appear here.', 'eurob2b' ) . '</p>';
		} else {
			echo '<ul class="eurob2b-tasks">';
			foreach ( $lines as $line ) {
				echo '<li><span class="eurob2b-tasks__count">' . (int) $line['count'] . '</span>';
				echo '<div><p class="eurob2b-tasks__title">' . esc_html( $line['title'] ) . '</p>';
				if ( '' !== $line['text'] ) {
					echo '<p class="eurob2b-tasks__text">' . esc_html( $line['text'] ) . '</p>';
				}
				echo '</div>';
				if ( '' !== $line['url'] ) {
					echo '<a class="button" href="' . esc_url( $line['url'] ) . '">' . esc_html( $line['action'] ) . '<span class="screen-reader-text">: ' . esc_html( $line['title'] ) . '</span></a>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</section></div>';
	}
}

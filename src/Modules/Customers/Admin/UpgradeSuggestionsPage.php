<?php
/**
 * Group upgrade suggestions screen.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Customers\Upgrades\UpgradeRule;
use EuroB2B\Modules\Customers\Upgrades\UpgradeSuggestions;
use Throwable;

/**
 * EuroB2B → Group suggestions (GRP-008): per group with an upgrade rule, the customers who reached
 * the threshold, with "Move to …" and "Keep in …" per customer and "Move all" per group.
 */
final class UpgradeSuggestionsPage {

	public const SLUG          = 'group-suggestions';
	public const ACTION        = 'eurob2b_group_suggestion';
	public const NONCE         = 'eurob2b_group_suggestion';
	public const NONCE_REFRESH = 'eurob2b_group_suggestion_refresh';

	/**
	 * Suggestions.
	 *
	 * @var UpgradeSuggestions
	 */
	private UpgradeSuggestions $suggestions;

	/**
	 * Constructor.
	 *
	 * @param UpgradeSuggestions $suggestions Suggestions.
	 */
	public function __construct( UpgradeSuggestions $suggestions ) {
		$this->suggestions = $suggestions;
	}

	/**
	 * Registers the page.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'Group suggestions', 'eurob2b' ), __( 'Group suggestions', 'eurob2b' ), Capabilities::MANAGE_CUSTOMERS, array( $this, 'render' ), 21 );
	}

	/**
	 * Labels of the periods.
	 *
	 * @return array<string, string>
	 */
	public static function period_labels(): array {
		return array(
			UpgradeRule::PERIOD_LAST_12_MONTHS => __( 'the last 12 months', 'eurob2b' ),
			UpgradeRule::PERIOD_PREVIOUS_YEAR  => __( 'the previous calendar year', 'eurob2b' ),
			UpgradeRule::PERIOD_PREVIOUS_MONTH => __( 'the previous month', 'eurob2b' ),
			UpgradeRule::PERIOD_LIFETIME       => __( 'all time', 'eurob2b' ),
		);
	}

	/**
	 * Action handler: move, move all, keep, suggest again.
	 *
	 * @return array{done: int, errors: string[]} Result (the handler redirects; tests read it).
	 */
	public function handle(): array {
		if ( ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage customers.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( (string) $_POST['operation'] ) ) : '';
		$group_id  = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
		$users     = isset( $_POST['user_ids'] ) && is_array( $_POST['user_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['user_ids'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = array(
			'done'   => 0,
			'errors' => array(),
		);
		$entry  = null;
		foreach ( $this->suggestions->rules() as $candidate ) {
			if ( $candidate['source']->id === $group_id ) {
				$entry = $candidate;
			}
		}
		if ( null === $entry ) {
			$result['errors'][] = __( 'This group has no upgrade rule.', 'eurob2b' );
			$this->redirect( $result );
			return $result;
		}
		foreach ( array_unique( array_filter( $users ) ) as $user_id ) {
			if ( ! current_user_can( 'edit_user', $user_id ) ) {
				/* translators: %d: user id */
				$result['errors'][] = sprintf( __( 'You are not allowed to edit user %d.', 'eurob2b' ), $user_id );
				continue;
			}
			try {
				if ( 'move' === $operation ) {
					$this->suggestions->move( $user_id, $entry['source'], $entry['target'], get_current_user_id() );
				} elseif ( 'keep' === $operation || 'release' === $operation ) {
					// A stale page must not set or lift the marker of a customer who left the group meanwhile.
					$this->suggestions->assert_member( $user_id, $entry['source'] );
					UpgradeSuggestions::hold( $user_id, 'keep' === $operation );
				} else {
					continue;
				}
				++$result['done'];
			} catch ( Throwable $e ) {
				$result['errors'][] = $e->getMessage();
			}
		}//end foreach
		$this->suggestions->forget( $entry['source'], $entry['rule'] );
		$this->redirect( $result );
		return $result;
	}

	/**
	 * Back to the page with the result.
	 *
	 * @param array{done: int, errors: string[]} $result Result.
	 */
	private function redirect( array $result ): void {
		$args = array( 'done' => $result['done'] );
		if ( ! empty( $result['errors'] ) ) {
			$args['error'] = implode( ' ', $result['errors'] );
		}
		// wp_safe_redirect() returns false when the "wp_redirect" filter cancels it (tests); then we must not exit.
		if ( wp_safe_redirect( Menu::url( self::SLUG, $args ) ) ) {
			exit;
		}
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage customers.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notices; the recalculation checks its nonce.
		$done  = isset( $_GET['done'] ) ? absint( $_GET['done'] ) : null;
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		$fresh = isset( $_GET['refresh'], $_GET['_wpnonce'] ) && false !== wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), self::NONCE_REFRESH );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap eurob2b-group-suggestions"><h1>' . esc_html__( 'Group suggestions', 'eurob2b' ) . '</h1>';
		if ( null !== $done && $done > 0 ) {
			/* translators: %d: number of customers */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d customer updated.', '%d customers updated.', $done, 'eurob2b' ), $done ) ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Customers whose net sales reached a group\'s threshold. Nothing changes automatically: you move a customer, or keep the customer in the group so that the suggestion does not come back.', 'eurob2b' ) . '</p>';

		$rules = $this->suggestions->rules();
		if ( empty( $rules ) ) {
			echo '<p><em>' . esc_html__( 'No group has an upgrade rule yet. Set one on the group\'s edit screen (EuroB2B → Groups).', 'eurob2b' ) . '</em></p></div>';
			return;
		}
		$generated = 0;
		foreach ( $rules as $entry ) {
			$list      = $this->suggestions->candidates( $entry['source'], $entry['rule'], $fresh );
			$generated = max( $generated, (int) $list['generated_at'] );
			$this->render_rule( $entry['source'], $entry['target'], $entry['rule'], $list['customers'], $list['company_members'] );
		}
		$refresh = wp_nonce_url( Menu::url( self::SLUG, array( 'refresh' => '1' ) ), self::NONCE_REFRESH );
		/* translators: %s: time */
		echo '<p class="description">' . esc_html( sprintf( __( 'Calculated at %s; lists are kept for an hour.', 'eurob2b' ), wp_date( (string) get_option( 'time_format', 'H:i' ), $generated ) ) ) . ' <a href="' . esc_url( $refresh ) . '">' . esc_html__( 'Recalculate now', 'eurob2b' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * One rule with its customers.
	 *
	 * @param Group                                                                 $source    Source group.
	 * @param Group                                                                 $target    Target group.
	 * @param UpgradeRule                                                           $rule      Rule.
	 * @param array<int, array{user_id: int, net: string, orders: int, held: bool}> $customers Candidates.
	 * @param int                                                                   $company   Company members left out.
	 */
	private function render_rule( Group $source, Group $target, UpgradeRule $rule, array $customers, int $company ): void {
		$labels = self::period_labels();
		echo '<h2>' . esc_html( $source->name . ' → ' . $target->name ) . '</h2>';
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: 1: group name, 2: amount, 3: period */
				__( 'Customers of %1$s with net sales of at least %2$s in %3$s.', 'eurob2b' ),
				esc_html( $source->name ),
				wc_price( (float) $rule->threshold ),
				esc_html( $labels[ $rule->period ] ?? $rule->period )
			)
		) . '</p>';
		$open = array_values( array_filter( $customers, static fn( array $c ): bool => ! $c['held'] ) );
		$held = array_values( array_filter( $customers, static fn( array $c ): bool => $c['held'] ) );
		if ( empty( $open ) ) {
			echo '<p>' . esc_html__( 'No suggestions.', 'eurob2b' ) . '</p>';
		} else {
			$this->render_table(
				$source,
				$open,
				array(
					/* translators: %s: group name */
					'move' => sprintf( __( 'Move to %s', 'eurob2b' ), $target->name ),
					/* translators: %s: group name */
					'keep' => sprintf( __( 'Keep in %s', 'eurob2b' ), $source->name ),
				)
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(' . esc_attr( (string) wp_json_encode( __( 'Move all listed customers?', 'eurob2b' ) ) ) . ');">';
			$this->hidden_fields( $source, 'move' );
			foreach ( $open as $customer ) {
				echo '<input type="hidden" name="user_ids[]" value="' . esc_attr( (string) $customer['user_id'] ) . '" />';
			}
			$count = count( $open );
			/* translators: 1: number of customers, 2: group name */
			submit_button( sprintf( _n( 'Move %1$d customer to %2$s', 'Move all %1$d customers to %2$s', $count, 'eurob2b' ), $count, $target->name ), 'secondary', 'submit', false );
			echo '</form>';
		}//end if
		if ( ! empty( $held ) ) {
			echo '<h3>' . esc_html__( 'Kept in the group', 'eurob2b' ) . '</h3>';
			$this->render_table( $source, $held, array( 'release' => __( 'Suggest again', 'eurob2b' ) ) );
		}
		if ( $company > 0 ) {
			/* translators: %d: number of customers */
			echo '<p class="description">' . esc_html( sprintf( _n( '%d member of a company account in this group is not checked: a company\'s group applies to all its members, so an upgrade belongs on the company.', '%d members of company accounts in this group are not checked: a company\'s group applies to all its members, so an upgrade belongs on the company.', $company, 'eurob2b' ), $company ) ) . '</p>';
		}
	}

	/**
	 * Customers table with one form per row.
	 *
	 * @param Group                                                                 $source    Source group.
	 * @param array<int, array{user_id: int, net: string, orders: int, held: bool}> $customers Customers.
	 * @param array<string, string>                                                 $actions   Operation => button label.
	 */
	private function render_table( Group $source, array $customers, array $actions ): void {
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Customer', 'eurob2b' ) . '</th><th scope="col" style="text-align:right">' . esc_html__( 'Net sales', 'eurob2b' ) . '</th><th scope="col" style="text-align:right">' . esc_html__( 'Orders', 'eurob2b' ) . '</th><th scope="col">' . esc_html__( 'Action', 'eurob2b' ) . '</th></tr></thead><tbody>';
		foreach ( $customers as $customer ) {
			$user = get_userdata( $customer['user_id'] );
			$name = false !== $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $customer['user_id'];
			echo '<tr><td><a href="' . esc_url( get_edit_user_link( $customer['user_id'] ) ) . '">' . esc_html( $name ) . '</a></td>';
			echo '<td style="text-align:right;font-variant-numeric:tabular-nums">' . wp_kses_post( wc_price( (float) $customer['net'] ) ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( number_format_i18n( $customer['orders'] ) ) . '</td><td>';
			foreach ( $actions as $operation => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px">';
				$this->hidden_fields( $source, $operation );
				echo '<input type="hidden" name="user_ids[]" value="' . esc_attr( (string) $customer['user_id'] ) . '" />';
				echo '<button type="submit" class="button' . ( 'move' === $operation ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Hidden fields of an action form.
	 *
	 * @param Group  $source    Source group.
	 * @param string $operation Operation.
	 */
	private function hidden_fields( Group $source, string $operation ): void {
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '" />';
		echo '<input type="hidden" name="group_id" value="' . esc_attr( (string) $source->id ) . '" />';
	}
}

<?php
/**
 * User profile: per-user method overrides.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Payments\MethodPolicy;
use WP_User;

/**
 * PAY-003 / SHP-001 (per-user override): "inherit from the audience" or a manual list of methods,
 * for payment gateways and shipping methods. Staff with `eurob2b_manage_customers` only.
 */
final class UserMethodFields {

	/**
	 * Policy.
	 *
	 * @var MethodPolicy
	 */
	private MethodPolicy $policy;

	/**
	 * Methods page (method lists).
	 *
	 * @var MethodsPage
	 */
	private MethodsPage $page;

	/**
	 * Constructor.
	 *
	 * @param MethodPolicy $policy Policy.
	 * @param MethodsPage  $page   Methods page.
	 */
	public function __construct( MethodPolicy $policy, MethodsPage $page ) {
		$this->policy = $policy;
		$this->page   = $page;
	}

	/**
	 * Hooks.
	 */
	public function hooks(): void {
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
	}

	/**
	 * Fields.
	 *
	 * @param mixed $user User.
	 */
	public function render( $user ): void {
		if ( ! $user instanceof WP_User || ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'EuroB2B payment and shipping methods', 'eurob2b' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( array(
			MethodPolicy::KIND_PAYMENT  => array( __( 'Payment gateways', 'eurob2b' ), $this->page->payment_rows() ),
			MethodPolicy::KIND_SHIPPING => array( __( 'Shipping methods', 'eurob2b' ), $this->page->shipping_rows() ),
		) as $kind => [ $label, $rows ] ) {
			$override = $this->policy->user_override( $user->ID, $kind );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			echo '<label><input type="radio" name="' . esc_attr( 'eurob2b_' . $kind . '_mode' ) . '" value="inherit" ' . checked( null === $override, true, false ) . ' /> ' . esc_html__( 'Inherit from the group / customer type', 'eurob2b' ) . '</label><br />';
			echo '<label><input type="radio" name="' . esc_attr( 'eurob2b_' . $kind . '_mode' ) . '" value="manual" ' . checked( null !== $override, true, false ) . ' /> ' . esc_html__( 'Only these:', 'eurob2b' ) . '</label>';
			echo '<div style="margin:.3em 0 0 1.5em">';
			foreach ( $rows as $id => $title ) {
				echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( 'eurob2b_' . $kind . '_methods[]' ) . '" value="' . esc_attr( $id ) . '" ' . checked( null !== $override && in_array( $id, $override, true ), true, false ) . ' /> ' . esc_html( $title ) . ' <code>' . esc_html( $id ) . '</code></label>';
			}
			echo '</div></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Saves the override (WordPress verified the profile nonce).
	 *
	 * @param mixed $user_id User id.
	 */
	public function save( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! current_user_can( Capabilities::MANAGE_CUSTOMERS ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		foreach ( array( MethodPolicy::KIND_PAYMENT, MethodPolicy::KIND_SHIPPING ) as $kind ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- WordPress verified update-user_{id}.
			if ( ! isset( $_POST[ 'eurob2b_' . $kind . '_mode' ] ) ) {
				continue;
			}
			$manual  = 'manual' === sanitize_key( (string) wp_unslash( $_POST[ 'eurob2b_' . $kind . '_mode' ] ) );
			$methods = isset( $_POST[ 'eurob2b_' . $kind . '_methods' ] ) && is_array( $_POST[ 'eurob2b_' . $kind . '_methods' ] ) ? MethodPolicy::ids( array_map( 'sanitize_text_field', wp_unslash( $_POST[ 'eurob2b_' . $kind . '_methods' ] ) ) ) : array();
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$this->policy->set_user_override( $user_id, $kind, $manual ? $methods : null );
		}
	}
}

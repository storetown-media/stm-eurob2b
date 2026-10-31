<?php
/**
 * Admin page: payment and shipping methods per audience.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Payments\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Admin\PageHeader;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Payments\MethodPolicy;
use WC_Shipping_Zone;
use WC_Shipping_Zones;

/**
 * Two matrices (gateways × audiences, shipping methods × audiences) with the default policy for
 * methods that appear later. Audiences are the groups plus guests; a customer without a group
 * falls back to the customer-type column. Below them EuroB2B Pro adds, through the hooks of this
 * page, the shipping cost change per audience (SHP-004), payment terms and the surcharges and
 * discounts per payment method (PAY-018/PAY-019). Capability: `eurob2b_manage_settings`.
 */
final class MethodsPage {

	public const SLUG        = 'methods';
	public const ACTION_SAVE = 'eurob2b_save_methods';
	public const NONCE_SAVE  = 'eurob2b_save_methods';

	/**
	 * Policy.
	 *
	 * @var MethodPolicy
	 */
	private MethodPolicy $policy;

	/**
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Constructor.
	 *
	 * @param MethodPolicy    $policy Policy.
	 * @param GroupRepository $groups Groups.
	 */
	public function __construct( MethodPolicy $policy, GroupRepository $groups ) {
		$this->policy = $policy;
		$this->groups = $groups;
	}

	/**
	 * Menu entry.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'Payment and shipping methods', 'eurob2b' ), __( 'Payment & shipping', 'eurob2b' ), Capabilities::MANAGE_SETTINGS, array( $this, 'render' ), 40 );
	}

	/**
	 * Saves both matrices.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_SAVE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; every value is validated in policy_from_request() / MethodPolicy::normalise().
		$post = wp_unslash( $_POST );
		$post = is_array( $post ) ? $post : array();
		foreach ( array( MethodPolicy::KIND_PAYMENT, MethodPolicy::KIND_SHIPPING ) as $kind ) {
			$this->policy->save( $kind, self::policy_from_request( $kind, $post, $this->method_ids( $kind ), array_keys( $this->audiences() ) ) );
		}
		/**
		 * Saves the further tables of the payment and shipping methods page after the method matrices
		 * were saved (capability and nonce are checked). EuroB2B Pro saves the payment terms, the
		 * shipping costs per group and the surcharges and discounts here (PLT-015). A table that cannot
		 * be saved keeps its previous values and adds an entry; the page then shows the messages.
		 *
		 * @param array<string, string> $failed    Tables that could not be saved (key → error message).
		 * @param array<string, mixed>  $post      Unslashed POST of the form; validate every value.
		 * @param array<string, string> $audiences Audience columns of the page (key → label).
		 */
		$failed = apply_filters( 'eurob2b_methods_page_save', array(), $post, $this->audiences() );
		$failed = is_array( $failed ) ? array_map( static fn( $message ): string => is_scalar( $message ) ? (string) $message : '', $failed ) : array();
		$args   = array() === $failed ? array( 'saved' => 1 ) : array(
			'error'  => implode( ' ', $failed ),
			'failed' => implode( ',', array_keys( $failed ) ),
		);
		if ( wp_safe_redirect( Menu::url( self::SLUG, $args ) ) ) {
			exit;
		}
	}

	/**
	 * Builds a policy from the submitted matrix.
	 *
	 * @param string               $kind      Kind.
	 * @param array<string, mixed> $post      Unslashed POST.
	 * @param string[]             $known     Method ids shown on the screen.
	 * @param string[]             $audiences Audience keys shown on the screen.
	 * @return array<string, mixed>
	 */
	public static function policy_from_request( string $kind, array $post, array $known, array $audiences ): array {
		$matrix   = isset( $post[ $kind ] ) && is_array( $post[ $kind ] ) ? $post[ $kind ] : array();
		$lists    = array();
		$previous = isset( $post[ $kind . '_previous' ] ) && is_array( $post[ $kind . '_previous' ] ) ? $post[ $kind . '_previous' ] : array();
		$retained = array();
		foreach ( $audiences as $key ) {
			$lists[ $key ] = isset( $matrix[ $key ] ) && is_array( $matrix[ $key ] ) ? MethodPolicy::ids( $matrix[ $key ] ) : array();
		}
		// Audiences that are not on the screen any more (deleted groups) keep their stored lists.
		foreach ( $previous as $key => $json ) {
			$list = is_string( $json ) ? json_decode( $json, true ) : null;
			if ( ! isset( $lists[ (string) $key ] ) && is_array( $list ) ) {
				$retained[ (string) $key ] = MethodPolicy::ids( $list );
			}
		}
		return array(
			'default_new' => isset( $post[ $kind . '_default_new' ] ) && MethodPolicy::DEFAULT_BLOCKED === $post[ $kind . '_default_new' ] ? MethodPolicy::DEFAULT_BLOCKED : MethodPolicy::DEFAULT_ALLOWED,
			'known'       => MethodPolicy::ids( $known ),
			'audiences'   => array_merge( $retained, $lists ),
			'non_zone'    => ! empty( $post['shipping_non_zone'] ),
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		PageHeader::open( 'eurob2b-methods', __( 'Payment and shipping methods', 'eurob2b' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'eurob2b' ) . '</p></div>';
		}
		/**
		 * Fires below the heading of the payment and shipping methods page, for notices about the last
		 * save (query arguments `error` and `failed` of eurob2b_methods_page_save). EuroB2B Pro shows
		 * which of its tables kept their previous values (PLT-015).
		 */
		do_action( 'eurob2b_methods_page_notices' );
		echo '<p class="description">' . esc_html__( 'Tick the methods each audience may use at checkout. An audience without any configuration (a column that was never saved) sees every method; once you save, only the ticked methods remain for it. Customers without a group fall back to their customer type. Individual customers can override this on their profile. The admin order editor is never restricted.', 'eurob2b' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';

		$this->matrix( MethodPolicy::KIND_PAYMENT, __( 'Payment gateways', 'eurob2b' ), $this->payment_rows() );
		echo '<p class="description">' . esc_html__( 'Shipping zones stay geographic: WooCommerce picks the zone from the address, EuroB2B then shows each audience only its ticked methods within that zone.', 'eurob2b' ) . '</p>';
		$this->matrix( MethodPolicy::KIND_SHIPPING, __( 'Shipping methods', 'eurob2b' ), $this->shipping_rows() );
		$shipping = $this->policy->load( MethodPolicy::KIND_SHIPPING );
		echo '<p><label><input type="checkbox" name="shipping_non_zone" value="1" ' . checked( $shipping['non_zone'], true, false ) . ' /> ' . esc_html__( 'Also control shipping methods that are not part of a zone (legacy methods and some plugins)', 'eurob2b' ) . '</label></p>';

		/**
		 * Fires inside the form of the payment and shipping methods page, after the method matrices and
		 * before the save button; the fields are saved through eurob2b_methods_page_save. EuroB2B Pro
		 * adds the shipping costs per group, the payment terms and the surcharges and discounts per
		 * payment method (PLT-015).
		 *
		 * @param MethodsPage $page The page (audience columns, payment and shipping method rows).
		 */
		do_action( 'eurob2b_methods_page_sections', $this );
		submit_button( __( 'Save methods', 'eurob2b' ) );
		echo '</form></div>';
	}

	/**
	 * One matrix.
	 *
	 * @param string                $kind  Kind.
	 * @param string                $title Heading.
	 * @param array<string, string> $rows  Method id → label.
	 */
	private function matrix( string $kind, string $title, array $rows ): void {
		$policy    = $this->policy->load( $kind );
		$audiences = $this->audiences();
		echo '<h2>' . esc_html( $title ) . '</h2>';
		foreach ( $policy['audiences'] as $key => $list ) {
			if ( ! isset( $audiences[ $key ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( $kind . '_previous[' . $key . ']' ) . '" value="' . esc_attr( (string) wp_json_encode( $list ) ) . '" />';
			}
		}
		echo '<p><label>' . esc_html__( 'Methods that appear later (new gateway, new zone method):', 'eurob2b' ) . ' <select name="' . esc_attr( $kind . '_default_new' ) . '">';
		echo '<option value="allowed" ' . selected( $policy['default_new'], MethodPolicy::DEFAULT_ALLOWED, false ) . '>' . esc_html__( 'allowed for everyone until you decide', 'eurob2b' ) . '</option>';
		echo '<option value="blocked" ' . selected( $policy['default_new'], MethodPolicy::DEFAULT_BLOCKED, false ) . '>' . esc_html__( 'blocked for configured audiences until you tick them', 'eurob2b' ) . '</option>';
		echo '</select></label></p>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No methods are enabled in WooCommerce.', 'eurob2b' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped eurob2b-method-matrix"><thead><tr><th>' . esc_html__( 'Method', 'eurob2b' ) . '</th>';
		foreach ( $audiences as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $id => $label ) {
			echo '<tr><td>' . esc_html( $label ) . ' <code>' . esc_html( $id ) . '</code></td>';
			foreach ( array_keys( $audiences ) as $key ) {
				$list = $policy['audiences'][ $key ] ?? null;
				if ( null === $list ) {
					$checked = true;
					// Unconfigured audience: everything allowed.
				} elseif ( ! in_array( $id, $policy['known'], true ) ) {
					$checked = MethodPolicy::DEFAULT_ALLOWED === $policy['default_new'];
				} else {
					$checked = in_array( $id, $list, true );
				}
				echo '<td><input type="checkbox" name="' . esc_attr( $kind . '[' . $key . '][]' ) . '" value="' . esc_attr( $id ) . '" ' . checked( $checked, true, false ) . ' /></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Audience columns: groups (B2B first) and guests.
	 *
	 * @return array<string, string>
	 */
	public function audiences(): array {
		$groups = $this->groups->all();
		usort(
			$groups,
			static function ( Group $a, Group $b ): int {
				return strcmp( $a->customer_type->value, $b->customer_type->value ) ?: $b->priority <=> $a->priority ?: strcmp( $a->name, $b->name );
			}
		);
		$out = array();
		foreach ( $groups as $group ) {
			$out[ 'group:' . $group->id ] = $group->name;
		}
		$out['guest'] = __( 'Guests', 'eurob2b' );
		return $out;
	}

	/**
	 * Method ids of a kind as shown on the screen.
	 *
	 * @param string $kind Kind.
	 * @return string[]
	 */
	public function method_ids( string $kind ): array {
		return array_map( 'strval', array_keys( MethodPolicy::KIND_PAYMENT === $kind ? $this->payment_rows() : $this->shipping_rows() ) );
	}

	/**
	 * Enabled gateways (id → title).
	 *
	 * @return array<string, string>
	 */
	public function payment_rows(): array {
		$rows = array();
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return $rows;
		}
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( 'yes' === $gateway->enabled ) {
				$rows[ (string) $gateway->id ] = (string) $gateway->get_method_title();
			}
		}
		return $rows;
	}

	/**
	 * Zone methods (method:instance → "Zone – Method") plus non-zone methods when controlled.
	 *
	 * @return array<string, string>
	 */
	public function shipping_rows(): array {
		$rows = array();
		if ( ! class_exists( WC_Shipping_Zones::class ) ) {
			return $rows;
		}
		$zones   = WC_Shipping_Zones::get_zones();
		$zones[] = array( 'zone_id' => 0 );
		foreach ( $zones as $data ) {
			$zone = new WC_Shipping_Zone( (int) ( $data['zone_id'] ?? 0 ) );
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				$rows[ $method->id . ':' . (int) $method->instance_id ] = $zone->get_zone_name() . ' – ' . $method->get_title();
			}
		}
		if ( $this->policy->load( MethodPolicy::KIND_SHIPPING )['non_zone'] && function_exists( 'WC' ) && null !== WC()->shipping() ) {
			foreach ( WC()->shipping()->get_shipping_methods() as $method ) {
				if ( ! $method->supports( 'shipping-zones' ) && 'yes' === $method->enabled ) {
					$rows[ (string) $method->id ] = $method->get_method_title();
				}
			}
		}
		return $rows;
	}
}

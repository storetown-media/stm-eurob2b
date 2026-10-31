<?php
/**
 * Groups admin page.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Admin\PageHeader;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Customers\Upgrades\UpgradeRule;
use EuroB2B\Support\Currency;
use EuroB2B\Support\Decimal;
use RuntimeException;

/**
 * List, create, edit and delete customer groups.
 *
 * Plain WordPress admin markup (wp-list-table, form-table), POST through
 * admin-post.php with nonce and capability checks, no JavaScript.
 */
final class GroupsPage {

	public const SLUG          = 'groups';
	public const ACTION_SAVE   = 'eurob2b_save_group';
	public const ACTION_DELETE = 'eurob2b_delete_group';
	public const NONCE_SAVE    = 'eurob2b_group_save';
	public const NONCE_DELETE  = 'eurob2b_group_delete';

	/**
	 * Repository.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Constructor.
	 *
	 * @param GroupRepository $groups Repository.
	 */
	public function __construct( GroupRepository $groups ) {
		$this->groups = $groups;
	}

	/**
	 * Registers the page.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'Customer groups', 'eurob2b' ), __( 'Groups', 'eurob2b' ), Capabilities::MANAGE_GROUPS, array( $this, 'render' ), 20 );
	}

	/**
	 * Handles create/update.
	 *
	 * @throws RuntimeException Validation problems; caught below and shown as an error notice.
	 */
	public function handle_save(): void {
		$this->authorise( self::NONCE_SAVE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$id         = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['name'] ) ) : '';
		$slug       = isset( $_POST['slug'] ) ? sanitize_key( (string) wp_unslash( $_POST['slug'] ) ) : '';
		$type       = isset( $_POST['customer_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['customer_type'] ) ) : '';
		$is_default = ! empty( $_POST['is_default'] );
		$priority   = isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 10;
		$upgrade_to = isset( $_POST['upgrade_to'] ) ? absint( $_POST['upgrade_to'] ) : 0;
		$threshold  = isset( $_POST['upgrade_threshold'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['upgrade_threshold'] ) ) : '';
		$period     = isset( $_POST['upgrade_period'] ) ? sanitize_key( wp_unslash( (string) $_POST['upgrade_period'] ) ) : '';
		$currency   = isset( $_POST['currency'] ) ? Currency::normalise( sanitize_text_field( wp_unslash( (string) $_POST['currency'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$customer_type = CustomerType::tryFrom( $type );
		$existing      = $id > 0 ? $this->groups->find( $id ) : null;

		try {
			if ( '' === $name ) {
				throw new RuntimeException( __( 'The group needs a name.', 'eurob2b' ) );
			}
			if ( null === $customer_type || CustomerType::GUEST === $customer_type ) {
				throw new RuntimeException( __( 'Choose whether the group is for business or private customers.', 'eurob2b' ) );
			}
			if ( $id > 0 && null === $existing ) {
				throw new RuntimeException( __( 'The group does not exist.', 'eurob2b' ) );
			}
			if ( null !== $existing && $existing->customer_type !== $customer_type ) {
				throw new RuntimeException( __( 'The customer type of an existing group cannot be changed; create a new group instead.', 'eurob2b' ) );
			}
			if ( null !== $existing && $existing->is_default && ! $is_default ) {
				throw new RuntimeException( __( 'Make another group the default first; every customer type needs one default group.', 'eurob2b' ) );
			}
			if ( '' === $slug ) {
				$slug = Group::slugify( $name );
			}
			if ( ! Currency::group_currencies_enabled() ) {
				// Without EuroB2B Pro the field is not shown: keep a currency stored while Pro was active.
				$currency = null !== $existing ? $existing->currency() : '';
			}
			if ( '' !== $currency && ! Currency::known( $currency ) ) {
				throw new RuntimeException( __( 'Choose a currency WooCommerce knows.', 'eurob2b' ) );
			}
			$rule = $this->upgrade_rule( $id, $customer_type, $upgrade_to, $threshold, $period );

			$group = null !== $existing
				? $existing->with(
					array(
						'name'       => $name,
						'slug'       => $slug,
						'is_default' => $is_default,
						'priority'   => $priority,
						'settings'   => self::with_currency( UpgradeRule::store( $existing->settings, $rule ), $currency ),
					)
				)
				: new Group( 0, $slug, $name, $customer_type, $is_default, $priority, self::with_currency( UpgradeRule::store( array(), $rule ), $currency ) );

			$saved = $this->groups->save( $group );
			$this->redirect( array( 'saved' => $saved->id ) );
		} catch ( \Throwable $e ) {
			$this->redirect(
				array(
					'error' => $e->getMessage(),
					'edit'  => $id,
				)
			);
		}//end try
	}

	/**
	 * Handles delete with member reassignment.
	 */
	public function handle_delete(): void {
		$this->authorise( self::NONCE_DELETE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$id     = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
		$target = isset( $_POST['target_group_id'] ) ? absint( $_POST['target_group_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		try {
			$this->groups->delete( $id, $target );
			$this->redirect( array( 'deleted' => '1' ) );
		} catch ( \Throwable $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Renders list or edit form.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_GROUPS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage customer groups.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : null;
		$error   = isset( $_GET['error'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['error'] ) ) : '';
		$saved   = isset( $_GET['saved'] );
		$deleted = isset( $_GET['deleted'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		PageHeader::open(
			'eurob2b-groups',
			__( 'Customer groups', 'eurob2b' ),
			null === $edit_id ? '<a class="button" href="' . esc_url( Menu::url( self::SLUG, array( 'edit' => 0 ) ) ) . '">' . esc_html__( 'Add group', 'eurob2b' ) . '</a>' : ''
		);

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Group saved.', 'eurob2b' ) . '</p></div>';
		}
		if ( $deleted ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Group deleted; its members were moved.', 'eurob2b' ) . '</p></div>';
		}

		if ( null !== $edit_id ) {
			$this->render_form( $edit_id > 0 ? $this->groups->find( $edit_id ) : null );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	/**
	 * Renders the table of groups.
	 */
	private function render_list(): void {
		$counts = $this->groups->member_counts();
		$all    = $this->groups->all();

		echo '<p class="description">' . esc_html__( 'Groups are private: customers never see group names. Prices, rules, visibility and payment methods attach to groups. Each customer type has exactly one default group for newly approved customers.', 'eurob2b' ) . '</p>';
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'eurob2b' ) . '</th><th>' . esc_html__( 'Slug', 'eurob2b' ) . '</th><th>' . esc_html__( 'Customer type', 'eurob2b' ) . '</th><th>' . esc_html__( 'Default', 'eurob2b' ) . '</th><th>' . esc_html__( 'Priority', 'eurob2b' ) . '</th><th>' . esc_html__( 'Currency', 'eurob2b' ) . '</th><th>' . esc_html__( 'Members', 'eurob2b' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $all ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No groups yet.', 'eurob2b' ) . '</td></tr>';
		}

		foreach ( $all as $group ) {
			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( Menu::url( self::SLUG, array( 'edit' => $group->id ) ) ) . '">' . esc_html( $group->name ) . '</a></strong></td>';
			echo '<td><code>' . esc_html( $group->slug ) . '</code></td>';
			echo '<td>' . esc_html( self::type_label( $group->customer_type ) ) . '</td>';
			echo '<td>' . ( $group->is_default ? esc_html__( 'Yes', 'eurob2b' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $group->priority ) . '</td>';
			echo '<td>' . esc_html( Currency::code( Currency::of_group( $group ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $counts[ $group->id ] ?? 0 ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders the create/edit form and, for existing non-default groups, the delete form.
	 *
	 * @param Group|null $group Group to edit, null for a new one.
	 */
	private function render_form( ?Group $group ): void {
		echo '<h2>' . ( null === $group ? esc_html__( 'Add group', 'eurob2b' ) : esc_html__( 'Edit group', 'eurob2b' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';
		echo '<input type="hidden" name="group_id" value="' . esc_attr( (string) ( $group->id ?? 0 ) ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="eurob2b-group-name">' . esc_html__( 'Name', 'eurob2b' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="eurob2b-group-name" name="name" required value="' . esc_attr( $group->name ?? '' ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="eurob2b-group-slug">' . esc_html__( 'Slug', 'eurob2b' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="eurob2b-group-slug" name="slug" value="' . esc_attr( $group->slug ?? '' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Lower-case identifier used in imports and code. Leave empty to derive it from the name.', 'eurob2b' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="eurob2b-group-type">' . esc_html__( 'Customer type', 'eurob2b' ) . '</label></th><td>';
		if ( null === $group ) {
			echo '<select id="eurob2b-group-type" name="customer_type">';
			foreach ( array( CustomerType::B2B, CustomerType::B2C ) as $type ) {
				echo '<option value="' . esc_attr( $type->value ) . '">' . esc_html( self::type_label( $type ) ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="hidden" name="customer_type" value="' . esc_attr( $group->customer_type->value ) . '" />';
			echo '<strong>' . esc_html( self::type_label( $group->customer_type ) ) . '</strong>';
			echo '<p class="description">' . esc_html__( 'The customer type of an existing group cannot be changed.', 'eurob2b' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Default group', 'eurob2b' ) . '</th><td>';
		echo '<label><input type="checkbox" name="is_default" value="1" ' . checked( (bool) ( $group->is_default ?? false ), true, false ) . ' /> ' . esc_html__( 'Newly approved customers of this type land in this group', 'eurob2b' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="eurob2b-group-priority">' . esc_html__( 'Priority', 'eurob2b' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="eurob2b-group-priority" name="priority" value="' . esc_attr( (string) ( $group->priority ?? 10 ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Higher wins when settings of several groups could apply.', 'eurob2b' ) . '</p></td></tr>';

		$this->render_currency_field( $group );
		$this->render_upgrade_fields( $group );

		echo '</tbody></table>';
		submit_button( null === $group ? __( 'Create group', 'eurob2b' ) : __( 'Save group', 'eurob2b' ) );
		echo ' <a class="button" href="' . esc_url( Menu::url( self::SLUG ) ) . '">' . esc_html__( 'Back to the list', 'eurob2b' ) . '</a>';
		echo '</form>';

		if ( null !== $group && ! $group->is_default ) {
			$targets = array_filter( $this->groups->of_type( $group->customer_type ), static fn( Group $g ): bool => $g->id !== $group->id );
			echo '<h2>' . esc_html__( 'Delete group', 'eurob2b' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(' . esc_attr( wp_json_encode( __( 'Delete this group and move its members?', 'eurob2b' ) ) ) . ');">';
			wp_nonce_field( self::NONCE_DELETE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE ) . '" />';
			echo '<input type="hidden" name="group_id" value="' . esc_attr( (string) $group->id ) . '" />';
			echo '<p class="description">' . esc_html__( 'The group prices, tier prices and quantity limits of this group are removed from all products.', 'eurob2b' ) . '</p>';
			echo '<p><label for="eurob2b-group-target">' . esc_html__( 'Move members to', 'eurob2b' ) . '</label> ';
			echo '<select id="eurob2b-group-target" name="target_group_id">';
			foreach ( $targets as $target ) {
				echo '<option value="' . esc_attr( (string) $target->id ) . '">' . esc_html( $target->name ) . '</option>';
			}
			echo '</select> ';
			submit_button( __( 'Delete group', 'eurob2b' ), 'delete', 'submit', false );
			echo '</p></form>';
		}
	}

	/**
	 * Currency field (CUR-001).
	 *
	 * @param Group|null $group Group being edited, null for a new one.
	 */
	private function render_currency_field( ?Group $group ): void {
		if ( ! Currency::group_currencies_enabled() ) {
			return;
		}
		$current = null !== $group ? $group->currency() : '';
		echo '<tr><th scope="row"><label for="eurob2b-group-currency">' . esc_html__( 'Currency', 'eurob2b' ) . '</label></th><td>';
		echo '<select id="eurob2b-group-currency" name="currency">';
		/* translators: %s: currency code of the shop, e.g. EUR */
		echo '<option value="">' . esc_html( sprintf( __( 'Shop currency (%s)', 'eurob2b' ), Currency::shop() ) ) . '</option>';
		foreach ( get_woocommerce_currencies() as $code => $label ) {
			if ( Currency::is_shop( (string) $code ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) $code ) . '" ' . selected( $current, (string) $code, false ) . '>' . esc_html( $code . ' – ' . $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Customers of this group see and pay in this currency, and their orders are stored in it. Nothing is converted: enter the group prices on the products in this currency. The shop\'s own prices, and rules with amounts in another currency, do not apply to the group; a product without a price in this currency shows "Price on request" and cannot be bought by the group. Offer only payment methods that accept the currency (Payment & shipping). After a change, prices in the previous currency stay stored but no longer apply.', 'eurob2b' ) . '</p></td></tr>';
	}

	/**
	 * Group settings with the currency set ('' or the shop currency removes it).
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $currency Normalised code or ''.
	 * @return array<string, mixed>
	 */
	private static function with_currency( array $settings, string $currency ): array {
		unset( $settings['currency'] );
		if ( '' !== $currency && ! Currency::is_shop( $currency ) ) {
			$settings['currency'] = $currency;
		}
		return $settings;
	}

	/**
	 * The upgrade rule of the form (GRP-008), or null when no target group was chosen.
	 *
	 * @param int          $group_id  Group id (0 for a new group).
	 * @param CustomerType $type      Customer type of the group.
	 * @param int          $target_id Chosen target group id.
	 * @param string       $threshold Entered threshold.
	 * @param string       $period    Chosen period.
	 * @throws RuntimeException When the rule cannot be used.
	 */
	private function upgrade_rule( int $group_id, CustomerType $type, int $target_id, string $threshold, string $period ): ?UpgradeRule {
		if ( 0 === $target_id ) {
			return null;
		}
		$target = $this->groups->find( $target_id );
		if ( null === $target || $target->id === $group_id || $target->customer_type !== $type ) {
			throw new RuntimeException( __( 'Upgrade suggestions need a target group of the same customer type.', 'eurob2b' ) );
		}
		$amount = str_replace( array( ' ', ',' ), array( '', '.' ), trim( $threshold ) );
		// Checked after rounding to the shop's decimals: "0.001" would otherwise be stored as "0.00" and
		// make every member a candidate. No float on the way (AGENTS.md rule 8).
		$amount = 1 === preg_match( '/^\d+(\.\d+)?$/', $amount ) ? Decimal::round( $amount, max( 0, min( 4, (int) wc_get_price_decimals() ) ) ) : '';
		if ( '' === $amount || Decimal::compare( $amount, '0' ) <= 0 ) {
			throw new RuntimeException( __( 'Please enter the net sales a customer needs for the upgrade suggestion.', 'eurob2b' ) );
		}
		if ( ! in_array( $period, UpgradeRule::periods(), true ) ) {
			$period = UpgradeRule::PERIOD_LAST_12_MONTHS;
		}
		return new UpgradeRule( $target->id, $amount, $period );
	}

	/**
	 * Form rows of the upgrade suggestion (GRP-008).
	 *
	 * @param Group|null $group Group being edited.
	 */
	private function render_upgrade_fields( ?Group $group ): void {
		$rule    = null !== $group ? UpgradeRule::of( $group ) : null;
		$targets = array();
		foreach ( $this->groups->all() as $candidate ) {
			if ( null === $group || ( $candidate->id !== $group->id && $candidate->customer_type === $group->customer_type ) ) {
				$targets[] = $candidate;
			}
		}
		echo '<tr><th scope="row"><label for="eurob2b-group-upgrade-to">' . esc_html__( 'Upgrade suggestion', 'eurob2b' ) . '</label></th><td>';
		echo '<select id="eurob2b-group-upgrade-to" name="upgrade_to"><option value="0">' . esc_html__( 'None', 'eurob2b' ) . '</option>';
		foreach ( $targets as $target ) {
			echo '<option value="' . esc_attr( (string) $target->id ) . '"' . selected( null !== $rule && $rule->target_id === $target->id, true, false ) . '>' . esc_html( $target->name ) . '</option>';
		}
		echo '</select> ';
		echo '<label for="eurob2b-group-upgrade-threshold">' . esc_html__( 'from net sales of', 'eurob2b' ) . '</label> ';
		echo '<input type="text" inputmode="decimal" class="small-text" id="eurob2b-group-upgrade-threshold" name="upgrade_threshold" value="' . esc_attr( null !== $rule ? wc_format_localized_price( $rule->threshold ) : '' ) . '" /> ';
		echo '<label for="eurob2b-group-upgrade-period">' . esc_html__( 'in', 'eurob2b' ) . '</label> ';
		echo '<select id="eurob2b-group-upgrade-period" name="upgrade_period">';
		foreach ( UpgradeSuggestionsPage::period_labels() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( null !== $rule ? $rule->period : UpgradeRule::PERIOD_LAST_12_MONTHS, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Customers of this group who reach the net sales (without tax and shipping, after refunds, paid orders) are listed under EuroB2B → Customers → Group suggestions. Nothing moves automatically. The target group must have the same customer type; for a new group, choose it after saving.', 'eurob2b' ) . '</p></td></tr>';
	}

	/**
	 * Checks capability and nonce for POST handlers.
	 *
	 * @param string $nonce Nonce action.
	 */
	private function authorise( string $nonce ): void {
		if ( ! current_user_can( Capabilities::MANAGE_GROUPS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage customer groups.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Redirects back to the page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function redirect( array $args ): void {
		// wp_safe_redirect() returns false when the "wp_redirect" filter cancels it (tests); then we must not exit.
		if ( wp_safe_redirect( Menu::url( self::SLUG, $args ) ) ) {
			exit;
		}
	}

	/**
	 * Label for a customer type.
	 *
	 * @param CustomerType $type Type.
	 */
	public static function type_label( CustomerType $type ): string {
		return match ( $type ) {
			CustomerType::B2B => __( 'Business (B2B)', 'eurob2b' ),
			CustomerType::B2C => __( 'Private (B2C)', 'eurob2b' ),
			CustomerType::GUEST => __( 'Guest', 'eurob2b' ),
		};
	}
}

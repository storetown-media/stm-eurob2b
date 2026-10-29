<?php
/**
 * Admin page: rules.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Rules\Admin;

use DateTimeImmutable;
use DateTimeZone;
use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Rules\Condition;
use EuroB2B\Modules\Rules\Rule;
use EuroB2B\Modules\Rules\RuleRepository;
use EuroB2B\Modules\Rules\RuleSupport;
use EuroB2B\Modules\Rules\Scope;
use EuroB2B\Support\Assets;
use EuroB2B\Support\Currency;
use EuroB2B\Support\Money;
use InvalidArgumentException;
use RuntimeException;
use WC_Product;

/**
 * List, create, edit and delete rules. Products, variations, categories and customers are chosen
 * with WooCommerce's search pickers (ids are never typed). Capability: `eurob2b_manage_rules`.
 *
 * The core edits percentage discounts (PLT-015). EuroB2B Pro adds its rule types
 * (`eurob2b_rule_types`), the fixed amount (`eurob2b_rules_amount_modes`), its form rows
 * (`eurob2b_rules_form_rows`), the payloads of its types (`eurob2b_rules_action_from_request`) and
 * its descriptions (`eurob2b_rules_page_descriptions`). The form is validated the same way with and
 * without it; a rule that needs EuroB2B Pro is refused, never saved without its parts, and listed
 * without a link to edit it while EuroB2B Pro is missing (`RuleSupport`).
 */
final class RulesPage {

	public const SLUG          = 'rules';
	public const ACTION_SAVE   = 'eurob2b_save_rule';
	public const ACTION_DELETE = 'eurob2b_delete_rule';
	public const NONCE_SAVE    = 'eurob2b_save_rule';
	public const NONCE_DELETE  = 'eurob2b_delete_rule';

	/**
	 * Positions of the form rows other modules add (`eurob2b_rules_form_rows`): after the amount,
	 * after the scope.
	 */
	public const ROWS_ACTION = 'action';
	public const ROWS_SCOPE  = 'scope';

	public const AUDIENCE_TOKENS = array( 'all', 'registered', 'b2b', 'b2c', 'guest' );

	/**
	 * Rules.
	 *
	 * @var RuleRepository
	 */
	private RuleRepository $rules;

	/**
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Constructor.
	 *
	 * @param RuleRepository  $rules  Rules.
	 * @param GroupRepository $groups Groups (audience picker).
	 */
	public function __construct( RuleRepository $rules, GroupRepository $groups ) {
		$this->rules  = $rules;
		$this->groups = $groups;
	}

	/**
	 * Menu entry.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'Rules', 'eurob2b' ), __( 'Rules', 'eurob2b' ), Capabilities::MANAGE_RULES, array( $this, 'render' ), 25 );
	}

	/**
	 * Loads WooCommerce's enhanced selects and the form script on this page only.
	 */
	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection.
		if ( ! isset( $_GET['page'] ) || Menu::page_slug( self::SLUG ) !== sanitize_key( (string) wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'eurob2b-admin-rules', EUROB2B_PLUGIN_URL . 'assets/js/admin-rules.js', array( 'jquery' ), Assets::version( 'assets/js/admin-rules.js' ), true );
	}

	/**
	 * Handles create/update.
	 *
	 * @throws RuntimeException Validation problem; caught below and shown as a notice.
	 */
	public function handle_save(): void {
		$this->authorise( self::NONCE_SAVE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
			$rule  = $this->rule_from_request( $id, wp_unslash( $_POST ) );
			$saved = $this->rules->save( $rule );
			$this->redirect( array( 'saved' => $saved->id ) );
		} catch ( \Throwable $e ) {
			$this->redirect(
				array(
					'error' => $e->getMessage(),
					'edit'  => $id,
				)
			);
		}
	}

	/**
	 * Handles delete.
	 *
	 * @throws RuntimeException Unknown rule; caught below and shown as a notice.
	 */
	public function handle_delete(): void {
		$this->authorise( self::NONCE_DELETE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
		try {
			if ( null === $this->rules->find( $id ) ) {
				throw new RuntimeException( __( 'The rule does not exist.', 'eurob2b' ) );
			}
			$this->rules->delete( $id );
			$this->redirect( array( 'deleted' => 1 ) );
		} catch ( \Throwable $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Builds a rule from the submitted form (sanitised and validated).
	 *
	 * @param int                  $id   Existing id or 0.
	 * @param array<string, mixed> $post Unslashed POST data.
	 * @throws RuntimeException Validation message for the admin.
	 * @throws InvalidArgumentException Rethrown as a RuntimeException with a readable message.
	 */
	public function rule_from_request( int $id, array $post ): Rule {
		$existing = $id > 0 ? $this->rules->find( $id ) : null;
		if ( $id > 0 && null === $existing ) {
			throw new RuntimeException( __( 'The rule does not exist.', 'eurob2b' ) );
		}
		// A stored rule with parts of EuroB2B Pro is never overwritten without them.
		if ( null !== $existing && ! self::editable( $existing ) ) {
			throw new RuntimeException( self::needs_pro_message() );
		}
		$name = isset( $post['name'] ) ? sanitize_text_field( (string) $post['name'] ) : '';
		if ( '' === $name ) {
			throw new RuntimeException( __( 'The rule needs a name.', 'eurob2b' ) );
		}
		$type = isset( $post['type'] ) ? sanitize_key( (string) $post['type'] ) : '';
		if ( ! array_key_exists( $type, Rule::types() ) ) {
			throw new RuntimeException( in_array( $type, RuleSupport::EXTENDED_TYPES, true ) ? self::needs_pro_message() : __( 'Choose a rule type.', 'eurob2b' ) );
		}

		$audience = array();
		foreach ( self::ids_or_keys( $post['audience_tokens'] ?? array(), 'key' ) as $token ) {
			if ( in_array( $token, self::AUDIENCE_TOKENS, true ) ) {
				$audience[] = $token;
			}
		}
		foreach ( self::ids_or_keys( $post['audience_groups'] ?? array(), 'int' ) as $group_id ) {
			$audience[] = 'group:' . $group_id;
		}
		foreach ( self::ids_or_keys( $post['audience_users'] ?? array(), 'int' ) as $user_id ) {
			$audience[] = 'user:' . $user_id;
		}
		if ( empty( $audience ) ) {
			throw new RuntimeException( __( 'Choose at least one audience.', 'eurob2b' ) );
		}

		$kind = isset( $post['scope_kind'] ) ? sanitize_key( (string) $post['scope_kind'] ) : Scope::ALL;
		// A payment window measures the whole cart or the order being paid; it has no product scope.
		if ( Rule::TYPE_PAYMENT_WINDOW === $type ) {
			$kind = Scope::ALL;
		}
		if ( ! in_array( $kind, Scope::KINDS, true ) ) {
			throw new RuntimeException( __( 'Choose a scope.', 'eurob2b' ) );
		}
		$scope_ids = array();
		if ( Scope::PRODUCTS === $kind || Scope::VARIATIONS === $kind ) {
			$scope_ids = self::ids_or_keys( $post['scope_products'] ?? array(), 'int' );
		} elseif ( Scope::CATEGORIES === $kind ) {
			$scope_ids = self::ids_or_keys( $post['scope_categories'] ?? array(), 'int' );
		} elseif ( Scope::TAGS === $kind ) {
			$scope_ids = self::ids_or_keys( $post['scope_tags'] ?? array(), 'int' );
		}
		if ( Scope::ALL !== $kind && empty( $scope_ids ) ) {
			throw new RuntimeException( __( 'Choose at least one product, category or tag for the scope.', 'eurob2b' ) );
		}

		$conditions = array();
		$rows       = isset( $post['cond'] ) && is_array( $post['cond'] ) ? $post['cond'] : array();
		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$ctype = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';
			if ( '' === $ctype ) {
				continue;
			}
			$operator = isset( $row['operator'] ) ? sanitize_key( (string) $row['operator'] ) : '';
			$value    = isset( $row['value'] ) ? self::decimal( (string) $row['value'] ) : '';
			$values   = array();
			if ( Condition::BILLING_COUNTRY === $ctype || Condition::SHIPPING_COUNTRY === $ctype ) {
				$values = array_map( 'strtoupper', self::ids_or_keys( $row['countries'] ?? array(), 'text' ) );
			} elseif ( Condition::PAYMENT_METHOD === $ctype ) {
				$values = self::ids_or_keys( $row['payment'] ?? array(), 'text' );
			} elseif ( Condition::SHIPPING_METHOD === $ctype ) {
				$values = self::ids_or_keys( $row['shipping'] ?? array(), 'text' );
			}
			$terms = Condition::CATEGORY_QUANTITY === $ctype ? self::ids_or_keys( $row['terms'] ?? array(), 'int' ) : array();
			try {
				if ( in_array( $ctype, Condition::LIST_TYPES, true ) && empty( $values ) ) {
					throw new InvalidArgumentException( 'empty list' );
				}
				if ( Condition::CATEGORY_QUANTITY === $ctype && empty( $terms ) ) {
					throw new InvalidArgumentException( 'no categories' );
				}
				$conditions[] = new Condition( $ctype, $operator, $value, $values, $terms );
			} catch ( InvalidArgumentException $e ) {
				/* translators: %d: condition row number */
				throw new RuntimeException( sprintf( __( 'Condition %d is incomplete.', 'eurob2b' ), $index + 1 ) );
			}
		}//end foreach
		$any = isset( $post['condition_match'] ) && 'any' === $post['condition_match'];
		// A fee that depends on the payment method is a payment surcharge: restricted by law (Germany:
		// § 270a BGB), so it needs its own setting (PAY-018/PAY-019) instead of a plain fee rule.
		if ( Rule::TYPE_FEE === $type ) {
			foreach ( $conditions as $condition ) {
				if ( Condition::PAYMENT_METHOD === $condition->type ) {
					throw new RuntimeException( __( 'A fee cannot depend on the payment method: surcharges for payment methods are restricted by law (Germany: § 270a BGB) and are not part of fee rules.', 'eurob2b' ) );
				}
			}
		}
		// Shipping rates are calculated before the customer chooses a payment or shipping method.
		if ( Rule::TYPE_FREE_SHIPPING === $type ) {
			foreach ( $conditions as $condition ) {
				if ( in_array( $condition->type, array( Condition::PAYMENT_METHOD, Condition::SHIPPING_METHOD ), true ) ) {
					throw new RuntimeException( __( 'Free shipping cannot depend on the payment or shipping method: shipping rates are calculated before the customer chooses them.', 'eurob2b' ) );
				}
			}
		}

		$starts = self::date_from_input( isset( $post['starts_at'] ) ? (string) $post['starts_at'] : '' );
		$ends   = self::date_from_input( isset( $post['ends_at'] ) ? (string) $post['ends_at'] : '' );
		if ( null !== $starts && null !== $ends && $ends <= $starts ) {
			throw new RuntimeException( __( 'The end of the date window must be after its start.', 'eurob2b' ) );
		}

		$action = $this->action_from_request( $type, $post );
		/**
		 * Filters the type-specific payload of a rule built from the rules form, the import or the
		 * REST API (EuroB2B Pro fills in its rule types). Throw a RuntimeException with a message
		 * for the admin to refuse the values.
		 *
		 * @param array<string, mixed> $action Payload (the discount's, or empty for other types).
		 * @param string               $type   Rule type.
		 * @param array<string, mixed> $post   Unslashed form values.
		 */
		$filtered = apply_filters( 'eurob2b_rules_action_from_request', $action, $type, $post );
		$action   = is_array( $filtered ) ? $filtered : $action;
		// Currency of the amounts (CUR-001): absent for the shop currency, so existing rules keep theirs.
		$currency = Currency::normalise( $post['action_currency'] ?? '' );
		if ( '' !== $currency && ! Currency::is_shop( $currency ) ) {
			if ( ! Currency::known( $currency ) ) {
				throw new RuntimeException( __( 'Choose a currency WooCommerce knows.', 'eurob2b' ) );
			}
			$action['currency'] = $currency;
		}

		$rule = new Rule(
			$id,
			$type,
			$name,
			! empty( $post['enabled'] ),
			isset( $post['priority'] ) ? (int) $post['priority'] : 10,
			array_values( array_unique( $audience ) ),
			new Scope( $kind, Scope::ALL === $kind ? array() : $scope_ids ),
			$conditions,
			$any,
			$action,
			$starts,
			$ends
		);
		// Without EuroB2B Pro a fixed amount, a condition or a discount line is refused, not dropped.
		if ( ! RuleSupport::supported( $rule ) ) {
			throw new RuntimeException( self::needs_pro_message() );
		}
		return $rule;
	}

	/**
	 * Why a rule is refused or cannot be edited without EuroB2B Pro.
	 */
	public static function needs_pro_message(): string {
		return __( 'This rule needs EuroB2B Pro: its type, a fixed amount, a condition or a discount line in the cart.', 'eurob2b' );
	}

	/**
	 * Whether the page may open the rule for editing: next to EuroB2B Pro every rule (as before),
	 * without it a rule of an offered type that needs nothing of EuroB2B Pro, so saving never drops
	 * a part of it.
	 *
	 * @param Rule $rule Rule.
	 */
	public static function editable( Rule $rule ): bool {
		return RuleSupport::extended() || ( array_key_exists( $rule->type, Rule::types() ) && ! RuleSupport::needs_extension( $rule ) );
	}

	/**
	 * Type-specific payload.
	 *
	 * @param string               $type Rule type.
	 * @param array<string, mixed> $post Unslashed POST data.
	 * @return array<string, mixed>
	 * @throws RuntimeException Validation message.
	 */
	private function action_from_request( string $type, array $post ): array {
		switch ( $type ) {
			case Rule::TYPE_DISCOUNT:
			case Rule::TYPE_MARKUP:
				$mode   = isset( $post['action_mode'] ) && 'fixed' === $post['action_mode'] ? 'fixed' : 'percent';
				$amount = self::decimal( isset( $post['action_amount'] ) ? (string) $post['action_amount'] : '' );
				if ( '' === $amount || 1 !== preg_match( '/^\d+(\.\d+)?$/', $amount ) || 0 === (int) str_replace( '.', '', $amount ) ) {
					throw new RuntimeException( __( 'Enter a positive amount.', 'eurob2b' ) );
				}
				if ( 'percent' === $mode ) {
					self::assert_percent_precision( $amount );
				}
				$payload = array(
					'mode'   => $mode,
					'amount' => $amount,
				);
				// PRC-018: stored only for a discount line in the cart, so existing rules keep their payload.
				if ( Rule::TYPE_DISCOUNT === $type && isset( $post['action_display'] ) && Rule::DISPLAY_CART === $post['action_display'] ) {
					$label = isset( $post['action_cart_label'] ) ? trim( sanitize_text_field( (string) $post['action_cart_label'] ) ) : '';
					if ( '' === $label ) {
						throw new RuntimeException( __( 'Enter the name of the discount line in the cart.', 'eurob2b' ) );
					}
					if ( mb_strlen( $label ) > Rule::MAX_CART_LABEL ) {
						/* translators: %d: maximum number of characters */
						throw new RuntimeException( sprintf( __( 'The name of the discount line may have at most %d characters.', 'eurob2b' ), Rule::MAX_CART_LABEL ) );
					}
					$payload['display'] = Rule::DISPLAY_CART;
					$payload['label']   = $label;
				}
				return $payload;
		}//end switch
		return array();
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_RULES ) ) {
			wp_die( esc_html__( 'You are not allowed to manage rules.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : null;
		$error   = isset( $_GET['error'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['error'] ) ) : '';
		$saved   = isset( $_GET['saved'] );
		$deleted = isset( $_GET['deleted'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap eurob2b-rules">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Rules', 'eurob2b' ) . '</h1>';
		if ( null === $edit_id ) {
			echo ' <a href="' . esc_url( Menu::url( self::SLUG, array( 'edit' => 0 ) ) ) . '" class="page-title-action">' . esc_html__( 'Add rule', 'eurob2b' ) . '</a>';
		}
		echo '<hr class="wp-header-end" />';
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule saved.', 'eurob2b' ) . '</p></div>';
		}
		if ( $deleted ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule deleted.', 'eurob2b' ) . '</p></div>';
		}
		if ( null !== $edit_id ) {
			$this->render_form( $edit_id > 0 ? $this->rules->find( $edit_id ) : null );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	/**
	 * The table of rules.
	 */
	private function render_list(): void {
		$all   = $this->rules->all();
		$types = Rule::types();
		echo '<p class="description">' . esc_html( self::descriptions()['list'] ) . '</p>';
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'eurob2b' ), __( 'Type', 'eurob2b' ), __( 'Scope', 'eurob2b' ), __( 'Audience', 'eurob2b' ), __( 'Conditions', 'eurob2b' ), __( 'Window', 'eurob2b' ), __( 'Priority', 'eurob2b' ), __( 'Enabled', 'eurob2b' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( empty( $all ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No rules yet.', 'eurob2b' ) . '</td></tr>';
		}
		foreach ( $all as $rule ) {
			echo '<tr>';
			if ( self::editable( $rule ) ) {
				echo '<td><strong><a href="' . esc_url( Menu::url( self::SLUG, array( 'edit' => $rule->id ) ) ) . '">' . esc_html( $rule->name ) . '</a></strong></td>';
			} else {
				echo '<td><strong>' . esc_html( $rule->name ) . '</strong><br /><span class="description">' . esc_html( self::needs_pro_note() ) . '</span></td>';
			}
			echo '<td>' . esc_html( ( $types[ $rule->type ] ?? $rule->type ) . ( '' !== $rule->currency() ? ' (' . $rule->currency() . ')' : '' ) . ( Rule::DISPLAY_CART === $rule->display() ? ' – ' . __( 'discount line in the cart', 'eurob2b' ) : '' ) ) . '</td>';
			echo '<td>' . esc_html( self::scope_summary( $rule->scope ) ) . '</td>';
			echo '<td>' . esc_html( $this->audience_summary( $rule->audience ) ) . '</td>';
			echo '<td>' . esc_html( (string) count( $rule->conditions ) ) . '</td>';
			echo '<td>' . esc_html( self::window_summary( $rule ) ) . '</td>';
			echo '<td>' . esc_html( (string) $rule->priority ) . '</td>';
			echo '<td>' . ( $rule->enabled ? esc_html__( 'Yes', 'eurob2b' ) : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Create/edit form.
	 *
	 * @param Rule|null $rule Rule or null for a new one.
	 */
	private function render_form( ?Rule $rule ): void {
		if ( null !== $rule && ! self::editable( $rule ) ) {
			echo '<h2>' . esc_html( $rule->name ) . '</h2>';
			echo '<div class="notice notice-warning inline"><p>' . esc_html( self::needs_pro_note() ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( Menu::url( self::SLUG ) ) . '">' . esc_html__( 'Back to the list', 'eurob2b' ) . '</a></p>';
			$this->render_delete( $rule );
			return;
		}
		$types   = Rule::types();
		$type    = $rule->type ?? Rule::TYPE_DISCOUNT;
		$scope   = $rule->scope ?? Scope::all();
		$tokens  = $rule->audience ?? array( 'b2b' );
		$groups  = array_map( static fn( string $t ): int => (int) substr( $t, 6 ), array_filter( $tokens, static fn( string $t ): bool => str_starts_with( $t, 'group:' ) ) );
		$users   = array_map( static fn( string $t ): int => (int) substr( $t, 5 ), array_filter( $tokens, static fn( string $t ): bool => str_starts_with( $t, 'user:' ) ) );
		$action  = $rule->action ?? array();
		$tz      = wp_timezone();
		$to_lcl  = static fn( ?DateTimeImmutable $d ): string => null === $d ? '' : $d->setTimezone( $tz )->format( 'Y-m-d\TH:i' );
		$product = static fn( int $id ): string => self::product_name( $id );

		echo '<h2>' . ( null === $rule ? esc_html__( 'Add rule', 'eurob2b' ) : esc_html__( 'Edit rule', 'eurob2b' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eurob2b-rule-form">';
		wp_nonce_field( self::NONCE_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';
		echo '<input type="hidden" name="rule_id" value="' . esc_attr( (string) ( $rule->id ?? 0 ) ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::row( 'name', __( 'Name', 'eurob2b' ), '<input type="text" class="regular-text" id="eurob2b-rule-name" name="name" required value="' . esc_attr( $rule->name ?? '' ) . '" />', __( 'Internal; customers never see it.', 'eurob2b' ) );

		$type_select = '<select id="eurob2b-rule-type" name="type">';
		foreach ( $types as $key => $label ) {
			$type_select .= '<option value="' . esc_attr( $key ) . '" ' . selected( $type, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$type_select .= '</select>';
		self::row( 'type', __( 'Type', 'eurob2b' ), $type_select );

		// Currency of the amounts (CUR-001): only offered when a group pays in a currency of its own.
		$amount_currency = Currency::code( null !== $rule ? $rule->currency() : '' );
		$currencies      = $this->group_currencies();
		if ( null !== $rule && '' !== $rule->currency() ) {
			$currencies[] = $rule->currency();
		}
		if ( ! empty( $currencies ) ) {
			$currency_select = '<select id="eurob2b-rule-currency" name="action_currency"><option value="">' . esc_html( Currency::shop() ) . ' (' . esc_html__( 'shop currency', 'eurob2b' ) . ')</option>';
			foreach ( array_unique( $currencies ) as $code ) {
				$currency_select .= '<option value="' . esc_attr( $code ) . '" ' . selected( $amount_currency, $code, false ) . '>' . esc_html( $code ) . '</option>';
			}
			$currency_select .= '</select>';
			self::row( 'currency', __( 'Currency of the amounts', 'eurob2b' ), $currency_select, __( 'Amounts are never converted. A rule with prices, fixed amounts or a cart value applies only to customers who pay in this currency; rules with percentages or quantities apply in every currency. Customers of a group with its own currency therefore need their own rules for amounts.', 'eurob2b' ) );
		}

		// Action payload per type.
		$modes = array( 'percent' => __( 'Percent', 'eurob2b' ) );
		/**
		 * Filters the modes of the amount of a discount on the rules form (mode => label; EuroB2B Pro
		 * adds the fixed amount in the rule's currency).
		 *
		 * @param array<string, string> $modes    Modes.
		 * @param string                $currency Currency code of the rule's amounts.
		 */
		$filtered = apply_filters( 'eurob2b_rules_amount_modes', $modes, $amount_currency );
		$modes    = is_array( $filtered ) && ! empty( $filtered ) ? $filtered : $modes;
		if ( count( $modes ) > 1 ) {
			$mode_select = '<select name="action_mode">';
			foreach ( $modes as $key => $label ) {
				$mode_select .= '<option value="' . esc_attr( (string) $key ) . '" ' . selected( $action['mode'] ?? 'percent', (string) $key, false ) . '>' . esc_html( (string) $label ) . '</option>';
			}
			$mode_select .= '</select>';
		} else {
			$mode_select = '<input type="hidden" name="action_mode" value="' . esc_attr( (string) array_key_first( $modes ) ) . '" />' . esc_html( (string) reset( $modes ) );
		}
		self::row( 'amount', __( 'Amount', 'eurob2b' ), '<input type="text" class="small-text" id="eurob2b-rule-amount" name="action_amount" inputmode="decimal" value="' . esc_attr( (string) ( $action['amount'] ?? '' ) ) . '" /> ' . $mode_select, self::descriptions()['amount'], 'discount,markup' );
		/**
		 * Fires on the rules form after the amount (position "action") and after the scope (position
		 * "scope"): EuroB2B Pro adds the rows of its rule types and the conditions there, built with
		 * RulesPage::row().
		 *
		 * @param string    $position RulesPage::ROWS_ACTION or RulesPage::ROWS_SCOPE.
		 * @param Rule|null $rule     Rule being edited, null for a new one.
		 * @param string    $currency Currency code of the rule's amounts.
		 */
		do_action( 'eurob2b_rules_form_rows', self::ROWS_ACTION, $rule, $amount_currency );

		// Audience.
		$audience_html = '<strong>' . esc_html__( 'Customer types', 'eurob2b' ) . '</strong><br />';
		foreach ( self::AUDIENCE_TOKENS as $token ) {
			$audience_html .= '<label style="margin-right:1em"><input type="checkbox" name="audience_tokens[]" value="' . esc_attr( $token ) . '" ' . checked( in_array( $token, $tokens, true ), true, false ) . ' /> ' . esc_html( self::token_label( $token ) ) . '</label>';
		}
		$audience_html .= '<p><strong>' . esc_html__( 'Groups', 'eurob2b' ) . '</strong><br />';
		foreach ( $this->groups->all() as $group ) {
			$audience_html .= '<label style="margin-right:1em"><input type="checkbox" name="audience_groups[]" value="' . esc_attr( (string) $group->id ) . '" ' . checked( in_array( $group->id, $groups, true ), true, false ) . ' /> ' . esc_html( $group->name ) . '</label>';
		}
		$audience_html .= '</p><p><strong>' . esc_html__( 'Individual customers', 'eurob2b' ) . '</strong><br />';
		$audience_html .= '<select class="wc-customer-search" multiple="multiple" style="width:50%" name="audience_users[]" data-placeholder="' . esc_attr__( 'Search customers…', 'eurob2b' ) . '">';
		foreach ( $users as $user_id ) {
			$user           = get_userdata( $user_id );
			$audience_html .= '<option value="' . esc_attr( (string) $user_id ) . '" selected>' . esc_html( $user ? $user->display_name . ' (#' . $user_id . ' – ' . $user->user_email . ')' : '#' . $user_id ) . '</option>';
		}
		$audience_html .= '</select></p>';
		self::row( 'audience', __( 'Audience', 'eurob2b' ), $audience_html, __( 'The rule applies when any ticked audience matches the visitor.', 'eurob2b' ) );

		// Scope.
		$scope_html = '<select id="eurob2b-rule-scope" name="scope_kind">';
		foreach ( self::scope_labels() as $key => $label ) {
			$scope_html .= '<option value="' . esc_attr( $key ) . '" ' . selected( $scope->kind, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$scope_html .= '</select>';
		$scope_html .= '<p class="eurob2b-scope-ids" data-eurob2b-scope="products,variations"><select class="wc-product-search" multiple="multiple" style="width:50%" name="scope_products[]" data-placeholder="' . esc_attr__( 'Search products…', 'eurob2b' ) . '" data-action="woocommerce_json_search_products_and_variations">';
		if ( Scope::PRODUCTS === $scope->kind || Scope::VARIATIONS === $scope->kind ) {
			foreach ( $scope->ids as $pid ) {
				$scope_html .= '<option value="' . esc_attr( (string) $pid ) . '" selected>' . esc_html( $product( $pid ) ) . '</option>';
			}
		}
		$scope_html .= '</select></p>';
		$scope_html .= '<p class="eurob2b-scope-ids" data-eurob2b-scope="categories"><select class="wc-category-search" multiple="multiple" data-return_id="id" style="width:50%" name="scope_categories[]" data-placeholder="' . esc_attr__( 'Search categories…', 'eurob2b' ) . '">';
		if ( Scope::CATEGORIES === $scope->kind ) {
			foreach ( $scope->ids as $tid ) {
				$term        = get_term( $tid, 'product_cat' );
				$scope_html .= '<option value="' . esc_attr( (string) $tid ) . '" selected>' . esc_html( $term instanceof \WP_Term ? $term->name : '#' . $tid ) . '</option>';
			}
		}
		$scope_html .= '</select></p>';
		$scope_html .= '<p class="eurob2b-scope-ids" data-eurob2b-scope="tags"><select class="wc-enhanced-select" multiple="multiple" style="width:50%" name="scope_tags[]" data-placeholder="' . esc_attr__( 'Choose tags…', 'eurob2b' ) . '">';
		$tags        = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
			)
		);
		foreach ( is_array( $tags ) ? $tags : array() as $tag ) {
			if ( $tag instanceof \WP_Term ) {
				$scope_html .= '<option value="' . esc_attr( (string) $tag->term_id ) . '" ' . selected( Scope::TAGS === $scope->kind && in_array( $tag->term_id, $scope->ids, true ), true, false ) . '>' . esc_html( $tag->name ) . '</option>';
			}
		}
		$scope_html .= '</select></p>';
		// Every type has a scope except the payment window, which always measures the whole cart.
		$scoped = implode( ',', array_keys( array_diff_key( Rule::types(), array( Rule::TYPE_PAYMENT_WINDOW => true ) ) ) );
		self::row( 'scope', __( 'Scope', 'eurob2b' ), $scope_html, __( 'A product rule covers its variations; a category rule covers the category and its sub-categories.', 'eurob2b' ), $scoped );

		// Conditions and other rows of other modules.
		/** This action is documented in RulesPage::render_form(). */
		do_action( 'eurob2b_rules_form_rows', self::ROWS_SCOPE, $rule, $amount_currency );

		// Window, priority, enabled.
		self::row( 'window', __( 'Date window', 'eurob2b' ), '<label>' . esc_html__( 'From', 'eurob2b' ) . ' <input type="datetime-local" name="starts_at" value="' . esc_attr( $to_lcl( $rule->starts_at ?? null ) ) . '" /></label> <label>' . esc_html__( 'To', 'eurob2b' ) . ' <input type="datetime-local" name="ends_at" value="' . esc_attr( $to_lcl( $rule->ends_at ?? null ) ) . '" /></label>', __( 'Site time zone. Leave empty for no limit; the rule is inactive outside the window.', 'eurob2b' ) );
		self::row( 'priority', __( 'Priority', 'eurob2b' ), '<input type="number" class="small-text" id="eurob2b-rule-priority" name="priority" value="' . esc_attr( (string) ( $rule->priority ?? 10 ) ) . '" />', __( 'Higher wins when several rules of the same type match.', 'eurob2b' ) );
		self::row( 'enabled', __( 'Enabled', 'eurob2b' ), '<label><input type="checkbox" name="enabled" value="1" ' . checked( $rule->enabled ?? true, true, false ) . ' /> ' . esc_html__( 'The rule is active', 'eurob2b' ) . '</label>' );

		echo '</tbody></table>';
		submit_button( null === $rule ? __( 'Create rule', 'eurob2b' ) : __( 'Save rule', 'eurob2b' ) );
		echo ' <a class="button" href="' . esc_url( Menu::url( self::SLUG ) ) . '">' . esc_html__( 'Back to the list', 'eurob2b' ) . '</a>';
		echo '</form>';

		if ( null !== $rule ) {
			$this->render_delete( $rule );
		}
	}

	/**
	 * The form that deletes a rule.
	 *
	 * @param Rule $rule Rule.
	 */
	private function render_delete( Rule $rule ): void {
		echo '<h2>' . esc_html__( 'Delete rule', 'eurob2b' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(' . esc_attr( (string) wp_json_encode( __( 'Delete this rule?', 'eurob2b' ) ) ) . ');">';
		wp_nonce_field( self::NONCE_DELETE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE ) . '" />';
		echo '<input type="hidden" name="rule_id" value="' . esc_attr( (string) $rule->id ) . '" />';
		submit_button( __( 'Delete rule', 'eurob2b' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Explanatory texts of the page.
	 *
	 * @return array{list: string, amount: string}
	 */
	private static function descriptions(): array {
		$texts = array(
			'list'   => __( 'Discount rules lower the prices for an audience by a percentage. Higher priority wins; the Prices settings decide whether discounts stack.', 'eurob2b' ),
			'amount' => __( 'Discounts lower the resolved price (after group prices).', 'eurob2b' ),
		);
		/**
		 * Filters the explanatory texts of the rules page: "list" above the table of rules, "amount"
		 * under the amount of a discount (EuroB2B Pro describes its rule types there).
		 *
		 * @param array{list: string, amount: string} $texts Texts.
		 */
		$filtered = apply_filters( 'eurob2b_rules_page_descriptions', $texts );
		foreach ( array_keys( $texts ) as $key ) {
			if ( is_array( $filtered ) && isset( $filtered[ $key ] ) && is_string( $filtered[ $key ] ) ) {
				$texts[ $key ] = $filtered[ $key ];
			}
		}
		return $texts;
	}

	/**
	 * The note on a rule that needs EuroB2B Pro while it is missing.
	 */
	private static function needs_pro_note(): string {
		return __( 'This rule uses features of EuroB2B Pro. Without it, the rule does not apply and cannot be edited.', 'eurob2b' );
	}

	/**
	 * Currencies of groups that pay in a currency of their own (CUR-001), without duplicates.
	 *
	 * @return string[]
	 */
	private function group_currencies(): array {
		$codes = array();
		foreach ( $this->groups->all() as $group ) {
			if ( '' !== \EuroB2B\Support\Currency::of_group( $group ) ) {
				$codes[ \EuroB2B\Support\Currency::of_group( $group ) ] = true;
			}
		}
		$codes = array_keys( $codes );
		sort( $codes );
		return $codes;
	}

	/**
	 * One form row; optional type restriction for the script.
	 *
	 * @param string $id          Row id.
	 * @param string $label       Label.
	 * @param string $control     Control HTML (already escaped).
	 * @param string $description Description.
	 * @param string $types       Comma-separated rule types the row belongs to ('' = always).
	 */
	public static function row( string $id, string $label, string $control, string $description = '', string $types = '' ): void {
		echo '<tr class="eurob2b-row-' . esc_attr( $id ) . '"' . ( '' !== $types ? ' data-eurob2b-type="' . esc_attr( $types ) . '"' : '' ) . '><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Capability and nonce.
	 *
	 * @param string $nonce Nonce action.
	 */
	private function authorise( string $nonce ): void {
		if ( ! current_user_can( Capabilities::MANAGE_RULES ) ) {
			wp_die( esc_html__( 'You are not allowed to manage rules.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Redirects back to the page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function redirect( array $args ): void {
		if ( wp_safe_redirect( Menu::url( self::SLUG, $args ) ) ) {
			exit;
		}
	}

	/**
	 * Sanitises a submitted list.
	 *
	 * @param mixed  $raw  Submitted value.
	 * @param string $mode 'int', 'key' or 'text'.
	 * @return array<int, int|string>
	 */
	private static function ids_or_keys( $raw, string $mode ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			if ( 'int' === $mode ) {
				$id = absint( $value );
				if ( $id > 0 ) {
					$out[] = $id;
				}
			} elseif ( 'key' === $mode ) {
				$key = sanitize_key( (string) $value );
				if ( '' !== $key ) {
					$out[] = $key;
				}
			} else {
				$text = sanitize_text_field( (string) $value );
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}//end foreach
		return array_values( array_unique( $out ) );
	}

	/**
	 * Rejects percentages the money arithmetic cannot apply exactly.
	 *
	 * @param string $percent Percentage (decimal string).
	 * @throws RuntimeException Too many decimals.
	 */
	public static function assert_percent_precision( string $percent ): void {
		$decimals = str_contains( $percent, '.' ) ? strlen( rtrim( substr( $percent, strpos( $percent, '.' ) + 1 ), '0' ) ) : 0;
		if ( $decimals > Money::MAX_PERCENT_DECIMALS ) {
			/* translators: %d: maximum number of decimals */
			throw new RuntimeException( sprintf( __( 'Percentages may have at most %d decimals.', 'eurob2b' ), Money::MAX_PERCENT_DECIMALS ) );
		}
	}

	/**
	 * Normalises a typed decimal (comma or dot).
	 *
	 * @param string $raw Input.
	 */
	public static function decimal( string $raw ): string {
		return str_replace( array( ',', ' ' ), array( '.', '' ), trim( $raw ) );
	}

	/**
	 * Parses a datetime-local input in the site time zone; '' → null.
	 *
	 * @param string $raw Input.
	 * @throws RuntimeException Unparseable input.
	 */
	private static function date_from_input( string $raw ): ?DateTimeImmutable {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		foreach ( array( 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $raw, wp_timezone() );
			if ( false !== $date ) {
				return $date->setTimezone( new DateTimeZone( 'UTC' ) );
			}
		}
		throw new RuntimeException( __( 'The date window could not be read.', 'eurob2b' ) );
	}

	/**
	 * Scope kinds with labels.
	 *
	 * @return array<string, string>
	 */
	public static function scope_labels(): array {
		return array(
			Scope::ALL        => __( 'All products', 'eurob2b' ),
			Scope::PRODUCTS   => __( 'Selected products', 'eurob2b' ),
			Scope::VARIATIONS => __( 'Selected variations', 'eurob2b' ),
			Scope::CATEGORIES => __( 'Categories', 'eurob2b' ),
			Scope::TAGS       => __( 'Tags', 'eurob2b' ),
		);
	}

	/**
	 * Label for a fixed audience token.
	 *
	 * @param string $token Token.
	 */
	public static function token_label( string $token ): string {
		switch ( $token ) {
			case 'all':
				return __( 'Everyone', 'eurob2b' );
			case 'registered':
				return __( 'Logged-in customers', 'eurob2b' );
			case 'b2b':
				return __( 'Business customers', 'eurob2b' );
			case 'b2c':
				return __( 'Private customers', 'eurob2b' );
			case 'guest':
				return __( 'Guests', 'eurob2b' );
		}
		return $token;
	}

	/**
	 * Formatted product name for a picker option.
	 *
	 * @param int $id Product id.
	 */
	private static function product_name( int $id ): string {
		$product = wc_get_product( $id );
		return $product instanceof WC_Product ? $product->get_formatted_name() : '#' . $id;
	}

	/**
	 * Readable scope.
	 *
	 * @param Scope $scope Scope.
	 */
	public static function scope_summary( Scope $scope ): string {
		if ( Scope::ALL === $scope->kind ) {
			return __( 'All products', 'eurob2b' );
		}
		return sprintf( '%s (%d)', self::scope_labels()[ $scope->kind ] ?? $scope->kind, count( $scope->ids ) );
	}

	/**
	 * Readable audience (group names resolved).
	 *
	 * @param string[] $tokens Tokens.
	 */
	private function audience_summary( array $tokens ): string {
		$names = array();
		foreach ( $tokens as $token ) {
			if ( str_starts_with( $token, 'group:' ) ) {
				$group   = $this->groups->find( (int) substr( $token, 6 ) );
				$names[] = $group instanceof Group ? $group->name : $token;
			} elseif ( str_starts_with( $token, 'user:' ) ) {
				$user    = get_userdata( (int) substr( $token, 5 ) );
				$names[] = $user ? $user->display_name : $token;
			} else {
				$names[] = self::token_label( $token );
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * Readable date window.
	 *
	 * @param Rule $rule Rule.
	 */
	private static function window_summary( Rule $rule ): string {
		if ( null === $rule->starts_at && null === $rule->ends_at ) {
			return '—';
		}
		$tz  = wp_timezone();
		$fmt = static fn( ?DateTimeImmutable $d ): string => null === $d ? '…' : $d->setTimezone( $tz )->format( 'Y-m-d H:i' );
		return $fmt( $rule->starts_at ) . ' – ' . $fmt( $rule->ends_at );
	}
}

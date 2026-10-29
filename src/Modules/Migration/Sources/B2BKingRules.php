<?php
/**
 * B2BKing's dynamic rules as EuroB2B rules.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Migration\Sources;

use EuroB2B\Modules\Migration\ImportWriter;
use EuroB2B\Modules\Migration\Run;
use EuroB2B\Modules\Rules\Rule;
use EuroB2B\Modules\Rules\Scope;

/**
 * Maps one B2BKing rule (post type `b2bking_rule`) to EuroB2B rules in the shape of the rules
 * screen's form (MIG-002, table in docs/migration.md). A rule whose products mix kinds (categories,
 * products, variations, tags) becomes one EuroB2B rule per kind. What EuroB2B cannot express
 * ("all except", value conditions per product or category) is imported switched off with a note;
 * tax, currency, buy-x-get-y and payment discount rules are not imported.
 */
final class B2BKingRules {

	/**
	 * Rule types that are reported and not imported, with the reason.
	 */
	private const NOT_IMPORTED = array(
		'tax_exemption'               => 'tax',
		'tax_exemption_user'          => 'tax',
		'add_tax_percentage'          => 'tax',
		'add_tax_amount'              => 'tax',
		'set_currency_symbol'         => 'currency',
		'bogo_discount'               => 'bogo',
		'rename_purchase_order'       => 'other',
		'payment_method_discount'     => 'payment',
		'payment_method_minmax_order' => 'format',
	);

	/**
	 * Imports one rule.
	 *
	 * @param array{id: int, title: string, status: string, order: int} $post   Rule post.
	 * @param array<string, string>                                     $meta   Its B2BKing meta.
	 * @param ImportWriter                                              $writer Writer.
	 */
	public function import( array $post, array $meta, ImportWriter $writer ): void {
		/* translators: %d: B2BKing rule id */
		$name = '' !== trim( $post['title'] ) ? trim( wp_strip_all_tags( $post['title'] ) ) : sprintf( __( 'B2BKing rule %d', 'eurob2b' ), $post['id'] );
		$what = (string) ( $meta['b2bking_rule_what'] ?? '' );
		if ( isset( self::NOT_IMPORTED[ $what ] ) ) {
			$writer->skip( self::reason( $name, self::NOT_IMPORTED[ $what ] ) );
			return;
		}
		$action = $this->action( $what, $meta );
		if ( null === $action ) {
			/* translators: 1: rule name, 2: B2BKing rule type */
			$writer->skip( sprintf( __( 'Rule "%1$s": the B2BKing rule type "%2$s" has no counterpart in EuroB2B.', 'eurob2b' ), $name, $what ) );
			return;
		}
		$audience = $this->audience( $meta, $writer );
		if ( empty( $audience['audience_tokens'] ) && empty( $audience['audience_groups'] ) && empty( $audience['audience_users'] ) ) {
			/* translators: %s: rule name */
			$writer->skip( sprintf( __( 'Rule "%s": none of the customers or groups it applies to were imported.', 'eurob2b' ), $name ) );
			return;
		}
		$enabled = 'publish' === $post['status'];
		$scopes  = $this->scopes( $meta );
		if ( null === $scopes ) {
			/* translators: %s: rule name */
			$writer->note( Run::LEVEL_WARNING, sprintf( __( 'Rule "%s" applies to all products except some; EuroB2B cannot exclude products, so the rule is imported switched off for all products.', 'eurob2b' ), $name ) );
			$enabled = false;
			$scopes  = array( Scope::ALL => array() );
		}
		$priority = (int) ( $meta['b2bking_standard_rule_priority'] ?? $meta['b2bking_rule_priority'] ?? 0 );
		foreach ( $scopes as $kind => $ids ) {
			$conditions = B2BKingFormat::conditions( (string) ( $meta['b2bking_rule_conditions'] ?? '' ), Scope::CATEGORIES === $kind ? $ids : array() );
			$on         = $enabled;
			if ( null === $conditions ) {
				/* translators: %s: rule name */
				$writer->note( Run::LEVEL_WARNING, sprintf( __( 'Rule "%s" has a condition EuroB2B cannot check (a value per product or category, or a category quantity without categories); it is imported switched off without conditions.', 'eurob2b' ), $name ) );
				$conditions = array();
				$on         = false;
			}
			$form = array_merge(
				array(
					'name'            => count( $scopes ) > 1 ? $name . ' (' . self::kind_label( $kind ) . ')' : $name,
					'type'            => $action['type'],
					'enabled'         => $on ? '1' : '',
					'priority'        => $priority > 0 ? $priority : 10,
					'scope_kind'      => $kind,
					'cond'            => $conditions,
					'condition_match' => 'all',
				),
				$audience,
				self::scope_field( $kind, $ids ),
				$action['form']
			);
			$writer->rule( 'rule:' . $post['id'] . ':' . $kind, $form );
		}//end foreach
	}

	/**
	 * EuroB2B type and form fields of a B2BKing rule type, or null when there is none.
	 *
	 * @param string                $what B2BKing type.
	 * @param array<string, string> $meta Rule meta.
	 * @return array{type: string, form: array<string, mixed>}|null
	 */
	private function action( string $what, array $meta ): ?array {
		$amount = B2BKingFormat::decimal( (string) ( $meta['b2bking_rule_howmuch'] ?? '' ) );
		switch ( $what ) {
			case 'discount_percentage':
			case 'discount_amount':
			case 'raise_price':
				$markup = 'raise_price' === $what || 'yes' === ( $meta['b2bking_rule_raise_price'] ?? '' );
				return array(
					'type' => $markup ? Rule::TYPE_MARKUP : Rule::TYPE_DISCOUNT,
					'form' => array(
						'action_mode'   => 'discount_amount' === $what ? 'fixed' : 'percent',
						'action_amount' => $amount,
					),
				);
			case 'fixed_price':
				return array(
					'type' => Rule::TYPE_FIXED_PRICE,
					'form' => array( 'action_price' => $amount ),
				);
			case 'hidden_price':
				return array(
					'type' => Rule::TYPE_HIDDEN_PRICE,
					'form' => array(
						'action_text'       => '',
						'action_login_link' => '',
					),
				);
			case 'unpurchasable':
				return array(
					'type' => Rule::TYPE_NON_PURCHASABLE,
					'form' => array(),
				);
			case 'tiered_price':
				$rows = B2BKingFormat::tiers( (string) ( $meta['b2bking_product_pricetiers_group_b2c'] ?? '' ) );
				return array(
					'type' => Rule::TYPE_TIERED_PRICE,
					'form' => array(
						'action_tier_mode'   => '1' === (string) get_option( 'b2bking_enter_percentage_tiered_setting', '0' ) ? 'percent' : 'price',
						'action_tier_min'    => array_column( $rows, 'min' ),
						'action_tier_amount' => array_column( $rows, 'price' ),
					),
				);
			case 'minimum_order':
			case 'maximum_order':
				return array(
					'type' => 'minimum_order' === $what ? Rule::TYPE_MIN_ORDER : Rule::TYPE_MAX_ORDER,
					'form' => array(
						'action_order_basis'  => 'quantity' === ( $meta['b2bking_rule_quantity_value'] ?? '' ) ? 'quantity' : 'value',
						'action_order_amount' => $amount,
					),
				);
			case 'required_multiple':
				return array(
					'type' => Rule::TYPE_REQUIRED_MULTIPLE,
					'form' => array( 'action_step' => (int) $amount ),
				);
			case 'free_shipping':
				return array(
					'type' => Rule::TYPE_FREE_SHIPPING,
					'form' => array(),
				);
			case 'quotes_products':
			case 'replace_prices_quote':
				return array(
					'type' => 'quote_only',
					'form' => array(),
				);
			case 'payment_method_minimum_order':
				return array(
					'type' => Rule::TYPE_PAYMENT_WINDOW,
					'form' => array(
						'action_gateways'     => array( (string) ( $meta['b2bking_rule_paymentmethod'] ?? '' ) ),
						'action_window_basis' => 'quantity' === ( $meta['b2bking_rule_quantity_value'] ?? '' ) ? 'quantity' : 'subtotal',
						'action_window_min'   => $amount,
						'action_window_max'   => '',
					),
				);
		}//end switch
		return null;
	}

	/**
	 * Who the rule applies to, as form fields.
	 *
	 * @param array<string, string> $meta   Rule meta.
	 * @param ImportWriter          $writer Writer.
	 * @return array{audience_tokens: string[], audience_groups: int[], audience_users: int[]}
	 */
	private function audience( array $meta, ImportWriter $writer ): array {
		$who    = (string) ( $meta['b2bking_rule_who'] ?? '' );
		$raw    = 'multiple_options' === $who ? (string) ( $meta['b2bking_rule_who_multiple_options'] ?? '' ) : $who;
		$tokens = B2BKingFormat::tokens( $raw );
		$out    = array(
			'audience_tokens' => array(),
			'audience_groups' => array(),
			'audience_users'  => array(),
		);
		foreach ( $tokens['other'] ?? array() as $token ) {
			$map = array(
				'everyone_registered'     => 'registered',
				'all_registered'          => 'registered',
				'everyone_registered_b2b' => 'b2b',
				'everyone_registered_b2c' => 'b2c',
			);
			if ( isset( $map[ $token ] ) ) {
				$out['audience_tokens'][] = $map[ $token ];
			}
		}
		foreach ( $tokens['group'] ?? array() as $b2bking_id ) {
			$group = B2BKingSource::group_of( $writer, (int) $b2bking_id );
			if ( null !== $group ) {
				$out['audience_groups'][] = $group;
			}
		}
		foreach ( $tokens['user'] ?? array() as $user_id ) {
			if ( 0 === (int) $user_id ) {
				$out['audience_tokens'][] = 'guest';
			} elseif ( get_userdata( (int) $user_id ) ) {
				$out['audience_users'][] = (int) $user_id;
			}
		}
		return $out;
	}

	/**
	 * What the rule applies to, split by kind; null for "all except".
	 *
	 * @param array<string, string> $meta Rule meta.
	 * @return array<string, int[]>|null
	 */
	private function scopes( array $meta ): ?array {
		$applies = (string) ( $meta['b2bking_rule_applies'] ?? 'cart_total' );
		if ( 'excluding_multiple_options' === $applies ) {
			return null;
		}
		if ( 'cart_total' === $applies || 'one_time' === $applies || '' === $applies ) {
			return array( Scope::ALL => array() );
		}
		$raw    = 'multiple_options' === $applies ? (string) ( $meta['b2bking_rule_applies_multiple_options'] ?? '' ) : $applies;
		$tokens = B2BKingFormat::tokens( $raw );
		$scopes = array();
		foreach ( $tokens['category'] ?? array() as $id ) {
			$scopes[ Scope::CATEGORIES ][] = (int) $id;
		}
		foreach ( $tokens['tag'] ?? array() as $id ) {
			$scopes[ Scope::TAGS ][] = (int) $id;
		}
		foreach ( $tokens['product'] ?? array() as $id ) {
			$kind              = 'product_variation' === get_post_type( (int) $id ) ? Scope::VARIATIONS : Scope::PRODUCTS;
			$scopes[ $kind ][] = (int) $id;
		}
		return empty( $scopes ) ? array( Scope::ALL => array() ) : $scopes;
	}

	/**
	 * The form field that carries a scope's ids.
	 *
	 * @param string $kind Scope kind.
	 * @param int[]  $ids  Ids.
	 * @return array<string, int[]>
	 */
	private static function scope_field( string $kind, array $ids ): array {
		$fields = array(
			Scope::PRODUCTS   => 'scope_products',
			Scope::VARIATIONS => 'scope_products',
			Scope::CATEGORIES => 'scope_categories',
			Scope::TAGS       => 'scope_tags',
		);
		return isset( $fields[ $kind ] ) ? array( $fields[ $kind ] => $ids ) : array();
	}

	/**
	 * Label of a scope kind in a split rule's name.
	 *
	 * @param string $kind Scope kind.
	 */
	private static function kind_label( string $kind ): string {
		$labels = array(
			Scope::PRODUCTS   => __( 'products', 'eurob2b' ),
			Scope::VARIATIONS => __( 'variations', 'eurob2b' ),
			Scope::CATEGORIES => __( 'categories', 'eurob2b' ),
			Scope::TAGS       => __( 'tags', 'eurob2b' ),
		);
		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Why a rule type is not imported.
	 *
	 * @param string $name   Rule name.
	 * @param string $reason Reason key.
	 */
	private static function reason( string $name, string $reason ): string {
		switch ( $reason ) {
			case 'tax':
				/* translators: %s: rule name */
				return sprintf( __( 'Rule "%s" is a tax rule; tax is not imported (EuroB2B decides on VAT in its tax module).', 'eurob2b' ), $name );
			case 'currency':
				/* translators: %s: rule name */
				return sprintf( __( 'Rule "%s" sets a currency; currencies are not imported.', 'eurob2b' ), $name );
			case 'bogo':
				/* translators: %s: rule name */
				return sprintf( __( 'Rule "%s" is a "buy X, get Y" rule, which EuroB2B does not offer.', 'eurob2b' ), $name );
			case 'payment':
				/* translators: %s: rule name */
				return sprintf( __( 'Rule "%s" gives a discount for a payment method; set it up under EuroB2B\'s surcharges and discounts per payment method, which respect the limits for SEPA payments.', 'eurob2b' ), $name );
			case 'format':
				/* translators: %s: rule name */
				return sprintf( __( 'Rule "%s" limits a payment method by order value in a form EuroB2B cannot read; set up a payment window by hand.', 'eurob2b' ), $name );
		}
		/* translators: %s: rule name */
		return sprintf( __( 'Rule "%s" has no counterpart in EuroB2B.', 'eurob2b' ), $name );
	}
}

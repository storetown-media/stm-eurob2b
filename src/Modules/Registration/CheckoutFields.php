<?php
/**
 * Custom fields at checkout and on orders.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use WC_Order;

/**
 * Shows "show in checkout" fields in the classic billing form and the Checkout block,
 * validates them, stores them on the customer and copies every custom field
 * value to the order (HPOS-safe meta) so invoicing plugins can print them.
 */
final class CheckoutFields {

	public const ORDER_META_PREFIX = '_eurob2b_field_';

	/**
	 * Meta prefix the Checkout block's additional fields API uses for this plugin's fields.
	 */
	public const BLOCK_META_PREFIX = '_wc_billing/eurob2b/';

	/**
	 * The same for the shipping address: WooCommerce keeps an address field's value for both addresses.
	 */
	public const BLOCK_META_PREFIX_SHIPPING = '_wc_shipping/eurob2b/';

	/**
	 * Fields.
	 *
	 * @var FieldRepository
	 */
	private FieldRepository $fields;

	/**
	 * Storage.
	 *
	 * @var FieldStorage
	 */
	private FieldStorage $storage;

	/**
	 * Registration service (knows the customer's registration type).
	 *
	 * @var RegistrationService
	 */
	private RegistrationService $service;

	/**
	 * Constructor.
	 *
	 * @param FieldRepository     $fields  Fields.
	 * @param FieldStorage        $storage Storage.
	 * @param RegistrationService $service Registration service.
	 */
	public function __construct( FieldRepository $fields, FieldStorage $storage, RegistrationService $service ) {
		$this->fields  = $fields;
		$this->storage = $storage;
		$this->service = $service;
	}

	/**
	 * Checkout fields that apply to this customer: fields limited to registration types only for
	 * customers of one of those types; guests and customers without a type only get fields that
	 * apply to every type. Used by both checkouts, so an unrelated business-only field never blocks
	 * a consumer.
	 *
	 * @param int $user_id User id (0 for guests).
	 * @return FieldDefinition[]
	 */
	public function fields_for_checkout( int $user_id ): array {
		return array_values( array_filter( $this->fields->for_checkout(), fn( FieldDefinition $field ): bool => $this->applies( $field, $user_id ) ) );
	}

	/**
	 * A mapped field edited at checkout also updates the order's own billing property (the customer's
	 * billing meta is updated separately), so the order never keeps a stale company or address.
	 *
	 * @param WC_Order        $order Order.
	 * @param FieldDefinition $field Field.
	 * @param string          $value Sanitised value.
	 */
	private static function apply_mapping( WC_Order $order, FieldDefinition $field, string $value ): void {
		if ( '' === $field->map_to || '' === $value ) {
			return;
		}
		$setter = 'set_' . $field->map_to;
		if ( method_exists( $order, $setter ) ) {
			$order->{$setter}( $value );
		}
	}

	/**
	 * Whether a field applies to a customer.
	 *
	 * @param FieldDefinition $field   Field.
	 * @param int             $user_id User id (0 for guests).
	 */
	private function applies( FieldDefinition $field, int $user_id ): bool {
		if ( empty( $field->registration_types ) ) {
			return true;
		}
		$type = $user_id > 0 ? $this->service->registration_type_of( $user_id ) : null;
		return null !== $type && $field->applies_to( $type->slug );
	}

	/**
	 * Registers hooks.
	 */
	public function hooks(): void {
		// Classic checkout.
		add_filter( 'woocommerce_billing_fields', array( $this, 'classic_billing_fields' ), 20 );
		add_action( 'woocommerce_checkout_process', array( $this, 'classic_validate' ) );
		add_action( 'woocommerce_checkout_update_customer', array( $this, 'classic_save_customer' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'copy_to_order' ), 10, 2 );

		// Checkout block (additional checkout fields API).
		add_action( 'woocommerce_init', array( $this, 'register_block_fields' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'block_order_processed' ) );

		// Orders created elsewhere (admin, REST) still get the customer's values.
		add_action( 'woocommerce_new_order', array( $this, 'copy_to_new_order' ), 10, 2 );

		// Privacy: order copies of custom field values go when WooCommerce anonymises the order (D-02/D-05).
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( $this, 'anonymize_order' ) );
	}

	/**
	 * Removes every custom field copy from an order (WooCommerce order anonymisation, HPOS-safe via CRUD).
	 *
	 * @param WC_Order $order Order being anonymised.
	 */
	public function anonymize_order( WC_Order $order ): void {
		$removed = false;
		foreach ( $order->get_meta_data() as $meta ) {
			$key = (string) $meta->key;
			if ( str_starts_with( $key, self::ORDER_META_PREFIX ) || str_starts_with( $key, self::BLOCK_META_PREFIX ) || str_starts_with( $key, self::BLOCK_META_PREFIX_SHIPPING ) ) {
				$order->delete_meta_data( $key );
				$removed = true;
			}
		}
		if ( $removed ) {
			$order->save();
		}
	}

	/**
	 * Adds checkout fields to the classic billing form.
	 *
	 * @param array<string, array<string, mixed>> $billing Billing fields.
	 * @return array<string, array<string, mixed>>
	 */
	public function classic_billing_fields( array $billing ): array {
		$user_id = get_current_user_id();
		foreach ( $this->fields_for_checkout( $user_id ) as $field ) {
			$definition = array(
				'label'       => $field->label,
				'required'    => $field->required,
				'placeholder' => $field->placeholder,
				'description' => $field->help,
				'class'       => array( 'form-row-wide', 'eurob2b-checkout-field' ),
				'priority'    => 200 + $field->order,
				'default'     => $user_id > 0 ? $this->storage->get( $user_id, $field ) : '',
			);
			switch ( $field->type ) {
				case FieldDefinition::TYPE_SELECT:
					$definition['type']    = 'select';
					$definition['options'] = array( '' => '' !== $field->placeholder ? $field->placeholder : __( 'Please choose', 'eurob2b' ) ) + $field->options;
					break;
				case FieldDefinition::TYPE_CHECKBOX:
					$definition['type'] = 'checkbox';
					break;
				case FieldDefinition::TYPE_TEXTAREA:
					$definition['type'] = 'textarea';
					break;
				case FieldDefinition::TYPE_NUMBER:
					$definition['type'] = 'number';
					break;
				case FieldDefinition::TYPE_EMAIL:
					$definition['type'] = 'email';
					break;
				case FieldDefinition::TYPE_TEL:
					$definition['type'] = 'tel';
					break;
				default:
					$definition['type'] = 'text';
			}//end switch
			$billing[ $field->input_name() ] = $definition;
		}//end foreach
		return $billing;
	}

	/**
	 * Validates classic checkout values with the field rules (WooCommerce only checks "required").
	 */
	public function classic_validate(): void {
		foreach ( $this->fields_for_checkout( get_current_user_id() ) as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verified the checkout nonce; value sanitised by FieldDefinition::sanitize().
			$raw   = isset( $_POST[ $field->input_name() ] ) ? wp_unslash( $_POST[ $field->input_name() ] ) : null;
			$value = $field->sanitize( $raw );
			$code  = $field->validate( $value );
			if ( null !== $code && 'required' !== $code ) {
				wc_add_notice( FieldRenderer::message( $field, $code ), 'error' );
			}
		}
	}

	/**
	 * Stores classic checkout values on the customer.
	 *
	 * @param \WC_Customer         $customer Customer.
	 * @param array<string, mixed> $data     Posted checkout data.
	 */
	public function classic_save_customer( \WC_Customer $customer, array $data ): void {
		$user_id = $customer->get_id();
		if ( $user_id <= 0 ) {
			return;
		}
		foreach ( $this->fields_for_checkout( $user_id ) as $field ) {
			if ( array_key_exists( $field->input_name(), $data ) ) {
				$this->storage->set( $user_id, $field, $field->sanitize( $data[ $field->input_name() ] ) );
			}
		}
	}

	/**
	 * Copies custom field values to the order being created at classic checkout.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $data  Posted checkout data.
	 */
	public function copy_to_order( WC_Order $order, array $data ): void {
		$user_id = $order->get_customer_id();
		foreach ( $this->fields->all() as $field ) {
			$value = '';
			if ( $field->show_in_checkout && ! $field->is_file() && $this->applies( $field, $user_id ) && array_key_exists( $field->input_name(), $data ) ) {
				$value = $field->sanitize( $data[ $field->input_name() ] );
				self::apply_mapping( $order, $field, $value );
			} elseif ( $user_id > 0 ) {
				$value = $this->storage->get( $user_id, $field );
			}
			if ( '' !== $value ) {
				$order->update_meta_data( self::ORDER_META_PREFIX . $field->key, $value );
			}
		}
	}

	/**
	 * Registers the fields with the Checkout block's additional fields API (WooCommerce 8.9+).
	 */
	public function register_block_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		// The editor (admin) lists every checkout field; the storefront registers the current customer's.
		$fields = is_admin() && ! wp_doing_ajax() ? $this->fields->for_checkout() : $this->fields_for_checkout( get_current_user_id() );
		foreach ( $fields as $field ) {
			$args = array(
				'id'                => 'eurob2b/' . str_replace( '_', '-', $field->key ),
				'label'             => $field->label,
				'location'          => 'address',
				'required'          => $field->required,
				'sanitize_callback' => fn( $value ) => $field->sanitize( $value ),
				'validate_callback' => function ( $value ) use ( $field ) {
					$code = $field->validate( $field->sanitize( $value ) );
					return null === $code || 'required' === $code ? true : new \WP_Error( 'eurob2b_field_' . $field->key, FieldRenderer::message( $field, $code ) );
				},
			);
			if ( FieldDefinition::TYPE_SELECT === $field->type ) {
				$args['type']    = 'select';
				$args['options'] = array();
				foreach ( $field->options as $value => $label ) {
					$args['options'][] = array(
						'value' => (string) $value,
						'label' => (string) $label,
					);
				}
			} elseif ( FieldDefinition::TYPE_CHECKBOX === $field->type ) {
				$args['type'] = 'checkbox';
			} else {
				$args['type'] = 'text';
			}
			woocommerce_register_additional_checkout_field( $args );
		}//end foreach
	}

	/**
	 * After a block checkout: mirror the additional field values to our meta and the customer.
	 *
	 * @param WC_Order $order Order.
	 */
	public function block_order_processed( WC_Order $order ): void {
		$user_id = $order->get_customer_id();
		foreach ( $this->fields->all() as $field ) {
			$value = '';
			if ( $field->show_in_checkout && ! $field->is_file() && $this->applies( $field, $user_id ) ) {
				$raw   = $order->get_meta( self::BLOCK_META_PREFIX . str_replace( '_', '-', $field->key ), true );
				$value = $field->sanitize( is_scalar( $raw ) ? (string) $raw : null );
				self::apply_mapping( $order, $field, $value );
				if ( $user_id > 0 ) {
					$this->storage->set( $user_id, $field, $value );
				}
			} elseif ( $user_id > 0 ) {
				$value = $this->storage->get( $user_id, $field );
			}
			if ( '' !== $value ) {
				$order->update_meta_data( self::ORDER_META_PREFIX . $field->key, $value );
			}
		}
		$order->save();
	}

	/**
	 * Orders created outside checkout: copy the customer's stored values if nothing was copied yet.
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order.
	 */
	public function copy_to_new_order( int $order_id, WC_Order $order ): void {
		if ( did_action( 'woocommerce_checkout_create_order' ) || did_action( 'woocommerce_store_api_checkout_order_processed' ) ) {
			return;
		}
		$user_id = $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$changed = false;
		foreach ( $this->fields->all() as $field ) {
			$value = $this->storage->get( $user_id, $field );
			if ( '' !== $value && '' === (string) $order->get_meta( self::ORDER_META_PREFIX . $field->key, true ) ) {
				$order->update_meta_data( self::ORDER_META_PREFIX . $field->key, $value );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->save();
		}
	}
}

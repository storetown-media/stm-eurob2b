<?php
/**
 * Registration admin page: types and custom fields.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration\Admin;

use EuroB2B\Infrastructure\Capabilities;
use EuroB2B\Modules\Admin\Menu;
use EuroB2B\Modules\Admin\PageHeader;
use EuroB2B\Modules\Customers\Admin\GroupsPage;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Registration\FieldDefinition;
use EuroB2B\Modules\Registration\FieldRepository;
use EuroB2B\Modules\Registration\RegistrationType;
use EuroB2B\Modules\Registration\RegistrationTypeRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manage registration types (public choices) and custom registration fields.
 */
final class RegistrationPage {

	public const SLUG                = 'registration';
	public const ACTION_SAVE_TYPE    = 'eurob2b_save_registration_type';
	public const ACTION_DELETE_TYPE  = 'eurob2b_delete_registration_type';
	public const ACTION_SAVE_FIELD   = 'eurob2b_save_registration_field';
	public const ACTION_DELETE_FIELD = 'eurob2b_delete_registration_field';
	public const NONCE               = 'eurob2b_registration_admin';

	/**
	 * Types.
	 *
	 * @var RegistrationTypeRepository
	 */
	private RegistrationTypeRepository $types;

	/**
	 * Fields.
	 *
	 * @var FieldRepository
	 */
	private FieldRepository $fields;

	/**
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Constructor.
	 *
	 * @param RegistrationTypeRepository $types  Types.
	 * @param FieldRepository            $fields Fields.
	 * @param GroupRepository            $groups Groups.
	 */
	public function __construct( RegistrationTypeRepository $types, FieldRepository $fields, GroupRepository $groups ) {
		$this->types  = $types;
		$this->fields = $fields;
		$this->groups = $groups;
	}

	/**
	 * Registers the page.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'Registration', 'eurob2b' ), __( 'Registration', 'eurob2b' ), Capabilities::MANAGE_SETTINGS, array( $this, 'render' ), 30 );
	}

	/**
	 * Saves a registration type.
	 *
	 * @throws InvalidArgumentException Validation problems; caught below and shown as an error notice.
	 */
	public function handle_save_type(): void {
		$this->authorise();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$slug  = isset( $_POST['slug'] ) ? sanitize_key( (string) wp_unslash( $_POST['slug'] ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['label'] ) ) : '';
		$data  = array(
			'slug'          => '' !== $slug ? $slug : sanitize_key( str_replace( ' ', '-', strtolower( remove_accents( $label ) ) ) ),
			'label'         => $label,
			'customer_type' => isset( $_POST['customer_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['customer_type'] ) ) : '',
			'approval'      => isset( $_POST['approval'] ) ? sanitize_key( (string) wp_unslash( $_POST['approval'] ) ) : RegistrationType::APPROVAL_AUTOMATIC,
			'group_id'      => isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0,
			'enabled'       => ! empty( $_POST['enabled'] ),
			'order'         => isset( $_POST['order'] ) ? (int) $_POST['order'] : 10,
			'description'   => isset( $_POST['description'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['description'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		try {
			$type = RegistrationType::from_array( $data );
			if ( null !== $type->group_id ) {
				$group = $this->groups->find( $type->group_id );
				if ( null === $group || $group->customer_type !== $type->customer_type ) {
					throw new InvalidArgumentException( __( 'The target group must belong to the chosen customer type.', 'eurob2b' ) );
				}
			}
			$this->types->save( $type );
			$this->redirect( array( 'saved' => 'type' ) );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			$this->redirect(
				array(
					'error'     => $e->getMessage(),
					'edit_type' => $slug,
				)
			);
		}
	}

	/**
	 * Deletes a registration type.
	 */
	public function handle_delete_type(): void {
		$this->authorise();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$slug = isset( $_POST['slug'] ) ? sanitize_key( (string) wp_unslash( $_POST['slug'] ) ) : '';
		try {
			$this->types->delete( $slug );
			$this->redirect( array( 'deleted' => 'type' ) );
		} catch ( RuntimeException $e ) {
			$this->redirect( array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Saves a field definition.
	 */
	public function handle_save_field(): void {
		$this->authorise();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$key   = isset( $_POST['key'] ) ? sanitize_key( (string) wp_unslash( $_POST['key'] ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['label'] ) ) : '';
		$types = isset( $_POST['registration_types'] ) && is_array( $_POST['registration_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['registration_types'] ) ) : array();
		$data  = array(
			'key'                => '' !== $key ? $key : FieldDefinition::keyify( $label ),
			'label'              => $label,
			'type'               => isset( $_POST['type'] ) ? sanitize_key( (string) wp_unslash( $_POST['type'] ) ) : FieldDefinition::TYPE_TEXT,
			'required'           => ! empty( $_POST['required'] ),
			'registration_types' => $types,
			'placeholder'        => isset( $_POST['placeholder'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['placeholder'] ) ) : '',
			'help'               => isset( $_POST['help'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['help'] ) ) : '',
			'options'            => isset( $_POST['options'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['options'] ) ) : '',
			'show_in_checkout'   => ! empty( $_POST['show_in_checkout'] ),
			'show_in_account'    => ! empty( $_POST['show_in_account'] ),
			'map_to'             => isset( $_POST['map_to'] ) ? sanitize_key( (string) wp_unslash( $_POST['map_to'] ) ) : '',
			'order'              => isset( $_POST['order'] ) ? (int) $_POST['order'] : 10,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		try {
			$this->fields->save( FieldDefinition::from_array( $data ) );
			$this->redirect( array( 'saved' => 'field' ) );
		} catch ( InvalidArgumentException $e ) {
			$this->redirect(
				array(
					'error'      => $e->getMessage(),
					'edit_field' => $key,
				)
			);
		}
	}

	/**
	 * Deletes a field definition.
	 */
	public function handle_delete_field(): void {
		$this->authorise();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorise().
		$key = isset( $_POST['key'] ) ? sanitize_key( (string) wp_unslash( $_POST['key'] ) ) : '';
		$this->fields->delete( $key );
		$this->redirect( array( 'deleted' => 'field' ) );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage registration settings.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$error      = isset( $_GET['error'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['error'] ) ) : '';
		$saved      = isset( $_GET['saved'] );
		$deleted    = isset( $_GET['deleted'] );
		$edit_type  = isset( $_GET['edit_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['edit_type'] ) ) : null;
		$edit_field = isset( $_GET['edit_field'] ) ? sanitize_key( (string) wp_unslash( $_GET['edit_field'] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		PageHeader::open( 'eurob2b-registration-admin', __( 'Registration', 'eurob2b' ) );
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'eurob2b' ) . '</p></div>';
		}
		if ( $deleted ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Deleted.', 'eurob2b' ) . '</p></div>';
		}
		$owners = $this->fields->mapping_owners();
		foreach ( $this->fields->dropped_mappings() as $key => $billing ) {
			$field = $this->fields->find( $key );
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: 1: WooCommerce billing field key, 2: label of the field that fills it, 3: label of the field that no longer fills it */
					__( 'The billing field %1$s was filled from more than one field. Only "%2$s" fills it now; "%3$s" keeps its own value. Please check the mapping of "%3$s".', 'eurob2b' ),
					$billing,
					isset( $owners[ $billing ] ) ? $owners[ $billing ]->label : '',
					null !== $field ? $field->label : $key
				)
			) . '</p></div>';
		}

		if ( null !== $edit_type ) {
			$this->render_type_form( '' !== $edit_type ? $this->types->find( $edit_type ) : null );
		} elseif ( null !== $edit_field ) {
			$this->render_field_form( '' !== $edit_field ? $this->fields->find( $edit_field ) : null );
		} else {
			$this->render_overview();
		}
		echo '</div>';
	}

	/**
	 * Overview: types table and fields table.
	 */
	private function render_overview(): void {
		echo '<h2>' . esc_html__( 'Registration types', 'eurob2b' ) . ' <a class="page-title-action" href="' . esc_url( Menu::url( self::SLUG, array( 'edit_type' => '' ) ) ) . '">' . esc_html__( 'Add type', 'eurob2b' ) . '</a></h2>';
		echo '<p class="description">' . esc_html__( 'Types are what customers choose when they register. Each type decides the customer type, whether you approve manually and which group the customer lands in.', 'eurob2b' ) . '</p>';
		echo '<table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__( 'Label', 'eurob2b' ) . '</th><th>' . esc_html__( 'Slug', 'eurob2b' ) . '</th><th>' . esc_html__( 'Customer type', 'eurob2b' ) . '</th><th>' . esc_html__( 'Approval', 'eurob2b' ) . '</th><th>' . esc_html__( 'Group', 'eurob2b' ) . '</th><th>' . esc_html__( 'Enabled', 'eurob2b' ) . '</th><th>' . esc_html__( 'Order', 'eurob2b' ) . '</th></tr></thead><tbody>';
		foreach ( $this->types->all() as $type ) {
			$group = null !== $type->group_id ? $this->groups->find( $type->group_id ) : null;
			echo '<tr><td><strong><a href="' . esc_url( Menu::url( self::SLUG, array( 'edit_type' => $type->slug ) ) ) . '">' . esc_html( $type->label ) . '</a></strong></td>';
			echo '<td><code>' . esc_html( $type->slug ) . '</code></td><td>' . esc_html( GroupsPage::type_label( $type->customer_type ) ) . '</td>';
			echo '<td>' . esc_html( $type->requires_approval() ? __( 'Manual', 'eurob2b' ) : __( 'Automatic', 'eurob2b' ) ) . '</td>';
			echo '<td>' . esc_html( null !== $group ? $group->name : __( 'Default of the customer type', 'eurob2b' ) ) . '</td>';
			echo '<td>' . ( $type->enabled ? esc_html__( 'Yes', 'eurob2b' ) : '—' ) . '</td><td>' . esc_html( (string) $type->order ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2 style="margin-top:32px;">' . esc_html__( 'Custom fields', 'eurob2b' ) . ' <a class="page-title-action" href="' . esc_url( Menu::url( self::SLUG, array( 'edit_field' => '' ) ) ) . '">' . esc_html__( 'Add field', 'eurob2b' ) . '</a></h2>';
		echo '<p class="description">' . esc_html__( 'Extra fields on the registration form. A field can also appear at checkout and in My Account, and can be mapped to a WooCommerce billing field so checkout is pre-filled.', 'eurob2b' ) . '</p>';
		echo '<table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__( 'Label', 'eurob2b' ) . '</th><th>' . esc_html__( 'Key', 'eurob2b' ) . '</th><th>' . esc_html__( 'Type', 'eurob2b' ) . '</th><th>' . esc_html__( 'Required', 'eurob2b' ) . '</th><th>' . esc_html__( 'Registration types', 'eurob2b' ) . '</th><th>' . esc_html__( 'Checkout', 'eurob2b' ) . '</th><th>' . esc_html__( 'My Account', 'eurob2b' ) . '</th><th>' . esc_html__( 'Mapped to', 'eurob2b' ) . '</th></tr></thead><tbody>';
		$all_fields = $this->fields->all();
		if ( empty( $all_fields ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No custom fields yet.', 'eurob2b' ) . '</td></tr>';
		}
		foreach ( $all_fields as $field ) {
			echo '<tr><td><strong><a href="' . esc_url( Menu::url( self::SLUG, array( 'edit_field' => $field->key ) ) ) . '">' . esc_html( $field->label ) . '</a></strong></td>';
			echo '<td><code>' . esc_html( $field->key ) . '</code></td><td>' . esc_html( $field->type ) . '</td><td>' . ( $field->required ? esc_html__( 'Yes', 'eurob2b' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( empty( $field->registration_types ) ? __( 'All', 'eurob2b' ) : implode( ', ', $field->registration_types ) ) . '</td>';
			echo '<td>' . ( $field->show_in_checkout ? esc_html__( 'Yes', 'eurob2b' ) : '—' ) . '</td><td>' . ( $field->show_in_account ? esc_html__( 'Yes', 'eurob2b' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $field->map_to ? $field->map_to : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Type form.
	 *
	 * @param RegistrationType|null $type Type or null for new.
	 */
	private function render_type_form( ?RegistrationType $type ): void {
		echo '<h2>' . ( null === $type ? esc_html__( 'Add registration type', 'eurob2b' ) : esc_html__( 'Edit registration type', 'eurob2b' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_TYPE ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row_text( 'label', __( 'Label (shown to customers)', 'eurob2b' ), $type->label ?? '', true );
		if ( null === $type ) {
			$this->row_text( 'slug', __( 'Slug', 'eurob2b' ), '', false, __( 'Leave empty to derive it from the label.', 'eurob2b' ) );
		} else {
			echo '<input type="hidden" name="slug" value="' . esc_attr( $type->slug ) . '" />';
			echo '<tr><th scope="row">' . esc_html__( 'Slug', 'eurob2b' ) . '</th><td><code>' . esc_html( $type->slug ) . '</code></td></tr>';
		}
		echo '<tr><th scope="row"><label for="eurob2b-type-customer-type">' . esc_html__( 'Customer type', 'eurob2b' ) . '</label></th><td><select id="eurob2b-type-customer-type" name="customer_type">';
		foreach ( array( CustomerType::B2B, CustomerType::B2C ) as $ct ) {
			echo '<option value="' . esc_attr( $ct->value ) . '" ' . selected( ( $type->customer_type ?? CustomerType::B2B )->value, $ct->value, false ) . '>' . esc_html( GroupsPage::type_label( $ct ) ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="eurob2b-type-approval">' . esc_html__( 'Approval', 'eurob2b' ) . '</label></th><td><select id="eurob2b-type-approval" name="approval">';
		echo '<option value="automatic" ' . selected( $type->approval ?? '', 'automatic', false ) . '>' . esc_html__( 'Automatic: the account is active immediately', 'eurob2b' ) . '</option>';
		echo '<option value="manual" ' . selected( $type->approval ?? '', 'manual', false ) . '>' . esc_html__( 'Manual: you approve each registration', 'eurob2b' ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="eurob2b-type-group">' . esc_html__( 'Target group', 'eurob2b' ) . '</label></th><td><select id="eurob2b-type-group" name="group_id"><option value="0">' . esc_html__( 'Default group of the customer type', 'eurob2b' ) . '</option>';
		foreach ( $this->groups->all() as $group ) {
			echo '<option value="' . esc_attr( (string) $group->id ) . '" ' . selected( $type->group_id ?? 0, $group->id, false ) . '>' . esc_html( $group->name . ' (' . GroupsPage::type_label( $group->customer_type ) . ')' ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Must belong to the chosen customer type.', 'eurob2b' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="eurob2b-type-description">' . esc_html__( 'Description (shown under the choice)', 'eurob2b' ) . '</label></th><td><textarea class="large-text" rows="2" id="eurob2b-type-description" name="description">' . esc_textarea( $type->description ?? '' ) . '</textarea></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'eurob2b' ) . '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( $type->enabled ?? true, true, false ) . ' /> ' . esc_html__( 'Offer this type on the registration form', 'eurob2b' ) . '</label></td></tr>';
		$this->row_number( 'order', __( 'Order', 'eurob2b' ), $type->order ?? 10 );
		echo '</tbody></table>';
		submit_button( null === $type ? __( 'Create type', 'eurob2b' ) : __( 'Save type', 'eurob2b' ) );
		echo ' <a class="button" href="' . esc_url( Menu::url( self::SLUG ) ) . '">' . esc_html__( 'Back', 'eurob2b' ) . '</a></form>';

		if ( null !== $type ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px;">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE_TYPE ) . '" /><input type="hidden" name="slug" value="' . esc_attr( $type->slug ) . '" />';
			submit_button( __( 'Delete type', 'eurob2b' ), 'delete', 'submit', false );
			echo ' <span class="description">' . esc_html__( 'Customers who registered with this type keep their status and group.', 'eurob2b' ) . '</span></form>';
		}
	}

	/**
	 * Field form.
	 *
	 * @param FieldDefinition|null $field Field or null for new.
	 */
	private function render_field_form( ?FieldDefinition $field ): void {
		echo '<h2>' . ( null === $field ? esc_html__( 'Add field', 'eurob2b' ) : esc_html__( 'Edit field', 'eurob2b' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_FIELD ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row_text( 'label', __( 'Label', 'eurob2b' ), $field->label ?? '', true );
		if ( null === $field ) {
			$this->row_text( 'key', __( 'Key', 'eurob2b' ), '', false, __( 'Lower-case identifier for meta keys and exports; leave empty to derive it from the label.', 'eurob2b' ) );
		} else {
			echo '<input type="hidden" name="key" value="' . esc_attr( $field->key ) . '" />';
			echo '<tr><th scope="row">' . esc_html__( 'Key', 'eurob2b' ) . '</th><td><code>' . esc_html( $field->key ) . '</code> <span class="description">' . esc_html( __( 'Meta key:', 'eurob2b' ) . ' ' . $field->meta_key() ) . '</span></td></tr>';
		}
		echo '<tr><th scope="row"><label for="eurob2b-field-type">' . esc_html__( 'Type', 'eurob2b' ) . '</label></th><td><select id="eurob2b-field-type" name="type">';
		foreach ( FieldDefinition::types() as $t ) {
			echo '<option value="' . esc_attr( $t ) . '" ' . selected( $field->type ?? 'text', $t, false ) . '>' . esc_html( $t ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="eurob2b-field-options">' . esc_html__( 'Options (select only)', 'eurob2b' ) . '</label></th><td><textarea class="large-text code" rows="4" id="eurob2b-field-options" name="options">' . esc_textarea( null !== $field ? $field->options_as_text() : '' ) . '</textarea><p class="description">' . esc_html__( 'One per line as value|Label, or just Label.', 'eurob2b' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Required', 'eurob2b' ) . '</th><td><label><input type="checkbox" name="required" value="1" ' . checked( $field->required ?? false, true, false ) . ' /> ' . esc_html__( 'Must be filled on registration', 'eurob2b' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Registration types', 'eurob2b' ) . '</th><td><fieldset>';
		foreach ( $this->types->all() as $type ) {
			echo '<label style="display:block"><input type="checkbox" name="registration_types[]" value="' . esc_attr( $type->slug ) . '" ' . checked( in_array( $type->slug, $field->registration_types ?? array(), true ), true, false ) . ' /> ' . esc_html( $type->label ) . '</label>';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'None ticked = shown for every type.', 'eurob2b' ) . '</p></td></tr>';
		$this->row_text( 'placeholder', __( 'Placeholder', 'eurob2b' ), $field->placeholder ?? '' );
		$this->row_text( 'help', __( 'Help text', 'eurob2b' ), $field->help ?? '' );
		echo '<tr><th scope="row">' . esc_html__( 'Also show', 'eurob2b' ) . '</th><td>';
		echo '<label style="display:block"><input type="checkbox" name="show_in_checkout" value="1" ' . checked( $field->show_in_checkout ?? false, true, false ) . ' /> ' . esc_html__( 'In the checkout billing section', 'eurob2b' ) . '</label>';
		echo '<label style="display:block"><input type="checkbox" name="show_in_account" value="1" ' . checked( $field->show_in_account ?? true, true, false ) . ' /> ' . esc_html__( 'On the My Account details page', 'eurob2b' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="eurob2b-field-map">' . esc_html__( 'Map to billing field', 'eurob2b' ) . '</label></th><td><select id="eurob2b-field-map" name="map_to"><option value="">' . esc_html__( 'No mapping', 'eurob2b' ) . '</option>';
		$owners = $this->fields->mapping_owners( $field->key ?? '' );
		foreach ( FieldDefinition::mappable_billing_fields() as $key => $label ) {
			// A billing field another field already fills cannot be chosen (unless this field holds it already).
			$owner = ( $field->map_to ?? '' ) !== $key ? ( $owners[ $key ] ?? null ) : null;
			$text  = $label . ' (' . $key . ')';
			if ( null !== $owner ) {
				/* translators: %s: label of the field that already fills this billing field */
				$text .= ' – ' . sprintf( __( 'already filled from "%s"', 'eurob2b' ), $owner->label );
			}
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $field->map_to ?? '', $key, false ) . disabled( null !== $owner, true, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'The value is also written to this WooCommerce billing field so checkout is pre-filled.', 'eurob2b' ) . '</p></td></tr>';
		$this->row_number( 'order', __( 'Order', 'eurob2b' ), $field->order ?? 10 );
		echo '</tbody></table>';
		submit_button( null === $field ? __( 'Create field', 'eurob2b' ) : __( 'Save field', 'eurob2b' ) );
		echo ' <a class="button" href="' . esc_url( Menu::url( self::SLUG ) ) . '">' . esc_html__( 'Back', 'eurob2b' ) . '</a></form>';

		if ( null !== $field ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px;">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE_FIELD ) . '" /><input type="hidden" name="key" value="' . esc_attr( $field->key ) . '" />';
			submit_button( __( 'Delete field', 'eurob2b' ), 'delete', 'submit', false );
			echo ' <span class="description">' . esc_html__( 'Values already stored for customers are kept until the customer data is erased.', 'eurob2b' ) . '</span></form>';
		}
	}

	/**
	 * Text row helper.
	 *
	 * @param string $name     Input name.
	 * @param string $label    Label.
	 * @param string $value    Value.
	 * @param bool   $required Required.
	 * @param string $help     Help text.
	 */
	private function row_text( string $name, string $label, string $value, bool $required = false, string $help = '' ): void {
		echo '<tr><th scope="row"><label for="eurob2b-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="eurob2b-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . ' />';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Number row helper.
	 *
	 * @param string $name  Input name.
	 * @param string $label Label.
	 * @param int    $value Value.
	 */
	private function row_number( string $name, string $label, int $value ): void {
		echo '<tr><th scope="row"><label for="eurob2b-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="eurob2b-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" /></td></tr>';
	}

	/**
	 * Capability + nonce.
	 */
	private function authorise(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage registration settings.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
	}

	/**
	 * Redirect helper.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function redirect( array $args ): void {
		if ( wp_safe_redirect( Menu::url( self::SLUG, $args ) ) ) {
			exit;
		}
	}
}

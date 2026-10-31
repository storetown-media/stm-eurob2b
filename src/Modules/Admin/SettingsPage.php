<?php
/**
 * Settings page.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use EuroB2B\Infrastructure\Capabilities;

/**
 * Renders the settings sections and handles the save request.
 *
 * The sections are listed in a side navigation, grouped by task (PLT-017): customers and access,
 * selling, tax and legal, reports. A section that is not in NAV_GROUPS (for example from an add-on)
 * appears under "More".
 *
 * Save flow: POST to admin-post.php with action "eurob2b_save_settings",
 * nonce and capability checked server-side, values sanitised by the schema,
 * redirect back with a status flag. No JavaScript is required.
 */
final class SettingsPage {

	public const ACTION = 'eurob2b_save_settings';
	public const NONCE  = 'eurob2b_settings';
	public const SLUG   = 'settings';

	/**
	 * Groups of the side navigation, each with its sections in order.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const NAV_GROUPS = array(
		'customers' => array( 'registration', 'access', 'company' ),
		'selling'   => array( 'pricing', 'ordering', 'stock', 'quotes', 'conversations', 'payments' ),
		'tax'       => array( 'tax', 'legal', 'einvoice', 'privacy' ),
		'reports'   => array( 'reports', 'datev' ),
	);

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the page in the menu.
	 *
	 * @param Menu $menu Menu.
	 */
	public function register_page( Menu $menu ): void {
		$menu->add_page( self::SLUG, __( 'EuroB2B settings', 'eurob2b' ), __( 'Settings', 'eurob2b' ), Capabilities::MANAGE_SETTINGS, array( $this, 'render' ), 100 );
	}

	/**
	 * Handles the POST (admin_post_eurob2b_save_settings).
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; every value is sanitised by the schema in update().
		$raw = isset( $_POST['eurob2b'] ) && is_array( $_POST['eurob2b'] ) ? wp_unslash( $_POST['eurob2b'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$tab = isset( $_POST['eurob2b_tab'] ) ? sanitize_key( (string) wp_unslash( $_POST['eurob2b_tab'] ) ) : '';

		// Only the fields of the submitted tab are written (patch); other tabs keep their stored values,
		// so a stale page cannot undo what someone changed on another tab meanwhile.
		$fields = $this->settings->schema()->fields_in( $tab );
		$input  = array();
		foreach ( array_keys( $fields ) as $id ) {
			$input[ $id ] = is_array( $raw ) ? ( $raw[ $id ] ?? null ) : null;
		}
		$args = array( 'updated' => '1' );
		if ( empty( $fields ) ) {
			$args = array( 'error' => __( 'Unknown settings tab; nothing was saved.', 'eurob2b' ) );
		} else {
			try {
				$this->settings->patch( $input );
			} catch ( \RuntimeException $e ) {
				$args = array( 'error' => $e->getMessage() );
			}
		}

		$url = Menu::url( self::SLUG, array_merge( array( 'tab' => $tab ), $args ) );
		// wp_safe_redirect() returns false when the "wp_redirect" filter cancels it (tests); then we must not exit.
		if ( wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'eurob2b' ), '', array( 'response' => 403 ) );
		}

		$schema   = $this->settings->schema();
		$sections = $schema->sections();
		$values   = $this->settings->all();

		$nav = self::nav( $sections );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$current = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : '';
		if ( ! isset( $sections[ $current ] ) ) {
			$first   = reset( $nav );
			$current = false === $first ? '' : (string) ( $first['sections'][0] ?? '' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- status flag only.
		$updated = isset( $_GET['updated'] );

		PageHeader::open( 'eurob2b-settings', __( 'EuroB2B settings', 'eurob2b' ) );

		if ( $updated ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'eurob2b' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice after a redirect.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['error'] ) ) : '';
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		echo '<div class="eurob2b-settings-layout"><nav class="eurob2b-sidenav" aria-label="' . esc_attr__( 'Settings sections', 'eurob2b' ) . '">';
		foreach ( $nav as $key => $group ) {
			echo '<p class="eurob2b-sidenav__group" id="eurob2b-sidenav-' . esc_attr( $key ) . '">' . esc_html( $group['label'] ) . '</p>';
			echo '<ul aria-labelledby="eurob2b-sidenav-' . esc_attr( $key ) . '">';
			foreach ( $group['sections'] as $id ) {
				echo '<li><a href="' . esc_url( Menu::url( self::SLUG, array( 'tab' => $id ) ) ) . '"' . ( $id === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $sections[ $id ]['label'] ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</nav><div class="eurob2b-settings-main">';
		if ( isset( $sections[ $current ] ) ) {
			echo '<h2 class="eurob2b-settings-title">' . esc_html( $sections[ $current ]['label'] ) . '</h2>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="eurob2b_tab" value="' . esc_attr( $current ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $schema->fields_in( $current ) as $id => $field ) {
			$this->render_row( $id, $field, $values[ $id ] ?? $field['default'] );
		}
		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'eurob2b' ) );
		echo '</form></div></div></div>';
	}

	/**
	 * Sections grouped for the side navigation: group => label and section ids, groups and sections in
	 * the order of NAV_GROUPS; sections missing there follow under "More" in their schema order. Groups
	 * without a registered section are left out.
	 *
	 * @param array<string, array{label: string, order: int}> $sections Sections of the schema, in order.
	 * @return array<string, array{label: string, sections: array<int, string>}>
	 */
	public static function nav( array $sections ): array {
		$labels = array(
			'customers' => __( 'Customers & access', 'eurob2b' ),
			'selling'   => __( 'Selling', 'eurob2b' ),
			'tax'       => __( 'Tax & legal', 'eurob2b' ),
			'reports'   => __( 'Reports', 'eurob2b' ),
			'other'     => __( 'More', 'eurob2b' ),
		);
		$nav    = array();
		$rest   = $sections;
		foreach ( self::NAV_GROUPS as $key => $ids ) {
			foreach ( $ids as $id ) {
				if ( isset( $sections[ $id ] ) ) {
					$nav[ $key ]['sections'][] = $id;
					unset( $rest[ $id ] );
				}
			}
		}
		foreach ( array_keys( $rest ) as $id ) {
			$nav['other']['sections'][] = (string) $id;
		}
		$out = array();
		foreach ( $nav as $key => $group ) {
			$out[ $key ] = array(
				'label'    => $labels[ $key ],
				'sections' => $group['sections'],
			);
		}
		return $out;
	}

	/**
	 * Renders one form row.
	 *
	 * @param string               $id    Field id.
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Current value.
	 */
	private function render_row( string $id, array $field, $value ): void {
		$name    = 'eurob2b[' . $id . ']';
		$dom_id  = 'eurob2b-' . $id;
		$schema  = $this->settings->schema();
		$options = $schema->options( $id );

		echo '<tr><th scope="row"><label for="' . esc_attr( $dom_id ) . '">' . esc_html( (string) $field['label'] ) . '</label></th><td>';

		switch ( $field['type'] ) {
			case SettingsSchema::TYPE_BOOLEAN:
				echo '<label><input type="checkbox" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $value, true, false ) . ' /> ';
				echo esc_html( (string) ( $field['checkbox_label'] ?? $field['label'] ) ) . '</label>';
				break;

			case SettingsSchema::TYPE_TEXTAREA:
				echo '<textarea class="large-text" rows="5" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
				break;

			case SettingsSchema::TYPE_NUMBER:
				$attrs = '';
				foreach ( array( 'min', 'max', 'step' ) as $attr ) {
					if ( isset( $field[ $attr ] ) ) {
						$attrs .= ' ' . $attr . '="' . esc_attr( (string) $field[ $attr ] ) . '"';
					}
				}
				echo '<input type="number" class="small-text" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
				break;

			case SettingsSchema::TYPE_SELECT:
				echo '<select id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( $options as $option_value => $label ) {
					echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( (string) $value, $option_value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case SettingsSchema::TYPE_MULTISELECT:
				$selected = array_map( 'strval', (array) $value );
				echo '<fieldset>';
				foreach ( $options as $option_value => $label ) {
					echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $option_value ) . '" ' . checked( in_array( $option_value, $selected, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
				}
				echo '</fieldset>';
				break;

			default:
				echo '<input type="text" class="regular-text" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}//end switch

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $field['description'] ) . '</p>';
		}
		echo '</td></tr>';
	}
}

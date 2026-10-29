<?php
/**
 * Registration form locked to one registration type.
 *
 * Renders the same fields and hooks as WooCommerce's own register form, so
 * WooCommerce's form handler processes the submission. Override by copying
 * to yourtheme/eurob2b/registration-form.php.
 *
 * @package EuroB2B
 * @var \EuroB2B\Modules\Registration\RegistrationType $type Registration type.
 */

defined( 'ABSPATH' ) || exit;

$eurob2b_generate_username = 'yes' === get_option( 'woocommerce_registration_generate_username' );
$eurob2b_generate_password = 'yes' === get_option( 'woocommerce_registration_generate_password' );
?>
<div class="eurob2b-registration" data-eurob2b-locked-type="<?php echo esc_attr( $type->slug ); ?>">
	<?php if ( '' !== $type->description ) : ?>
		<p class="eurob2b-registration-description"><?php echo esc_html( $type->description ); ?></p>
	<?php endif; ?>

	<form method="post" class="woocommerce-form woocommerce-form-register register eurob2b-registration-form" <?php do_action( 'woocommerce_register_form_tag' ); ?>>

		<?php do_action( 'woocommerce_register_form_start' ); ?>

		<?php if ( ! $eurob2b_generate_username ) : ?>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="reg_username"><?php esc_html_e( 'Username', 'eurob2b' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="reg_username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" required /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- same as WooCommerce core. ?>
			</p>
		<?php endif; ?>

		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="reg_email"><?php esc_html_e( 'Email address', 'eurob2b' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
			<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>" required /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- same as WooCommerce core. ?>
		</p>

		<?php if ( ! $eurob2b_generate_password ) : ?>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="reg_password"><?php esc_html_e( 'Password', 'eurob2b' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" required />
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'A link to set a new password will be sent to your email address.', 'eurob2b' ); ?></p>
		<?php endif; ?>

		<?php do_action( 'woocommerce_register_form' ); ?>

		<p class="woocommerce-form-row form-row">
			<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
			<button type="submit" class="woocommerce-Button woocommerce-button button wp-element-button woocommerce-form-register__submit" name="register" value="<?php esc_attr_e( 'Register', 'eurob2b' ); ?>"><?php esc_html_e( 'Register', 'eurob2b' ); ?></button>
		</p>

		<?php do_action( 'woocommerce_register_form_end' ); ?>

	</form>
</div>

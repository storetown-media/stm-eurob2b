<?php
/**
 * Admin e-mail: new registration (HTML).
 *
 * @package EuroB2B
 * @var string                                                 $email_heading
 * @var \EuroB2B\Modules\Registration\Emails\AbstractEmail     $email
 * @var \WP_User|null                                          $user
 * @var string                                                 $customer_name
 * @var \EuroB2B\Modules\Registration\RegistrationType|null    $registration_type
 * @var array<string, string>                                  $fields
 * @var string                                                 $profile_url
 * @var string                                                 $additional_content
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>
	<?php
	/* translators: 1: customer name, 2: registration type */
	echo esc_html( sprintf( __( '%1$s registered as "%2$s". The account is active.', 'eurob2b' ), $customer_name, null !== $registration_type ? $registration_type->label : '' ) );
	?>
</p>
<?php if ( $user instanceof WP_User ) : ?>
<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #e5e5e5;" border="1">
	<tr><th scope="row" style="text-align:left;"><?php esc_html_e( 'Username', 'eurob2b' ); ?></th><td><?php echo esc_html( $user->user_login ); ?></td></tr>
	<tr><th scope="row" style="text-align:left;"><?php esc_html_e( 'E-mail', 'eurob2b' ); ?></th><td><?php echo esc_html( $user->user_email ); ?></td></tr>
	<?php foreach ( $fields as $label => $value ) : ?>
	<tr><th scope="row" style="text-align:left;"><?php echo esc_html( $label ); ?></th><td><?php echo nl2br( esc_html( $value ) ); ?></td></tr>
	<?php endforeach; ?>
</table>
<?php endif; ?>
<?php if ( '' !== $profile_url ) : ?>
<p><a href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Open the user profile', 'eurob2b' ); ?></a></p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );

<?php
/**
 * Customer e-mail: account approved (HTML).
 *
 * @package EuroB2B
 * @var string                                              $email_heading
 * @var \EuroB2B\Modules\Registration\Emails\AbstractEmail  $email
 * @var string                                              $customer_name
 * @var string                                              $account_url
 * @var string                                              $additional_content
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>
	<?php
	/* translators: %s: customer name */
	echo esc_html( sprintf( __( 'Hello %s,', 'eurob2b' ), $customer_name ) );
	?>
</p>
<p><?php esc_html_e( 'Your account has been checked and approved. You can now log in and see your conditions.', 'eurob2b' ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Log in to your account', 'eurob2b' ); ?></a></p>
<p><?php esc_html_e( 'No password yet, or forgotten it?', 'eurob2b' ); ?> <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Set your password here.', 'eurob2b' ); ?></a></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );

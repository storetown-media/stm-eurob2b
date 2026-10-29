<?php
/**
 * Customer e-mail: registration not approved (HTML).
 *
 * @package EuroB2B
 * @var string                                              $email_heading
 * @var \EuroB2B\Modules\Registration\Emails\AbstractEmail  $email
 * @var string                                              $customer_name
 * @var string                                              $note
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
<p><?php esc_html_e( 'Unfortunately we could not approve your registration.', 'eurob2b' ); ?></p>
<?php if ( '' !== $note ) : ?>
<p><?php echo nl2br( esc_html( $note ) ); ?></p>
<?php endif; ?>
<p><?php esc_html_e( 'If you think this is a mistake, simply reply to this e-mail.', 'eurob2b' ); ?></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );

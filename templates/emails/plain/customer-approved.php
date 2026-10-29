<?php
/**
 * Customer e-mail: account approved (plain text).
 *
 * @package EuroB2B
 * @var string $email_heading
 * @var string $customer_name
 * @var string $account_url
 * @var string $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer name */
echo esc_html( sprintf( __( 'Hello %s,', 'eurob2b' ), $customer_name ) ) . "\n\n";
echo esc_html__( 'Your account has been checked and approved. You can now log in and see your conditions.', 'eurob2b' ) . "\n\n";
echo esc_url_raw( $account_url ) . "\n";
echo esc_html__( 'No password yet, or forgotten it? Set it here:', 'eurob2b' ) . ' ' . esc_url_raw( wp_lostpassword_url() ) . "\n";
if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}
echo "\n" . esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) );

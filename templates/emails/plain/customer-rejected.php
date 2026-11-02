<?php
/**
 * Customer e-mail: registration not approved (plain text).
 *
 * @package EuroB2B
 * @var string $email_heading
 * @var string $customer_name
 * @var string $note
 * @var string $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer name */
echo esc_html( sprintf( __( 'Hello %s,', 'stm-eu-b2b' ), $customer_name ) ) . "\n\n";
echo esc_html__( 'Unfortunately we could not approve your registration.', 'stm-eu-b2b' ) . "\n";
if ( '' !== $note ) {
	echo "\n" . esc_html( $note ) . "\n";
}
echo "\n" . esc_html__( 'If you think this is a mistake, simply reply to this e-mail.', 'stm-eu-b2b' ) . "\n";
if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}
echo "\n" . esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter: the footer text of the shop's plain-text e-mails, applied as WooCommerce's own plain templates do.

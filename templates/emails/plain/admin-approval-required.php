<?php
/**
 * Admin e-mail: a registration needs approval (plain text).
 *
 * @package EuroB2B
 * @var string                                                 $email_heading
 * @var \WP_User|null                                          $user
 * @var string                                                 $customer_name
 * @var \EuroB2B\Modules\Registration\RegistrationType|null    $registration_type
 * @var array<string, string>                                  $fields
 * @var string                                                 $approvals_url
 * @var string                                                 $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: 1: customer name, 2: registration type */
echo esc_html( sprintf( __( '%1$s registered as "%2$s" and is waiting for your approval.', 'eurob2b' ), $customer_name, null !== $registration_type ? $registration_type->label : '' ) ) . "\n\n";
if ( $user instanceof WP_User ) {
	echo esc_html__( 'Username', 'eurob2b' ) . ': ' . esc_html( $user->user_login ) . "\n";
	echo esc_html__( 'E-mail', 'eurob2b' ) . ': ' . esc_html( $user->user_email ) . "\n";
	foreach ( $fields as $label => $value ) {
		echo esc_html( $label ) . ': ' . esc_html( $value ) . "\n";
	}
}
echo "\n" . esc_html__( 'Approvals', 'eurob2b' ) . ': ' . esc_url_raw( $approvals_url ) . "\n";
if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}
echo "\n" . esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) );

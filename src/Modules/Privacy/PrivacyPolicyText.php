<?php
/**
 * Suggested privacy policy text.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy;

use EuroB2B\Modules\Admin\Settings;

/**
 * Registers a suggested section for the shop's privacy policy (PRV-007).
 *
 * WordPress shows it in the privacy policy guide (Settings → Privacy → Policy guide). The text
 * describes what the plugin stores and sends; it follows the current settings (VIES on or off,
 * retention periods). It names a legal basis only where the product owner approved one (the abuse
 * protection, 2026-10-25); the others are open questions for the shop owner's data protection
 * advisor (docs/privacy-and-data-flows.md §8).
 */
final class PrivacyPolicyText {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook callback (admin_init).
	 */
	public function register(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'EuroB2B', $this->content() );
		}
	}

	/**
	 * The suggested text as HTML.
	 */
	public function content(): string {
		/**
		 * Filters the note to the shop owner at the start of the suggested privacy policy text (plain
		 * text). EuroB2B Pro names its checking services in it (PLT-015).
		 *
		 * @param string $intro Note naming VIES as the only service VAT IDs are sent to.
		 */
		$tutorial = apply_filters( 'eurob2b_privacy_policy_intro', __( 'EuroB2B stores the data described below and, only if you enable VAT ID validation, sends VAT IDs to the European Commission (VIES). Remove the sections for features you do not use and add the legal basis of each processing after reviewing it with your data protection advisor. The retention periods follow your current settings.', 'eurob2b' ) );

		$html  = '<p class="privacy-policy-tutorial">' . esc_html( is_string( $tutorial ) ? $tutorial : '' ) . '</p>';
		$html .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'eurob2b' ) . ' </strong></p>';
		// Sections by position; EuroB2B Pro adds those of its modules in between (PLT-015).
		$intro    = $html;
		$sections = array();

		$html  = '';
		$html .= '<h3>' . esc_html__( 'Business customer accounts', 'eurob2b' ) . '</h3>';
		/**
		 * Filters the first sentences of the section on business customer accounts in the suggested
		 * privacy policy text. EuroB2B Pro adds the products agreed for a group (visibility).
		 *
		 * @param string $text Plain text.
		 */
		$account  = apply_filters( 'eurob2b_privacy_policy_account_text', __( 'When you register as a business customer, we store the registration type you chose, the information you enter in the registration form and the time you accepted our terms. We use this information to review your application, to assign you to a customer group and to show you the prices and payment methods agreed for that group.', 'eurob2b' ) );
		$html    .= '<p>' . esc_html( is_string( $account ) ? $account : '' );
		$rejected = (int) $this->settings->get( 'privacy_rejected_days' );
		if ( $rejected > 0 ) {
			/* translators: %d: number of days */
			$html .= ' ' . esc_html( sprintf( _n( 'If we reject a registration we never approved, we delete the account %d day after the rejection, unless orders were placed with it or it has other roles in our shop.', 'If we reject a registration we never approved, we delete the account %d days after the rejection, unless orders were placed with it or it has other roles in our shop.', $rejected, 'eurob2b' ), $rejected ) );
		}
		if ( ProcessingRecord::has_file_field() ) {
			$file_days = max( 0, (int) $this->settings->get( 'registration_file_retention_days' ) );
			$html     .= ' ' . esc_html(
				$file_days > 0
					/* translators: %d: number of days */
					? sprintf( _n( 'If you upload a document with your registration (for example a trade licence), we store the file, its name, type and size and the time of the upload. We use the document only to review your registration; it cannot be downloaded publicly. We delete it %d day after our decision on your registration, at the latest with your customer account.', 'If you upload a document with your registration (for example a trade licence), we store the file, its name, type and size and the time of the upload. We use the document only to review your registration; it cannot be downloaded publicly. We delete it %d days after our decision on your registration, at the latest with your customer account.', $file_days, 'eurob2b' ), $file_days )
					: __( 'If you upload a document with your registration (for example a trade licence), we store the file, its name, type and size and the time of the upload. We use the document only to review your registration; it cannot be downloaded publicly. We delete it with your customer account.', 'eurob2b' )
			);
		}
		$html        .= '</p>';
		$sections[10] = $html;

		$html  = '<h3>' . esc_html__( 'VAT identification number', 'eurob2b' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'If you enter a VAT identification number, we store it with your account and on your orders, because it decides the tax treatment of your orders.', 'eurob2b' );
		if ( (bool) $this->settings->get( 'tax_vies_enabled' ) ) {
			$html .= ' ' . ( '' !== trim( (string) $this->settings->get( 'tax_shop_vat_id' ) )
				? esc_html__( 'To confirm that the number is registered, we send it together with our own VAT identification number to the VIES service of the European Commission and store the answer as evidence.', 'eurob2b' )
				: esc_html__( 'To confirm that the number is registered, we send it to the VIES service of the European Commission and store the answer as evidence.', 'eurob2b' ) );
		}
		/**
		 * Filters the sentences that checking services besides VIES add to the section on the VAT
		 * identification number, after the one on VIES (EuroB2B Pro: the BZSt, the Swiss UID register
		 * and the Brønnøysund register while they are on, PLT-015). Plain text.
		 *
		 * @param string[] $sentences Sentences (none without an add-on).
		 */
		$services = apply_filters( 'eurob2b_privacy_policy_vat_services', array() );
		foreach ( is_array( $services ) ? $services : array() as $sentence ) {
			if ( is_string( $sentence ) && '' !== $sentence ) {
				$html .= ' ' . esc_html( $sentence );
			}
		}
		$years = max( 1, (int) $this->settings->get( 'tax_retention_years' ) );
		$days  = max( 1, (int) $this->settings->get( 'tax_retention_orderless_days' ) );
		/* translators: %d: number of years */
		$html .= ' ' . esc_html( sprintf( _n( 'Check results that belong to an order are kept for %d year as tax evidence.', 'Check results that belong to an order are kept for %d years as tax evidence.', $years, 'eurob2b' ), $years ) );
		/* translators: %d: number of days */
		$html        .= ' ' . esc_html( sprintf( _n( 'Other check results are deleted after %d day.', 'Other check results are deleted after %d days.', $days, 'eurob2b' ), $days ) ) . '</p>';
		$sections[20] = $html;

		$html      = '';
		$protected = $this->protected_forms();
		if ( array() !== $protected ) {
			$html .= '<h3>' . esc_html__( 'Protection against abuse', 'eurob2b' ) . '</h3>';
			/* translators: %l: list of the protected forms, e.g. "the registration, quote requests without a customer account and the order form" */
			$html .= '<p>' . esc_html( wp_sprintf( __( 'To protect %l against automated mass requests, we store a hash of your IP address that cannot be turned back into the address, together with the number of attempts, for at most one hour (Art. 6(1)(f) GDPR).', 'eurob2b' ), $protected ) ) . '</p>';
		}
		$sections[70] = $html;

		/**
		 * Sections of the suggested privacy policy text by position (PRV-007): 10 business customer
		 * accounts, 20 VAT identification number, 70 protection against abuse. EuroB2B Pro adds 30
		 * company accounts, 40 quote requests and offers, 50 messages, 60 purchase lists, 80
		 * e-invoicing details, 90 Peppol Directory, 100 confirmation of receipt, 110 export evidence and
		 * 120 purchase order documents. Empty sections are left out.
		 *
		 * @param array<int, string> $sections HTML by position.
		 */
		$sections = apply_filters( 'eurob2b_privacy_policy_sections', $sections );
		$sections = is_array( $sections ) ? array_filter( $sections, 'is_string' ) : array();
		ksort( $sections );
		return $intro . implode( '', $sections );
	}

	/**
	 * The forms whose requests are limited per connection (D-15, D-16), as parts of a sentence.
	 *
	 * @return string[]
	 */
	private function protected_forms(): array {
		$forms = array();
		if ( (int) $this->settings->get( 'registration_throttle_limit' ) > 0 ) {
			$forms[] = __( 'the registration', 'eurob2b' );
		}
		/**
		 * Further forms whose requests are limited per connection, as parts of a sentence ("the order
		 * form"); EuroB2B Pro adds quote requests from guests and the order form.
		 *
		 * @param string[] $forms Forms.
		 */
		$forms = apply_filters( 'eurob2b_protected_forms', $forms );
		return is_array( $forms ) ? array_values( array_filter( $forms, 'is_string' ) ) : array();
	}
}

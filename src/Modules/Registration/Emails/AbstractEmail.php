<?php
/**
 * Base class for EuroB2B e-mails.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration\Emails;

use EuroB2B\Modules\Registration\FieldStorage;
use EuroB2B\Modules\Registration\RegistrationService;
use EuroB2B\Modules\Registration\RegistrationType;
use EuroB2B\Plugin;
use WC_Email;
use WP_User;

/**
 * Shared template handling for the registration e-mails.
 *
 * Templates live in the plugin's templates/emails directory and can be
 * overridden from the theme under woocommerce/emails/.
 */
abstract class AbstractEmail extends WC_Email {

	/**
	 * Customer the e-mail is about.
	 *
	 * @var WP_User|null
	 */
	protected ?WP_User $user = null;

	/**
	 * Registration type, if known.
	 *
	 * @var RegistrationType|null
	 */
	protected ?RegistrationType $registration_type = null;

	/**
	 * Note passed by the trigger (rejection reason).
	 *
	 * @var string
	 */
	protected string $note = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->template_base  = EUROB2B_PLUGIN_DIR . 'templates/';
		$this->template_html  = 'emails/' . $this->template_slug() . '.php';
		$this->template_plain = 'emails/plain/' . $this->template_slug() . '.php';
		$this->placeholders   = array(
			'{site_title}' => $this->get_blogname(),
			'{customer}'   => '',
			'{type}'       => '',
		);
		parent::__construct();
	}

	/**
	 * Template file name without extension.
	 */
	abstract protected function template_slug(): string;

	/**
	 * Loads user, type and placeholders for a user id.
	 *
	 * @param int $user_id User id.
	 */
	protected function load_user( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		$this->user              = $user;
		$this->object            = $user;
		$this->registration_type = Plugin::instance()->container()->get( RegistrationService::class )->registration_type_of( $user_id );

		$this->placeholders['{customer}'] = $this->customer_name();
		$this->placeholders['{type}']     = null !== $this->registration_type ? $this->registration_type->label : '';
		return true;
	}

	/**
	 * Display name of the customer.
	 */
	protected function customer_name(): string {
		if ( null === $this->user ) {
			return '';
		}
		$name = trim( $this->user->first_name . ' ' . $this->user->last_name );
		return '' !== $name ? $name : $this->user->display_name;
	}

	/**
	 * Custom field labels and values of the customer for the templates.
	 *
	 * @return array<string, string> label => value
	 */
	protected function field_values(): array {
		if ( null === $this->user ) {
			return array();
		}
		return Plugin::instance()->container()->get( FieldStorage::class )->labelled_values( $this->user->ID );
	}

	/**
	 * Template variables shared by html and plain templates.
	 *
	 * @param bool $plain Plain text mode.
	 * @return array<string, mixed>
	 */
	protected function template_args( bool $plain ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => $this->is_admin_email(),
			'plain_text'         => $plain,
			'email'              => $this,
			'user'               => $this->user,
			'customer_name'      => $this->customer_name(),
			'registration_type'  => $this->registration_type,
			'fields'             => $this->field_values(),
			'note'               => $this->note,
			'approvals_url'      => admin_url( 'admin.php?page=eurob2b-approvals' ),
			'profile_url'        => null !== $this->user ? get_edit_user_link( $this->user->ID ) : '',
			'account_url'        => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ),
		);
	}

	/**
	 * Whether this e-mail goes to shop staff.
	 */
	protected function is_admin_email(): bool {
		return false;
	}

	/**
	 * HTML content.
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain text content.
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * Sends the e-mail if enabled and a recipient exists.
	 */
	protected function send_if_enabled(): void {
		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			$this->restore_locale();
			return;
		}
		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		$this->restore_locale();
	}
}

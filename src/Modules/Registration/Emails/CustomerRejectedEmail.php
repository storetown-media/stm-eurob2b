<?php
/**
 * Customer e-mail: registration not approved.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration\Emails;

/**
 * Tells the customer that the registration was not approved. Disabled by default.
 */
final class CustomerRejectedEmail extends AbstractEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'eurob2b_customer_rejected';
		$this->title          = __( 'EuroB2B: registration not approved', 'eurob2b' );
		$this->description    = __( 'Sent to the customer when a pending registration is rejected. Off by default; the optional note is included when "Include note" is enabled.', 'eurob2b' );
		$this->heading        = __( 'About your registration', 'eurob2b' );
		$this->subject        = __( '[{site_title}] Your registration could not be approved', 'eurob2b' );
		$this->customer_email = true;
		parent::__construct();
		add_action( 'eurob2b_registration_rejected_notification', array( $this, 'trigger' ), 10, 2 );
	}

	/**
	 * Template slug.
	 */
	protected function template_slug(): string {
		return 'customer-rejected';
	}

	/**
	 * Default: disabled.
	 */
	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields['enabled']['default'] = 'no';
		$this->form_fields['include_note']       = array(
			'title'   => __( 'Include note', 'eurob2b' ),
			'type'    => 'checkbox',
			'label'   => __( 'Include the note entered on rejection in the e-mail', 'eurob2b' ),
			'default' => 'no',
		);
	}

	/**
	 * Trigger.
	 *
	 * @param int    $user_id User id.
	 * @param string $note    Note.
	 */
	public function trigger( int $user_id, string $note = '' ): void {
		$this->setup_locale();
		$this->note = 'yes' === $this->get_option( 'include_note', 'no' ) ? $note : '';
		if ( $this->load_user( $user_id ) && null !== $this->user ) {
			$this->recipient = $this->user->user_email;
			$this->send_if_enabled();
		} else {
			$this->restore_locale();
		}
	}
}

<?php
/**
 * Customer e-mail: account approved.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration\Emails;

/**
 * Tells the customer that the account was approved and can be used.
 */
final class CustomerApprovedEmail extends AbstractEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'eurob2b_customer_approved';
		$this->title          = __( 'EuroB2B: account approved', 'eurob2b' );
		$this->description    = __( 'Sent to the customer when a pending registration is approved.', 'eurob2b' );
		$this->heading        = __( 'Your account is now active', 'eurob2b' );
		$this->subject        = __( '[{site_title}] Your account has been approved', 'eurob2b' );
		$this->customer_email = true;
		parent::__construct();
		add_action( 'eurob2b_registration_approved_notification', array( $this, 'trigger' ), 10, 1 );
	}

	/**
	 * Template slug.
	 */
	protected function template_slug(): string {
		return 'customer-approved';
	}

	/**
	 * Trigger.
	 *
	 * @param int $user_id User id.
	 */
	public function trigger( int $user_id ): void {
		$this->setup_locale();
		if ( $this->load_user( $user_id ) && null !== $this->user ) {
			$this->recipient = $this->user->user_email;
			$this->send_if_enabled();
		} else {
			$this->restore_locale();
		}
	}
}

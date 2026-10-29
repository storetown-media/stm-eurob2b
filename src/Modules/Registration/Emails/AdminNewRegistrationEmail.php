<?php
/**
 * Admin e-mail: new registration.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration\Emails;

use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Registration\RegistrationType;

/**
 * Tells the shop manager about a new EuroB2B registration that did not need approval.
 * Registrations that need approval are announced by AdminApprovalRequiredEmail instead.
 */
final class AdminNewRegistrationEmail extends AbstractEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'eurob2b_admin_new_registration';
		$this->title          = __( 'EuroB2B: new registration', 'eurob2b' );
		$this->description    = __( 'Sent to the shop manager when a customer registered through EuroB2B and was approved automatically.', 'eurob2b' );
		$this->heading        = __( 'New customer registration', 'eurob2b' );
		$this->subject        = __( '[{site_title}] New registration: {customer} ({type})', 'eurob2b' );
		$this->customer_email = false;
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		add_action( 'eurob2b_customer_registered_notification', array( $this, 'trigger' ), 10, 3 );
	}

	/**
	 * Template slug.
	 */
	protected function template_slug(): string {
		return 'admin-new-registration';
	}

	/**
	 * Admin e-mail.
	 */
	protected function is_admin_email(): bool {
		return true;
	}

	/**
	 * Trigger.
	 *
	 * @param int              $user_id User id.
	 * @param RegistrationType $type    Type.
	 * @param CustomerStatus   $status  Status.
	 */
	public function trigger( int $user_id, RegistrationType $type, CustomerStatus $status ): void {
		if ( CustomerStatus::APPROVED !== $status ) {
			return;
		}
		$this->setup_locale();
		if ( $this->load_user( $user_id ) ) {
			$this->send_if_enabled();
		} else {
			$this->restore_locale();
		}
	}

	/**
	 * Settings form fields (recipient added).
	 */
	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array(
				'recipient' => array(
					'title'       => __( 'Recipient(s)', 'eurob2b' ),
					'type'        => 'text',
					/* translators: %s: admin e-mail */
					'description' => sprintf( __( 'Comma-separated. Defaults to %s.', 'eurob2b' ), '<code>' . esc_html( (string) get_option( 'admin_email' ) ) . '</code>' ),
					'default'     => '',
					'desc_tip'    => true,
				),
			),
			$this->form_fields
		);
	}
}

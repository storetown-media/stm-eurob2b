<?php
/**
 * Admin e-mail: approval required.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration\Emails;

use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Registration\RegistrationType;

/**
 * Tells the shop manager that a registration waits for approval, with the submitted details.
 */
final class AdminApprovalRequiredEmail extends AbstractEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'eurob2b_admin_approval_required';
		$this->title          = __( 'EuroB2B: registration needs approval', 'eurob2b' );
		$this->description    = __( 'Sent to the shop manager when a customer registered through a type that requires manual approval.', 'eurob2b' );
		$this->heading        = __( 'A registration needs your approval', 'eurob2b' );
		$this->subject        = __( '[{site_title}] Approval needed: {customer} ({type})', 'eurob2b' );
		$this->customer_email = false;
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		add_action( 'eurob2b_customer_registered_notification', array( $this, 'trigger' ), 10, 3 );
	}

	/**
	 * Template slug.
	 */
	protected function template_slug(): string {
		return 'admin-approval-required';
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
		if ( CustomerStatus::PENDING !== $status ) {
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

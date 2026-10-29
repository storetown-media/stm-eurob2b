<?php
/**
 * Privacy provider for company memberships.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy\ProData;

use EuroB2B\Modules\Privacy\PersonalDataProviderInterface;

/**
 * Exports a user's company membership (and how many memberships they created) and removes both on
 * erasure (D-04): the membership row goes, `created_by` references are cleared. The company itself is
 * a business record: when its owner is erased, the owner reference is cleared and the company stays
 * with its name and billing address (reported). The company e-mail address is removed when it is the
 * erased address (CMP-012), for members and non-members alike. Part of the core: it reads the shared
 * tables, also while EuroB2B Pro, which manages the company accounts, is inactive (PLT-015).
 */
final class CompanyDataProvider implements PersonalDataProviderInterface {

	/**
	 * Stored companies.
	 *
	 * @var CompanyRecords
	 */
	private CompanyRecords $records;

	/**
	 * Constructor.
	 *
	 * @param CompanyRecords $records Stored companies.
	 */
	public function __construct( CompanyRecords $records ) {
		$this->records = $records;
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return 'company';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'EuroB2B company membership', 'eurob2b' );
	}

	/**
	 * Export callback.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user       = get_user_by( 'email', $email_address );
		$membership = $user ? $this->records->membership( $user->ID ) : null;
		$company    = null !== $membership ? $this->records->company( $membership['company_id'] ) : null;
		$created    = $user ? $this->records->count_created_by( $user->ID ) : 0;
		$data       = array();
		foreach ( $this->records->companies() as $contact_company ) {
			$email = CompanyRecords::email( $contact_company );
			if ( '' !== $email && strtolower( $email ) === strtolower( $email_address ) ) {
				$data[] = array(
					'group_id'    => 'eurob2b-company',
					'group_label' => $this->label(),
					'item_id'     => 'eurob2b-company-contact-' . $contact_company['id'],
					'data'        => array(
						array(
							'name'  => __( 'Company using this e-mail address', 'eurob2b' ),
							'value' => $contact_company['name'],
						),
						array(
							'name'  => __( 'Order e-mails copied to the company e-mail', 'eurob2b' ),
							'value' => CompanyRecords::copies_order_emails( $contact_company ) ? __( 'Yes', 'eurob2b' ) : __( 'No', 'eurob2b' ),
						),
					),
				);
			}
		}
		if ( $created > 0 ) {
			$data[] = array(
				'group_id'    => 'eurob2b-company',
				'group_label' => $this->label(),
				'item_id'     => 'eurob2b-company-created-by-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Members added by you', 'eurob2b' ),
						'value' => (string) $created,
					),
				),
			);
		}
		if ( null === $membership || null === $company ) {
			return array(
				'data' => $data,
				'done' => true,
			);
		}
		$labels = CompanyRecords::permission_labels();
		array_unshift(
			$data,
			array(
				'group_id'    => 'eurob2b-company',
				'group_label' => $this->label(),
				'item_id'     => 'eurob2b-company-membership-' . $membership['id'],
				'data'        => array(
					array(
						'name'  => __( 'Company', 'eurob2b' ),
						'value' => $company['name'],
					),
					array(
						'name'  => __( 'Role', 'eurob2b' ),
						'value' => CompanyRecords::role_labels()[ $membership['role'] ] ?? $membership['role'],
					),
					array(
						'name'  => __( 'Permissions', 'eurob2b' ),
						'value' => implode( ', ', array_map( static fn( string $p ): string => $labels[ $p ] ?? $p, CompanyRecords::effective_permissions( $membership ) ) ),
					),
					array(
						'name'  => __( 'Orders need approval', 'eurob2b' ),
						'value' => $membership['requires_approval'] ? __( 'Yes', 'eurob2b' ) : __( 'No', 'eurob2b' ),
					),
					array(
						'name'  => __( 'Member since (UTC)', 'eurob2b' ),
						'value' => $membership['created_at'],
					),
					array(
						'name'  => __( 'Company billing address', 'eurob2b' ),
						'value' => CompanyRecords::has_billing_address( $company ) ? wp_strip_all_tags( WC()->countries->get_formatted_address( array_merge( CompanyRecords::billing( $company ), array( 'company' => $company['name'] ) ), ', ' ) ) : '',
					),
					array(
						'name'  => __( 'Company e-mail address', 'eurob2b' ),
						'value' => CompanyRecords::email( $company ),
					),
					array(
						'name'  => __( 'Order e-mails copied to the company e-mail', 'eurob2b' ),
						'value' => CompanyRecords::copies_order_emails( $company ) ? __( 'Yes', 'eurob2b' ) : __( 'No', 'eurob2b' ),
					),
				),
			)
		);
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase callback: the membership is removed; a company keeps its name and billing address but loses
	 * the owner reference; a company e-mail equal to the erased address is removed.
	 *
	 * @param string $email_address E-mail.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user       = get_user_by( 'email', $email_address );
		$membership = $user ? $this->records->membership( $user->ID ) : null;
		$messages   = array();
		$retained   = false;
		$contacts   = 0;
		foreach ( $this->records->companies() as $contact_company ) {
			$email = CompanyRecords::email( $contact_company );
			if ( '' !== $email && strtolower( $email ) === strtolower( $email_address ) ) {
				$this->records->clear_contact( $contact_company );
				++$contacts;
			}
		}
		if ( $contacts > 0 ) {
			/* translators: %d: number of companies */
			$messages[] = sprintf( __( 'This e-mail address was removed as the contact address of %d company/companies; order e-mail copies to it are off.', 'eurob2b' ), $contacts );
		}
		$cleared = $user ? $this->records->anonymise_creator( $user->ID ) : 0;
		if ( $cleared > 0 ) {
			/* translators: %d: number of memberships */
			$messages[] = sprintf( __( 'The reference to this user as the creator of %d memberships was removed.', 'eurob2b' ), $cleared );
		}
		if ( $user ) {
			delete_user_meta( $user->ID, CompanyRecords::META_PREVIOUS );
		}
		if ( null !== $membership ) {
			$company = $this->records->company( $membership['company_id'] );
			if ( null !== $company && $company['owner_user_id'] === $membership['user_id'] ) {
				$this->records->clear_owner( $company['id'] );
				$messages[] = CompanyRecords::has_billing_address( $company )
					/* translators: %s: company name */
					? sprintf( __( 'The company "%s" stays as a business record with its name and billing address (contract and accounting records); its owner reference was removed.', 'eurob2b' ), $company['name'] )
					/* translators: %s: company name */
					: sprintf( __( 'The company "%s" stays as a business record without personal data; its owner reference was removed.', 'eurob2b' ), $company['name'] );
				$retained = true;
			}
			$this->records->remove_membership( $membership['user_id'] );
		}
		return array(
			'items_removed'  => null !== $membership || $cleared > 0 || $contacts > 0,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}

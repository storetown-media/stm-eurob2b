<?php
/**
 * Writes audit log entries for administrative decisions.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Audit;

use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\CustomerType;
use EuroB2B\Modules\Customers\Group;
use EuroB2B\Modules\Tax\Export\ExportEvidenceStatus;
use EuroB2B\Modules\Tax\Validation\ValidationRecord;
use WC_Order;

/**
 * Listens to the decisions the other modules announce and writes one entry per changed value
 * (PRV-011). Changes without a deciding user (actor 0: automatic approval, jobs) are not logged;
 * the log is about people's decisions.
 *
 * - `eurob2b_customer_assigned`: registration approved or rejected (from "pending"), other status
 *   changes, customer type and customer group changes, wherever they are made (approvals screen,
 *   user profile, bulk actions, REST API).
 * - `eurob2b_vat_decided`: manual decisions on VAT ID checks.
 * - `eurob2b_export_evidence_changed`: every change of the export evidence of an order (TAX-032).
 *
 * Decisions on orders waiting for approval and acting as a member belong to company accounts of
 * EuroB2B Pro, whose CompanyAuditEvents writes them into the same log (PLT-015).
 */
final class AuditEvents {

	/**
	 * Log.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Constructor.
	 *
	 * @param AuditLog $log Log.
	 */
	public function __construct( AuditLog $log ) {
		$this->log = $log;
	}

	/**
	 * Adds the listeners.
	 */
	public function hooks(): void {
		add_action( 'eurob2b_customer_assigned', array( $this, 'customer_assigned' ), 10, 8 );
		add_action( 'eurob2b_vat_decided', array( $this, 'vat_decided' ), 10, 3 );
		add_action( 'eurob2b_export_evidence_changed', array( $this, 'export_evidence_changed' ), 10, 5 );
		add_action( 'eurob2b_tax_review_confirmed', array( $this, 'tax_review_confirmed' ), 10, 2 );
	}

	/**
	 * A staff member confirmed the review of an order whose tax treatment EuroB2B flagged. Old value:
	 * empty; new value: `reviewed`. The concerned person is the order's customer (0 for guests).
	 *
	 * @param mixed $order    Order.
	 * @param mixed $actor_id Staff user.
	 */
	public function tax_review_confirmed( $order, $actor_id ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->log->record( AuditLog::ACTION_TAX_REVIEWED, (int) $actor_id, (int) $order->get_customer_id(), AuditLog::OBJECT_ORDER, (int) $order->get_id(), '', 'reviewed' );
	}

	/**
	 * A staff member changed the export evidence of an order (TAX-032). Old value: the status before;
	 * new value: `<new status>/<event>`, so changes that keep the status (another document) are listed
	 * too. The concerned person is the order's customer (0 for guests).
	 *
	 * @param mixed $order    Order.
	 * @param mixed $status   New status.
	 * @param mixed $event    Event.
	 * @param mixed $actor_id Staff user.
	 * @param mixed $previous Status before.
	 */
	public function export_evidence_changed( $order, $status, $event, $actor_id, $previous ): void {
		if ( ! $order instanceof WC_Order || ! $status instanceof ExportEvidenceStatus || ! $previous instanceof ExportEvidenceStatus ) {
			return;
		}
		$this->log->record( AuditLog::ACTION_EXPORT_EVIDENCE, (int) $actor_id, (int) $order->get_customer_id(), AuditLog::OBJECT_ORDER, (int) $order->get_id(), $previous->value, $status->value . '/' . sanitize_key( (string) $event ) );
	}

	/**
	 * Status, type and group changes of a customer.
	 *
	 * @param mixed $user_id         User id.
	 * @param mixed $type            New type.
	 * @param mixed $status          New status.
	 * @param mixed $group           New group.
	 * @param mixed $actor_id        Actor (0 = system).
	 * @param mixed $previous_status Previous status.
	 * @param mixed $previous_type   Previous type.
	 * @param mixed $previous_group  Previous group.
	 */
	public function customer_assigned( $user_id, $type, $status, $group, $actor_id, $previous_status, $previous_type, $previous_group ): void {
		$user_id  = (int) $user_id;
		$actor_id = (int) $actor_id;
		if ( $user_id <= 0 || $actor_id <= 0 ) {
			return;
		}
		if ( $status instanceof CustomerStatus && $previous_status instanceof CustomerStatus && $status !== $previous_status ) {
			$action = AuditLog::ACTION_STATUS_CHANGED;
			if ( CustomerStatus::PENDING === $previous_status && CustomerStatus::APPROVED === $status ) {
				$action = AuditLog::ACTION_REGISTRATION_APPROVED;
			} elseif ( CustomerStatus::PENDING === $previous_status && CustomerStatus::REJECTED === $status ) {
				$action = AuditLog::ACTION_REGISTRATION_REJECTED;
			}
			$this->log->record( $action, $actor_id, $user_id, AuditLog::OBJECT_USER, $user_id, $previous_status->value, $status->value );
		}
		if ( $type instanceof CustomerType && $previous_type instanceof CustomerType && $type !== $previous_type ) {
			$this->log->record( AuditLog::ACTION_TYPE_CHANGED, $actor_id, $user_id, AuditLog::OBJECT_USER, $user_id, $previous_type->value, $type->value );
		}
		$old_group = $previous_group instanceof Group ? (string) $previous_group->id : '';
		$new_group = $group instanceof Group ? (string) $group->id : '';
		if ( $old_group !== $new_group ) {
			$this->log->record( AuditLog::ACTION_GROUP_CHANGED, $actor_id, $user_id, AuditLog::OBJECT_USER, $user_id, $old_group, $new_group );
		}
	}

	/**
	 * Manual decision on a VAT ID check.
	 *
	 * @param mixed $record   Record after the decision.
	 * @param mixed $actor_id Actor.
	 * @param mixed $previous Status before the decision.
	 */
	public function vat_decided( $record, $actor_id, $previous = '' ): void {
		$actor_id = (int) $actor_id;
		if ( ! $record instanceof ValidationRecord || $actor_id <= 0 ) {
			return;
		}
		$this->log->record( AuditLog::ACTION_VAT_DECIDED, $actor_id, (int) $record->user_id, AuditLog::OBJECT_VAT, $record->id, is_string( $previous ) ? $previous : '', $record->status->value );
	}
}

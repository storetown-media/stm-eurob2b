<?php
/**
 * Labels for audit log entries.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Audit;

use EuroB2B\Modules\Customers\CustomerStatus;
use EuroB2B\Modules\Customers\GroupRepository;
use EuroB2B\Modules\Tax\Export\ExportEvidenceRecords;
use EuroB2B\Modules\Tax\Export\ExportEvidenceStatus;
use EuroB2B\Modules\Tax\VatId\ValidationStatus;

/**
 * Turns action keys and value codes into text for the admin screen and the privacy export. Names of
 * users and groups are looked up when shown, so the log itself stores none.
 */
final class AuditFormatter {

	/**
	 * Groups.
	 *
	 * @var GroupRepository
	 */
	private GroupRepository $groups;

	/**
	 * Constructor.
	 *
	 * @param GroupRepository $groups Groups.
	 */
	public function __construct( GroupRepository $groups ) {
		$this->groups = $groups;
	}

	/**
	 * Action labels.
	 *
	 * @return array<string, string>
	 */
	public static function action_labels(): array {
		return array(
			AuditLog::ACTION_REGISTRATION_APPROVED => __( 'Registration approved', 'eurob2b' ),
			AuditLog::ACTION_REGISTRATION_REJECTED => __( 'Registration rejected', 'eurob2b' ),
			AuditLog::ACTION_STATUS_CHANGED        => __( 'Customer status changed', 'eurob2b' ),
			AuditLog::ACTION_TYPE_CHANGED          => __( 'Customer type changed', 'eurob2b' ),
			AuditLog::ACTION_GROUP_CHANGED         => __( 'Customer group changed', 'eurob2b' ),
			AuditLog::ACTION_VAT_DECIDED           => __( 'VAT ID check decided manually', 'eurob2b' ),
			AuditLog::ACTION_ORDER_APPROVED        => __( 'Order approved', 'eurob2b' ),
			AuditLog::ACTION_ORDER_REJECTED        => __( 'Order rejected', 'eurob2b' ),
			AuditLog::ACTION_MEMBER_LOGIN          => __( 'Logged in as a company member', 'eurob2b' ),
			AuditLog::ACTION_MEMBER_LOGOUT         => __( 'Acting as a company member ended', 'eurob2b' ),
			AuditLog::ACTION_MEMBER_ORDER          => __( 'Order placed while acting as a member', 'eurob2b' ),
			AuditLog::ACTION_EXPORT_EVIDENCE       => __( 'Export evidence changed', 'eurob2b' ),
		);
	}

	/**
	 * Label of an action.
	 *
	 * @param string $action Action key.
	 */
	public function action( string $action ): string {
		return self::action_labels()[ $action ] ?? $action;
	}

	/**
	 * Label of a stored value for an action ('' stays '').
	 *
	 * @param string $action Action key.
	 * @param string $value  Value code.
	 */
	public function value( string $action, string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		switch ( $action ) {
			case AuditLog::ACTION_REGISTRATION_APPROVED:
			case AuditLog::ACTION_REGISTRATION_REJECTED:
			case AuditLog::ACTION_STATUS_CHANGED:
				$status = CustomerStatus::tryFrom( $value );
				return null !== $status ? $status->label() : $value;
			case AuditLog::ACTION_MEMBER_LOGIN:
				/* translators: %d: minutes */
				return sprintf( _n( 'for at most %d minute', 'for at most %d minutes', (int) $value, 'eurob2b' ), (int) $value );
			case AuditLog::ACTION_TYPE_CHANGED:
				$types = array(
					'b2b'   => __( 'Business customer', 'eurob2b' ),
					'b2c'   => __( 'Private customer', 'eurob2b' ),
					'guest' => __( 'Guest', 'eurob2b' ),
				);
				return $types[ $value ] ?? $value;
			case AuditLog::ACTION_GROUP_CHANGED:
				$group = $this->groups->find( (int) $value );
				/* translators: %d: group id */
				return null !== $group ? $group->name : sprintf( __( 'Deleted group #%d', 'eurob2b' ), (int) $value );
			case AuditLog::ACTION_VAT_DECIDED:
				$labels = array(
					ValidationStatus::VALID->value       => __( 'Valid', 'eurob2b' ),
					ValidationStatus::INVALID->value     => __( 'Invalid', 'eurob2b' ),
					ValidationStatus::UNAVAILABLE->value => __( 'Service unavailable', 'eurob2b' ),
					ValidationStatus::PENDING->value     => __( 'Pending (retrying)', 'eurob2b' ),
				);
				return $labels[ $value ] ?? $value;
			case AuditLog::ACTION_EXPORT_EVIDENCE:
				return self::export_evidence_value( $value );
		}//end switch
		/**
		 * Label of a stored value the core does not know, e.g. why acting as a member ended
		 * (company accounts of EuroB2B Pro). Without a label the stored code is shown.
		 *
		 * @param string $value  Stored value code.
		 * @param string $action Action key.
		 */
		$label = apply_filters( 'eurob2b_audit_value_label', $value, $action );
		return is_string( $label ) ? $label : $value;
	}

	/**
	 * Export evidence value: a status, or `<status>/<event>` (TAX-032).
	 *
	 * @param string $value Value code.
	 */
	private static function export_evidence_value( string $value ): string {
		$parts  = explode( '/', $value, 2 );
		$status = ExportEvidenceStatus::tryFrom( $parts[0] );
		$label  = null !== $status ? $status->label() : $parts[0];
		if ( ! isset( $parts[1] ) ) {
			return $label;
		}
		$events = array(
			ExportEvidenceRecords::EVENT_DETAILS    => __( 'details recorded', 'eurob2b' ),
			ExportEvidenceRecords::EVENT_DOCUMENT   => __( 'document added', 'eurob2b' ),
			ExportEvidenceRecords::EVENT_ACCEPTED   => __( 'accepted', 'eurob2b' ),
			ExportEvidenceRecords::EVENT_NOT_NEEDED => __( 'marked as not needed', 'eurob2b' ),
			ExportEvidenceRecords::EVENT_REOPENED   => __( 'reopened', 'eurob2b' ),
		);
		return $label . ' (' . ( $events[ $parts[1] ] ?? $parts[1] ) . ')';
	}

	/**
	 * "old → new", "new" or "".
	 *
	 * @param AuditEntry $entry Entry.
	 */
	public function change( AuditEntry $entry ): string {
		$old = $this->value( $entry->action, $entry->old_value );
		$new = $this->value( $entry->action, $entry->new_value );
		if ( '' === $old && '' === $new ) {
			return '';
		}
		$none = _x( 'none', 'audit value', 'eurob2b' );
		if ( '' === $old ) {
			return AuditLog::ACTION_GROUP_CHANGED === $entry->action ? $none . ' → ' . $new : $new;
		}
		return $old . ' → ' . ( '' !== $new ? $new : $none );
	}
}

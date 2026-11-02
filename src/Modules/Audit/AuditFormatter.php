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
			AuditLog::ACTION_REGISTRATION_APPROVED => __( 'Registration approved', 'stm-eu-b2b' ),
			AuditLog::ACTION_REGISTRATION_REJECTED => __( 'Registration rejected', 'stm-eu-b2b' ),
			AuditLog::ACTION_STATUS_CHANGED        => __( 'Customer status changed', 'stm-eu-b2b' ),
			AuditLog::ACTION_TYPE_CHANGED          => __( 'Customer type changed', 'stm-eu-b2b' ),
			AuditLog::ACTION_GROUP_CHANGED         => __( 'Customer group changed', 'stm-eu-b2b' ),
			AuditLog::ACTION_VAT_DECIDED           => __( 'VAT ID check decided manually', 'stm-eu-b2b' ),
			AuditLog::ACTION_ORDER_APPROVED        => __( 'Order approved', 'stm-eu-b2b' ),
			AuditLog::ACTION_ORDER_REJECTED        => __( 'Order rejected', 'stm-eu-b2b' ),
			AuditLog::ACTION_MEMBER_LOGIN          => __( 'Logged in as a company member', 'stm-eu-b2b' ),
			AuditLog::ACTION_MEMBER_LOGOUT         => __( 'Acting as a company member ended', 'stm-eu-b2b' ),
			AuditLog::ACTION_MEMBER_ORDER          => __( 'Order placed while acting as a member', 'stm-eu-b2b' ),
			AuditLog::ACTION_EXPORT_EVIDENCE       => __( 'Export evidence changed', 'stm-eu-b2b' ),
			AuditLog::ACTION_TAX_REVIEWED          => __( 'Tax treatment reviewed', 'stm-eu-b2b' ),
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
				return sprintf( _n( 'for at most %d minute', 'for at most %d minutes', (int) $value, 'stm-eu-b2b' ), (int) $value );
			case AuditLog::ACTION_TYPE_CHANGED:
				$types = array(
					'b2b'   => __( 'Business customer', 'stm-eu-b2b' ),
					'b2c'   => __( 'Private customer', 'stm-eu-b2b' ),
					'guest' => __( 'Guest', 'stm-eu-b2b' ),
				);
				return $types[ $value ] ?? $value;
			case AuditLog::ACTION_GROUP_CHANGED:
				$group = $this->groups->find( (int) $value );
				/* translators: %d: group id */
				return null !== $group ? $group->name : sprintf( __( 'Deleted group #%d', 'stm-eu-b2b' ), (int) $value );
			case AuditLog::ACTION_VAT_DECIDED:
				$labels = array(
					ValidationStatus::VALID->value       => __( 'Valid', 'stm-eu-b2b' ),
					ValidationStatus::INVALID->value     => __( 'Invalid', 'stm-eu-b2b' ),
					ValidationStatus::UNAVAILABLE->value => __( 'Service unavailable', 'stm-eu-b2b' ),
					ValidationStatus::PENDING->value     => __( 'Pending (retrying)', 'stm-eu-b2b' ),
				);
				return $labels[ $value ] ?? $value;
			case AuditLog::ACTION_EXPORT_EVIDENCE:
				return self::export_evidence_value( $value );
			case AuditLog::ACTION_TAX_REVIEWED:
				return 'reviewed' === $value ? __( 'Reviewed', 'stm-eu-b2b' ) : $value;
		}//end switch
		/**
		 * Label of a stored value the core does not know, e.g. why acting as a member ended
		 * (company accounts of EU-B2B Pro). Without a label the stored code is shown.
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
			ExportEvidenceRecords::EVENT_DETAILS    => __( 'details recorded', 'stm-eu-b2b' ),
			ExportEvidenceRecords::EVENT_DOCUMENT   => __( 'document added', 'stm-eu-b2b' ),
			ExportEvidenceRecords::EVENT_ACCEPTED   => __( 'accepted', 'stm-eu-b2b' ),
			ExportEvidenceRecords::EVENT_NOT_NEEDED => __( 'marked as not needed', 'stm-eu-b2b' ),
			ExportEvidenceRecords::EVENT_REOPENED   => __( 'reopened', 'stm-eu-b2b' ),
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
		$none = _x( 'none', 'audit value', 'stm-eu-b2b' );
		if ( '' === $old ) {
			return AuditLog::ACTION_GROUP_CHANGED === $entry->action ? $none . ' → ' . $new : $new;
		}
		return $old . ' → ' . ( '' !== $new ? $new : $none );
	}
}

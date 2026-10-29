<?php
/**
 * Registration type.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use EuroB2B\Modules\Customers\CustomerType;
use InvalidArgumentException;

/**
 * A public choice on the registration form ("business customer", "private customer").
 *
 * Types are public; groups are private. A type decides the customer type,
 * whether approval is manual, and which group an approved customer lands in.
 */
final class RegistrationType {

	public const APPROVAL_AUTOMATIC = 'automatic';
	public const APPROVAL_MANUAL    = 'manual';

	/**
	 * Constructor.
	 *
	 * @param string       $slug          Unique slug.
	 * @param string       $label         Public label.
	 * @param CustomerType $customer_type B2B or B2C.
	 * @param string       $approval      automatic|manual.
	 * @param int|null     $group_id      Target group id; null = default group of the customer type.
	 * @param bool         $enabled       Whether the type is offered.
	 * @param int          $order         Sort order.
	 * @param string       $description   Optional help text under the choice.
	 * @throws InvalidArgumentException On invalid slug, label, type or approval mode.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $label,
		public readonly CustomerType $customer_type,
		public readonly string $approval = self::APPROVAL_AUTOMATIC,
		public readonly ?int $group_id = null,
		public readonly bool $enabled = true,
		public readonly int $order = 10,
		public readonly string $description = ''
	) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug ) ) {
			throw new InvalidArgumentException( sprintf( 'RegistrationType: "%s" is not a valid slug.', $slug ) );
		}
		if ( '' === trim( $label ) ) {
			throw new InvalidArgumentException( 'RegistrationType: label must not be empty.' );
		}
		if ( CustomerType::GUEST === $customer_type ) {
			throw new InvalidArgumentException( 'RegistrationType: guests cannot register.' );
		}
		if ( ! in_array( $approval, array( self::APPROVAL_AUTOMATIC, self::APPROVAL_MANUAL ), true ) ) {
			throw new InvalidArgumentException( 'RegistrationType: unknown approval mode.' );
		}
	}

	/**
	 * Whether a shop manager has to approve registrations of this type.
	 */
	public function requires_approval(): bool {
		return self::APPROVAL_MANUAL === $this->approval;
	}

	/**
	 * Array form for option storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'slug'          => $this->slug,
			'label'         => $this->label,
			'customer_type' => $this->customer_type->value,
			'approval'      => $this->approval,
			'group_id'      => $this->group_id,
			'enabled'       => $this->enabled,
			'order'         => $this->order,
			'description'   => $this->description,
		);
	}

	/**
	 * Builds a type from stored or submitted data; invalid data throws.
	 *
	 * @param array<string, mixed> $data Data.
	 * @throws InvalidArgumentException On invalid data.
	 */
	public static function from_array( array $data ): self {
		$type = CustomerType::tryFrom( (string) ( $data['customer_type'] ?? '' ) );
		if ( null === $type ) {
			throw new InvalidArgumentException( 'RegistrationType: unknown customer type.' );
		}
		$group_id = isset( $data['group_id'] ) && (int) $data['group_id'] > 0 ? (int) $data['group_id'] : null;
		return new self(
			(string) ( $data['slug'] ?? '' ),
			trim( (string) ( $data['label'] ?? '' ) ),
			$type,
			(string) ( $data['approval'] ?? self::APPROVAL_AUTOMATIC ),
			$group_id,
			! isset( $data['enabled'] ) || filter_var( $data['enabled'], FILTER_VALIDATE_BOOLEAN ),
			(int) ( $data['order'] ?? 10 ),
			trim( (string) ( $data['description'] ?? '' ) )
		);
	}

	/**
	 * Copy with changes.
	 *
	 * @param array<string, mixed> $changes Changed keys (same names as to_array()).
	 */
	public function with( array $changes ): self {
		return self::from_array( array_merge( $this->to_array(), $changes ) );
	}
}

<?php
/**
 * Customer group entity.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Customers;

use EuroB2B\Support\Currency;
use InvalidArgumentException;

/**
 * A customer group: the unit prices, rules, visibility and methods attach to.
 *
 * Groups are private (customers never see group names). Every group is either
 * B2B or B2C; exactly one group per customer type is the default that newly
 * approved customers of that type land in.
 */
final class Group {

	/**
	 * Constructor.
	 *
	 * @param int                  $id            Database id, 0 for a new group.
	 * @param string               $slug          Unique slug (lower case, a-z0-9-_).
	 * @param string               $name          Display name for admins.
	 * @param CustomerType         $customer_type B2B or B2C.
	 * @param bool                 $is_default    Default group of its customer type.
	 * @param int                  $priority      Higher wins when rules of several groups could apply.
	 * @param array<string, mixed> $settings      Free-form settings (payment methods, shipping methods, ...).
	 * @throws InvalidArgumentException On an invalid slug, empty name or guest type.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $name,
		public readonly CustomerType $customer_type,
		public readonly bool $is_default = false,
		public readonly int $priority = 10,
		public readonly array $settings = array()
	) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug ) ) {
			throw new InvalidArgumentException( sprintf( 'Group: "%s" is not a valid slug.', $slug ) );
		}
		if ( '' === trim( $name ) ) {
			throw new InvalidArgumentException( 'Group: name must not be empty.' );
		}
		if ( CustomerType::GUEST === $customer_type ) {
			throw new InvalidArgumentException( 'Group: guests cannot have a group.' );
		}
	}

	/**
	 * Copy with changed fields.
	 *
	 * @param array<string, mixed> $changes Keys: slug, name, customer_type, is_default, priority, settings.
	 */
	public function with( array $changes ): self {
		return new self(
			$this->id,
			(string) ( $changes['slug'] ?? $this->slug ),
			(string) ( $changes['name'] ?? $this->name ),
			$changes['customer_type'] ?? $this->customer_type,
			(bool) ( $changes['is_default'] ?? $this->is_default ),
			(int) ( $changes['priority'] ?? $this->priority ),
			(array) ( $changes['settings'] ?? $this->settings )
		);
	}

	/**
	 * Copy with the given id (after insert).
	 *
	 * @param int $id Database id.
	 */
	public function with_id( int $id ): self {
		return new self( $id, $this->slug, $this->name, $this->customer_type, $this->is_default, $this->priority, $this->settings );
	}

	/**
	 * Whether the group is persisted.
	 */
	public function exists(): bool {
		return $this->id > 0;
	}

	/**
	 * The group's own currency (CUR-001): an ISO code from the settings, '' for the shop currency.
	 * A code equal to the shop currency is returned as '' too, so callers compare one form only.
	 */
	public function currency(): string {
		$currency = Currency::normalise( $this->settings['currency'] ?? '' );
		return '' === $currency || Currency::is_shop( $currency ) ? '' : $currency;
	}

	/**
	 * Converts a free-form name into a slug candidate.
	 *
	 * @param string $name Name.
	 */
	public static function slugify( string $name ): string {
		$slug = trim( $name );
		if ( function_exists( 'remove_accents' ) ) {
			$slug = remove_accents( $slug );
		}
		$slug = strtolower( $slug );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( $slug, '-_' );
		return '' === $slug ? 'group' : substr( $slug, 0, 64 );
	}
}

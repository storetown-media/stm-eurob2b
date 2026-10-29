<?php
/**
 * Registration type repository.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Registration;

use EuroB2B\Modules\Customers\CustomerType;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stores registration types in one option (configuration, no personal data).
 */
final class RegistrationTypeRepository {

	public const OPTION = 'eurob2b_registration_types';

	/**
	 * Request cache.
	 *
	 * @var array<string, RegistrationType>|null
	 */
	private ?array $cache = null;

	/**
	 * All types ordered by sort order, keyed by slug.
	 *
	 * @return array<string, RegistrationType>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$stored      = get_option( self::OPTION, null );
		$this->cache = array();
		if ( ! is_array( $stored ) ) {
			foreach ( self::defaults() as $type ) {
				$this->cache[ $type->slug ] = $type;
			}
			return $this->cache;
		}
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$type                       = RegistrationType::from_array( $row );
				$this->cache[ $type->slug ] = $type;
			} catch ( InvalidArgumentException $e ) {
				continue;
				// Skip corrupt rows instead of breaking the form.
			}
		}
		uasort( $this->cache, static fn( RegistrationType $a, RegistrationType $b ): int => $a->order <=> $b->order ?: strcmp( $a->slug, $b->slug ) );
		return $this->cache;
	}

	/**
	 * Enabled types only, in order.
	 *
	 * @return array<string, RegistrationType>
	 */
	public function enabled(): array {
		return array_filter( $this->all(), static fn( RegistrationType $t ): bool => $t->enabled );
	}

	/**
	 * Types offered on the registration forms: the enabled ones the shop's mode allows (ACC-009 offers
	 * business types only while the shop sells to business customers only).
	 *
	 * @return array<string, RegistrationType>
	 */
	public function offered(): array {
		return array_filter( $this->enabled(), array( $this, 'is_offered' ) );
	}

	/**
	 * Whether a type is offered on the registration forms.
	 *
	 * @param RegistrationType $type Type.
	 */
	public function is_offered( RegistrationType $type ): bool {
		/**
		 * Filters whether an enabled registration type is offered on the registration forms.
		 *
		 * @param bool             $offered Whether it is offered.
		 * @param RegistrationType $type    Registration type.
		 */
		return $type->enabled && (bool) apply_filters( 'eurob2b_registration_type_offered', true, $type );
	}

	/**
	 * Finds a type by slug.
	 *
	 * @param string $slug Slug.
	 */
	public function find( string $slug ): ?RegistrationType {
		return $this->all()[ $slug ] ?? null;
	}

	/**
	 * Creates or replaces a type.
	 *
	 * @param RegistrationType $type Type.
	 * @throws RuntimeException When the change would leave no enabled type.
	 */
	public function save( RegistrationType $type ): void {
		$all                = $this->all();
		$all[ $type->slug ] = $type;
		self::assert_one_enabled( $all );
		$this->persist( $all );
	}

	/**
	 * At least one enabled type must remain, otherwise no registration form can be submitted.
	 *
	 * @param array<string, RegistrationType> $types Types after the change.
	 * @throws RuntimeException When no enabled type is left.
	 */
	private static function assert_one_enabled( array $types ): void {
		if ( empty( array_filter( $types, static fn( RegistrationType $t ): bool => $t->enabled ) ) ) {
			throw new RuntimeException( esc_html__( 'At least one enabled registration type is required.', 'eurob2b' ) );
		}
	}

	/**
	 * Deletes a type. At least one enabled type must remain.
	 *
	 * @param string $slug Slug.
	 * @throws RuntimeException When it is the last enabled type.
	 */
	public function delete( string $slug ): void {
		$all = $this->all();
		if ( ! isset( $all[ $slug ] ) ) {
			return;
		}
		unset( $all[ $slug ] );
		self::assert_one_enabled( $all );
		$this->persist( $all );
	}

	/**
	 * Writes the option.
	 *
	 * @param array<string, RegistrationType> $types Types.
	 */
	private function persist( array $types ): void {
		update_option( self::OPTION, array_values( array_map( static fn( RegistrationType $t ): array => $t->to_array(), $types ) ), false );
		$this->cache = null;
	}

	/**
	 * Shipped defaults: private customers auto-approved, business customers manually approved.
	 *
	 * @return RegistrationType[]
	 */
	public static function defaults(): array {
		return array(
			new RegistrationType( 'private', __( 'Private customer', 'eurob2b' ), CustomerType::B2C, RegistrationType::APPROVAL_AUTOMATIC, null, true, 10 ),
			new RegistrationType( 'business', __( 'Business customer', 'eurob2b' ), CustomerType::B2B, RegistrationType::APPROVAL_MANUAL, null, true, 20, __( 'Your account is activated after we have checked your company details.', 'eurob2b' ) ),
		);
	}

	/**
	 * Drops the request cache.
	 */
	public function flush(): void {
		$this->cache = null;
	}
}

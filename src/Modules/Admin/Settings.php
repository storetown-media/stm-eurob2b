<?php
/**
 * Settings store.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Admin;

use Closure;
use RuntimeException;
use EuroB2B\Infrastructure\Schema;

/**
 * Typed access to the plugin settings stored in one option.
 *
 * Reads are cached per request. Writes go through the schema sanitiser, so
 * the stored array never contains unknown keys or wrong types; only the stored
 * values of EuroB2B Pro's settings stay while Pro is inactive (KeptSettings). The uninstall
 * opt-in is mirrored into its own option because uninstall.php runs without
 * the plugin being loaded.
 */
final class Settings {

	/**
	 * Schema.
	 *
	 * @var SettingsSchema
	 */
	private ?SettingsSchema $schema = null;

	/**
	 * Builds the schema on first use, so every module had the chance to add its fields.
	 *
	 * @var Closure(): SettingsSchema
	 */
	private Closure $schema_factory;

	/**
	 * Cached values, null until first read.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $values = null;

	/**
	 * Constructor.
	 *
	 * @param SettingsSchema|Closure $schema Schema, or a factory that builds it on first use.
	 */
	public function __construct( SettingsSchema|Closure $schema ) {
		if ( $schema instanceof SettingsSchema ) {
			$this->schema         = $schema;
			$this->schema_factory = static fn(): SettingsSchema => $schema;
		} else {
			$this->schema_factory = $schema;
		}
	}

	/**
	 * Schema (built lazily, after all modules booted and registered their fields).
	 */
	public function schema(): SettingsSchema {
		if ( null === $this->schema ) {
			$this->schema = ( $this->schema_factory )();
		}
		return $this->schema;
	}

	/**
	 * Returns one setting (default when unset).
	 *
	 * @param string $id Field id.
	 * @return mixed
	 */
	public function get( string $id ) {
		$values = $this->all();
		if ( array_key_exists( $id, $values ) ) {
			return $values[ $id ];
		}
		return $this->schema()->defaults()[ $id ] ?? null;
	}

	/**
	 * The stored value of a setting, also when no module registered its field: the privacy handling
	 * of data written by EuroB2B Pro reads its retention periods with it while Pro is inactive
	 * (PLT-015). The value is as it was stored (sanitised by the schema that saved it).
	 *
	 * @param string $id       Field id.
	 * @param mixed  $fallback Value when nothing is stored (the field's documented default).
	 * @return mixed
	 */
	public function stored( string $id, $fallback = null ) {
		if ( $this->schema()->has( $id ) ) {
			return $this->get( $id );
		}
		$stored = get_option( Schema::OPTION_SETTINGS, array() );
		return is_array( $stored ) && array_key_exists( $id, $stored ) ? $stored[ $id ] : $fallback;
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->values ) {
			$stored       = get_option( Schema::OPTION_SETTINGS, array() );
			$stored       = is_array( $stored ) ? $stored : array();
			$this->values = array_merge( $this->schema()->defaults(), array_intersect_key( $stored, $this->schema()->fields() ) );
		}
		return $this->values;
	}

	/**
	 * Sanitises and stores a complete set of values (missing keys become defaults).
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> The stored, sanitised values.
	 * @throws RuntimeException When a write fails (the previous values are restored).
	 */
	public function update( array $input ): array {
		// The uninstall opt-in is mirrored into its own option (read by the uninstaller without the
		// plugin loaded). Both writes are verified; if either fails, both are restored so the page
		// never shows one value while the uninstaller would act on another.
		$previous_settings = get_option( Schema::OPTION_SETTINGS, array() );
		// Without EuroB2B Pro its fields are not in the schema; their stored values stay (PLT-015).
		$clean         = KeptSettings::merge( $this->schema()->sanitize( $input ), $previous_settings );
		$previous_flag = (bool) get_option( Schema::OPTION_REMOVE_DATA, false );
		$mirror        = array_key_exists( 'remove_data_on_uninstall', $clean );

		update_option( Schema::OPTION_SETTINGS, $clean, true );
		$ok = get_option( Schema::OPTION_SETTINGS ) === $clean;
		if ( $ok && $mirror ) {
			update_option( Schema::OPTION_REMOVE_DATA, (bool) $clean['remove_data_on_uninstall'], false );
			$ok = (bool) get_option( Schema::OPTION_REMOVE_DATA, false ) === (bool) $clean['remove_data_on_uninstall'];
		}
		if ( ! $ok ) {
			update_option( Schema::OPTION_SETTINGS, $previous_settings, true );
			update_option( Schema::OPTION_REMOVE_DATA, $previous_flag, false );
			$this->values = null;
			throw new RuntimeException( esc_html__( 'The settings could not be saved completely; nothing was changed.', 'eurob2b' ) );
		}

		$this->values = null;

		/**
		 * Fires after the settings were saved.
		 *
		 * @param array<string, mixed> $clean Stored values.
		 */
		do_action( 'eurob2b_settings_updated', $clean );

		return $clean;
	}

	/**
	 * Updates a subset of settings, keeping the rest.
	 *
	 * @param array<string, mixed> $partial Field id => raw value.
	 * @return array<string, mixed>
	 */
	public function patch( array $partial ): array {
		return $this->update( array_merge( $this->all(), $partial ) );
	}

	/**
	 * Drops the request cache (e.g. after an external option change).
	 */
	public function flush(): void {
		$this->values = null;
	}
}

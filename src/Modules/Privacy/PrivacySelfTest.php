<?php
/**
 * Self-test of the WordPress privacy tools.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Privacy;

use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/**
 * PRV-009: WordPress's "Export Personal Data" and "Erase Personal Data" tools work only with the
 * exporters and erasers that plugins add to two filters. A plugin that returns a new list instead
 * of extending the one it receives silently removes everything registered before it (seen with
 * Germanized 4.1.5 and its EU order withdrawal button package): requests then complete without the
 * data of WordPress, WooCommerce or EuroB2B (GDPR Art. 15, 17, 20).
 *
 * The test replays both filter chains callback by callback, names every callback that drops entries
 * (with the plugin it belongs to) and lists the expected entries that are missing. It reports in
 * Site Health and on the two privacy tool screens. It repairs nothing: the cause is in the other
 * plugin.
 */
final class PrivacySelfTest {

	/**
	 * Filters by kind.
	 */
	public const HOOKS = array(
		'exporters' => 'wp_privacy_personal_data_exporters',
		'erasers'   => 'wp_privacy_personal_data_erasers',
	);

	/**
	 * Site Health test id.
	 */
	public const TEST = 'eurob2b_privacy_tools';

	/**
	 * Result of this request.
	 *
	 * @var array<string, array{missing: string[], culprits: array<int, array{callback: string, plugin: string, priority: int, dropped: string[]}>}>|null
	 */
	private ?array $result = null;

	/**
	 * Hooks.
	 */
	public function hooks(): void {
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Adds the Site Health test.
	 *
	 * @param mixed $tests Tests.
	 * @return mixed
	 */
	public function register_test( $tests ) {
		if ( is_array( $tests ) ) {
			$tests['direct'][ self::TEST ] = array(
				'label' => __( 'Personal data export and erasure', 'eurob2b' ),
				'test'  => array( $this, 'site_health' ),
			);
		}
		return $tests;
	}

	/**
	 * Replays both filter chains.
	 *
	 * @return array<string, array{missing: string[], culprits: array<int, array{callback: string, plugin: string, priority: int, dropped: string[]}>}>
	 */
	public function check(): array {
		if ( null !== $this->result ) {
			return $this->result;
		}
		$result = array();
		foreach ( self::HOOKS as $kind => $hook ) {
			$replay  = self::replay( $hook );
			$dropped = array();
			foreach ( $replay['culprits'] as $culprit ) {
				$dropped = array_merge( $dropped, $culprit['dropped'] );
			}
			$result[ $kind ] = array(
				// Missing: what WordPress and EuroB2B always register, and whatever a callback in the
				// chain added (WooCommerce, other plugins), but is not in the final list; entries a
				// named culprit dropped are reported with it, not twice.
				'missing'  => array_values( array_diff( array_unique( array_merge( $this->expected( $kind ), $replay['seen'] ) ), $replay['ids'], $dropped ) ),
				'culprits' => $replay['culprits'],
			);
		}
		$this->result = $result;
		return $result;
	}

	/**
	 * Drops the cached result (tests, or after plugins changed).
	 */
	public function flush(): void {
		$this->result = null;
	}

	/**
	 * Whether both tools are complete.
	 */
	public function healthy(): bool {
		foreach ( $this->check() as $part ) {
			if ( ! empty( $part['missing'] ) || ! empty( $part['culprits'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Site Health result.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health(): array {
		$healthy = $this->healthy();
		return array(
			'label'       => $healthy ? __( 'Personal data export and erasure are complete', 'eurob2b' ) : __( 'Personal data export and erasure are incomplete', 'eurob2b' ),
			'status'      => $healthy ? 'good' : 'critical',
			'badge'       => array(
				'label' => __( 'Privacy', 'eurob2b' ),
				'color' => 'blue',
			),
			'description' => $healthy
				? '<p>' . esc_html__( 'The exporters and erasers of WordPress, WooCommerce and EuroB2B are all registered; requests under Tools → Export Personal Data and Erase Personal Data include their data.', 'eurob2b' ) . '</p>'
				: $this->findings_html(),
			'actions'     => $healthy ? '' : '<p><a href="' . esc_url( admin_url( 'export-personal-data.php' ) ) . '">' . esc_html__( 'Export Personal Data', 'eurob2b' ) . '</a></p>',
			'test'        => self::TEST,
		);
	}

	/**
	 * Warning on the two privacy tool screens.
	 */
	public function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, array( 'export-personal-data', 'erase-personal-data' ), true ) || $this->healthy() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'EuroB2B: personal data requests are incomplete.', 'eurob2b' ) . '</strong></p>' . $this->findings_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- findings_html() escapes every part.
	}

	/**
	 * The findings as HTML (every part escaped).
	 */
	private function findings_html(): string {
		$labels = array(
			'exporters' => __( 'Export Personal Data', 'eurob2b' ),
			'erasers'   => __( 'Erase Personal Data', 'eurob2b' ),
		);
		$html   = '<p>' . esc_html__( 'A plugin removes entries from the list of exporters or erasers instead of adding its own. Requests then finish without that data, and nobody notices. Ask the plugin\'s vendor for a fix; EuroB2B does not change other plugins.', 'eurob2b' ) . '</p><ul style="list-style:disc;margin-left:2em">';
		foreach ( $this->check() as $kind => $part ) {
			foreach ( $part['culprits'] as $culprit ) {
				$html .= '<li>' . esc_html(
					sprintf(
						/* translators: 1: privacy tool, 2: plugin, 3: callback, 4: filter priority, 5: removed entries */
						__( '%1$s: %2$s (%3$s, priority %4$d) removes %5$s.', 'eurob2b' ),
						$labels[ $kind ],
						$culprit['plugin'],
						$culprit['callback'],
						$culprit['priority'],
						implode( ', ', $culprit['dropped'] )
					)
				) . '</li>';
			}
			if ( ! empty( $part['missing'] ) ) {
				$html .= '<li>' . esc_html(
					sprintf(
						/* translators: 1: privacy tool, 2: missing entries */
						__( '%1$s: missing %2$s.', 'eurob2b' ),
						$labels[ $kind ],
						implode( ', ', $part['missing'] )
					)
				) . '</li>';
			}
		}//end foreach
		return $html . '</ul>';
	}

	/**
	 * Entries that must always be registered: WordPress's own (wp-includes/default-filters.php) and
	 * EuroB2B's. Other plugins' entries count when they appear anywhere in the chain.
	 *
	 * @param string $kind 'exporters' or 'erasers'.
	 * @return string[]
	 */
	private function expected( string $kind ): array {
		$ids = 'exporters' === $kind ? array( 'wordpress-comments', 'wordpress-media', 'wordpress-user' ) : array( 'wordpress-comments' );
		/** This filter is documented in src/Modules/Privacy/PrivacyModule.php */
		$providers = apply_filters( 'eurob2b_personal_data_providers', array() );
		foreach ( is_array( $providers ) ? $providers : array() as $provider ) {
			if ( $provider instanceof PersonalDataProviderInterface ) {
				$ids[] = 'eurob2b-' . $provider->id();
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Runs the callbacks of a filter one by one, as apply_filters() would, and records which one
	 * drops entries it received.
	 *
	 * @param string $hook Filter.
	 * @return array{ids: string[], seen: string[], culprits: array<int, array{callback: string, plugin: string, priority: int, dropped: string[]}>}
	 */
	private static function replay( string $hook ): array {
		global $wp_filter;
		$value    = array();
		$seen     = array();
		$culprits = array();
		$filter   = $wp_filter[ $hook ] ?? null;
		$chain    = is_object( $filter ) && isset( $filter->callbacks ) && is_array( $filter->callbacks ) ? $filter->callbacks : array();
		ksort( $chain );
		foreach ( $chain as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$before = array_map( 'strval', array_keys( $value ) );
				try {
					$next = call_user_func( $callback['function'], $value );
				} catch ( Throwable $e ) {
					$next = $value;
				}
				$next    = is_array( $next ) ? $next : array();
				$dropped = array_values( array_diff( $before, array_map( 'strval', array_keys( $next ) ) ) );
				if ( ! empty( $dropped ) ) {
					$origin     = self::origin( $callback['function'] );
					$culprits[] = array(
						'callback' => $origin['callback'],
						'plugin'   => $origin['plugin'],
						'priority' => (int) $priority,
						'dropped'  => $dropped,
					);
				}
				$value = $next;
				$seen  = array_merge( $seen, array_map( 'strval', array_keys( $value ) ) );
			}//end foreach
		}//end foreach
		return array(
			'ids'      => array_map( 'strval', array_keys( $value ) ),
			'seen'     => array_values( array_unique( $seen ) ),
			'culprits' => $culprits,
		);
	}

	/**
	 * Name and plugin of a callback.
	 *
	 * @param mixed $callback Callback.
	 * @return array{callback: string, plugin: string}
	 */
	private static function origin( $callback ): array {
		$name = __( 'unknown callback', 'eurob2b' );
		$file = '';
		try {
			if ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && 2 === count( $callback ) && is_string( $callback[1] ) ) {
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
				$name       = ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1];
				$file       = (string) $reflection->getFileName();
			} elseif ( $callback instanceof Closure || is_string( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
				$name       = $callback instanceof Closure ? sprintf( '{closure} %s:%d', basename( (string) $reflection->getFileName() ), (int) $reflection->getStartLine() ) : $callback;
				$file       = (string) $reflection->getFileName();
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new ReflectionMethod( $callback, '__invoke' );
				$name       = get_class( $callback ) . '::__invoke';
				$file       = (string) $reflection->getFileName();
			}
		} catch ( ReflectionException $e ) {
			$file = '';
		}
		return array(
			'callback' => $name,
			'plugin'   => self::plugin_of( $file ),
		);
	}

	/**
	 * The plugin (name and version) or other component a file belongs to.
	 *
	 * @param string $file File.
	 */
	private static function plugin_of( string $file ): string {
		$file = wp_normalize_path( $file );
		if ( '' === $file ) {
			return __( 'unknown plugin', 'eurob2b' );
		}
		$plugins_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		if ( str_starts_with( $file, $plugins_dir ) ) {
			$folder = strtok( substr( $file, strlen( $plugins_dir ) ), '/' );
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $basename => $data ) {
				if ( str_starts_with( $basename, $folder . '/' ) || $basename === $folder ) {
					return trim( $data['Name'] . ' ' . $data['Version'] );
				}
			}
			return (string) $folder;
		}
		if ( defined( 'WPMU_PLUGIN_DIR' ) && str_starts_with( $file, trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) ) ) {
			/* translators: %s: file name */
			return sprintf( __( 'must-use plugin %s', 'eurob2b' ), basename( $file ) );
		}
		if ( str_starts_with( $file, wp_normalize_path( get_theme_root() ) ) ) {
			return __( 'the theme', 'eurob2b' );
		}
		return str_starts_with( $file, wp_normalize_path( ABSPATH ) ) ? substr( $file, strlen( wp_normalize_path( ABSPATH ) ) ) : basename( $file );
	}
}

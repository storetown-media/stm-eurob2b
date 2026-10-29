<?php
/**
 * Asset versions.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

/**
 * Version strings for the plugin's scripts and styles.
 *
 * Release builds use the plugin version, which changes with every release. Development builds keep
 * "…-dev" across deploys, so a browser would go on running its cached copy of a changed file; their
 * asset versions therefore carry the commit of the build (the BUILD_SHA file the deploy build writes
 * into the plugin root) or, without that file, the asset's modification time. The editor scripts of the
 * plugin's blocks (blocks/<name>/block.json) get the same version through block_metadata().
 */
final class Assets {

	/**
	 * File in the plugin root that holds the commit a development build was made from.
	 */
	public const BUILD_FILE = 'BUILD_SHA';

	/**
	 * Version argument for wp_register_script(), wp_enqueue_script(), wp_register_style() and wp_enqueue_style().
	 *
	 * @param string      $relative_path Asset path relative to the plugin root, e.g. "assets/js/registration.js".
	 * @param string|null $plugin_dir    Root of the plugin that ships the asset; null for this plugin.
	 *                                   EuroB2B Pro passes its own directory (EUROB2B_PRO_DIR).
	 */
	public static function version( string $relative_path, ?string $plugin_dir = null ): string {
		return self::version_for( EUROB2B_VERSION, $plugin_dir ?? EUROB2B_PLUGIN_DIR, $relative_path );
	}

	/**
	 * Testable core of version(): the plugin version for releases; for development versions the plugin
	 * version plus ".<commit>" from the build file, else plus ".<modification time>" of the asset, else unchanged.
	 *
	 * @param string $version       Plugin version.
	 * @param string $plugin_dir    Plugin root directory.
	 * @param string $relative_path Asset path relative to the plugin root.
	 */
	public static function version_for( string $version, string $plugin_dir, string $relative_path ): string {
		if ( ! str_contains( $version, '-dev' ) ) {
			return $version;
		}

		$root  = rtrim( $plugin_dir, '/\\' ) . '/';
		$build = self::build_sha( $root . self::BUILD_FILE );
		if ( '' !== $build ) {
			return $version . '.' . $build;
		}

		$file  = $root . ltrim( $relative_path, '/\\' );
		$mtime = is_file( $file ) ? filemtime( $file ) : false;
		return false === $mtime ? $version : $version . '.' . $mtime;
	}

	/**
	 * Filter callback for block_type_metadata: the plugin's own blocks get the version of their editor script
	 * from version(), which register_block_script_handle() then uses instead of the fixed "version" of block.json.
	 *
	 * @param mixed $metadata Block metadata read from block.json.
	 * @return mixed
	 */
	public static function block_metadata( $metadata ) {
		return is_array( $metadata ) ? self::block_metadata_for( $metadata, EUROB2B_VERSION, EUROB2B_PLUGIN_DIR ) : $metadata;
	}

	/**
	 * Testable core of block_metadata(): sets "version" for a block.json under <plugin root>/blocks/ whose
	 * editor script is a "file:" path; any other block passes unchanged.
	 *
	 * @param array<string, mixed> $metadata   Block metadata; "file" is the path of block.json.
	 * @param string               $version    Plugin version.
	 * @param string               $plugin_dir Plugin root directory.
	 * @return array<string, mixed>
	 */
	public static function block_metadata_for( array $metadata, string $version, string $plugin_dir ): array {
		$file   = isset( $metadata['file'] ) && is_string( $metadata['file'] ) ? self::normalize_path( $metadata['file'] ) : '';
		$root   = self::normalize_path( rtrim( $plugin_dir, '/\\' ) ) . '/';
		$script = $metadata['editorScript'] ?? null;
		if ( '' === $file || ! str_starts_with( $file, $root . 'blocks/' ) || ! is_string( $script ) || ! str_starts_with( $script, 'file:' ) ) {
			return $metadata;
		}

		$relative            = substr( dirname( $file ), strlen( $root ) ) . '/' . (string) preg_replace( '#^(?:\./)+#', '', substr( $script, strlen( 'file:' ) ) );
		$metadata['version'] = self::version_for( $version, $plugin_dir, $relative );
		return $metadata;
	}

	/**
	 * Forward slashes and an upper-case drive letter, as wp_normalize_path() writes the "file" of block metadata.
	 *
	 * @param string $path File system path.
	 */
	private static function normalize_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return ':' === substr( $path, 1, 1 ) ? ucfirst( $path ) : $path;
	}

	/**
	 * Commit hash from the build file; '' when the file is missing or holds anything but a hexadecimal hash.
	 *
	 * @param string $file Path of the build file.
	 */
	private static function build_sha( string $file ): string {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return '';
		}
		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file in the plugin root.
		$sha     = false === $content ? '' : strtolower( trim( $content ) );
		return 1 === preg_match( '/^[0-9a-f]{4,40}$/', $sha ) ? $sha : '';
	}
}

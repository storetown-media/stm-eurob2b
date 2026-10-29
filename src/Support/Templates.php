<?php
/**
 * Template loader.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

/**
 * Loads plugin templates with theme overrides (yourtheme/eurob2b/<template>).
 */
final class Templates {

	/**
	 * Resolves a template path: theme override first, then the plugin's templates directory.
	 *
	 * @param string $template Relative path, e.g. "registration-form.php".
	 */
	public static function locate( string $template ): string {
		$theme = locate_template( array( 'eurob2b/' . $template ) );
		if ( '' !== $theme ) {
			return $theme;
		}
		return EUROB2B_PLUGIN_DIR . 'templates/' . $template;
	}

	/**
	 * Renders a template to a string with the given variables in scope.
	 *
	 * @param string               $template Relative path.
	 * @param array<string, mixed> $vars     Variables.
	 */
	public static function render( string $template, array $vars = array() ): string {
		$path = self::locate( $template );
		if ( ! is_readable( $path ) ) {
			return '';
		}
		ob_start();
		( static function ( string $eurob2b_template_path, array $eurob2b_template_vars ): void {
			extract( $eurob2b_template_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope, keys are plugin-controlled.
			include $eurob2b_template_path;
		} )( $path, $vars );
		return (string) ob_get_clean();
	}
}

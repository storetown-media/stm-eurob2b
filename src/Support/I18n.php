<?php
/**
 * When the plugin may translate (PLT-020).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Support;

/**
 * WordPress 6.7+ loads a plugin's translations just in time and reports a translation that runs
 * before after_setup_theme has finished as "triggered too early". The plugin boots on
 * plugins_loaded, and a few things are needed before that point although nobody sees their text
 * yet: the settings values (their schema carries the field labels) and WooCommerce's list of order
 * statuses, which WooCommerce asks for early. `untranslated()` builds those with the text domains
 * held back: the strings stay English and no catalogue is loaded; the translated version is
 * built on the next use after `ready()` (Settings caches only a schema built then).
 * TranslationTimingTest (integration) fails on any other early translation.
 */
final class I18n {

	/**
	 * Text domain of the plugin (translations from translate.wordpress.org).
	 */
	public const DOMAIN = 'stm-eu-b2b';

	/**
	 * The text domains held back before `ready()`: the plugin's and the one of the add-on EU-B2B Pro
	 * (its own catalogues), whose early strings (order statuses) are built through this class too.
	 */
	public const DOMAINS = array( self::DOMAIN, 'stm-eu-b2b-pro' );

	/**
	 * Whether translations may load now (after_setup_theme has finished).
	 */
	public static function ready(): bool {
		if ( ! function_exists( 'did_action' ) ) {
			return true;
		}
		return did_action( 'after_setup_theme' ) > 0 && ! doing_action( 'after_setup_theme' );
	}

	/**
	 * Runs $build with the text domains held back, so its strings come back untranslated and
	 * WordPress does not load a catalogue too early. After `ready()` it just runs $build.
	 *
	 * @template T
	 * @param callable(): T $build Code that translates.
	 * @return T
	 */
	public static function untranslated( callable $build ) {
		if ( self::ready() ) {
			return $build();
		}
		// _load_textdomain_just_in_time() skips a domain listed in $l10n_unloaded (as after
		// unload_textdomain()); the previous state is restored afterwards.
		$unloaded = isset( $GLOBALS['l10n_unloaded'] ) && is_array( $GLOBALS['l10n_unloaded'] ) ? $GLOBALS['l10n_unloaded'] : array();
		$catalogs = isset( $GLOBALS['l10n'] ) && is_array( $GLOBALS['l10n'] ) ? $GLOBALS['l10n'] : array();
		foreach ( self::DOMAINS as $domain ) {
			$GLOBALS['l10n_unloaded'][ $domain ] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress global, held back for the duration of $build only, restored below.
		}
		try {
			return $build();
		} finally {
			foreach ( self::DOMAINS as $domain ) {
				if ( ! isset( $unloaded[ $domain ] ) ) {
					unset( $GLOBALS['l10n_unloaded'][ $domain ] );
				}
				// A string translated while the domain is held back makes get_translations_for_domain()
				// store its empty placeholder (NOOP_Translations) in $l10n. Left there, it stops the
				// just-in-time load for the rest of the request, so a schema built in between kept
				// English labels on every site (1.8.0). Nothing can load the domain while it is held
				// back, so an entry that was not there before is that placeholder and goes again.
				if ( ! isset( $catalogs[ $domain ] ) && isset( $GLOBALS['l10n'] ) && is_array( $GLOBALS['l10n'] ) ) {
					unset( $GLOBALS['l10n'][ $domain ] );
				}
			}
		}
	}
}

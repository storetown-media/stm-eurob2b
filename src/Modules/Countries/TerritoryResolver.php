<?php
/**
 * Special territory resolver.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Countries;

/**
 * TAX-024: tells addresses that carry a member state's country code but lie outside the EU VAT
 * area (Art. 6 VAT Directive) from the rest of that state. The rules are data with sources
 * (docs/country-matrix.md §8, checked 2026-10-28). Precedence: for a country with state rules
 * (Spain) a given WooCommerce state decides and the postcode is only the fallback when the state
 * is missing; otherwise the most specific postcode rule wins (a whole postcode before a longer
 * prefix before a shorter one). `resolve()` applies verified rules only; an UNVERIFIED match never
 * changes anything.
 *
 * WooCommerce has own country codes for the French overseas departments and collectivities (GP,
 * MQ, GF, RE, YT, MF, BL, PM, WF, PF) and for Åland (AX); `CountryRegistry` treats them as third
 * countries already, so they need no rule here.
 */
final class TerritoryResolver {

	/**
	 * Rules version, shown on the VAT IDs page.
	 */
	public const RULES_VERSION = '2026.10.1';

	/**
	 * Date the sources were checked.
	 */
	public const CHECKED_AT = '2026-10-28';

	/**
	 * VAT Directive 2006/112/EC, consolidated version of 2025-04-14 (Art. 6 and Art. 7).
	 */
	public const SOURCE_DIRECTIVE = 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:02006L0112-20250414';

	/**
	 * European Commission: territorial status of EU countries and certain territories.
	 */
	public const SOURCE_COMMISSION = 'https://taxation-customs.ec.europa.eu/territorial-status-eu-countries-and-certain-territories_en';

	/**
	 * Spanish statistics office: province codes (the first two digits of a Spanish postcode).
	 */
	public const SOURCE_ES_PROVINCES = 'https://www.ine.es/daco/daco42/codmun/cod_provincia.htm';

	/**
	 * La Poste: official base of French postcodes (including overseas and Monaco).
	 */
	public const SOURCE_FR_POSTCODES = 'https://data.laposte.fr/datasets/laposte-hexasmal';

	/**
	 * Length of the postcodes of every country with rules (ES, FR, DE, IT, GR, FI).
	 */
	private const POSTCODE_LENGTH = 5;

	/**
	 * Rules, built once.
	 *
	 * @var Territory[]|null
	 */
	private static ?array $rules = null;

	/**
	 * The applied territory of an address: a verified rule, or null for a normal address.
	 *
	 * @param string $country  ISO country code.
	 * @param string $state    WooCommerce state code ("" when unknown).
	 * @param string $postcode Postcode as typed.
	 */
	public static function resolve( string $country, string $state = '', string $postcode = '' ): ?Territory {
		$territory = self::find( $country, $state, $postcode );
		return null !== $territory && $territory->is_verified() ? $territory : null;
	}

	/**
	 * The most specific rule that matches an address, verified or not.
	 *
	 * @param string $country  ISO country code.
	 * @param string $state    WooCommerce state code ("" when unknown).
	 * @param string $postcode Postcode as typed.
	 */
	public static function find( string $country, string $state = '', string $postcode = '' ): ?Territory {
		$country = strtoupper( trim( $country ) );
		$rules   = array_values( array_filter( self::all(), static fn( Territory $t ): bool => $t->member_state === $country ) );
		if ( array() === $rules ) {
			return null;
		}
		$state       = strtoupper( trim( $state ) );
		$state_rules = array_filter( $rules, static fn( Territory $t ): bool => array() !== $t->states );
		if ( '' !== $state && array() !== $state_rules ) {
			// The state decides; a mainland state with an island postcode stays mainland.
			foreach ( $state_rules as $territory ) {
				if ( in_array( $state, $territory->states, true ) ) {
					return $territory;
				}
			}
			return null;
		}
		$code = self::normalise_postcode( $postcode );
		if ( '' === $code ) {
			return null;
		}
		$best       = null;
		$best_score = 0;
		foreach ( $rules as $territory ) {
			$score = in_array( $code, $territory->postcodes, true ) ? PHP_INT_MAX : 0;
			foreach ( $territory->postcode_prefixes as $prefix ) {
				if ( str_starts_with( $code, $prefix ) ) {
					$score = max( $score, strlen( $prefix ) );
				}
			}
			if ( $score > $best_score ) {
				$best       = $territory;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * Postcode as the rules compare it: upper case, without spaces and hyphens, without a leading
	 * country letter ("D-27498"), and numeric codes padded with the leading zeros a spreadsheet or
	 * a form drops ("3801" is 03801 in Alicante, not a Canary Islands code).
	 *
	 * @param string $postcode Postcode as typed.
	 */
	public static function normalise_postcode( string $postcode ): string {
		$code = strtoupper( (string) preg_replace( '/[\s\-.]+/u', '', $postcode ) );
		$code = (string) preg_replace( '/^[A-Z]{1,3}(?=\d+$)/', '', $code );
		if ( '' !== $code && ctype_digit( $code ) && strlen( $code ) < self::POSTCODE_LENGTH ) {
			$code = str_pad( $code, self::POSTCODE_LENGTH, '0', STR_PAD_LEFT );
		}
		return $code;
	}

	/**
	 * A rule by id.
	 *
	 * @param string $id Territory id.
	 */
	public static function get( string $id ): ?Territory {
		foreach ( self::all() as $territory ) {
			if ( $territory->id === $id ) {
				return $territory;
			}
		}
		return null;
	}

	/**
	 * Every rule, verified and UNVERIFIED, in display order.
	 *
	 * @return Territory[]
	 */
	public static function all(): array {
		if ( null === self::$rules ) {
			self::$rules = self::build();
		}
		return self::$rules;
	}

	/**
	 * The shipped rules (docs/country-matrix.md §8).
	 *
	 * @return Territory[]
	 */
	private static function build(): array {
		$out      = Territory::OUTSIDE_EU_VAT_AREA;
		$ok       = Territory::REVIEW_VERIFIED;
		$open     = Territory::REVIEW_UNVERIFIED;
		$d        = self::SOURCE_DIRECTIVE;
		$com      = self::SOURCE_COMMISSION;
		$es       = self::SOURCE_ES_PROVINCES;
		$fr       = self::SOURCE_FR_POSTCODES;
		$at       = self::CHECKED_AT;
		$oct      = 'Art. 355(2) TFEU, Annex II (overseas country and territory, outside the EU)';
		$art_6_1c = 'Art. 6(1)(c) VAT Directive (Art. 349 TFEU)';
		return array(
			new Territory( 'es_canary_islands', 'Canary Islands', 'ES', $out, '', 'Art. 6(1)(b) VAT Directive', array( 'GC', 'TF' ), array(), array( '35', '38' ), $d, $es, $at, $ok ),
			new Territory( 'es_ceuta', 'Ceuta', 'ES', $out, '', 'Art. 6(2)(c) VAT Directive', array( 'CE' ), array(), array( '51' ), $d, $es, $at, $ok ),
			new Territory( 'es_melilla', 'Melilla', 'ES', $out, '', 'Art. 6(2)(d) VAT Directive', array( 'ML' ), array(), array( '52' ), $d, $es, $at, $ok ),
			new Territory( 'fr_guadeloupe', 'Guadeloupe', 'FR', $out, '', $art_6_1c, array(), array(), array( '971' ), $d, $fr, $at, $ok ),
			new Territory( 'fr_saint_barthelemy', 'Saint-Barthélemy', 'FR', $out, '', $oct, array(), array( '97133' ), array(), $com, $fr, $at, $ok ),
			new Territory( 'fr_saint_martin', 'Saint-Martin', 'FR', $out, '', $art_6_1c, array(), array( '97150' ), array(), $d, $fr, $at, $ok ),
			new Territory( 'fr_martinique', 'Martinique', 'FR', $out, '', $art_6_1c, array(), array(), array( '972' ), $d, $fr, $at, $ok ),
			new Territory( 'fr_french_guiana', 'French Guiana', 'FR', $out, '', $art_6_1c, array(), array(), array( '973' ), $d, $fr, $at, $ok ),
			new Territory( 'fr_reunion', 'Réunion', 'FR', $out, '', $art_6_1c, array(), array(), array( '974' ), $d, $fr, $at, $ok ),
			new Territory( 'fr_saint_pierre_miquelon', 'Saint Pierre and Miquelon', 'FR', $out, '', $oct, array(), array(), array( '975' ), $com, $fr, $at, $ok ),
			new Territory( 'fr_mayotte', 'Mayotte', 'FR', $out, '', $art_6_1c, array(), array(), array( '976' ), $d, $fr, $at, $ok ),
			new Territory( 'fr_overseas', 'French overseas territory', 'FR', $out, '', $art_6_1c, array(), array(), array( '97' ), $d, $fr, $at, $ok, 'Every other French postcode 97xxx.' ),
			new Territory( 'fr_wallis_futuna', 'Wallis and Futuna', 'FR', $out, '', $oct, array(), array(), array( '986' ), $com, $fr, $at, $ok ),
			new Territory( 'fr_french_polynesia', 'French Polynesia', 'FR', $out, '', $oct, array(), array(), array( '987' ), $com, $fr, $at, $ok ),
			new Territory( 'fr_monaco', 'Monaco', 'FR', Territory::TREATED_AS_MEMBER_STATE, 'FR', 'Art. 7(1) VAT Directive', array(), array( '98000' ), array(), $d, $fr, $at, $ok, 'Treated as France: the address stays a normal French one.' ),
			new Territory( 'de_heligoland', 'Heligoland', 'DE', $out, '', 'Art. 6(2)(a) VAT Directive', array(), array( '27498' ), array(), $d, 'https://www.helgoland.de/impressum/', $at, $ok ),
			new Territory( 'de_busingen', 'Büsingen am Hochrhein', 'DE', $out, '', 'Art. 6(2)(b) VAT Directive', array(), array( '78266' ), array(), $d, 'https://www.buesingen.de/impressum', $at, $ok, 'Addresses with CH and 8238 are in the Swiss VAT area already.' ),
			new Territory( 'it_campione', "Campione d'Italia", 'IT', $out, '', 'Art. 6(1)(f) VAT Directive', array(), array( '22061' ), array(), $d, 'https://www.comune.campione-d-italia.co.it/vivere_il_comune/luoghi/luogo_1.html', $at, $ok ),
			new Territory( 'it_livigno', 'Livigno', 'IT', $out, '', 'Art. 6(2)(e) VAT Directive', array(), array( '23041' ), array(), $d, 'https://www.comune.livigno.so.it/', $at, $ok ),
			new Territory( 'gr_mount_athos', 'Mount Athos', 'GR', $out, '', 'Art. 6(1)(a) VAT Directive', array(), array( '63086' ), array(), $d, '', $at, $open, 'Postcode not confirmed by an official source.' ),
			new Territory( 'fi_aland', 'Åland Islands', 'FI', $out, '', 'Art. 6(1)(d) VAT Directive', array(), array(), array( '22' ), $d, '', $at, $open, 'Postcodes not confirmed by an official source; addresses with the country code AX are third-country addresses already.' ),
			new Territory( 'de_heligoland_po_box', 'Heligoland (PO boxes)', 'DE', $out, '', 'Art. 6(2)(a) VAT Directive', array(), array( '27486' ), array(), $d, '', $at, $open, 'Postcode not confirmed by an official source.' ),
			new Territory( 'it_livigno_shared', 'Livigno (shared postcode)', 'IT', $out, '', 'Art. 6(2)(e) VAT Directive', array(), array( '23030' ), array(), $d, '', $at, $open, 'Shared with other municipalities; it never decides alone.' ),
		);
	}
}

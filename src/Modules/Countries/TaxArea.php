<?php
/**
 * Tax area classification.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Countries;

/**
 * Which VAT system a country belongs to. EU, EEA and "Europe" are not the same thing.
 *
 * - EU_VAT_AREA:     EU member state, VAT Directive 2006/112/EC applies, VIES available.
 * - SWISS_VAT_AREA:  Switzerland and Liechtenstein (common VAT territory, Swiss VAT law).
 * - EEA_OWN_VAT:     EEA member outside the EU with its own VAT system (Norway, Iceland).
 * - THIRD_COUNTRY:   Everything else; exports/imports, no VIES.
 *
 * Note: parts of some EU member states are excluded from the EU VAT area
 * (e.g. Canary Islands, Åland); that is handled per country profile, not here.
 */
enum TaxArea: string {
	case EU_VAT_AREA    = 'eu';
	case SWISS_VAT_AREA = 'ch_li';
	case EEA_OWN_VAT    = 'eea';
	case THIRD_COUNTRY  = 'third';

	/**
	 * Whether VIES can validate VAT IDs from this area.
	 */
	public function supports_vies(): bool {
		return self::EU_VAT_AREA === $this;
	}
}

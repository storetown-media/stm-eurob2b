<?php
/**
 * Tax treatment of a sale.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\Treatment;

/**
 * How VAT is handled for an order (docs/country-matrix.md §3). Stored on the order as
 * `_eurob2b_tax_treatment`, so reports and invoices can rely on it.
 */
enum TaxTreatment: string {
	/** Shop and customer in the same country: VAT charged, a VAT ID changes nothing (EU-02). */
	case DOMESTIC = 'domestic';
	/** EU customer without the conditions for the exemption: VAT of the dispatch country (EU-03, EU-07). */
	case INTRA_EU_TAXED = 'intra_eu_taxed';
	/** Intra-Community B2B supply or service: exempt, customer accounts for VAT (EU-01, EU-05). */
	case INTRA_EU_REVERSE_CHARGE = 'intra_eu_reverse_charge';
	/** Swiss VAT area (CH, LI): WooCommerce rates decide; a CHE number never exempts (CH-05). */
	case SWISS_AREA = 'swiss_area';
	/** Third country or EEA with own VAT: export, WooCommerce rates decide (EU-06, NO-02). */
	case EXPORT = 'export';
	/** Taxed and flagged for a person: pickup without transport evidence, unresolved validation. */
	case MANUAL = 'manual';
	/** Goods and services in one order with different outcomes: goods decide the customer-wide exemption, exempt service lines carry no tax. */
	case MIXED = 'mixed';
	/** The shop is not in the EU VAT area: no automatic decision. */
	case OUT_OF_SCOPE = 'out_of_scope';

	/**
	 * Whether this treatment exempts the sale from the shop's VAT.
	 */
	public function is_exempt(): bool {
		return self::INTRA_EU_REVERSE_CHARGE === $this;
	}
}

=== EuroB2B – B2B and Wholesale for WooCommerce in Europe ===
Contributors: jobhunter99
Tags: b2b, wholesale, vat, reverse charge, customer groups
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

B2B for WooCommerce, built for Europe: business customers, group prices, VAT ID check with VIES and a reverse charge decision you can follow.

== Description ==

EuroB2B turns a WooCommerce shop into a shop for business customers – or for business customers and consumers side by side – with the rules European shops need.

= Business customers and groups =

* Customer types (business, consumer) and customer groups.
* Registration form for business customers with your own fields, approval by the shop and e-mails for approval and rejection.
* Group prices for simple and variable products.
* Percentage discount rules per group, product or category.
* Payment and shipping methods per customer type or group.
* Prices shown net for business customers and gross for consumers, with the matching price suffix.

= VAT in Europe =

* VAT ID field at registration, in My Account, in the classic checkout and in the Checkout block.
* VAT ID check with the EU VIES service (off until you switch it on), with the result stored as evidence (time, consultation number when available).
* A decision table for the tax treatment of every order: domestic, intra-Community supply or service with reverse charge, export to a third country, Switzerland and Liechtenstein. A valid VAT ID alone never exempts an order; unclear cases are taxed and flagged for review.
* A VAT ID of the shop's own country never exempts.
* Country rules with sources and review status, so you can see what EuroB2B decides and why.

= Privacy by design =

* Personal data export and erasure for everything EuroB2B stores, in the WordPress privacy tools.
* A suggested text for your privacy policy and a record of processing activities (Art. 30 GDPR) generated from the features you use.
* An audit log of changes made by shop staff.
* No telemetry, no tracking, no remote fonts. The only external connection is the VAT ID check (see External services).

= Switching from another B2B plugin =

* Import customer groups, group prices and business customers from B2BKing and Wholesale Suite, with a journal and a way back.

= Languages =

English, German (informal and formal), French, Italian, Spanish, Dutch and Polish. The French, Italian, Spanish, Dutch and Polish translations were made with machine assistance and a fixed glossary; corrections are welcome.

= EuroB2B Pro =

EuroB2B works on its own. EuroB2B Pro is a separate add-on for shops that need more: order form and CSV quick order, purchase lists, quote requests and offers with PDF, company accounts with order approval, tier prices and price lists, invoice and purchase order payment, dunning, recapitulative statement, proof of intra-Community supply and export evidence, OSS threshold monitor, audit files, an accounting export in the DATEV format, e-invoicing data with Peppol check, a REST API and more. See [storetown-media.de](https://storetown-media.de).

== Installation ==

1. Install and activate WooCommerce (10.0 or newer).
2. Install EuroB2B from *Plugins → Add New* or upload the zip file, then activate it.
3. Open *EuroB2B → Settings*: set your shop's VAT ID, choose how the VAT ID field behaves and whether VAT IDs are checked with VIES.
4. Create customer groups under *EuroB2B → Customers → Groups* and assign business customers to them.

EuroB2B needs PHP 8.1 or newer.

== Frequently Asked Questions ==

= Does a valid VAT ID make an order tax-free? =

Not on its own. EuroB2B exempts an order only when every condition of the decision table holds: business customer, VAT ID of another member state confirmed as valid, place of supply in another member state, no self-collection, the exemption switched on and the country rules reviewed. Everything else is taxed, and unclear cases are flagged for review.

= Is this tax or legal advice? =

No. EuroB2B documents the rules it applies with their sources, but your tax adviser decides how your shop is set up.

= Does EuroB2B work with the Cart and Checkout blocks? =

Yes. The VAT ID field, the tax decision and the price display work in the classic checkout and in the Cart and Checkout blocks. Differences that remain are listed on the settings page.

= Does EuroB2B work with Germanized or German Market? =

EuroB2B is tested next to Germanized and leaves legal texts, invoices and the withdrawal button to the plugins that provide them.

= Where are the data stored? =

In your WordPress database only. Nothing is sent to Storetown Media.

== External services ==

EuroB2B connects to one external service, and only when you use it:

* **VIES (VAT Information Exchange System) of the European Commission** checks whether a VAT ID is valid. When a customer enters a VAT ID or shop staff check one again, EuroB2B sends the VAT ID and, if you entered it in the settings, your shop's own VAT ID (so that VIES returns a consultation number). No trader name or address and no other personal data is sent. The check is off until you switch it on in the settings (*Validate EU VAT IDs via VIES*). Service: https://ec.europa.eu/taxation_customs/vies/ – legal notice: https://commission.europa.eu/legal-notice_en – privacy policy: https://commission.europa.eu/privacy-policy-websites-managed-european-commission_en

== Screenshots ==

1. Customer groups with group prices.
2. Registration of business customers with approval.
3. VAT ID check and the stored evidence.
4. Tax treatment of an order with its reason.
5. Settings.

== Changelog ==

The full changelog is in CHANGELOG.md in the plugin folder and on https://github.com/storetown-media/eurob2b.

= 1.5.0 =
* Clearer admin: the EuroB2B menu is grouped into areas with an overview of what is waiting, settings with a side navigation, coloured statuses and a product data tab of its own.
* My Account tables fit narrow theme columns.

= 1.4.0 =
* First release on WordPress.org: EuroB2B is now the free core; the Pro features moved to the separate add-on EuroB2B Pro.

== Upgrade Notice ==

= 1.4.0 =
EuroB2B is now split into the free core and the add-on EuroB2B Pro. If you used Pro features (quotes, company accounts, order form, tier prices, invoice payment, tax reports …), install EuroB2B Pro so they stay available.

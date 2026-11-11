=== STM EU-B2B ===
Contributors: jobhunter99
Tags: b2b, wholesale, vat, reverse charge, customer groups
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

B2B and Wholesale for WooCommerce in Europe: business customers, group prices, VAT ID check with VIES and a reverse charge decision you can follow.

== Description ==

STM EU-B2B turns a WooCommerce shop into a shop for business customers – or for business customers and consumers side by side – with the rules European shops need.

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
* Country rules with sources and review status, so you can see what EU-B2B decides and why.

= Privacy by design =

* Personal data export and erasure for everything EU-B2B stores, in the WordPress privacy tools.
* A suggested text for your privacy policy and a record of processing activities (Art. 30 GDPR) generated from the features you use.
* An audit log of changes made by shop staff.
* No telemetry, no tracking, no remote fonts. The only external connection is the VAT ID check (see External services).

= Switching from another B2B plugin =

* Import customer groups, group prices and business customers from B2BKing and Wholesale Suite, with a journal and a way back.

= Languages =

The plugin is written in English and gets its translations from translate.wordpress.org. Complete translations into German (informal and formal), French, Italian, Spanish, Dutch and Polish exist and are submitted there; until they are approved there, the plugin shows English. The French, Italian, Spanish, Dutch and Polish translations were made with machine assistance and a fixed glossary; corrections are welcome.

= EU-B2B Pro =

EU-B2B works on its own. EU-B2B Pro is a separate add-on for shops that need more: order form and CSV quick order, purchase lists, quote requests and offers with PDF, company accounts with order approval, tier prices and price lists, invoice and purchase order payment, dunning, recapitulative statement, proof of intra-Community supply and export evidence, OSS threshold monitor, audit files, an accounting export in the DATEV format, e-invoicing data with Peppol check, a REST API and more. See [storetown-media.de](https://storetown-media.de).

== Installation ==

1. Install and activate WooCommerce (10.0 or newer).
2. Install EU-B2B from *Plugins → Add New* or upload the zip file, then activate it.
3. Open *STM EU-B2B → Settings*: set your shop's VAT ID, choose how the VAT ID field behaves and whether VAT IDs are checked with VIES.
4. Create customer groups under *STM EU-B2B → Customers → Groups* and assign business customers to them.

EU-B2B needs PHP 8.1 or newer.

== Frequently Asked Questions ==

= Does a valid VAT ID make an order tax-free? =

Not on its own. EU-B2B exempts an order only when every condition of the decision table holds: business customer, VAT ID of another member state confirmed as valid, place of supply in another member state, no self-collection, the exemption switched on and the country rules reviewed. Everything else is taxed, and unclear cases are flagged for review.

= Is this tax or legal advice? =

No. EU-B2B documents the rules it applies with their sources, but your tax adviser decides how your shop is set up.

= Does EU-B2B work with the Cart and Checkout blocks? =

Yes. The VAT ID field, the tax decision and the price display work in the classic checkout and in the Cart and Checkout blocks. Differences that remain are listed on the settings page.

= Does EU-B2B work with Germanized or German Market? =

EU-B2B is tested next to Germanized and leaves legal texts, invoices and the withdrawal button to the plugins that provide them.

= Where are the data stored? =

In your WordPress database only. Nothing is sent to Storetown Media.

== External services ==

EU-B2B connects to one external service, and only when you use it:

* **VIES (VAT Information Exchange System) of the European Commission** checks whether a VAT ID is valid. The check is off until you switch it on in the settings (*Validate EU VAT IDs via VIES*). Then, when a customer enters a VAT ID or shop staff check one again, EU-B2B sends the VAT ID and, if you entered it in the settings, your shop's own VAT ID (so that VIES returns a consultation number). No trader name or address and no other personal data is sent. Requests go to `https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number` and, when that does not answer, to `https://ec.europa.eu/taxation_customs/vies/services/checkVatService` (the SOAP interface of the same service). Service: https://ec.europa.eu/taxation_customs/vies/ – terms of use (legal notice of the European Commission): https://commission.europa.eu/legal-notice_en – privacy policy: https://commission.europa.eu/privacy-policy-websites-managed-european-commission_en

Besides that, the Site Health test *Uploaded documents are not reachable from the web* requests a small test file from your own site to check that uploaded documents are protected. This request goes to your own site only and sends no data.

== Screenshots ==

1. Group prices per customer group on the product (tab STM EU-B2B).
2. Registration approvals: approve a business customer into a group, or reject with a note.
3. VAT ID validations with VIES, the stored evidence and the cases that need a look.
4. The order shows customer type, group, VAT ID check and the tax treatment with its reason.
5. Settings for tax and VAT IDs, including the VIES check and the countries checked at checkout.

== Changelog ==

The full changelog is in CHANGELOG.md in the plugin folder and on https://github.com/storetown-media/stm-eurob2b.

= 1.9.2 =
* The list of VAT ID checks that need a look works on SQLite (for example WordPress Playground).

= 1.9.1 =
* All output is escaped where it is printed; the printable record of processing activities and the confirmation prompts no longer use inline style or script tags.
* The privacy notice on the export and erase screens shows its list with bullets again.

= 1.9.0 =
* Translations now come from translate.wordpress.org; the plugin no longer ships its own language files.
* STM EU-B2B Pro has its own text domain and keeps the translations of both plugins until language packs exist.
* Plain-text e-mails, the registration form and the admin styles follow the WordPress.org guidelines more closely.
* The external services section of this readme names the VIES endpoints and the Site Health self-test.

= 1.8.1 =
* The settings screens are translated again (1.8.0 showed them in English on sites in other languages).
* The plugin links point to the public repository on GitHub.

= 1.8.0 =
* EuroB2B is now STM EU-B2B. The plugin moves to the folder stm-eu-b2b; all settings and data stay. See the upgrade notice.
* Ready for the WordPress.org plugin directory: messages escaped, uploads through WordPress, translations loaded on time.
* Order screen: the internal supply type field is no longer listed under the order lines.

= 1.7.0 =
* Checkout block: the result of the VAT ID check appears at the billing address as soon as the number changes.

= 1.6.0 =
* Confirm the review of orders whose tax treatment EU-B2B could not decide itself; the overview lists them.
* Contact details, data protection officer and representative for the record of processing activities in the privacy settings.
* Summaries in the EU-B2B product tab.

= 1.5.0 =
* Clearer admin: the EU-B2B menu is grouped into areas with an overview of what is waiting, settings with a side navigation, coloured statuses and a product data tab of its own.
* My Account tables fit narrow theme columns.

= 1.4.0 =
* First release on WordPress.org: EU-B2B is now the free core; the Pro features moved to the separate add-on EU-B2B Pro.

== Upgrade Notice ==

= 1.9.2 =
If you use STM EU-B2B Pro, update it to 1.9.2 at the same time; until both have the same version the Pro features are off.

= 1.9.1 =
If you use STM EU-B2B Pro, update it to 1.9.1 at the same time; until both have the same version the Pro features are off.

= 1.9.0 =
If you use STM EU-B2B Pro, update it to 1.9.0 at the same time; until both have the same version the Pro features are off. Without Pro, EU-B2B shows English until its language pack from translate.wordpress.org is available.

= 1.8.0 =
EuroB2B is now STM EU-B2B. Install STM EU-B2B and update STM EU-B2B Pro first, then deactivate the old "EuroB2B": STM EU-B2B takes over with all data. Then delete the old plugin.

= 1.4.0 =
EU-B2B is now split into the free core and the add-on EU-B2B Pro. If you used Pro features (quotes, company accounts, order form, tier prices, invoice payment, tax reports …), install EU-B2B Pro so they stay available.

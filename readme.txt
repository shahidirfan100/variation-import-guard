=== Variation Import Guard ===
Contributors: shahidirfan100
Tags: woocommerce, product-import, csv, product-variations, validation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Check variation relationships and download a corrected WooCommerce product CSV before importing it.

== Description ==

Variation Import Guard checks WooCommerce product CSV files before an import. It reports duplicate SKUs, missing parents, parent row order, attribute mismatches, missing variation prices, and invalid stock quantities. It can move variable parent rows before variation rows and map common supplier column names to WooCommerce's CSV headings.

The checker never creates, updates, or deletes products. It writes a new corrected CSV and a separate row report. Store matching is optional and read-only.

== Features ==

* Detect common WooCommerce and supplier CSV headings.
* Review the detected mapping before processing.
* Check parent and variation relationships, SKUs, attributes, price, and stock.
* Move parent rows before variation rows in the corrected download.
* Trim mapped cells only when you opt in.
* Optionally compare product SKUs and parent types with the current store.
* Keep working files in a protected temporary folder and remove them after 24 hours.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New Plugin > Upload Plugin, or copy the plugin folder to `/wp-content/plugins/`.
2. Activate Variation Import Guard.
3. Open Tools > Variation Import Guard.
4. Choose a CSV, confirm its type, SKU, and parent columns, then run the preflight.
5. Review the findings and download the corrected CSV and row report.
6. Import the corrected CSV using WooCommerce's product importer. Test a small sample on staging first.

== Screenshots ==

1. Upload a supplier CSV and see the checks that run before product data is written.
2. Confirm detected columns, select supplier fields, and opt in to whitespace trimming or store matching.
3. Review row findings, parent ordering repairs, and separate download links for the corrected CSV and report.

== Frequently Asked Questions ==

= Does the plugin import products? =

No. It validates the uploaded file and prepares two downloads. Use WooCommerce's own importer to make product changes.

= Which files can I check? =

CSV files up to 8 MB and 15,000 data rows. UTF-8 (with or without BOM) and Windows-1252/Latin-1 supplier files are supported. Save UTF-16 files as UTF-8 before uploading.

= What does the corrected CSV change? =

It renames mapped fields to WooCommerce headings, puts variable parent rows before variation rows, and can trim surrounding whitespace in mapped fields when that option is selected. It leaves other product cells as supplied.

= Does the live catalog check change products? =

No. It reads matching product SKUs and parent product types. It does not create or update store data.

= How long are uploaded files kept? =

The source file is removed after the preflight finishes. The corrected file and row report stay in a protected temporary folder for up to 24 hours so you can download them.

= Can I import the corrected file immediately? =

Review every reported error and warning first. A clean CSV check does not confirm the WooCommerce importer's final column mapping. Test a small sample on a staging store before a large import.

== Privacy ==

The plugin does not send file contents or store data to an external service. CSV content is processed on your WordPress server. The uploaded source file is removed after processing; output files expire after 24 hours.

== Changelog ==

= 1.0.1 =
* Fix numeric parent SKU matching and preserve quoted CSV values during row reordering.
* Report output write failures instead of returning incomplete downloads.
* Reduce admin cleanup work, clear scheduled cleanup on uninstall, and improve footer contrast.

= 1.0.0 =
* Initial release.
* Add bounded CSV preflight, parent-first repair, WooCommerce field mapping, row report, and optional read-only catalog check.

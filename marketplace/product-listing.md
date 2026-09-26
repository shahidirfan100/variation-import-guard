# Variation Import Guard

## Short description

Check supplier CSVs for broken variation links, duplicate SKUs, and attribute issues before running WooCommerce's product importer.

## Product overview

Variation Import Guard is a read-only preflight for WooCommerce product CSVs. It checks the parent and variation rows, points to row-level problems, and creates a corrected CSV with variable parents placed before their variations. A separate report lists each finding.

The extension never creates, edits, or deletes products. The source file is kept unchanged and removed after the check. Corrected files stay in a private temporary folder for up to 24 hours.

## What it checks

- Duplicate or missing variation SKUs
- Missing, misplaced, or non-variable parents
- Variation attribute names and values against the parent row
- Missing or invalid variation prices
- Invalid stock quantities
- Duplicate variation attribute combinations
- Supplier headings that can be mapped to WooCommerce fields

The optional store check compares SKUs and parent product types using read-only queries. It does not change catalog data.

## How it works

1. Upload a UTF-8 or Windows CSV file.
2. Review the detected product type, SKU, parent, ID, price, and stock columns.
3. Optionally trim whitespace in mapped cells or check product SKUs against the store.
4. Review the findings and download the corrected CSV and row report.
5. Import the corrected CSV with WooCommerce's own product importer.

Files may be up to 8 MB and 15,000 data rows. UTF-8, UTF-8 with BOM, and Windows-1252/Latin-1 are supported. UTF-16 files need to be saved as UTF-8 first.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- WooCommerce is needed only for the optional store matching check
- An administrator or WooCommerce manager account

## Short description for product cards

Find variation CSV issues before import. Review parent links, SKUs, attributes, price, and stock, then download a corrected file and row report.

## Gallery captions

1. Upload a product or supplier CSV and see the checks before import.
2. Review detected fields and choose whether to trim mapped values or compare against the store.
3. Read the row findings, then download the corrected CSV and the separate report.

## FAQ

### Does the extension import products?

No. It produces a corrected CSV and report. Use WooCommerce's product importer to make catalog changes.

### Does data leave my site?

No. The plugin processes CSV content on the WordPress server and makes no external service calls.

### Does it repair every issue automatically?

It places variable parent rows before their variations and can normalize mapped headings. It reports catalog and row-data issues for review; it does not invent product data.

### How long are the files available?

The source is removed after processing. The corrected CSV and row report expire within 24 hours.

### Can I check a supplier file larger than the limit?

Split it into smaller files of up to 8 MB and 15,000 rows each.

## Visual setup

- Product icon: `assets/product-icon-160x160.png`
- Suggested highlight card background: `#E7F5ED`
- Gallery images: `gallery/01-check-the-csv-before-the-import.png`, `gallery/02-confirm-the-supplier's-columns.png`, and `gallery/03-get-specific-row-findings.png`

The product icon is a square source image without baked-in rounded corners. The gallery images are original artwork built around captured screens from the tested WordPress admin flow.

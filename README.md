# Variation Import Guard

Check WooCommerce variable product relationships before importing a CSV. The plugin reports row-level problems, can place parent rows before variations, and creates a separate report. It never changes products.

## Install

Install `variation-import-guard.zip` from the WordPress Plugins screen, activate it, and open **Tools → Variation Import Guard**.

## Check a file

1. Upload a UTF-8 or Windows CSV file up to 8 MB and 15,000 data rows.
2. Confirm the type, SKU, and parent columns. Common WooCommerce headings and supplier aliases are detected.
3. Leave whitespace trimming off to preserve exact values, or enable it to trim mapped fields and attribute cells in the output.
4. If WooCommerce is active, optionally compare SKUs and parent product types with the store. This query is read-only.
5. Review findings, download the corrected CSV and row report, then import with WooCommerce's own importer.

The corrected file moves all variable parent rows before variation rows and converts mapped supplier fields into WooCommerce headers. It preserves unrelated product cells. Numeric parent IDs are written using WooCommerce's `id:123` form when the matching parent is present in the CSV or selected store catalog.

## Limits and privacy

The checker accepts CSV files up to 8 MB and 15,000 rows. It streams rows and stores only compact relationship metadata in a temporary spool. Files are held in a randomized per-site directory under WordPress's configured temporary path, with access-deny rules added. The source file is deleted after the check; corrected and report files expire after 24 hours. No file content is sent off the site.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- WooCommerce is required only for the optional live catalog check

## Development checks

The release validation report and test fixtures are included beside the install ZIP in the release package, outside the plugin folder.

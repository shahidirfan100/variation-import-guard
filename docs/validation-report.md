# Variation Import Guard — release validation

**Release:** 1.0.0
**Date:** 2026-09-26
**Install package:** `variation-import-guard.zip`
**SHA-256:** `2D72695776C3C4578A2DB47A5D9485ADD3E4954FBD53190A73CBA0F02C304BF2`

## Results

| Check | Result |
| --- | --- |
| PHP syntax on the five packaged PHP files | Pass; PHP 8.5.8 CLI |
| Focused CSV preflight harness | Pass; 30 assertions |
| PHPCS PluginCheck ruleset | Pass; PHPCS 3.13.6, no findings |
| WordPress Plugin Check 2.1.0 | Pass; `errors: []`, `warnings: []` |
| WordPress runtime | Pass; WordPress 7.1.2 with PHP 8.3.33 |
| WooCommerce runtime | Pass; WooCommerce 11.1.2 activated |
| Live upload → mapping → preflight → downloads | Pass on the 391-byte, five-row CSV fixture |
| Optional WooCommerce catalog check | Pass with an empty test catalog; returned the read-only status and no runtime error |
| Mobile results layout at 390px | Pass; page width stayed within viewport, table scrolled inside its panel, both download buttons remained visible |
| Release ZIP | Pass; one top-level `variation-import-guard/` folder, eight files, header and readme both 1.0.0 |
| ZIP/source equality | Pass; every extracted file hash matched the plugin source tree |
| Media asset validation | Pass; 11 valid PNGs, expected WordPress.org dimensions, Woo icon at 160 × 160, three gallery images at 1400 × 930 |

## Runtime fixture

The five-row fixture included a variation before its parent, a duplicate SKU, a missing parent, an invalid stock quantity, a missing price, and an attribute value absent from the parent. The result reported five data rows, one variable parent, four variations, one orphan row, one duplicate SKU, one reorder, six errors, and one warning. The corrected CSV put the parent first and retained the remaining supplier cells.

Both downloads returned HTTP 200 with `text/csv; charset=UTF-8`. The corrected file began with the normalized WooCommerce headings and the row report began with `Row,Severity,Check,Finding`. The fixture hash before and after the run was `8E3309EF01987ABA6373CBF9A3114FD26D1827814859989122747720140B38CD`.

The optional store-matching path ran with WooCommerce active. The disposable test store contained no catalog products, so this confirms the empty-result runtime path; matching against an existing variable product and variation SKU still needs a populated staging catalog.

## Package and media

The install ZIP contains only the plugin folder. The WordPress.org assets are separate, ready to copy to the top-level `assets/` directory of the plugin SVN repository. The three WordPress screenshots were captured from the working test site; the WooCommerce gallery artwork uses those captured screens.

The WordPress.org icon and banner filenames and dimensions follow the [Plugin Handbook asset guide](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/). The WooCommerce product icon and gallery sizes follow the [WooCommerce Marketplace product assets guide](https://developer.woocommerce.com/docs/woo-marketplace/product-page-content-and-assets). Exact image sizes and SHA-256 values are in `media-assets-manifest.json`.

## Test environment notes and limits

- The WordPress and WooCommerce runtime checks used a local WordPress Playground site, not a production store.
- The Playground filesystem did not support the `LOCK_EX` flag used by Plugin Check's own report writer. Its local test copy used `FILE_APPEND` without that flag. A temporary empty `object-cache.php` test shim also allowed WordPress to fall back to its built-in cache after the Playground drop-in disappeared. Neither change is in this plugin or release ZIP.
- PHP 7.4 itself was not available for a runtime test. PHP lint ran on 8.5.8, and the live WordPress site ran the plugin on PHP 8.3.33.
- The WordPress.org SVN upload, WooCommerce Marketplace submission, and their human review processes were not performed. The listing text and image assets are prepared for those submission steps.

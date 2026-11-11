<?php
/** Deterministic black-box checks for the CSV preflight engine. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
class VARIATION_IMPORT_GUARD_Test_WPDB {
	public $term_taxonomy = 'wp_term_taxonomy';
	public $terms = 'wp_terms';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $term_relationships = 'wp_term_relationships';
	public $products = array();
	private $arguments = array();
	public function prepare( $query, ...$arguments ) { $this->arguments = $arguments; return $query; }
	public function get_var( $query ) { return 17; }
	public function get_results( $query, $format ) {
		$requested = array_slice( $this->arguments, 1 );
		$by_sku = false !== strpos( $query, 'pm.meta_value IN' );
		return array_values( array_filter( $this->products, static function ( $product ) use ( $requested, $by_sku ) {
			return $by_sku ? in_array( $product['sku'], $requested, true ) : in_array( $product['ID'], array_map( 'intval', $requested ), true );
		} ) );
	}
}
function __( $text, $domain = '' ) { return $text; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_delete_file( $path ) { return @unlink( $path ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR; }
function wc_get_product() { return true; }

require_once dirname( __DIR__ ) . '/includes/class-csv-preflight.php';

set_error_handler( static function ( $severity, $message, $file, $line ) {
	if ( error_reporting() & $severity ) {
		throw new RuntimeException( $message . ' at ' . $file . ':' . $line );
	}
	return false;
} );

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vig-qa-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0700, true );
$passed = 0;

function assert_true( $condition, $message ) {
	global $passed;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$passed;
}
function write_csv( $path, $rows, $delimiter = ',' ) {
	$handle = fopen( $path, 'wb' );
	foreach ( $rows as $row ) {
		fputcsv( $handle, $row, $delimiter, '"', '' );
	}
	fclose( $handle );
}
function run_job( $root, $source, $token, $options = array() ) {
	$fixed = $root . DIRECTORY_SEPARATOR . $token . '-fixed.csv';
	$report = $root . DIRECTORY_SEPARATOR . $token . '-report.csv';
	$options['token'] = $token;
	$result = VariationImportGuard\CSV_Preflight::run( $source, $fixed, $report, $options );
	return array( $result, $fixed, $report );
}
function read_csv( $path ) {
	$handle = fopen( $path, 'rb' );
	$rows = array();
	while ( false !== ( $row = fgetcsv( $handle, 1048576, ',', '"', '' ) ) ) {
		$rows[] = $row;
	}
	fclose( $handle );
	return $rows;
}
function read_codes( $path ) {
	$rows = read_csv( $path );
	return array_column( array_slice( $rows, 1 ), 2 );
}

try {
	// Spreadsheet formulas are neutralized in every downloaded field, including headers.
	$source = $root . DIRECTORY_SEPARATOR . 'formula-cells.csv';
	write_csv( $source, array(
		array( 'Type', 'SKU', 'Parent', 'Description', 'Formula minus', 'Formula at', 'Formula tab', 'Already text', '=CUSTOM_HEADER()' ),
		array( 'simple', '+1+1', '', '=HYPERLINK("https://example.test","click")', '-cmd|/C calc', '@SUM(A1:A2)', "\t=1+1", "'=1+1", "  =2+2" ),
	) );
	list( $summary, $fixed ) = run_job( $root, $source, str_repeat( 'm', 48 ) );
	$rows = read_csv( $fixed );
	assert_true( "'=CUSTOM_HEADER()" === $rows[0][8], 'Prefixes a formula-like source heading in the corrected download.' );
	assert_true( "'+1+1" === $rows[1][1] && "'=HYPERLINK(\"https://example.test\",\"click\")" === $rows[1][3], 'Prefixes equals and plus formula-like source cells.' );
	assert_true( "'-cmd|/C calc" === $rows[1][4] && "'@SUM(A1:A2)" === $rows[1][5], 'Prefixes minus and at-sign formula-like source cells.' );
	assert_true( "'\t=1+1" === $rows[1][6] && "'  =2+2" === $rows[1][8], 'Prefixes formula-like values after tabs and leading whitespace.' );
	assert_true( "'=1+1" === $rows[1][7], 'Does not double-prefix a value already escaped with an apostrophe.' );

	// Parent-first export keeps the source intact and retains unrelated cells.
	$source = $root . DIRECTORY_SEPARATOR . 'ordered.csv';
	write_csv( $source, array(
		array( 'Type', 'SKU', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)', 'Regular price', 'Stock', 'Description' ),
		array( 'variation', 'tee-red', 'TEE-1', 'Color', 'Red', '19.99', '8', 'red shirt' ),
		array( 'simple', 'mug-1', '', '', '', '6.50', '2', 'keep this cell' ),
		array( 'variable', 'TEE-1', '', 'Color', 'Red, Blue', '', '', 'parent data' ),
		array( 'variation', 'tee-blue', 'TEE-1', 'Color', 'Blue', '21.00', '4', 'blue shirt' ),
	) );
	$original_hash = hash_file( 'sha256', $source );
	list( $summary, $fixed, $report ) = run_job( $root, $source, str_repeat( 'a', 48 ) );
	assert_true( $original_hash === hash_file( 'sha256', $source ), 'Leaves the uploaded source file byte-for-byte unchanged.' );
	assert_true( is_array( $summary ) && 4 === $summary['rows'], 'Counts data rows excluding the header.' );
	assert_true( 1 === $summary['parents'] && 2 === $summary['variations'], 'Counts variable parents and variation rows.' );
	assert_true( 0 === $summary['errors'] && 1 === $summary['warnings'], 'Flags only the parent order warning.' );
	$rows = read_csv( $fixed );
	assert_true( 'TEE-1' === $rows[2][1] && 'variable' === $rows[2][0], 'Keeps the variable parent ahead of its child rows.' );
	assert_true( 'keep this cell' === $rows[1][7], 'Preserves unrelated product cells.' );
	assert_true( 'variation' === $rows[3][0] && 'variation' === $rows[4][0], 'Keeps all variation rows after parent rows.' );
	assert_true( 'parent_after_variation' === read_codes( $report )[0], 'Writes the row-level parent-order code.' );

	// Supplier aliases produce native WooCommerce columns and product type values.
	$source = $root . DIRECTORY_SEPARATOR . 'supplier.csv';
	write_csv( $source, array(
		array( 'product_type', 'item_code', 'parent_sku', 'color', 'price', 'qty' ),
		array( 'child', 'cap-blue', 'CAP-1', 'Blue', '14.00', '5' ),
		array( 'parent', 'CAP-1', '', 'Blue, Red', '', '' ),
	) );
	$inspection = VariationImportGuard\CSV_Preflight::inspect_headers( $source );
	assert_true( 'UTF-8' === $inspection['encoding'] && 'comma' === $inspection['delimiter'], 'Detects UTF-8 and comma delimiter.' );
	list( $summary, $fixed ) = run_job( $root, $source, str_repeat( 'b', 48 ) );
	$rows = read_csv( $fixed );
	assert_true( array( 'Type', 'SKU', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)', 'Regular price', 'Stock' ) === $rows[0], 'Maps aliases into WooCommerce import headings.' );
	assert_true( 'variable' === $rows[1][0] && 'variation' === $rows[2][0], 'Converts supported parent/child type aliases.' );
	assert_true( 'Color' === $rows[2][3] && 'Blue' === $rows[2][4], 'Adds the supplier attribute name and value columns.' );
	assert_true( 0 === $summary['errors'], 'Valid supplier aliases pass relationship checks.' );

	// Missing parent, duplicate SKU, out-of-range attributes, bad price and stock appear in the report.
	$source = $root . DIRECTORY_SEPARATOR . 'invalid.csv';
	write_csv( $source, array(
		array( 'Type', 'SKU', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)', 'Regular price', 'Stock' ),
		array( 'variable', 'P-1', '', 'Size', 'S, M', '', '' ),
		array( 'variation', 'DUP', 'P-1', 'Size', 'L', 'bad', '-2' ),
		array( 'variation', 'DUP', 'ABSENT', 'Color', 'Blue', '', '2.2' ),
	) );
	list( $summary, $fixed, $report ) = run_job( $root, $source, str_repeat( 'c', 48 ) );
	$codes = read_codes( $report );
	foreach ( array( 'duplicate_sku', 'attribute_value_not_in_parent', 'invalid_price', 'invalid_stock', 'missing_parent', 'missing_price' ) as $expected ) {
		assert_true( in_array( $expected, $codes, true ), 'Reports ' . $expected . '.' );
	}
	assert_true( 1 === $summary['orphan_rows'] && $summary['errors'] >= 7, 'Counts orphan and row errors: ' . wp_json_encode( $summary ) );

	// A blank parent is one missing-parent finding, not two duplicate findings.
	$source = $root . DIRECTORY_SEPARATOR . 'blank-parent.csv';
	write_csv( $source, array(
		array( 'Type', 'SKU', 'Parent', 'Regular price' ),
		array( 'variation', 'NO-PARENT', '', '9.00' ),
	) );
	list( $summary, $fixed, $report ) = run_job( $root, $source, str_repeat( 'f', 48 ) );
	$codes = read_codes( $report );
	assert_true( 1 === $summary['orphan_rows'] && 1 === count( array_filter( $codes, static function ( $code ) { return 'missing_parent' === $code; } ) ), 'Reports a blank parent once while counting its orphan row.' );

	// Duplicate attribute combinations cannot represent two distinct variations.
	$source = $root . DIRECTORY_SEPARATOR . 'duplicate-combination.csv';
	write_csv( $source, array(
		array( 'Type', 'SKU', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)', 'Regular price' ),
		array( 'variable', 'P-3', '', 'Color', 'Black, White', '' ),
		array( 'variation', 'V-3A', 'P-3', 'Color', 'Black', '10.00' ),
		array( 'variation', 'V-3B', 'P-3', 'Color', 'Black', '11.00' ),
	) );
	list( $summary, $fixed, $report ) = run_job( $root, $source, str_repeat( 'g', 48 ) );
	assert_true( in_array( 'duplicate_variation_combination', read_codes( $report ), true ), 'Finds duplicate parent and attribute combinations.' );

	// The row cap fails cleanly before creating a partial output.
	$source = $root . DIRECTORY_SEPARATOR . 'too-many-rows.csv';
	$handle = fopen( $source, 'wb' );
	fputcsv( $handle, array( 'Type', 'SKU', 'Parent' ), ',', '"', '' );
	for ( $index = 0; $index <= VariationImportGuard\CSV_Preflight::MAX_ROWS; ++$index ) {
		fputcsv( $handle, array( 'simple', 'item-' . $index, '' ), ',', '"', '' );
	}
	fclose( $handle );
	list( $summary, $fixed, $report ) = run_job( $root, $source, str_repeat( 'h', 48 ) );
	assert_true( $summary instanceof WP_Error && false === file_exists( $fixed ), 'Rejects more than 15,000 rows without leaving a partial corrected file.' );

	// Numeric product ID parent references are repaired to WooCommerce's id:NNN syntax.
	$source = $root . DIRECTORY_SEPARATOR . 'parent-id.csv';
	write_csv( $source, array(
		array( 'Type', 'ID', 'SKU', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)', 'Regular price' ),
		array( 'variation', '', 'shoe-40', '77', 'Size', '40', '80.00' ),
		array( 'variable', '77', '', '', 'Size', '40, 41', '' ),
	) );
	list( $summary, $fixed, $report ) = run_job( $root, $source, str_repeat( 'd', 48 ) );
	$rows = read_csv( $fixed );
	assert_true( 'id:77' === $rows[2][3], 'Repairs numeric product ID parent reference.' );
	assert_true( in_array( 'parent_id_prefix', read_codes( $report ), true ), 'Reports the stable ID-prefix repair.' );

	// An unprefixed numeric parent is a SKU when it matches a store product, even if another product has that ID.
	$GLOBALS['wpdb'] = new VARIATION_IMPORT_GUARD_Test_WPDB();
	$GLOBALS['wpdb']->products = array(
		array( 'ID' => 456, 'post_type' => 'product', 'sku' => '123', 'is_variable' => '1' ),
		array( 'ID' => 123, 'post_type' => 'product', 'sku' => 'OTHER', 'is_variable' => '0' ),
	);
	$source = $root . DIRECTORY_SEPARATOR . 'numeric-parent-sku.csv';
	write_csv( $source, array(
		array( 'Type', 'SKU', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)', 'Regular price' ),
		array( 'variation', 'V-123', '123', 'Size', 'M', '10.00' ),
	) );
	list( $summary, $fixed, $report ) = run_job( $root, $source, str_repeat( 'j', 48 ), array( 'catalog_check' => true ) );
	$codes = read_codes( $report );
	assert_true( 0 === $summary['errors'], 'Resolves a numeric parent SKU to its matching variable product.' );
	assert_true( ! in_array( 'parent_id_prefix', $codes, true ), 'Does not rewrite a numeric SKU as an unrelated product ID.' );

	// Reordered variation rows preserve quotes after a literal backslash.
	$source = $root . DIRECTORY_SEPARATOR . 'quoted-backslash.csv';
	write_csv( $source, array(
		array( 'Type', 'SKU', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)', 'Regular price', 'Description' ),
		array( 'variable', 'P-BS', '', 'Size', 'M', '', 'parent' ),
		array( 'variation', 'V-BS', 'P-BS', 'Size', 'M', '10.00', 'path\\"quote' ),
	) );
	list( $summary, $fixed ) = run_job( $root, $source, str_repeat( 'k', 48 ) );
	$rows = read_csv( $fixed );
	assert_true( 'path\\"quote' === $rows[2][6], 'Preserves a literal backslash and quote in a reordered variation row.' );

	// Delimited rows, BOM, and Windows-1252 characters are handled.
	$source = $root . DIRECTORY_SEPARATOR . 'encoded.csv';
	$csv = "\xEF\xBB\xBFType;SKU;Parent;Attribute 1 name;Attribute 1 value(s);Regular price;Name\r\nvariable;P-2;;Color;Caf\xE9, Red;;Caf\xE9 parent\r\nvariation;V-2;P-2;Color;Caf\xE9;2.00;Caf\xE9 item\r\n";
	file_put_contents( $source, $csv );
	$inspection = VariationImportGuard\CSV_Preflight::inspect_headers( $source );
	assert_true( 'Windows-1252' === $inspection['encoding'] && 'semicolon' === $inspection['delimiter'], 'Detects Windows-1252 and semicolon delimiter.' );
	list( $summary, $fixed ) = run_job( $root, $source, str_repeat( 'e', 48 ) );
	$rows = read_csv( $fixed );
	assert_true( false !== strpos( $rows[1][6], 'Caf' . "\xC3\xA9" ), 'Converts Windows-1252 text into valid UTF-8 output.' );
	assert_true( 0 === $summary['errors'], 'Accepts a valid converted relationship.' );

	// Invalid mapping and UTF-16 are returned as controlled errors.
	$source = $root . DIRECTORY_SEPARATOR . 'unmapped.csv';
	write_csv( $source, array( array( 'SKU', 'Parent' ), array( 'x', 'p' ) ) );
	$error = VariationImportGuard\CSV_Preflight::run( $source, $root . '\\x-fixed.csv', $root . '\\x-report.csv' );
	assert_true( $error instanceof WP_Error, 'Requires a product type mapping.' );
	$source = $root . DIRECTORY_SEPARATOR . 'utf16.csv';
	file_put_contents( $source, "\xFF\xFET\x00y\x00p\x00e\x00,\x00S\x00K\x00U\x00,\x00P\x00a\x00r\x00e\x00n\x00t\x00\r\x00\n\x00" );
	$error = VariationImportGuard\CSV_Preflight::inspect_headers( $source );
	assert_true( $error instanceof WP_Error, 'Rejects unsupported UTF-16 with an actionable message.' );

	fwrite( STDOUT, 'PASS: ' . $passed . " assertions\n" );
} finally {
	$items = glob( $root . DIRECTORY_SEPARATOR . '*' );
	foreach ( $items as $item ) {
		if ( is_file( $item ) ) {
			unlink( $item );
		}
	}
	rmdir( $root );
}

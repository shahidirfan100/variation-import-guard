<?php
/** CSV detection, validation, and repair. */
namespace VariationImportGuard;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Stream bounded CSV records and private spool files; WP_Filesystem only exposes whole-file string methods.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preflights a bounded CSV file, writes a WooCommerce-shaped CSV, and streams a row report.
 *
 * The source and outputs are files. CSV rows are never retained as a whole catalog in memory.
 */
final class CSV_Preflight {
	const MAX_BYTES = 8388608;
	const MAX_ROWS  = 15000;
	// A CSV row cannot be larger than the complete 8 MB upload limit.
	const MAX_RECORD_BYTES = 8388608;

	/** Detect the source encoding and suggest column mappings without processing records. */
	public static function inspect_headers( $source_path ) {
		if ( ! is_file( $source_path ) || filesize( $source_path ) > self::MAX_BYTES ) {
			return new \WP_Error( 'vig_file_size', __( 'Choose a CSV file no larger than 8 MB.', 'variation-import-guard' ) );
		}
		$encoding = self::detect_encoding( $source_path );
		if ( 'UTF-16' === $encoding ) {
			return new \WP_Error( 'vig_encoding', __( 'This file appears to use UTF-16 encoding. Save a UTF-8 or Windows CSV and upload it again.', 'variation-import-guard' ) );
		}
		$handle = fopen( $source_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file -- Read only the first record to detect the encoding and headers.
		if ( false === $handle ) {
			return new \WP_Error( 'vig_open', __( 'The uploaded file could not be opened.', 'variation-import-guard' ) );
		}
		$delimiter = self::detect_delimiter( $handle );
		$headers = self::read_csv_row( $handle, $delimiter );
		fclose( $handle );
		if ( false === $headers || empty( $headers ) ) {
			return new \WP_Error( 'vig_header', __( 'The file has no readable header row.', 'variation-import-guard' ) );
		}
		$headers = array_map( static function ( $value ) use ( $encoding ) {
			return self::convert_to_utf8( (string) $value, $encoding );
		}, $headers );
		$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
		return array( 'headers' => $headers, 'mapping' => self::map_columns( $headers, array() ), 'encoding' => $encoding, 'delimiter' => self::delimiter_name( $delimiter ) );
	}

	/**
	 * Validate and repair one CSV file.
	 *
	 * @param string $source_path Original uploaded CSV.
	 * @param string $fixed_path Corrected CSV destination.
	 * @param string $report_path Row-level CSV report destination.
	 * @param array  $options Mapping and opt-in settings.
	 * @return array|\WP_Error Summary or an error.
	 */
	public static function run( $source_path, $fixed_path, $report_path, $options = array() ) {
		if ( ! is_file( $source_path ) || filesize( $source_path ) > self::MAX_BYTES ) {
			return new \WP_Error( 'vig_file_size', __( 'Choose a CSV file no larger than 8 MB.', 'variation-import-guard' ) );
		}

		$encoding = self::detect_encoding( $source_path );
		if ( 'UTF-16' === $encoding ) {
			return new \WP_Error( 'vig_encoding', __( 'This file appears to use UTF-16 encoding. Save a UTF-8 or Windows CSV and upload it again.', 'variation-import-guard' ) );
		}
		$source = fopen( $source_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file -- fgetcsv streams the bounded upload without buffering the file.
		if ( false === $source ) {
			return new \WP_Error( 'vig_open', __( 'The uploaded file could not be opened.', 'variation-import-guard' ) );
		}

		$delimiter = self::detect_delimiter( $source );
		$header    = self::read_csv_row( $source, $delimiter );
		if ( false === $header || empty( $header ) ) {
			fclose( $source );
			return new \WP_Error( 'vig_header', __( 'The file has no readable header row.', 'variation-import-guard' ) );
		}
		$header = array_map( static function ( $value ) use ( $encoding ) {
			return self::convert_to_utf8( (string) $value, $encoding );
		}, $header );
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$mapping   = self::map_columns( $header, isset( $options['mapping'] ) ? $options['mapping'] : array() );
		if ( false === $mapping['type'] || false === $mapping['sku'] || false === $mapping['parent'] ) {
			fclose( $source );
			return new \WP_Error( 'vig_mapping', __( 'Map the product type, SKU, and parent columns before running the check.', 'variation-import-guard' ) );
		}
		$mapped_indexes = array_filter( array( $mapping['type'], $mapping['sku'], $mapping['parent'], $mapping['id'], $mapping['price'], $mapping['stock'] ), static function ( $index ) {
			return false !== $index;
		} );
		if ( count( $mapped_indexes ) !== count( array_unique( $mapped_indexes ) ) ) {
			fclose( $source );
			return new \WP_Error( 'vig_mapping_duplicate', __( 'Each mapped product field must use a different CSV column.', 'variation-import-guard' ) );
		}

		$normalize  = ! empty( $options['normalize'] );
		$catalog_on = ! empty( $options['catalog_check'] ) && function_exists( 'wc_get_product' );
		$scan_rows  = 0;
		$max_columns = count( $header );
		while ( false !== ( $scan_row = self::read_csv_row( $source, $delimiter ) ) ) {
			++$scan_rows;
			$max_columns = max( $max_columns, count( $scan_row ) );
			if ( $scan_rows > self::MAX_ROWS ) {
				fclose( $source );
				return new \WP_Error( 'vig_row_limit', __( 'This file has more than 15,000 data rows. Split it into smaller CSV files and run the check again.', 'variation-import-guard' ) );
			}
		}
		rewind( $source );
		self::read_csv_row( $source, $delimiter );
		$token      = isset( $options['token'] ) ? $options['token'] : self::random_token();
		$base       = dirname( $fixed_path );
		$variation_path = trailingslashit( $base ) . $token . '-variations.tmp';
		$metadata_path  = trailingslashit( $base ) . $token . '-metadata.tmp';
		$output     = fopen( $fixed_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file -- Private, randomized output path; streamed via an authorized download route.
		$variations = fopen( $variation_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file -- Private row spool used to place parent rows first without retaining rows in memory.
		$metadata   = fopen( $metadata_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file -- Compact relationship metadata spool.
		$report     = fopen( $report_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file -- Private report output.
		if ( false === $output || false === $variations || false === $metadata || false === $report ) {
			fclose( $source );
			self::close_many( array( $output, $variations, $metadata, $report ) );
			self::delete_many( array( $fixed_path, $report_path, $variation_path, $metadata_path ) );
			return new \WP_Error( 'vig_write', __( 'A private working file could not be created. Check the server temporary directory permissions.', 'variation-import-guard' ) );
		}

		$write_failed = false;
		$write_csv = static function ( $handle, $fields ) use ( &$write_failed ) {
			if ( false === self::write_csv_row( $handle, $fields ) ) {
				$write_failed = true;
			}
		};
		$summary = array(
			'rows' => 0,
			'parents' => 0,
			'variations' => 0,
			'orphan_rows' => 0,
			'duplicate_skus' => 0,
			'repairable_rows' => 0,
			'errors' => 0,
			'warnings' => 0,
			'notices' => 0,
			'sample' => array(),
		);
		$write_csv( $report, array( __( 'Row', 'variation-import-guard' ), __( 'Severity', 'variation-import-guard' ), __( 'Check', 'variation-import-guard' ), __( 'Finding', 'variation-import-guard' ) ) );
		$issue_writer = static function ( $row, $severity, $code, $message ) use ( $report, &$summary, $write_csv ) {
			$write_csv( $report, array( (int) $row, $severity, $code, self::safe_report_text( $message ) ) );
			++$summary[ 'error' === $severity ? 'errors' : ( 'warning' === $severity ? 'warnings' : 'notices' ) ];
			if ( count( $summary['sample'] ) < 20 ) {
				$summary['sample'][] = array( 'row' => (int) $row, 'severity' => $severity, 'code' => $code, 'message' => $message );
			}
		};

		$output_layout = self::output_layout( $header, $mapping, $max_columns );
		$write_csv( $output, $output_layout['headers'] );
		$header_positions = array();
		foreach ( $header as $index => $label ) {
			$key = self::header_key( $label );
			if ( isset( $header_positions[ $key ] ) ) {
				// translators: %1$s: column heading; %2$d and %3$d: one-based CSV column positions.
				$issue_writer( 1, 'error', 'duplicate_header', sprintf( __( 'Column heading "%1$s" appears more than once (columns %2$d and %3$d).', 'variation-import-guard' ), $label, $header_positions[ $key ] + 1, $index + 1 ) );
			} else {
				$header_positions[ $key ] = $index;
			}
		}
		$parents       = array();
		$sku_first     = array();
		$all_skus      = array();
		$parent_refs   = array();
		$parent_id_repairs = array();
		$row_number    = 1;
		$attribute_sets = $mapping['attributes'];

		while ( false !== ( $row = self::read_csv_row( $source, $delimiter ) ) ) {
			++$row_number;
			++$summary['rows'];
			if ( $summary['rows'] > self::MAX_ROWS ) {
				fclose( $source );
				self::close_many( array( $output, $variations, $metadata, $report ) );
				self::delete_many( array( $fixed_path, $report_path, $variation_path, $metadata_path ) );
				return new \WP_Error( 'vig_row_limit', __( 'This file has more than 15,000 data rows. Split it into smaller CSV files and run the check again.', 'variation-import-guard' ) );
			}
			if ( count( $row ) > count( $header ) ) {
				$issue_writer( $row_number, 'warning', 'extra_columns', __( 'This row has more cells than the header. Extra cells were kept in the corrected file.', 'variation-import-guard' ) );
			}
			$row = array_map( static function ( $value ) use ( $encoding ) {
				return self::convert_to_utf8( (string) $value, $encoding );
			}, $row );
			$row = array_pad( $row, count( $header ), '' );
			$type = self::cell( $row, $mapping['type'], $normalize );
			$type = self::normalize_type( $type );
			$sku  = self::cell( $row, $mapping['sku'], $normalize );
			$parent = self::cell( $row, $mapping['parent'], $normalize );
			$id = self::cell( $row, $mapping['id'], $normalize );
			$is_variation = ( 'variation' === $type || '' !== $parent );
			$attributes = self::row_attributes( $row, $attribute_sets, $normalize );
			$price = self::cell( $row, $mapping['price'], $normalize );
			$stock = self::cell( $row, $mapping['stock'], $normalize );
			if ( '' === trim( self::cell( $row, $mapping['type'], false ) ) ) {
				$issue_writer( $row_number, 'error', 'missing_type', __( 'Every product row needs a mapped product type.', 'variation-import-guard' ) );
			} elseif ( ! in_array( $type, array( 'simple', 'variable', 'variation', 'grouped', 'external', 'virtual', 'downloadable' ), true ) ) {
				$issue_writer( $row_number, 'error', 'invalid_type', __( 'This product type is not recognized. Use a WooCommerce product type or supported supplier alias.', 'variation-import-guard' ) );
			}
			if ( '' !== trim( $parent ) && 'variation' !== $type ) {
				$issue_writer( $row_number, 'error', 'type_parent_mismatch', __( 'A row with a parent identifier must use the variation product type.', 'variation-import-guard' ) );
			}
			if ( '' !== trim( $sku ) ) {
				$key = self::identifier_key( $sku, $normalize );
				$all_skus[ $key ] = $sku;
				if ( isset( $sku_first[ $key ] ) ) {
					++$summary['duplicate_skus'];
					// translators: %1$s: duplicate product SKU; %2$d: CSV row number of the duplicate.
					$issue_writer( $sku_first[ $key ], 'error', 'duplicate_sku', sprintf( __( 'SKU "%1$s" also appears on row %2$d.', 'variation-import-guard' ), $sku, $row_number ) );
					// translators: %1$s: product SKU; %2$d: CSV row number where it first appeared.
					$issue_writer( $row_number, 'error', 'duplicate_sku', sprintf( __( 'SKU "%1$s" already appeared on row %2$d.', 'variation-import-guard' ), $sku, $sku_first[ $key ] ) );
				} else {
					$sku_first[ $key ] = $row_number;
				}
			}
			if ( $is_variation ) {
				++$summary['variations'];
				$parent_refs[ self::identifier_key( $parent, $normalize ) ] = $parent;
				if ( '' === trim( $sku ) ) {
					$issue_writer( $row_number, 'error', 'missing_variation_sku', __( 'Variation rows need a unique SKU.', 'variation-import-guard' ) );
				}
				if ( '' === $price ) {
					$issue_writer( $row_number, 'error', 'missing_price', __( 'Variation rows need a regular price.', 'variation-import-guard' ) );
				} elseif ( ! is_numeric( $price ) || (float) $price < 0 ) {
					$issue_writer( $row_number, 'error', 'invalid_price', __( 'The variation price must be a number zero or greater.', 'variation-import-guard' ) );
				}
				if ( '' !== $stock && ( ! preg_match( '/^\d+$/', $stock ) ) ) {
					$issue_writer( $row_number, 'error', 'invalid_stock', __( 'Stock quantity must be a whole number zero or greater.', 'variation-import-guard' ) );
				}
				if ( '' === trim( $parent ) ) {
					$issue_writer( $row_number, 'error', 'missing_parent', __( 'Variation row has no parent identifier.', 'variation-import-guard' ) );
				}
				$meta = array( 'row' => $row_number, 'sku' => $sku, 'parent' => $parent, 'type' => $type, 'attributes' => $attributes, 'parent_key' => self::identifier_key( $parent, $normalize ) );
				$metadata_line = wp_json_encode( $meta, JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
				if ( strlen( $metadata_line ) !== fwrite( $metadata, $metadata_line ) ) {
					$write_failed = true;
				}
				$row = self::make_output_row( $row, $output_layout, $normalize );
				$write_csv( $variations, $row );
			} else {
				if ( 'variable' === $type ) {
					++$summary['parents'];
					$parent_data = array( 'row' => $row_number, 'type' => $type, 'attributes' => $attributes, 'sku' => $sku );
					if ( '' !== trim( $sku ) ) {
						$key = self::identifier_key( $sku, $normalize );
					if ( ! isset( $parents[ $key ] ) ) {
						$parents[ $key ] = $parent_data;
					}
					}
					if ( '' !== trim( $id ) && preg_match( '/^\d+$/', trim( $id ) ) ) {
						$parents[ 'id:' . trim( $id ) ] = $parent_data;
					}
				} elseif ( 'variation' === $type ) {
					// Type is explicitly variation even if the parent column is empty.
				}
				$row = self::make_output_row( $row, $output_layout, $normalize );
				$write_csv( $output, $row );
			}
		}
		fclose( $source );
		if ( ! self::close_many( array( $variations, $metadata ) ) ) {
			$write_failed = true;
		}
		if ( $write_failed ) {
			self::close_many( array( $output, $report ) );
			self::delete_many( array( $fixed_path, $report_path, $variation_path, $metadata_path ) );
			return new \WP_Error( 'vig_write', __( 'A private output file could not be completed. Check the temporary folder space and permissions, then try again.', 'variation-import-guard' ) );
		}

		$catalog = array();
		if ( $catalog_on ) {
			$catalog = self::catalog_lookup( array_merge( array_values( $all_skus ), array_values( $parent_refs ) ), array_values( $parent_refs ), $normalize );
			foreach ( $all_skus as $key => $sku_value ) {
				if ( isset( $catalog[ self::identifier_key( $sku_value, $normalize ) ] ) ) {
					// translators: %s: product SKU that already exists in the store.
					$issue_writer( $sku_first[ $key ], 'warning', 'catalog_sku_exists', sprintf( __( 'SKU "%s" already exists in this store. Confirm the importer update setting before continuing.', 'variation-import-guard' ), $sku_value ) );
				}
			}
		}

		$metadata = fopen( $metadata_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file -- Relationship metadata is read one record at a time.
		$variation_file = fopen( $variation_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file -- Variations are appended after parent rows.
		if ( false === $metadata || false === $variation_file ) {
			self::close_many( array( $output, $metadata, $variation_file, $report ) );
			self::delete_many( array( $fixed_path, $report_path, $variation_path, $metadata_path ) );
			return new \WP_Error( 'vig_spool', __( 'The temporary CSV could not be read back.', 'variation-import-guard' ) );
		}

		$seen_combinations = array();
		while ( false !== ( $line = fgets( $metadata, self::MAX_RECORD_BYTES ) ) ) {
			$data = json_decode( $line, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$parent_key = $data['parent_key'];
			$parent_data = isset( $parents[ $parent_key ] ) ? $parents[ $parent_key ] : false;
			if ( ! $parent_data && $catalog_on && isset( $catalog[ $parent_key ] ) ) {
				$parent_data = $catalog[ $parent_key ];
			}
			if ( ! $parent_data && preg_match( '/^\d+$/', $data['parent'] ) && isset( $parents[ 'id:' . $data['parent'] ] ) ) {
				$parent_data = $parents[ 'id:' . $data['parent'] ];
				$parent_id_repairs[ $data['row'] ] = 'id:' . $data['parent'];
				// translators: %s: numeric product ID used as the parent reference.
				$issue_writer( $data['row'], 'notice', 'parent_id_prefix', sprintf( __( 'The parent ID was rewritten as "id:%s", the format WooCommerce expects.', 'variation-import-guard' ), $data['parent'] ) );
				++$summary['repairable_rows'];
			}
			if ( ! $parent_data && $catalog_on && preg_match( '/^\d+$/', $data['parent'] ) && isset( $catalog[ 'id:' . $data['parent'] ] ) ) {
				$parent_data = $catalog[ 'id:' . $data['parent'] ];
				$parent_id_repairs[ $data['row'] ] = 'id:' . $data['parent'];
				// translators: %s: numeric product ID used as the parent reference.
				$issue_writer( $data['row'], 'notice', 'parent_id_prefix', sprintf( __( 'The parent ID was rewritten as "id:%s", the format WooCommerce expects.', 'variation-import-guard' ), $data['parent'] ) );
				++$summary['repairable_rows'];
			}
			if ( ! $parent_data ) {
				++$summary['orphan_rows'];
				if ( '' !== trim( $data['parent'] ) ) {
					// translators: %s: missing parent SKU or product ID.
					$issue_writer( $data['row'], 'error', 'missing_parent', sprintf( __( 'Parent "%s" was not found in this CSV or the checked store catalog.', 'variation-import-guard' ), $data['parent'] ) );
				}
			} else {
				if ( ! empty( $parent_data['row'] ) && $parent_data['row'] > $data['row'] ) {
					// translators: %d: CSV row number containing the variable parent.
					$issue_writer( $data['row'], 'warning', 'parent_after_variation', sprintf( __( 'The parent row is row %d, after this variation. The corrected file moves parent rows first.', 'variation-import-guard' ), $parent_data['row'] ) );
					++$summary['repairable_rows'];
				}
				if ( 'variable' !== $parent_data['type'] ) {
					$issue_writer( $data['row'], 'error', 'parent_not_variable', __( 'The referenced parent is not marked as a variable product.', 'variation-import-guard' ) );
				}
				if ( ! empty( $parent_data['row'] ) ) {
					self::compare_attributes( $data, $parent_data, $normalize, $issue_writer );
				} elseif ( $catalog_on ) {
					$issue_writer( $data['row'], 'notice', 'catalog_attributes_not_checked', __( 'The parent SKU exists in this store. Attribute values were not compared with the store product.', 'variation-import-guard' ) );
				}
			}
			$combination_values = array();
			foreach ( $data['attributes'] as $attribute_key => $attribute_value ) {
				$combination_values[] = $attribute_key . '=' . self::identifier_key( $attribute_value['value'], $normalize );
			}
			sort( $combination_values, SORT_STRING );
			$combination_key = $data['parent_key'] . '|' . implode( '|', $combination_values );
			if ( isset( $seen_combinations[ $combination_key ] ) ) {
				// translators: %d: CSV row number with the same parent and attribute combination.
				$issue_writer( $data['row'], 'error', 'duplicate_variation_combination', sprintf( __( 'This attribute combination already appears on row %d.', 'variation-import-guard' ), $seen_combinations[ $combination_key ] ) );
			} else {
				$seen_combinations[ $combination_key ] = $data['row'];
			}
		}
		rewind( $metadata );
		while ( false !== ( $line = fgets( $metadata, self::MAX_RECORD_BYTES ) ) ) {
			$data = json_decode( $line, true );
			$row = self::read_csv_row( $variation_file, ',' );
			if ( false === $row ) {
				break;
			}
			if ( is_array( $data ) && isset( $parent_id_repairs[ $data['row'] ], $output_layout['source_to_output'][ $mapping['parent'] ] ) ) {
				$row[ $output_layout['source_to_output'][ $mapping['parent'] ] ] = $parent_id_repairs[ $data['row'] ];
			}
			$write_csv( $output, $row );
		}
		if ( ! self::close_many( array( $output, $metadata, $variation_file, $report ) ) ) {
			$write_failed = true;
		}
		self::delete_many( array( $variation_path, $metadata_path ) );
		if ( $write_failed ) {
			self::delete_many( array( $fixed_path, $report_path ) );
			return new \WP_Error( 'vig_write', __( 'A private output file could not be completed. Check the temporary folder space and permissions, then try again.', 'variation-import-guard' ) );
		}

		if ( 0 === $summary['errors'] && 0 === $summary['warnings'] ) {
			$summary['status'] = 'ready';
		} elseif ( 0 < $summary['errors'] ) {
			$summary['status'] = 'review';
		} else {
			$summary['status'] = 'warnings';
		}
		$summary['catalog_checked'] = $catalog_on;
		$summary['normalized'] = $normalize;
		$summary['source_delimiter'] = self::delimiter_name( $delimiter );
		return $summary;
	}

	/** Detect CSV delimiter from a short sample. */
	private static function detect_delimiter( $handle ) {
		$line = fgets( $handle, 65536 );
		rewind( $handle );
		$candidates = array( ',', ';', "\t", '|' );
		$best = ',';
		$max = 1;
		foreach ( $candidates as $candidate ) {
			$count = count( str_getcsv( (string) $line, $candidate, '"', '' ) );
			if ( $count > $max ) {
				$best = $candidate;
				$max  = $count;
			}
		}
		return $best;
	}

	/** Detect UTF-8, UTF-8 with BOM, Windows-1252/Latin-1, or unsupported UTF-16. */
	private static function detect_encoding( $path ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file -- The sample is capped at 64 KB.
		if ( false === $handle ) {
			return 'UTF-8';
		}
		$sample = fread( $handle, 65536 );
		fclose( $handle );
		if ( false !== strpos( $sample, "\0" ) ) {
			return 'UTF-16';
		}
		return 1 === preg_match( '//u', $sample ) ? 'UTF-8' : 'Windows-1252';
	}

	/** Convert one Windows-1252 field to UTF-8 without requiring mbstring or iconv. */
	private static function convert_to_utf8( $value, $encoding ) {
		if ( 'UTF-8' === $encoding ) {
			return $value;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $value, 'UTF-8', $encoding );
		}
		$special = array(
			0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021,
			0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D,
			0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013,
			0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
		);
		$output = '';
		for ( $index = 0, $length = strlen( $value ); $index < $length; ++$index ) {
			$byte = ord( $value[ $index ] );
			if ( $byte < 0x80 ) {
				$output .= $value[ $index ];
				continue;
			}
			$codepoint = isset( $special[ $byte ] ) ? $special[ $byte ] : $byte;
			if ( $codepoint < 0x800 ) {
				$output .= chr( 0xC0 | ( $codepoint >> 6 ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
			} else {
				$output .= chr( 0xE0 | ( $codepoint >> 12 ) ) . chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
			}
		}
		return $output;
	}

	/** Write an RFC 4180 CSV row with PHP 8.4's implicit escape disabled. */
	private static function write_csv_row( $handle, $fields ) {
		return fputcsv( $handle, $fields, ',', '"', '' );
	}

	/** Read one RFC-style CSV record with a bounded record buffer. */
	private static function read_csv_row( $handle, $delimiter ) {
		return fgetcsv( $handle, self::MAX_RECORD_BYTES, $delimiter, '"', '' );
	}

	/** Map canonical WooCommerce fields and common supplier aliases. */
	private static function map_columns( $headers, $overrides ) {
		$aliases = array(
			'type' => array( 'type', 'producttype', 'productkind', 'kind' ),
			'sku' => array( 'sku', 'variantsku', 'productsku', 'itemsku', 'itemcode', 'productcode', 'code' ),
			'parent' => array( 'parent', 'parentsku', 'parentid', 'parentcode', 'parentproduct', 'parentproductsku', 'parentskuid' ),
			'id' => array( 'id', 'productid' ),
			'price' => array( 'regularprice', 'price', 'variantprice', 'unitprice', 'wholesaleprice' ),
			'stock' => array( 'stock', 'stockquantity', 'quantity', 'qty', 'inventory', 'onhand' ),
		);
		$map = array( 'type' => false, 'sku' => false, 'parent' => false, 'id' => false, 'price' => false, 'stock' => false, 'attributes' => array() );
		$normalized = array();
		foreach ( $headers as $index => $header ) {
			$normalized[ self::header_key( $header ) ] = $index;
		}
		foreach ( $aliases as $role => $names ) {
			if ( isset( $overrides[ $role ] ) && is_numeric( $overrides[ $role ] ) && isset( $headers[ (int) $overrides[ $role ] ] ) ) {
				$map[ $role ] = (int) $overrides[ $role ];
				continue;
			}
			foreach ( $names as $name ) {
				if ( isset( $normalized[ $name ] ) ) {
					$map[ $role ] = $normalized[ $name ];
					break;
				}
			}
		}
		$attribute_groups = array();
		$generic = array( 'color', 'colour', 'size', 'material', 'fit', 'style', 'pattern', 'finish', 'capacity', 'flavor', 'scent' );
		foreach ( $headers as $index => $header ) {
			$key = self::header_key( $header );
			if ( preg_match( '/^attribute(\d+)(name|values?|visible|global)$/', $key, $match ) ) {
				$number = (int) $match[1];
				$part = rtrim( $match[2], 's' );
				if ( ! isset( $attribute_groups[ $number ] ) ) {
					// translators: %d: one-based WooCommerce attribute number.
					$attribute_groups[ $number ] = array( 'name_index' => false, 'value_index' => false, 'label' => sprintf( __( 'Attribute %d', 'variation-import-guard' ), $number ) );
				}
				if ( 'name' === $part ) {
					$attribute_groups[ $number ]['name_index'] = $index;
				} elseif ( 'value' === $part ) {
					$attribute_groups[ $number ]['value_index'] = $index;
				}
			} elseif ( in_array( $key, $generic, true ) ) {
				$attribute_groups[ 100 + $index ] = array( 'name_index' => false, 'value_index' => $index, 'label' => ucwords( (string) $header ), 'generic' => true );
			}
		}
		ksort( $attribute_groups );
		foreach ( $attribute_groups as $group ) {
			if ( false !== $group['value_index'] ) {
				$map['attributes'][] = $group;
			}
		}
		return $map;
	}

	/** Convert source headings into WooCommerce field names and add names for generic attributes. */
	private static function output_layout( $headers, $mapping, $max_columns ) {
		$roles = array( 'type' => 'Type', 'sku' => 'SKU', 'parent' => 'Parent', 'id' => 'ID', 'price' => 'Regular price', 'stock' => 'Stock' );
		$rename = array();
		foreach ( $roles as $role => $label ) {
			if ( false !== $mapping[ $role ] ) {
				$rename[ $mapping[ $role ] ] = $label;
			}
		}
		$generic = array();
		foreach ( $mapping['attributes'] as $attribute_number => $attribute ) {
			if ( ! empty( $attribute['generic'] ) ) {
				$generic[ $attribute['value_index'] ] = array( 'number' => $attribute_number + 1, 'label' => $attribute['label'] );
			} else {
				if ( false !== $attribute['name_index'] ) {
					$rename[ $attribute['name_index'] ] = sprintf( 'Attribute %d name', $attribute_number + 1 );
				}
				$rename[ $attribute['value_index'] ] = sprintf( 'Attribute %d value(s)', $attribute_number + 1 );
			}
		}
		$headers_out = array();
		$operations  = array();
		foreach ( $headers as $index => $header ) {
			if ( isset( $generic[ $index ] ) ) {
				$headers_out[] = sprintf( 'Attribute %d name', $generic[ $index ]['number'] );
				$operations[] = array( 'kind' => 'constant', 'value' => $generic[ $index ]['label'] );
				$headers_out[] = sprintf( 'Attribute %d value(s)', $generic[ $index ]['number'] );
				$operations[] = array( 'kind' => 'source', 'index' => $index, 'trim' => true );
			} else {
				$headers_out[] = isset( $rename[ $index ] ) ? $rename[ $index ] : $header;
				$operations[] = array( 'kind' => 'source', 'index' => $index, 'trim' => isset( $rename[ $index ] ) );
			}
		}
		for ( $index = count( $headers ); $index < $max_columns; ++$index ) {
			// translators: %d: one-based position of an extra CSV column.
			$headers_out[] = sprintf( __( 'Extra column %d', 'variation-import-guard' ), $index - count( $headers ) + 1 );
		}
		$source_to_output = array();
		foreach ( $operations as $output_index => $operation ) {
			if ( 'source' === $operation['kind'] ) {
				$source_to_output[ $operation['index'] ] = $output_index;
			}
		}
		return array( 'headers' => $headers_out, 'operations' => $operations, 'source_header_count' => count( $headers ), 'max_source_columns' => $max_columns, 'source_to_output' => $source_to_output, 'type_source_index' => $mapping['type'] );
	}

	/** Make one output row, applying only explicitly requested whitespace normalization. */
	private static function make_output_row( $row, $layout, $normalize ) {
		$result = array();
		foreach ( $layout['operations'] as $operation ) {
			if ( 'constant' === $operation['kind'] ) {
				$result[] = $operation['value'];
			} else {
				$value = isset( $row[ $operation['index'] ] ) ? (string) $row[ $operation['index'] ] : '';
				if ( $operation['index'] === $layout['type_source_index'] ) {
					$value = self::normalize_type( $value );
				} elseif ( $normalize && ! empty( $operation['trim'] ) ) {
					$value = trim( $value );
				}
				$result[] = $value;
			}
		}
		for ( $index = $layout['source_header_count']; $index < $layout['max_source_columns']; ++$index ) {
			$result[] = isset( $row[ $index ] ) ? (string) $row[ $index ] : '';
		}
		return $result;
	}

	/** Extract attribute names and values for one row. */
	private static function row_attributes( $row, $groups, $normalize ) {
		$attributes = array();
		foreach ( $groups as $group ) {
			$name = false !== $group['name_index'] ? ( isset( $row[ $group['name_index'] ] ) ? $row[ $group['name_index'] ] : '' ) : $group['label'];
			$value = isset( $row[ $group['value_index'] ] ) ? $row[ $group['value_index'] ] : '';
			$name = self::cell_value( $name, $normalize );
			$value = self::cell_value( $value, $normalize );
			if ( '' !== $name ) {
				$attributes[ self::attribute_key( $name, $normalize ) ] = array( 'name' => $name, 'value' => $value );
			}
		}
		return $attributes;
	}

	/** Compare the variation's attributes with its parent row. */
	private static function compare_attributes( $variation, $parent, $normalize, $issue_writer ) {
		$parent_attributes = isset( $parent['attributes'] ) ? $parent['attributes'] : array();
		$child_attributes  = isset( $variation['attributes'] ) ? $variation['attributes'] : array();
		if ( empty( $parent_attributes ) && empty( $child_attributes ) ) {
			$issue_writer( $variation['row'], 'error', 'missing_attributes', __( 'The parent and variation rows have no variation attributes.', 'variation-import-guard' ) );
			return;
		}
		foreach ( $parent_attributes as $key => $parent_attribute ) {
			if ( ! isset( $child_attributes[ $key ] ) ) {
				// translators: %s: attribute name listed on the parent product row.
				$issue_writer( $variation['row'], 'error', 'attribute_name_mismatch', sprintf( __( 'Variation is missing parent attribute "%s".', 'variation-import-guard' ), $parent_attribute['name'] ) );
				continue;
			}
			$values = array_map( static function ( $value ) use ( $normalize ) {
				return self::identifier_key( $value, $normalize );
			}, array_map( 'trim', explode( ',', $parent_attribute['value'] ) ) );
			$child_value = $child_attributes[ $key ]['value'];
			if ( '' === $child_value ) {
				// translators: %s: attribute name expected on the variation row.
				$issue_writer( $variation['row'], 'error', 'attribute_value_missing', sprintf( __( 'Variation has no value for "%s".', 'variation-import-guard' ), $child_attributes[ $key ]['name'] ) );
			} elseif ( ! in_array( self::identifier_key( $child_value, $normalize ), $values, true ) ) {
				// translators: %1$s: variation attribute value; %2$s: attribute name.
				$issue_writer( $variation['row'], 'error', 'attribute_value_not_in_parent', sprintf( __( 'Value "%1$s" for "%2$s" does not appear in the parent row.', 'variation-import-guard' ), $child_value, $child_attributes[ $key ]['name'] ) );
			}
		}
		foreach ( $child_attributes as $key => $child_attribute ) {
			if ( ! isset( $parent_attributes[ $key ] ) ) {
				// translators: %s: attribute name that is absent from the parent row.
				$issue_writer( $variation['row'], 'error', 'attribute_name_mismatch', sprintf( __( 'Attribute "%s" is not listed on the parent row.', 'variation-import-guard' ), $child_attribute['name'] ) );
			}
		}
	}

	/** Fetch matching products using parameterized, read-only WooCommerce product queries. */
	private static function catalog_lookup( $skus, $parents, $normalize ) {
		global $wpdb;
		$result = array();
		$ids = array();
		foreach ( $parents as $parent ) {
			if ( preg_match( '/^(?:id:)?(\d+)$/i', trim( $parent ), $match ) ) {
				$ids[] = (int) $match[1];
			}
		}
		$variable_term = $wpdb->get_var( $wpdb->prepare( "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND t.slug = %s", 'product_type', 'variable' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One read-only lookup is required for the optional live catalog check.
		foreach ( array_chunk( array_unique( array_filter( $skus, 'strlen' ) ), 300 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$sql = "SELECT p.ID, p.post_type, pm.meta_value AS sku, CASE WHEN variable_rel.object_id IS NULL THEN 0 ELSE 1 END AS is_variable FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID LEFT JOIN {$wpdb->term_relationships} variable_rel ON variable_rel.object_id = p.ID AND variable_rel.term_taxonomy_id = %d WHERE p.post_type IN ('product', 'product_variation') AND pm.meta_key = '_sku' AND pm.meta_value IN ($placeholders)";
			$args = array_merge( array( (int) $variable_term ), $chunk );
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Chunked read-only lookup; dynamic placeholders and values are prepared together.
			foreach ( $rows as $row ) {
				if ( '1' === (string) $row['is_variable'] ) {
					$product_type = 'variable';
				} elseif ( 'product' === $row['post_type'] ) {
					$product_type = 'simple';
				} else {
					$product_type = 'variation';
				}
				$item = array( 'id' => (int) $row['ID'], 'type' => $product_type, 'row' => 0, 'attributes' => array() );
				$result[ self::identifier_key( $row['sku'], $normalize ) ] = $item;
				$result[ 'id:' . (int) $row['ID'] ] = $item;
			}
		}
		foreach ( array_chunk( array_unique( array_filter( $ids ) ), 300 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$sql = "SELECT p.ID, p.post_type, CASE WHEN variable_rel.object_id IS NULL THEN 0 ELSE 1 END AS is_variable FROM {$wpdb->posts} p LEFT JOIN {$wpdb->term_relationships} variable_rel ON variable_rel.object_id = p.ID AND variable_rel.term_taxonomy_id = %d WHERE p.ID IN ($placeholders) AND p.post_type IN ('product', 'product_variation')";
			$args = array_merge( array( (int) $variable_term ), $chunk );
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Chunked read-only lookup; dynamic placeholders and values are prepared together.
			foreach ( $rows as $row ) {
				if ( '1' === (string) $row['is_variable'] ) {
					$product_type = 'variable';
				} elseif ( 'product' === $row['post_type'] ) {
					$product_type = 'simple';
				} else {
					$product_type = 'variation';
				}
				$result[ 'id:' . (int) $row['ID'] ] = array( 'id' => (int) $row['ID'], 'type' => $product_type, 'row' => 0, 'attributes' => array() );
			}
		}
		return $result;
	}

	/** Return the first-value map for core product fields. */
	private static function cell( $row, $index, $normalize ) {
		return false === $index || ! isset( $row[ $index ] ) ? '' : self::cell_value( $row[ $index ], $normalize );
	}

	/** Normalize text for comparison and, optionally, output. */
	private static function cell_value( $value, $normalize ) {
		$value = (string) $value;
		return $normalize ? trim( $value ) : $value;
	}

	/** Normalize a CSV header for alias matching. */
	private static function header_key( $header ) {
		return preg_replace( '/[^a-z0-9]+/', '', strtolower( trim( (string) $header ) ) );
	}

	/** Normalize product type aliases. */
	private static function normalize_type( $type ) {
		$type = strtolower( trim( (string) $type ) );
		if ( in_array( $type, array( 'child', 'variant', 'productvariation' ), true ) ) {
			return 'variation';
		}
		if ( in_array( $type, array( 'parent', 'variableproduct', 'variableitem' ), true ) ) {
			return 'variable';
		}
		return $type;
	}

	/** Return a deterministic exact or opted-in normalized identifier key. */
	private static function identifier_key( $value, $normalize ) {
		$value = (string) $value;
		if ( $normalize ) {
			$value = strtolower( trim( $value ) );
		}
		return $value;
	}

	/** Compare attribute names exactly unless whitespace normalization was opted in. */
	private static function attribute_key( $value, $normalize ) {
		$value = (string) $value;
		return $normalize ? strtolower( trim( $value ) ) : $value;
	}

	/** Make CSV report strings safe to open in a spreadsheet. */
	private static function safe_report_text( $value ) {
		$value = (string) $value;
		return preg_match( '/^[\s]*[=+@\-]/', $value ) ? "'" . $value : $value;
	}

	/** Generate a private file token when the caller did not supply one. */
	private static function random_token() {
		return bin2hex( random_bytes( 24 ) );
	}

	/** Close handles that opened successfully. */
	private static function close_many( $handles ) {
		$success = true;
		foreach ( $handles as $handle ) {
			if ( is_resource( $handle ) && ! fclose( $handle ) ) {
				$success = false;
			}
		}
		return $success;
	}

	/** Remove intermediate paths. */
	private static function delete_many( $paths ) {
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/** Describe the detected delimiter for the results screen. */
	private static function delimiter_name( $delimiter ) {
		$names = array( ',' => __( 'comma', 'variation-import-guard' ), ';' => __( 'semicolon', 'variation-import-guard' ), "\t" => __( 'tab', 'variation-import-guard' ), '|' => __( 'pipe', 'variation-import-guard' ) );
		return isset( $names[ $delimiter ] ) ? $names[ $delimiter ] : __( 'comma', 'variation-import-guard' );
	}
}

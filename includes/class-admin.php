<?php
/** WordPress admin screens and protected actions. */
namespace VariationImportGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the CSV preflight page under Tools.
 */
final class Admin {
	const PAGE = 'variation-import-guard';
	const UPLOAD_PREFIX = 'vig_upload_';
	const JOB_TTL = DAY_IN_SECONDS;

	/** Register page, form handlers, and private-file cleanup. */
	public function register() {
		Storage::register_hooks();
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_vig_stage', array( $this, 'stage_upload' ) );
		add_action( 'admin_post_vig_process', array( $this, 'process_upload' ) );
		add_action( 'admin_post_vig_download', array( $this, 'download' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'load-tools_page_' . self::PAGE, array( 'VariationImportGuard\\Storage', 'cleanup' ) );
	}

	/** Add a Tools screen accessible to store managers and administrators. */
	public function add_page() {
		$capability = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
		add_management_page(
			esc_html__( 'Variation Import Guard', 'variation-import-guard' ),
			esc_html__( 'Variation Import Guard', 'variation-import-guard' ),
			$capability,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/** Load the stylesheet on this plugin page only. */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'vig-admin', VIG_URL . 'assets/css/admin.css', array(), VIG_VERSION );
	}

	/** Render upload, mapping, or result view. */
	public function render_page() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to use this tool.', 'variation-import-guard' ) );
		}
		$upload_token = isset( $_GET['vig_upload'] ) ? sanitize_text_field( wp_unslash( $_GET['vig_upload'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects a read-only view; the token is checked against the current user's transient.
		$job_token    = isset( $_GET['vig_job'] ) ? sanitize_text_field( wp_unslash( $_GET['vig_job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects a read-only view; the token is checked against the current user's transient.
		$error_token  = isset( $_GET['vig_error'] ) ? sanitize_text_field( wp_unslash( $_GET['vig_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects a read-only notice; the token is checked against the current user's transient.
		$error = '';
		if ( $error_token && preg_match( '/^[a-f0-9]{48}$/', $error_token ) ) {
			$error_data = get_transient( 'vig_error_' . $error_token );
			if ( is_array( $error_data ) && get_current_user_id() === (int) $error_data['user_id'] ) {
				$error = $error_data['message'];
			}
		}
		?>
		<div class="wrap vig-wrap">
			<header class="vig-header">
				<div class="vig-brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
				<div>
					<h1><?php esc_html_e( 'Variation Import Guard', 'variation-import-guard' ); ?></h1>
					<p><?php esc_html_e( 'Check product relationships in a CSV before WooCommerce imports it.', 'variation-import-guard' ); ?></p>
				</div>
			</header>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<p class="vig-safety-note"><strong><?php esc_html_e( 'Read-only preflight.', 'variation-import-guard' ); ?></strong> <?php esc_html_e( 'This tool checks your file and creates new downloads. It does not add, edit, or remove products.', 'variation-import-guard' ); ?></p>
			<?php
			if ( $job_token && preg_match( '/^[a-f0-9]{48}$/', $job_token ) ) {
				$this->render_result( $job_token );
			} elseif ( $upload_token && preg_match( '/^[a-f0-9]{48}$/', $upload_token ) ) {
				$this->render_mapping( $upload_token );
			} else {
				$this->render_upload();
			}
			?>
			<footer class="vig-footer"><span><?php esc_html_e( 'Files stay in a protected temporary folder and are removed after 24 hours.', 'variation-import-guard' ); ?></span></footer>
		</div>
		<?php
	}

	/** Render initial CSV upload form. */
	private function render_upload() {
		?>
		<section class="vig-card">
			<div class="vig-step"><span>01</span><div><h2><?php esc_html_e( 'Choose a CSV file', 'variation-import-guard' ); ?></h2><p><?php esc_html_e( 'Upload a product or supplier CSV. The original file will stay untouched.', 'variation-import-guard' ); ?></p></div></div>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
				<input type="hidden" name="action" value="vig_stage">
				<?php wp_nonce_field( 'vig_stage_upload', 'vig_nonce' ); ?>
				<label class="vig-file-label" for="vig-file"><?php esc_html_e( 'CSV file (up to 8 MB)', 'variation-import-guard' ); ?></label>
				<input id="vig-file" name="vig_file" type="file" accept=".csv,text/csv" required>
				<p class="description"><?php esc_html_e( 'Files with up to 15,000 data rows are supported. Larger files can be split and checked in separate batches.', 'variation-import-guard' ); ?></p>
				<div class="vig-actions"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Continue to column mapping', 'variation-import-guard' ); ?></button></div>
			</form>
		</section>
		<section class="vig-card vig-howto">
			<h2><?php esc_html_e( 'What gets checked', 'variation-import-guard' ); ?></h2>
			<ul class="vig-check-list">
				<li><?php esc_html_e( 'Duplicate or missing variation SKUs', 'variation-import-guard' ); ?></li>
				<li><?php esc_html_e( 'Missing parent rows and parent order', 'variation-import-guard' ); ?></li>
				<li><?php esc_html_e( 'Attribute names and values against the parent', 'variation-import-guard' ); ?></li>
				<li><?php esc_html_e( 'Variation price and stock quantity', 'variation-import-guard' ); ?></li>
			</ul>
		</section>
		<?php
	}

	/** Render header mapping and repair choices for a staged file. */
	private function render_mapping( $token ) {
		$upload = get_transient( self::UPLOAD_PREFIX . $token );
		if ( ! $this->owned_upload( $upload ) || ! is_file( $upload['path'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This staged file has expired. Upload the CSV again.', 'variation-import-guard' ) . '</p></div>';
			$this->render_upload();
			return;
		}
		$headers = $upload['headers'];
		$detected = $upload['mapping'];
		?>
		<section class="vig-card">
			<div class="vig-step"><span>02</span><div><h2><?php esc_html_e( 'Confirm the columns', 'variation-import-guard' ); ?></h2><p><?php esc_html_e( 'Detected headers are selected for you. Change a field if your supplier uses a different name.', 'variation-import-guard' ); ?></p></div></div>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="vig_process"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( 'vig_process_' . $token, 'vig_nonce' ); ?>
				<div class="vig-map-grid">
					<?php
					$roles = array(
						'type' => __( 'Product type', 'variation-import-guard' ),
						'sku' => __( 'Product or variation SKU', 'variation-import-guard' ),
						'parent' => __( 'Variation parent', 'variation-import-guard' ),
						'id' => __( 'Product ID', 'variation-import-guard' ),
						'price' => __( 'Regular price', 'variation-import-guard' ),
						'stock' => __( 'Stock quantity', 'variation-import-guard' ),
					);
					foreach ( $roles as $role => $label ) :
						$required = in_array( $role, array( 'type', 'sku', 'parent' ), true );
						?>
						<div class="vig-map-field"><label for="vig-map-<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $label ); ?><?php if ( $required ) : ?> <span class="vig-required"><?php esc_html_e( 'required', 'variation-import-guard' ); ?></span><?php endif; ?></label>
							<select id="vig-map-<?php echo esc_attr( $role ); ?>" name="mapping[<?php echo esc_attr( $role ); ?>]" <?php echo $required ? 'required' : ''; ?>>
								<option value=""><?php esc_html_e( 'Not mapped', 'variation-import-guard' ); ?></option>
								<?php foreach ( $headers as $index => $header ) : ?>
									<option value="<?php echo esc_attr( (string) $index ); ?>" <?php selected( isset( $detected[ $role ] ) && $detected[ $role ] === $index ); ?>><?php echo esc_html( $header . ' (' . self::column_letter( $index ) . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endforeach; ?>
				</div>
				<fieldset class="vig-options">
					<legend><?php esc_html_e( 'Optional checks and repairs', 'variation-import-guard' ); ?></legend>
					<label><input type="checkbox" name="normalize" value="1"> <?php esc_html_e( 'Trim surrounding whitespace in mapped values and attribute cells', 'variation-import-guard' ); ?></label>
					<p class="description"><?php esc_html_e( 'Off by default. Matching stays case-sensitive. Trimming changes only the corrected download.', 'variation-import-guard' ); ?></p>
					<?php if ( function_exists( 'wc_get_product' ) ) : ?>
						<label><input type="checkbox" name="catalog_check" value="1"> <?php esc_html_e( 'Compare SKUs and parent products with this store (read-only)', 'variation-import-guard' ); ?></label>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Live catalog matching is available when WooCommerce is active.', 'variation-import-guard' ); ?></p>
					<?php endif; ?>
				</fieldset>
				<div class="vig-actions"><a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Choose another file', 'variation-import-guard' ); ?></a><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Run preflight', 'variation-import-guard' ); ?></button></div>
			</form>
		</section>
		<div class="vig-file-meta"><span><?php echo esc_html( number_format_i18n( (int) $upload['bytes'] ) ); ?> <?php esc_html_e( 'bytes', 'variation-import-guard' ); ?></span><span><?php echo esc_html( count( $headers ) ); ?> <?php esc_html_e( 'columns detected', 'variation-import-guard' ); ?></span><span><?php echo esc_html( $upload['encoding'] ); ?> <?php esc_html_e( 'detected', 'variation-import-guard' ); ?></span></div>
		<?php
	}

	/** Show summary, findings, and authorized download links. */
	private function render_result( $token ) {
		$job = get_transient( Storage::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $job ) || get_current_user_id() !== (int) $job['user_id'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This result has expired or belongs to another account. Run the check again.', 'variation-import-guard' ) . '</p></div>';
			$this->render_upload();
			return;
		}
		$summary = $job['summary'];
		$status = 'ready' === $summary['status'] ? 'vig-status-ready' : ( 'review' === $summary['status'] ? 'vig-status-review' : 'vig-status-warnings' );
		?>
		<section class="vig-card vig-results">
			<div class="vig-results-head"><div><div class="vig-step"><span>03</span><div><h2><?php esc_html_e( 'Preflight results', 'variation-import-guard' ); ?></h2><p><?php esc_html_e( 'Review findings before importing this file with WooCommerce.', 'variation-import-guard' ); ?></p></div></div></div><span class="vig-status <?php echo esc_attr( $status ); ?>"><?php echo esc_html( $this->status_label( $summary['status'] ) ); ?></span></div>
			<div class="vig-stats" aria-label="<?php esc_attr_e( 'CSV summary', 'variation-import-guard' ); ?>">
				<?php $this->stat( __( 'Rows checked', 'variation-import-guard' ), $summary['rows'] ); ?>
				<?php $this->stat( __( 'Variable parents', 'variation-import-guard' ), $summary['parents'] ); ?>
				<?php $this->stat( __( 'Variations', 'variation-import-guard' ), $summary['variations'] ); ?>
				<?php $this->stat( __( 'Orphan rows', 'variation-import-guard' ), $summary['orphan_rows'] ); ?>
				<?php $this->stat( __( 'Duplicate SKUs', 'variation-import-guard' ), $summary['duplicate_skus'] ); ?>
				<?php $this->stat( __( 'Rows to reorder', 'variation-import-guard' ), $summary['repairable_rows'] ); ?>
			</div>
			<div class="vig-severity-summary"><span class="vig-severity-error"><?php echo esc_html( number_format_i18n( $summary['errors'] ) ); ?> <?php esc_html_e( 'errors', 'variation-import-guard' ); ?></span><span class="vig-severity-warning"><?php echo esc_html( number_format_i18n( $summary['warnings'] ) ); ?> <?php esc_html_e( 'warnings', 'variation-import-guard' ); ?></span><span><?php echo esc_html( number_format_i18n( $summary['notices'] ) ); ?> <?php esc_html_e( 'notes', 'variation-import-guard' ); ?></span></div>
			<?php if ( ! empty( $summary['catalog_checked'] ) ) : ?><p class="vig-inline-note"><?php esc_html_e( 'Store SKU matching ran as a read-only check.', 'variation-import-guard' ); ?></p><?php endif; ?>
			<?php if ( ! empty( $summary['sample'] ) ) : ?>
				<h3><?php esc_html_e( 'Findings and repair preview', 'variation-import-guard' ); ?></h3>
				<div class="vig-table-wrap"><table class="widefat striped vig-findings"><thead><tr><th><?php esc_html_e( 'CSV row', 'variation-import-guard' ); ?></th><th><?php esc_html_e( 'Severity', 'variation-import-guard' ); ?></th><th><?php esc_html_e( 'Check', 'variation-import-guard' ); ?></th><th><?php esc_html_e( 'Finding', 'variation-import-guard' ); ?></th></tr></thead><tbody>
					<?php foreach ( $summary['sample'] as $finding ) : ?><tr><td><?php echo esc_html( (string) $finding['row'] ); ?></td><td><span class="vig-finding-<?php echo esc_attr( $finding['severity'] ); ?>"><?php echo esc_html( ucfirst( $finding['severity'] ) ); ?></span></td><td><code><?php echo esc_html( $finding['code'] ); ?></code></td><td><?php echo esc_html( $finding['message'] ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div>
				<?php if ( $summary['errors'] + $summary['warnings'] + $summary['notices'] > count( $summary['sample'] ) ) : ?><p class="description"><?php esc_html_e( 'The full report includes every finding.', 'variation-import-guard' ); ?></p><?php endif; ?>
			<?php else : ?><div class="vig-clean"><h3><?php esc_html_e( 'No issues found', 'variation-import-guard' ); ?></h3><p><?php esc_html_e( 'The CSV passed the checks that apply to its mapped columns.', 'variation-import-guard' ); ?></p></div><?php endif; ?>
			<div class="vig-downloads"><div><h3><?php esc_html_e( 'Download your files', 'variation-import-guard' ); ?></h3><p><?php esc_html_e( 'The corrected CSV places variable parents before their variations. The report contains each flagged row.', 'variation-import-guard' ); ?></p></div><div class="vig-actions"><a class="button button-primary" href="<?php echo esc_url( $this->download_url( $token, 'fixed' ) ); ?>"><?php esc_html_e( 'Download corrected CSV', 'variation-import-guard' ); ?></a><a class="button" href="<?php echo esc_url( $this->download_url( $token, 'report' ) ); ?>"><?php esc_html_e( 'Download row report', 'variation-import-guard' ); ?></a></div></div>
			<p class="vig-caution"><?php esc_html_e( 'A clean preflight checks CSV structure. Review WooCommerce column mapping and test an import on a staging store before updating a large catalog.', 'variation-import-guard' ); ?></p>
		</section>
		<div class="vig-actions vig-bottom-actions"><a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Check another CSV', 'variation-import-guard' ); ?></a></div>
		<?php
	}

	/** Store a newly uploaded file and display column mapping. */
	public function stage_upload() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to use this tool.', 'variation-import-guard' ) );
		}
		check_admin_referer( 'vig_stage_upload', 'vig_nonce' );
		if ( empty( $_FILES['vig_file'] ) || ! isset( $_FILES['vig_file']['error'], $_FILES['vig_file']['tmp_name'], $_FILES['vig_file']['name'], $_FILES['vig_file']['size'] ) ) {
			$this->fail( __( 'Choose a CSV file to continue.', 'variation-import-guard' ) );
		}
		$file = $_FILES['vig_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as a PHP upload, then the filename and size are checked before moving it.
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->fail( __( 'The upload did not finish. Check the file size allowed by your server and try again.', 'variation-import-guard' ) );
		}
		$name = sanitize_file_name( wp_unslash( $file['name'] ) );
		if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || (int) $file['size'] < 1 || (int) $file['size'] > CSV_Preflight::MAX_BYTES ) {
			$this->fail( __( 'Choose a non-empty CSV file no larger than 8 MB.', 'variation-import-guard' ) );
		}
		$directory = Storage::ensure_directory();
		if ( is_wp_error( $directory ) ) {
			$this->fail( $directory->get_error_message() );
		}
		$token = Storage::token();
		$file['name'] = $token . '-source.csv';
		$private_upload_dir = static function ( $uploads ) use ( $directory ) {
			$uploads['path']    = $directory;
			$uploads['url']     = '';
			$uploads['subdir']  = '';
			$uploads['basedir'] = $directory;
			$uploads['baseurl'] = '';
			return $uploads;
		};
		add_filter( 'upload_dir', $private_upload_dir, 99 );
		$handled_upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				// Server MIME probes vary for CSV; the extension is restricted above and the bounded contents are parsed before use.
				'test_type' => false,
			)
		);
		remove_filter( 'upload_dir', $private_upload_dir, 99 );
		if ( ! is_array( $handled_upload ) || empty( $handled_upload['file'] ) ) {
			$message = is_array( $handled_upload ) && ! empty( $handled_upload['error'] ) ? $handled_upload['error'] : __( 'The uploaded file could not be stored in the protected temporary folder.', 'variation-import-guard' );
			$this->fail( $message );
		}
		$path = $handled_upload['file'];
		if ( DIRECTORY_SEPARATOR !== '\\' ) {
			chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best-effort POSIX hardening; download access also requires authorization.
		}
		$inspection = CSV_Preflight::inspect_headers( $path );
		if ( is_wp_error( $inspection ) ) {
			wp_delete_file( $path );
			$this->fail( $inspection->get_error_message() );
		}
		$upload = array( 'user_id' => get_current_user_id(), 'path' => $path, 'bytes' => (int) $file['size'], 'headers' => $inspection['headers'], 'mapping' => $inspection['mapping'], 'encoding' => $inspection['encoding'], 'created' => time() );
		set_transient( self::UPLOAD_PREFIX . $token, $upload, self::JOB_TTL );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'vig_upload' => $token ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/** Run validation and store the result for authorized downloads. */
	public function process_upload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The token selects the matching per-upload nonce; it is format-checked before nonce verification.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) {
			$this->fail( __( 'The upload reference is invalid. Upload the CSV again.', 'variation-import-guard' ) );
		}
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to use this tool.', 'variation-import-guard' ) );
		}
		check_admin_referer( 'vig_process_' . $token, 'vig_nonce' );
		$upload = get_transient( self::UPLOAD_PREFIX . $token );
		if ( ! $this->owned_upload( $upload ) || ! is_file( $upload['path'] ) ) {
			$this->fail( __( 'This staged file has expired. Upload the CSV again.', 'variation-import-guard' ) );
		}
		$mapping = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce was verified above.
		if ( isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ) {
			$posted = wp_unslash( $_POST['mapping'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each key is allow-listed and each value must be decimal before absint.
			foreach ( array( 'type', 'sku', 'parent', 'id', 'price', 'stock' ) as $role ) {
				if ( isset( $posted[ $role ] ) && '' !== $posted[ $role ] && ctype_digit( (string) $posted[ $role ] ) ) {
					$mapping[ $role ] = absint( $posted[ $role ] );
				}
			}
		}
		if ( ! isset( $mapping['type'], $mapping['sku'], $mapping['parent'] ) ) {
			$this->fail( __( 'Map the product type, SKU, and parent columns before running the preflight.', 'variation-import-guard' ) );
		}
		$options = array(
			'mapping' => $mapping,
			'normalize' => isset( $_POST['normalize'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['normalize'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce was verified above.
			'catalog_check' => isset( $_POST['catalog_check'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['catalog_check'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce was verified above.
			'token' => $token,
		);
		$fixed_path = Storage::job_file( $token, 'fixed' );
		$report_path = Storage::job_file( $token, 'report' );
		if ( ! $fixed_path || ! $report_path ) {
			$this->fail( __( 'The private working folder is not available.', 'variation-import-guard' ) );
		}
		$summary = CSV_Preflight::run( $upload['path'], $fixed_path, $report_path, $options );
		wp_delete_file( $upload['path'] );
		delete_transient( self::UPLOAD_PREFIX . $token );
		if ( is_wp_error( $summary ) ) {
			$error_token = Storage::token();
			set_transient( 'vig_error_' . $error_token, array( 'user_id' => get_current_user_id(), 'message' => $summary->get_error_message() ), MINUTE_IN_SECONDS * 10 );
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'vig_error' => $error_token ), admin_url( 'tools.php' ) ) );
			exit;
		}
		$job = array( 'user_id' => get_current_user_id(), 'summary' => $summary, 'created' => time() );
		set_transient( Storage::TRANSIENT_PREFIX . $token, $job, self::JOB_TTL );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'vig_job' => $token ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/** Stream one private output after capability, ownership, and nonce checks. */
	public function download() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'variation-import-guard' ) );
		}
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$kind  = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		check_admin_referer( 'vig_download_' . $token );
		$job = get_transient( Storage::TRANSIENT_PREFIX . $token );
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) || ! in_array( $kind, array( 'fixed', 'report' ), true ) || ! is_array( $job ) || get_current_user_id() !== (int) $job['user_id'] ) {
			wp_die( esc_html__( 'This download has expired or is not available to your account.', 'variation-import-guard' ) );
		}
		$path = Storage::job_file( $token, $kind );
		if ( ! $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'The requested file has expired. Run the check again.', 'variation-import-guard' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="variation-import-' . ( 'fixed' === $kind ? 'corrected' : 'report' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the private CSV without loading the whole result into PHP memory; access is gated by capability, nonce, owner and random token.
		exit;
	}

	/** Inspect mapping metadata for safe file ownership. */
	private function owned_upload( $upload ) {
		$directory = Storage::ensure_directory();
		if ( is_wp_error( $directory ) ) {
			return false;
		}
		return is_array( $upload ) && isset( $upload['user_id'], $upload['path'] ) && get_current_user_id() === (int) $upload['user_id'] && 0 === strpos( wp_normalize_path( $upload['path'] ), wp_normalize_path( trailingslashit( $directory ) ) );
	}

	/** Require a WooCommerce manager or WordPress administrator. */
	private function can_manage() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/** Redirect with a short-lived user-owned error. */
	private function fail( $message ) {
		$error_token = Storage::token();
		set_transient( 'vig_error_' . $error_token, array( 'user_id' => get_current_user_id(), 'message' => sanitize_text_field( $message ) ), MINUTE_IN_SECONDS * 10 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'vig_error' => $error_token ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/** Render one count card. */
	private function stat( $label, $value ) {
		?>
		<div class="vig-stat"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $value ) ); ?></strong></div>
		<?php
	}

	/** Build a capability and nonce protected download URL. */
	private function download_url( $token, $kind ) {
		$url = add_query_arg( array( 'action' => 'vig_download', 'token' => $token, 'kind' => $kind ), admin_url( 'admin-post.php' ) );
		return wp_nonce_url( $url, 'vig_download_' . $token );
	}

	/** Human-readable status label. */
	private function status_label( $status ) {
		$labels = array( 'ready' => __( 'Ready to review', 'variation-import-guard' ), 'review' => __( 'Review findings', 'variation-import-guard' ), 'warnings' => __( 'Review warnings', 'variation-import-guard' ) );
		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Results', 'variation-import-guard' );
	}

	/** Convert zero-based index to spreadsheet column letters. */
	private static function column_letter( $index ) {
		$label = '';
		for ( ++$index; $index > 0; $index = (int) floor( ( $index - 1 ) / 26 ) ) {
			$label = chr( 65 + ( $index - 1 ) % 26 ) . $label;
		}
		return $label;
	}
}

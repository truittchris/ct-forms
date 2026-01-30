<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CT_Forms_Tools {

	/**
	 * init method.
	 *
	 * @return mixed
	 */
	public static function init() {
		add_action( 'admin_post_ct_forms_export_entries_csv', array( __CLASS__, 'handle_export_entries_csv' ) );
		add_action( 'admin_post_ct_forms_cleanup', array( __CLASS__, 'handle_cleanup' ) );
	}

	/**
	 * page_tools method.
	 *
	 * @return mixed
	 */
	public static function page_tools() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ct-forms' ) );
		}

		$scan = self::scan_installation();

		$settings            = CT_Forms_Admin::get_settings();
		$delete_on_uninstall = ! empty( $settings['delete_on_uninstall'] );

		?>
		<div class="wrap ct-forms-wrap">
			<h1><?php esc_html_e( 'CT Forms Tools', 'ct-forms' ); ?></h1>

			<div class="truitt-card" style="max-width:1100px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Export', 'ct-forms' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Export entries to CSV. This is recommended before cleanup on any site that has received submissions.', 'ct-forms' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ct_forms_export_entries_csv">
					<?php wp_nonce_field( 'ct_forms_export_entries_csv' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Form', 'ct-forms' ); ?></th>
							<td>
								<select name="form_id">
									<option value="0"><?php esc_html_e( 'All forms', 'ct-forms' ); ?></option>
									<?php foreach ( $scan['forms'] as $f ) : ?>
										<option value="<?php echo (int) $f['id']; ?>"><?php echo esc_html( $f['title'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'ct-forms' ); ?></th>
							<td>
								<select name="status">
									<option value=""><?php esc_html_e( 'All statuses', 'ct-forms' ); ?></option>
									<?php foreach ( self::status_labels() as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Submitted after', 'ct-forms' ); ?></th>
							<td>
								<input type="date" name="date_from" value="">
								<p class="description"><?php esc_html_e( 'Optional. Filters by submitted/created date.', 'ct-forms' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Submitted before', 'ct-forms' ); ?></th>
							<td><input type="date" name="date_to" value=""></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Include file info', 'ct-forms' ); ?></th>
							<td>
								<label><input type="checkbox" name="include_files" value="1" checked> <?php esc_html_e( 'Include uploaded file names/URLs in the CSV', 'ct-forms' ); ?></label>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Download CSV', 'ct-forms' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="truitt-card" style="max-width:1100px;margin-top:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Diagnostics', 'ct-forms' ); ?></h2>
				<p class="description"><?php esc_html_e( 'This is a read-only scan of what the plugin created on this site.', 'ct-forms' ); ?></p>

				<table class="widefat striped">
					<tbody>
						<tr>
							<th style="width:260px;"><?php esc_html_e( 'Site instance ID', 'ct-forms' ); ?></th>
							<td><code><?php echo esc_html( self::get_site_instance_id() ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Tables found', 'ct-forms' ); ?></th>
							<td><?php echo esc_html( implode( ', ', $scan['tables_found'] ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Options found', 'ct-forms' ); ?></th>
							<td><?php echo esc_html( implode( ', ', $scan['options_found'] ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Uploads folder', 'ct-forms' ); ?></th>
							<td>
								<code><?php echo esc_html( $scan['uploads_dir'] ); ?></code><br>
								<?php
								/* translators: 1: number of files, 2: total size. */
								echo esc_html( sprintf( __( '%1$d files, %2$s', 'ct-forms' ), (int) $scan['uploads_count'], size_format( (int) $scan['uploads_bytes'] ) ) );
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="truitt-card" style="max-width:1100px;margin-top:16px;border-left:4px solid #b32d2e;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Cleanup', 'ct-forms' ); ?></h2>
				<p class="description"><?php esc_html_e( 'This permanently deletes data. Export first. You must type the confirmation phrase exactly.', 'ct-forms' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'This will permanently delete selected CT Forms data. Continue?', 'ct-forms' ) ); ?>');">
					<input type="hidden" name="action" value="ct_forms_cleanup">
					<?php wp_nonce_field( 'ct_forms_cleanup' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Delete', 'ct-forms' ); ?></th>
							<td>
								<label style="display:block;margin:4px 0;"><input type="checkbox" name="delete_tables" value="1" checked> <?php esc_html_e( 'Plugin database tables', 'ct-forms' ); ?></label>
								<label style="display:block;margin:4px 0;"><input type="checkbox" name="delete_options" value="1" checked> <?php esc_html_e( 'Plugin options/settings', 'ct-forms' ); ?></label>
								<label style="display:block;margin:4px 0;"><input type="checkbox" name="delete_uploads" value="1" checked> <?php esc_html_e( 'Uploaded files (uploads/ct-forms)', 'ct-forms' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Confirmation phrase', 'ct-forms' ); ?></th>
							<td>
								<input type="text" class="regular-text" name="confirm_phrase" placeholder="DELETE TRUITT FORMS" required>
								<p class="description"><?php esc_html_e( 'Type: DELETE TRUITT FORMS', 'ct-forms' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Delete on uninstall', 'ct-forms' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'If enabled in Settings, uninstall.php will delete plugin data when the plugin is deleted from WordPress. Default is disabled (best practice).', 'ct-forms' ); ?></p>
								<p><?php echo $delete_on_uninstall ? esc_html__( 'Enabled', 'ct-forms' ) : esc_html__( 'Disabled', 'ct-forms' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Delete Selected Data', 'ct-forms' ), 'delete', 'submit', false ); ?>
				</form>
			</div>

		</div>
		<?php
	}

	/**
	 * handle_export_entries_csv method.
	 *
	 * @return mixed
	 */
	public static function handle_export_entries_csv() {
		if ( ! current_user_can( 'ct_forms_export_entries' ) && ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( esc_html__( 'Not allowed', 'ct-forms' ) );
		}
		check_admin_referer( 'ct_forms_export_entries_csv' );

		$form_id       = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		$status        = isset( $_POST['status'] ) ? sanitize_key( (string) wp_unslash( $_POST['status'] ) ) : '';
		$include_files = ! empty( $_POST['include_files'] );

		$date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
		$date_to   = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';

		global $wpdb;
		$table = CT_Forms_DB::entries_table();

				$pk_col = CT_Forms_DB::entries_pk_column();
		$created_col    = CT_Forms_DB::entries_created_column();
		$data_col       = CT_Forms_DB::entries_data_column();

		$entries_cols = CT_Forms_DB::entries_columns();

		// Status column detection (supports legacy installs that used state/entry_status).
		$status_col = in_array( 'status', $entries_cols, true ) ? 'status' : ( in_array( 'state', $entries_cols, true ) ? 'state' : ( in_array( 'entry_status', $entries_cols, true ) ? 'entry_status' : '' ) );

		// Files column detection (supports legacy installs).
		$files_col = in_array( 'files', $entries_cols, true ) ? 'files' : ( in_array( 'files_json', $entries_cols, true ) ? 'files_json' : ( in_array( 'uploads', $entries_cols, true ) ? 'uploads' : '' ) );
		if ( ! $pk_col || ! $created_col || ! $data_col ) {
			wp_die( esc_html__( 'Entries table schema not detected.', 'ct-forms' ) );
		}

		$where  = array();
		$params = array();

		if ( $form_id > 0 ) {
			$where[]  = 'form_id = %d';
			$params[] = $form_id;
		}
		if ( $status !== '' && $status_col ) {
			$where[]  = "{$status_col} = %s";
			$params[] = $status;
		}
		if ( $date_from !== '' ) {
			$where[]  = "{$created_col} >= %s";
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to !== '' ) {
			$where[]  = "{$created_col} <= %s";
			$params[] = $date_to . ' 23:59:59';
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$select = array(
			"{$pk_col} AS entry_id",
			'form_id',
			"{$created_col} AS submitted_at",
		);
		if ( $status_col ) {
			$select[] = "{$status_col} AS status"; }
		$select[] = "{$data_col} AS data_json";
		if ( $include_files && $files_col ) {
			$select[] = "{$files_col} AS files_json"; }

		$sql = 'SELECT ' . implode( ', ', $select ) . " FROM {$table} {$where_sql} ORDER BY {$created_col} DESC";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, ...$params );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$filename = 'ct-forms-entries-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		// Determine headers from data keys (dynamic, but stable).
		$headers = array( 'entry_id', 'form_id', 'submitted_at' );
		if ( $status_col ) {
			$headers[] = 'status'; }
		$headers[] = 'field_data';
		if ( $include_files && $files_col ) {
			$headers[] = 'files'; }

		fputcsv( $out, $headers );

		foreach ( (array) $rows as $r ) {
			$data                 = array();
			$data['entry_id']     = $r['entry_id'];
			$data['form_id']      = $r['form_id'];
			$data['submitted_at'] = $r['submitted_at'];
			if ( $status_col ) {
				$data['status'] = isset( $r['status'] ) ? $r['status'] : ''; }

			// data_json contains field values.
			$data['field_data'] = isset( $r['data_json'] ) ? $r['data_json'] : '';

			if ( $include_files && $files_col ) {
				$data['files'] = isset( $r['files_json'] ) ? $r['files_json'] : '';
			}

			$row = array();
			foreach ( $headers as $h ) {
				$row[] = isset( $data[ $h ] ) ? $data[ $h ] : '';
			}
			fputcsv( $out, $row );
		}

		fclose( $out );
		exit;
	}

	/**
	 * handle_cleanup method.
	 *
	 * @return mixed
	 */
	public static function handle_cleanup() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( esc_html__( 'Not allowed', 'ct-forms' ) );
		}
		check_admin_referer( 'ct_forms_cleanup' );

		$phrase = isset( $_POST['confirm_phrase'] ) ? trim( (string) wp_unslash( $_POST['confirm_phrase'] ) ) : '';
		if ( $phrase !== 'DELETE TRUITT FORMS' ) {
			wp_safe_redirect( add_query_arg( array( 'ct_forms_cleanup_error' => 1 ), admin_url( 'admin.php?page=ct-forms-tools' ) ) );
			exit;
		}

		$delete_tables  = ! empty( $_POST['delete_tables'] );
		$delete_options = ! empty( $_POST['delete_options'] );
		$delete_uploads = ! empty( $_POST['delete_uploads'] );

		$result = array(
			'tables_dropped'  => array(),
			'options_deleted' => 0,
			'uploads_deleted' => 0,
		);

		global $wpdb;

		if ( $delete_tables ) {
			$tables = self::candidate_tables();
			foreach ( $tables as $t ) {
				$full   = $wpdb->prefix . $t;
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
				if ( $exists === $full ) {
					$wpdb->query( "DROP TABLE IF EXISTS {$full}" );
					$result['tables_dropped'][] = $full;
				}
			}
		}

		if ( $delete_options ) {
			// Known options.
			$known = array(
				'ct_forms_settings',
				'ct_forms_db_version',
				'ct_forms_site_instance_id',
			);
			foreach ( $known as $k ) {
				if ( get_option( $k, null ) !== null ) {
					delete_option( $k );
				}
			}

			// Any ct_forms_* options/transients.
			$like = $wpdb->esc_like( 'ct_forms_' ) . '%';
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			foreach ( (array) $rows as $name ) {
				delete_option( $name );
				++$result['options_deleted'];
			}

			// Transients may exist.
			$t_like = $wpdb->esc_like( '_transient_ct_forms_' ) . '%';
			$t_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $t_like ) );
			foreach ( (array) $t_rows as $name ) {
				delete_option( $name );
				++$result['options_deleted'];
			}
		}

		if ( $delete_uploads ) {
			$upload_dir = wp_upload_dir();
			$dir        = trailingslashit( $upload_dir['basedir'] ) . 'ct-forms';
			if ( is_dir( $dir ) ) {
				$result['uploads_deleted'] = self::rrmdir( $dir ) ? 1 : 0;
			}
		}

		$redirect = admin_url( 'admin.php?page=ct-forms-tools' );
		$redirect = add_query_arg( array( 'ct_forms_cleanup_done' => 1 ), $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * candidate_tables method.
	 *
	 * @return mixed
	 */
	private static function candidate_tables() {
		return array(
			'ct_forms',
			'ct_forms_entries',
			'ct_forms_entry_meta',
			'ct_forms_mail_log',
		);
	}

	/**
	 * status_labels method.
	 *
	 * @return mixed
	 */
	private static function status_labels() {
		return array(
			'new'       => __( 'New', 'ct-forms' ),
			'reviewed'  => __( 'Reviewed', 'ct-forms' ),
			'follow_up' => __( 'Follow Up', 'ct-forms' ),
			'spam'      => __( 'Spam', 'ct-forms' ),
			'archived'  => __( 'Archived', 'ct-forms' ),
		);
	}

	/**
	 * scan_installation method.
	 *
	 * @return mixed
	 */
	private static function scan_installation() {
		global $wpdb;

		$tables_found = array();
		foreach ( self::candidate_tables() as $t ) {
			$full   = $wpdb->prefix . $t;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			if ( $exists === $full ) {
				$tables_found[] = $full;
			}
		}

		$options_found = array();
		$like          = $wpdb->esc_like( 'ct_forms_' ) . '%';
		$rows          = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( (array) $rows as $name ) {
			$options_found[] = $name; }

		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'ct-forms';

		$uploads_count = 0;
		$uploads_bytes = 0;
		if ( is_dir( $dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() ) {
					++$uploads_count;
					$uploads_bytes += (int) $file->getSize();
				}
			}
		}

		$forms = array();
		$posts = get_posts(
			array(
				'post_type'   => 'ct_form',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => 200,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		foreach ( (array) $posts as $p ) {
			$forms[] = array(
				'id'    => (int) $p->ID,
				'title' => (string) $p->post_title,
			);
		}

		return array(
			'tables_found'  => $tables_found,
			'options_found' => $options_found,
			'uploads_dir'   => $dir,
			'uploads_count' => $uploads_count,
			'uploads_bytes' => $uploads_bytes,
			'forms'         => $forms,
		);
	}

	/**
	 * rrmdir method.
	 *
	 * @param mixed $dir Parameter.
	 * @return mixed
	 */
	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false; }
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $file ) {
			if ( $file->isDir() ) {
				@rmdir( $file->getPathname() );
			} else {
				@unlink( $file->getPathname() );
			}
		}
		return @rmdir( $dir );
	}

	/**
	 * get_site_instance_id method.
	 *
	 * @return mixed
	 */
	private static function get_site_instance_id() {
		$id = get_option( 'ct_forms_site_instance_id', '' );
		if ( empty( $id ) && function_exists( 'wp_generate_uuid4' ) ) {
			$id = wp_generate_uuid4();
			add_option( 'ct_forms_site_instance_id', $id );
		}
		return $id;
	}
}
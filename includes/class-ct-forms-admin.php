<?php
/**
 * Admin screens and settings.
 *
 * @package CT_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CT_Forms_Admin class.
 *
 * @package CT_Forms
 */

final class CT_Forms_Admin {
	/**
	 * normalize_template_newlines method.
	 *
	 * @param mixed $value Parameter.
	 * @return mixed
	 */
	private static function normalize_template_newlines( $value ) {
		$value = is_string( $value ) ? $value : '';

		// Convert escaped sequences (e.g., "\\r\\n") to real LF newlines for storage.
		$value = str_replace(
			array(
				"\r\n",
				"\n",
				'
',
				"\r",
			),
			'
',
			$value
		);

		// Normalize actual CRLF/CR to LF.
		$value = preg_replace( "/\r\n|\r/", "\n", $value );

		// Back-compat: handle legacy artifacts where escaped newline sequences got stripped.
		// These have shown up in saved templates as:
		// - "...{form_name}.rn{all_fields}rnrnEntry ID..." (\\r\\n -> rn)
		// - "...{form_name}.n{all_fields}nnEntry ID..."   (\\n   -> n)
		// Convert doubled artifacts to a blank line (only in common template boundary contexts).
		$value = preg_replace( '/rnrn(?=\{)/', "\n\n", $value );
		$value = preg_replace( '/rnrn(?=Entry\b)/', "\n\n", $value );
		$value = preg_replace( '/(?<=[\}\]\.\)])rnrn/', "\n\n", $value );
		// Also handle cases like "Entry ID: 14nnTest" where the artifact appears
		// immediately after a number.
		$value = preg_replace( '/(?<=\d)rnrn(?=[A-Za-z])/', "\n\n", $value );
		$value = preg_replace( '/nn(?=\{)/', "\n\n", $value );
		$value = preg_replace( '/nn(?=Entry\b)/', "\n\n", $value );
		$value = preg_replace( '/(?<=[\}\]\.\)])\s*nn/', "\n\n", $value );
		$value = preg_replace( '/(?<=\d)nn(?=[A-Za-z])/', "\n\n", $value );
		// Common boundary patterns where the artifact appears.
		$value = preg_replace( '/rn(?=\{)/', "\n", $value );
		$value = preg_replace( '/(?<=[\}\]\.\)])rn/', "\n", $value );
		$value = preg_replace( '/rn(?=Reference\b)/', "\n", $value );
		// Same, but for the stripped "n" artifact.
		$value = preg_replace( '/n(?=\{)/', "\n", $value );
		$value = preg_replace( '/(?<=[\}\]\.\)])n/', "\n", $value );
		$value = preg_replace( '/n(?=Reference\b)/', "\n", $value );
		$value = preg_replace( '/(?<=\d)n(?=[A-Za-z])/', "\n\n", $value );

		return $value;
	}

	/**
	 * format_submitted_cell_html method.
	 *
	 * @param mixed $submitted_raw Parameter.
	 * @return mixed
	 */
	private static function format_submitted_cell_html( $submitted_raw ) {
		if ( empty( $submitted_raw ) || $submitted_raw === '0000-00-00 00:00:00' ) {
			return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">Not recorded</span></span>';
		}

		$ts = strtotime( (string) $submitted_raw );
		if ( $ts === false || $ts <= 0 ) {
			return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">Not recorded</span></span>';
		}

		// Treat clearly invalid historic values (e.g., -0001-11-30...) as missing.
		if ( $ts < 0 ) {
			return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">Not recorded</span></span>';
		}

		$date_fmt = get_option( 'date_format' );
		$time_fmt = get_option( 'time_format' );

		$main = wp_date( $date_fmt . ' \a\t ' . $time_fmt, $ts );
		$ago  = human_time_diff( $ts, current_time( 'timestamp' ) ) . ' ago';

		return '<span class="truitt-submitted"><span class="truitt-submitted__main">' . esc_html( $main ) . '</span><span class="truitt-submitted__sub">' . esc_html( $ago ) . '</span></span>';
	}

	/**
	 * get_status_label method.
	 *
	 * @param mixed $status Parameter.
	 * @return mixed
	 */
	private static function get_status_label( $status ) {
		$status = sanitize_key( (string) $status );
		$labels = array(
			'new'       => __( 'New', 'ct-forms' ),
			'reviewed'  => __( 'Reviewed', 'ct-forms' ),
			'follow_up' => __( 'Follow Up', 'ct-forms' ),
			'spam'      => __( 'Spam', 'ct-forms' ),
			'archived'  => __( 'Archived', 'ct-forms' ),
		);

		if ( isset( $labels[ $status ] ) ) {
			return $labels[ $status ];
		}

		return $status ? ucwords( str_replace( '_', ' ', $status ) ) : __( 'New', 'ct-forms' );
	}


	/**
	 * init method.
	 *
	 * @return mixed
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		add_action( 'admin_post_ct_forms_save_builder', array( __CLASS__, 'save_builder' ) );
		add_action( 'admin_post_ct_forms_save_form_settings', array( __CLASS__, 'save_form_settings' ) );
		add_action( 'admin_post_ct_forms_resend_admin', array( __CLASS__, 'resend_admin' ) );
		add_action( 'admin_post_ct_forms_bulk_entries', array( __CLASS__, 'handle_bulk_entries' ) );
		add_action( 'admin_post_ct_forms_update_entry_status', array( __CLASS__, 'update_entry_status' ) );
		add_action( 'admin_post_ct_forms_send_support', array( __CLASS__, 'handle_send_support' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		// Cron for retention
		add_action( 'ct_forms_retention_cleanup', array( __CLASS__, 'run_retention_cleanup' ) );
		if ( ! wp_next_scheduled( 'ct_forms_retention_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ct_forms_retention_cleanup' );
		}
	}

	/**
	 * Handle bulk actions for the Entries list.
	 */
	public static function handle_bulk_entries() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' );
		}

		check_admin_referer( 'ct_forms_bulk_entries' );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( (string) $_POST['bulk_action'] ) : '';
		$ids    = isset( $_POST['entry_ids'] ) && is_array( $_POST['entry_ids'] ) ? array_map( 'intval', $_POST['entry_ids'] ) : array();
		$ids    = array_values( array_filter( $ids ) );

		$redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( (string) $_POST['_redirect'] ) : '';
		$redirect = wp_validate_redirect( $redirect, admin_url( 'admin.php?page=ct-forms-entries' ) );

		if ( empty( $ids ) || '' === $action ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$count = 0;

		// Status mapping actions.
		$status_map = array(
			'mark_new'       => 'new',
			'mark_reviewed'  => 'reviewed',
			'mark_follow_up' => 'follow_up',
			'mark_spam'      => 'spam',
			'archive'        => 'archived',
		);

		if ( isset( $status_map[ $action ] ) ) {
			$status = $status_map[ $action ];
			foreach ( $ids as $id ) {
				if ( CT_Forms_DB::update_entry_status( $id, $status ) ) {
					++$count;
				}
			}
			$redirect = add_query_arg(
				array(
					'ct_forms_bulk'       => $action,
					'ct_forms_bulk_count' => $count,
				),
				$redirect
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( 'delete' === $action ) {
			foreach ( $ids as $id ) {
				if ( CT_Forms_Submissions::delete_entry_and_files( $id ) ) {
					++$count;
				}
			}
			$redirect = add_query_arg(
				array(
					'ct_forms_bulk'       => 'delete',
					'ct_forms_bulk_count' => $count,
				),
				$redirect
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( 'resend_admin' === $action ) {
			foreach ( $ids as $id ) {
				$entry = CT_Forms_DB::get_entry( $id );
				if ( ! $entry ) {
					continue;
				}

				$form_settings = CT_Forms_CPT::get_form_settings( (int) $entry['form_id'] );
				$post          = get_post( (int) $entry['form_id'] );
				$form_name     = $post ? $post->post_title : 'Form';

				$tokens     = array(
					'{form_name}'    => $form_name,
					'{entry_id}'     => (string) $id,
					'{submitted_at}' => isset( $entry['submitted_at'] ) ? (string) $entry['submitted_at'] : '',
				);
				$all_fields = '';
				$data       = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
				foreach ( (array) $data as $k => $v ) {
					if ( is_array( $v ) ) {
						$v = implode( ', ', $v ); }
					$all_fields                    .= $k . ': ' . $v . "\n";
					$tokens[ '{field:' . $k . '}' ] = (string) $v;
				}
				$tokens['{all_fields}'] = trim( $all_fields );

				$subject = strtr( (string) ( $form_settings['email_subject'] ?? '' ), $tokens );
				$body    = strtr( (string) ( $form_settings['email_body'] ?? '' ), $tokens );

				$headers = array();

				// Reply-To best practice: route replies to the submitter when a valid email is present.
				if ( ! empty( $data['email'] ) && is_email( $data['email'] ) ) {
					$headers[] = 'Reply-To: ' . $data['email'];
				}
				$from_name  = sanitize_text_field( (string) ( $form_settings['from_name'] ?? '' ) );
				$from_email = sanitize_email( (string) ( $form_settings['from_email'] ?? '' ) );
				if ( $from_email ) {
					$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
				}

				$sent = wp_mail( (string) ( $form_settings['to_email'] ?? '' ), $subject, $body, $headers );
				if ( $sent ) {
					++$count;
				}

				$mail_log                      = isset( $entry['mail_log'] ) && is_array( $entry['mail_log'] ) ? $entry['mail_log'] : array();
				$mail_log['bulk_resend_admin'] = array(
					'sent_at' => current_time( 'mysql' ),
					'sent'    => (bool) $sent,
				);
				CT_Forms_DB::update_entry_mail_log( $id, $mail_log );
			}

			$redirect = add_query_arg(
				array(
					'ct_forms_bulk'       => 'resend_admin',
					'ct_forms_bulk_count' => $count,
				),
				$redirect
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Unknown action.
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Update an individual entry status from the Entry detail screen.
	 */
	public static function update_entry_status() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;
		if ( $entry_id <= 0 ) {
			wp_die( 'Invalid entry.' );
		}

		check_admin_referer( 'ct_forms_update_entry_status_' . $entry_id );

		$allowed_statuses = array( 'new', 'reviewed', 'follow_up', 'spam', 'archived' );
		$status           = isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'new';
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'new';
		}

		CT_Forms_DB::update_entry_status( $entry_id, $status );

		$redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( (string) $_POST['_redirect'] ) : '';
		$redirect = wp_validate_redirect( $redirect, admin_url( 'admin.php?page=ct-forms-entries&entry_id=' . $entry_id ) );
		$redirect = add_query_arg( array( 'ct_forms_status_updated' => 1 ), $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * menu method.
	 *
	 * @return mixed
	 */
	public static function menu() {
		$cap = 'ct_forms_manage';

		add_menu_page(
			__( 'CT Forms', 'ct-forms' ),
			__( 'CT Forms', 'ct-forms' ),
			$cap,
			'ct-forms',
			array( __CLASS__, 'page_forms' ),
			'dashicons-feedback',
			58
		);

		add_submenu_page(
			'ct-forms',
			__( 'Forms', 'ct-forms' ),
			__( 'Forms', 'ct-forms' ),
			$cap,
			'ct-forms',
			array( __CLASS__, 'page_forms' )
		);

		add_submenu_page(
			'ct-forms',
			__( 'Entries', 'ct-forms' ),
			__( 'Entries', 'ct-forms' ),
			'ct_forms_view_entries',
			'ct-forms-entries',
			array( __CLASS__, 'page_entries' )
		);

		add_submenu_page(
			'ct-forms',
			__( 'Files', 'ct-forms' ),
			__( 'Files', 'ct-forms' ),
			'ct_forms_view_entries',
			'ct-forms-files',
			array( __CLASS__, 'page_files' )
		);

		add_submenu_page(
			'ct-forms',
			__( 'Settings', 'ct-forms' ),
			__( 'Settings', 'ct-forms' ),
			$cap,
			'ct-forms-settings',
			array( __CLASS__, 'page_settings' )
		);

		add_submenu_page(
			'ct-forms',
			__( 'Tools', 'ct-forms' ),
			__( 'Tools', 'ct-forms' ),
			$cap,
			'ct-forms-tools',
			array( 'CT_Forms_Tools', 'page_tools' )
		);

		add_submenu_page(
			'ct-forms',
			__( 'Support', 'ct-forms' ),
			__( 'Support', 'ct-forms' ),
			$cap,
			'ct-forms-support',
			array( __CLASS__, 'page_support' )
		);
	}

	/**
	 * assets method.
	 *
	 * @param mixed $hook Parameter.
	 * @return mixed
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'ct-forms' ) ) {
			return; }

		$settings = self::get_settings();

		// Needed for the Notification/Autoresponder body editors.
		if ( function_exists( 'wp_enqueue_editor' ) ) {
			wp_enqueue_editor();
		}

		// Base admin styles + UI polish layer (loaded after base for overrides).
		wp_enqueue_style( 'ct-forms-admin', CT_FORMS_PLUGIN_URL . 'assets/css/admin.css', array(), CT_FORMS_VERSION );
		wp_enqueue_style( 'ct-forms-admin-ui', CT_FORMS_PLUGIN_URL . 'assets/css/admin-ui.css', array( 'ct-forms-admin' ), CT_FORMS_VERSION );
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'ct-forms-admin', CT_FORMS_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), CT_FORMS_VERSION, true );

		wp_localize_script(
			'ct-forms-admin',
			'CTFormsAdmin',
			array(
				'nonce'         => wp_create_nonce( 'ct_forms_admin' ),
				'allowed_mimes' => isset( $settings['allowed_mimes'] ) ? (string) $settings['allowed_mimes'] : '',
				'max_file_mb'   => isset( $settings['max_file_mb'] ) ? (int) $settings['max_file_mb'] : 0,
			)
		);
	}

	/**
	 * register_settings method.
	 *
	 * @return mixed
	 */
	public static function register_settings() {
		register_setting(
			'ct_forms_settings',
			'ct_forms_settings',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * sanitize_settings method.
	 *
	 * @param mixed $input Parameter.
	 * @return mixed
	 */
	public static function sanitize_settings( $input ) {
		$out = array();

		$out['allowed_mimes']       = isset( $input['allowed_mimes'] ) ? sanitize_text_field( $input['allowed_mimes'] ) : '';
		$out['max_file_mb']         = isset( $input['max_file_mb'] ) ? max( 1, (int) $input['max_file_mb'] ) : 10;
		$out['rate_limit']          = isset( $input['rate_limit'] ) ? max( 0, (int) $input['rate_limit'] ) : 10;
		$out['rate_window_minutes'] = isset( $input['rate_window_minutes'] ) ? max( 1, (int) $input['rate_window_minutes'] ) : 10;
		$out['store_ip']            = ! empty( $input['store_ip'] ) ? 1 : 0;
		$out['store_user_agent']    = ! empty( $input['store_user_agent'] ) ? 1 : 0;
		$out['retention_days']      = isset( $input['retention_days'] ) ? max( 0, (int) $input['retention_days'] ) : 0;

		$mode = isset( $input['from_mode'] ) ? sanitize_key( (string) $input['from_mode'] ) : 'default';
		if ( ! in_array( $mode, array( 'default', 'site', 'custom' ), true ) ) {
			$mode = 'default'; }
		$out['from_mode']           = $mode;
		$out['enforce_from_domain'] = ! empty( $input['enforce_from_domain'] ) ? 1 : 0;

		$type          = isset( $input['recaptcha_type'] ) ? sanitize_key( $input['recaptcha_type'] ) : '';
		$allowed_types = array( 'disabled', 'v2_checkbox', 'v2_invisible', 'v3' );
		if ( '' === $type ) {
			// Back-compat: older versions used a boolean recaptcha_enabled option.
			$type = ! empty( $input['recaptcha_enabled'] ) ? 'v2_checkbox' : 'disabled';
		}
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'disabled';
		}

		$out['recaptcha_type']    = $type;
		$out['recaptcha_enabled'] = ( 'disabled' !== $type ) ? 1 : 0;

		$out['recaptcha_site_key']   = isset( $input['recaptcha_site_key'] ) ? sanitize_text_field( $input['recaptcha_site_key'] ) : '';
		$out['recaptcha_secret_key'] = isset( $input['recaptcha_secret_key'] ) ? sanitize_text_field( $input['recaptcha_secret_key'] ) : '';

		$out['recaptcha_v3_action'] = isset( $input['recaptcha_v3_action'] ) ? sanitize_text_field( $input['recaptcha_v3_action'] ) : 'ct_forms_submit';
		$th                         = isset( $input['recaptcha_v3_threshold'] ) ? floatval( $input['recaptcha_v3_threshold'] ) : 0.5;
		if ( $th < 0 ) {
			$th = 0; }
		if ( $th > 1 ) {
			$th = 1; }
		$out['recaptcha_v3_threshold'] = $th;

		return $out;
	}

	/**
	 * get_settings method.
	 *
	 * @return mixed
	 */
	public static function get_settings() {
		$defaults = array(
			'allowed_mimes'          => 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt',
			'max_file_mb'            => 10,
			'rate_limit'             => 10,
			'rate_window_minutes'    => 10,
			'store_ip'               => 1,
			'store_user_agent'       => 1,
			'retention_days'         => 0, // 0 = keep forever
			'from_mode'              => 'default', // default|site|custom
			'enforce_from_domain'    => 1,
			'delete_on_uninstall'    => 0,
			// reCAPTCHA (v2 checkbox)
			'recaptcha_enabled'      => 0,
			'recaptcha_type'         => 'disabled',
			'recaptcha_site_key'     => '',
			'recaptcha_secret_key'   => '',
			'recaptcha_v3_action'    => 'ct_forms_submit',
			'recaptcha_v3_threshold' => 0.5,
		);

		$s = get_option( 'ct_forms_settings', array() );
		if ( ! is_array( $s ) ) {
			$s = array(); }
		$s = array_merge( $defaults, $s );

		// Back-compat: older versions used a boolean recaptcha_enabled option.
		if ( empty( $s['recaptcha_type'] ) ) {
			$s['recaptcha_type'] = ! empty( $s['recaptcha_enabled'] ) ? 'v2_checkbox' : 'disabled';
		}
		$s['recaptcha_enabled'] = ( 'disabled' !== $s['recaptcha_type'] ) ? 1 : 0;

		return $s;
	}

	/**
	 * page_forms method.
	 *
	 * @return mixed
	 */
	public static function page_forms() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' ); }

		// Handle quick create
		if ( isset( $_POST['truitt_new_form'] ) && check_admin_referer( 'truitt_new_form' ) ) {
			$title   = isset( $_POST['form_title'] ) ? sanitize_text_field( wp_unslash( $_POST['form_title'] ) ) : 'New Form';
			$form_id = wp_insert_post(
				array(
					'post_type'   => 'ct_form',
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);

			if ( $form_id ) {
				CT_Forms_CPT::save_form_definition( $form_id, CT_Forms_CPT::default_form_definition() );
				wp_safe_redirect( admin_url( 'admin.php?page=ct-forms&edit_form=' . (int) $form_id ) );
				exit;
			}
		}

		$edit_form_id = isset( $_GET['edit_form'] ) ? (int) $_GET['edit_form'] : 0;

		if ( $edit_form_id > 0 ) {
			self::page_form_builder( $edit_form_id );
			return;
		}

		$forms = get_posts(
			array(
				'post_type'   => 'ct_form',
				'post_status' => 'publish',
				'numberposts' => 200,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		?>
		<div class="wrap ct-forms-wrap">
			<h1><?php esc_html_e( 'CT Forms', 'ct-forms' ); ?></h1>

			<div class="ct-forms-admin-card">
				<h2><?php esc_html_e( 'Create a new form', 'ct-forms' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'truitt_new_form' ); ?>
					<input type="hidden" name="truitt_new_form" value="1">
					<input type="text" name="form_title" class="regular-text" placeholder="Form name" required>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Create', 'ct-forms' ); ?></button>
				</form>
			</div>

			<div class="ct-forms-admin-card">
				<h2><?php esc_html_e( 'Your forms', 'ct-forms' ); ?></h2>
				<?php if ( empty( $forms ) ) : ?>
					<p><?php esc_html_e( 'No forms yet.', 'ct-forms' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Form', 'ct-forms' ); ?></th>
								<th><?php esc_html_e( 'Shortcode', 'ct-forms' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'ct-forms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $forms as $f ) : ?>
								<tr>
									<td><?php echo esc_html( $f->post_title ); ?></td>
									<td><code>[ct_form id="<?php echo (int) $f->ID; ?>"]</code></td>
									<td>
										<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ct-forms&edit_form=' . (int) $f->ID ) ); ?>"><?php esc_html_e( 'Edit', 'ct-forms' ); ?></a>
										<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ct-forms-entries&form_id=' . (int) $f->ID ) ); ?>"><?php esc_html_e( 'Entries', 'ct-forms' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}


	/**
	 * truitt_parse_wp_mysql_datetime_to_ts method.
	 *
	 * @param mixed $mysql_datetime Parameter.
	 * @return mixed
	 */
	private static function truitt_parse_wp_mysql_datetime_to_ts( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) || ! is_string( $mysql_datetime ) ) {
			return 0;
		}

		// Interpret the stored value as WordPress local time (current_time('mysql')).
		try {
			$tz = wp_timezone();
			$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, $tz );
			if ( $dt instanceof \DateTimeImmutable ) {
				return (int) $dt->getTimestamp();
			}
		} catch ( \Exception $e ) {
			// Fall through.
		}

		$ts = strtotime( $mysql_datetime );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * render_mail_log_html method.
	 *
	 * @param mixed $mail_log Parameter.
	 * @return mixed
	 */
	private static function render_mail_log_html( $mail_log ) {
		$log = is_array( $mail_log ) ? $mail_log : array();

		$sent_at_raw = isset( $log['sent_at'] ) ? (string) $log['sent_at'] : '';
		$sent_ts     = self::truitt_parse_wp_mysql_datetime_to_ts( $sent_at_raw );

		$date_fmt = get_option( 'date_format' );
		$time_fmt = get_option( 'time_format' );

		$sent_main = $sent_ts ? wp_date( "{$date_fmt} \\a\\t {$time_fmt}", $sent_ts ) : '—';
		$sent_sub  = $sent_ts ? ( human_time_diff( $sent_ts, current_time( 'timestamp' ) ) . ' ago' ) : '';

		$channels = array(
			'admin'         => array(
				'label' => 'Admin notification',
				'data'  => isset( $log['admin'] ) && is_array( $log['admin'] ) ? $log['admin'] : array(),
			),
			'autoresponder' => array(
				'label' => 'Autoresponder',
				'data'  => isset( $log['autoresponder'] ) && is_array( $log['autoresponder'] ) ? $log['autoresponder'] : array(),
			),
		);

		$raw_json = wp_json_encode( $log, JSON_PRETTY_PRINT );

		ob_start();
		?>
		<div class="truitt-maillog-meta" style="margin:0 0 12px 0;">
			<div style="font-weight:600;">Sent</div>
			<div><?php echo esc_html( $sent_main ); ?></div>
			<?php if ( ! empty( $sent_sub ) ) : ?>
				<div style="color:#6c757d;font-size:12px;"><?php echo esc_html( $sent_sub ); ?></div>
			<?php endif; ?>
		</div>

		<table class="widefat striped" style="margin-top:8px;">
			<thead>
				<tr>
					<th style="width:180px;">Type</th>
					<th>To</th>
					<th>Subject</th>
					<th style="width:110px;">Result</th>
					<th>Error</th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $channels as $key => $ch ) :
				$d       = $ch['data'];
				$to      = isset( $d['to'] ) ? (string) $d['to'] : '';
				$subject = isset( $d['subject'] ) ? (string) $d['subject'] : '';
				$sent    = isset( $d['sent'] ) ? (bool) $d['sent'] : false;
				$error   = isset( $d['error'] ) ? (string) $d['error'] : '';
				?>
				<tr>
					<td><?php echo esc_html( $ch['label'] ); ?></td>
					<td><?php echo esc_html( $to ); ?></td>
					<td><?php echo esc_html( $subject ); ?></td>
					<td>
						<?php if ( $sent ) : ?>
							<span class="truitt-badge truitt-badge--ok" style="display:inline-block;padding:2px 8px;border-radius:999px;background:#e8f5e9;color:#1b5e20;font-weight:600;font-size:12px;">Sent</span>
						<?php else : ?>
							<span class="truitt-badge truitt-badge--bad" style="display:inline-block;padding:2px 8px;border-radius:999px;background:#ffebee;color:#b71c1c;font-weight:600;font-size:12px;">Failed</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $error ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<button type="button" class="button button-secondary truitt-copy-mail-log" data-mail-log="<?php echo esc_attr( $raw_json ); ?>">Copy log</button>
			<span style="color:#6c757d;font-size:12px;">
				Note: “Sent” means WordPress accepted the email for delivery. For delivery confirmation, check your SMTP provider or email log plugin (e.g., WP Mail SMTP).
			</span>
		</div>

		<details style="margin-top:10px;">
			<summary>Raw log</summary>
			<pre style="white-space:pre-wrap;margin-top:8px;"><?php echo esc_html( $raw_json ); ?></pre>
		</details>

		<script>
		(function(){
			function truittCopyText(text){
				if (navigator.clipboard && navigator.clipboard.writeText) {
					return navigator.clipboard.writeText(text);
				}
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.setAttribute('readonly','readonly');
				ta.style.position = 'fixed';
				ta.style.left = '-9999px';
				document.body.appendChild(ta);
				ta.select();
				try { document.execCommand('copy'); } catch(e) {}
				document.body.removeChild(ta);
				return Promise.resolve();
			}

			document.addEventListener('click', function(e){
				var btn = e.target && e.target.closest ? e.target.closest('.truitt-copy-mail-log') : null;
				if (!btn) return;
				e.preventDefault();
				var log = btn.getAttribute('data-mail-log') || '';
				truittCopyText(log).then(function(){
					var prev = btn.textContent;
					btn.textContent = 'Copied';
					setTimeout(function(){ btn.textContent = prev; }, 1200);
				});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * page_form_builder method.
	 *
	 * @param mixed $form_id Parameter.
	 * @return mixed
	 */
	private static function page_form_builder( $form_id ) {
		$post = get_post( $form_id );
		if ( ! $post || 'ct_form' !== $post->post_type ) {
			echo '<div class="wrap ct-forms-wrap"><p>Form not found.</p></div>';
			return;
		}

		$def      = CT_Forms_CPT::get_form_definition( $form_id );
		$settings = CT_Forms_CPT::get_form_settings( $form_id );
		?>
		<div class="wrap ct-forms-wrap">
			<h1><?php echo esc_html( 'Edit Form: ' . $post->post_title ); ?></h1>

			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ct-forms' ) ); ?>">&larr; Back to Forms</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ct-forms-entries&form_id=' . (int) $form_id ) ); ?>"><?php esc_html_e( 'View Entries', 'ct-forms' ); ?></a>
			</p>

			
			<div class="ct-forms-flow">
				<div class="ct-forms-step ct-forms-admin-card">
					<div class="ct-forms-step__header">
						<div>
							<h2 class="ct-forms-step__title"><?php esc_html_e( '1. Build your form', 'ct-forms' ); ?></h2>
							<p class="description" style="margin:4px 0 0;"><?php esc_html_e( 'Add fields, reorder them, and configure field details. Save when done.', 'ct-forms' ); ?></p>
						</div>
						<div class="ct-forms-step__actions">
							<button type="button" class="button" id="ct-expand-all"><?php esc_html_e( 'Expand all', 'ct-forms' ); ?></button>
							<button type="button" class="button" id="ct-collapse-all"><?php esc_html_e( 'Collapse all', 'ct-forms' ); ?></button>
						</div>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ct_forms_save_builder_' . $form_id ); ?>
						<input type="hidden" name="action" value="ct_forms_save_builder">
						<input type="hidden" name="form_id" value="<?php echo (int) $form_id; ?>">
						<input type="hidden" id="ct_form_definition" name="ct_form_definition" value="<?php echo esc_attr( wp_json_encode( $def ) ); ?>">

						<div id="ct-forms-builder" class="ct-forms-builder" data-form-id="<?php echo (int) $form_id; ?>"></div>

						<div class="ct-forms-builder-actions ct-forms-builder-actions--sticky">
							<div class="ct-forms-builder-actions__left">
								<button type="button" class="button" id="ct-add-field"><?php esc_html_e( 'Add Field', 'ct-forms' ); ?></button>
							</div>
							<div class="ct-forms-builder-actions__right">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Form', 'ct-forms' ); ?></button>
							</div>
						</div>
					</form>
				</div>

				<div class="ct-forms-step ct-forms-admin-card">
					<div class="ct-forms-step__header">
						<div>
							<h2 class="ct-forms-step__title"><?php esc_html_e( '2. Configure settings', 'ct-forms' ); ?></h2>
							<p class="description" style="margin:4px 0 0;"><?php esc_html_e( 'Notification, autoresponder, confirmation, and routing.', 'ct-forms' ); ?></p>
						</div>
						<div class="ct-forms-step__actions">
</div>
					</div>

					<form id="ct-forms-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ct_forms_save_form_settings_' . $form_id ); ?>
						<input type="hidden" id="ct_form_definition_settings" name="ct_form_definition" value="" />
						<input type="hidden" name="action" value="ct_forms_save_form_settings">
						<input type="hidden" name="form_id" value="<?php echo (int) $form_id; ?>">
						<input type="hidden" name="ct_active_tab" id="ct_active_tab" value="notification">

						<div class="ct-forms-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Form settings tabs', 'ct-forms' ); ?>">
							<button type="button" class="ct-forms-tab is-active" data-tab="notification" role="tab" aria-selected="true"><?php esc_html_e( 'Notification', 'ct-forms' ); ?></button>
							<button type="button" class="ct-forms-tab" data-tab="autoresponder" role="tab" aria-selected="false"><?php esc_html_e( 'Autoresponder', 'ct-forms' ); ?></button>
							<button type="button" class="ct-forms-tab" data-tab="confirmation" role="tab" aria-selected="false"><?php esc_html_e( 'Confirmation', 'ct-forms' ); ?></button>
							<button type="button" class="ct-forms-tab" data-tab="routing" role="tab" aria-selected="false"><?php esc_html_e( 'Routing', 'ct-forms' ); ?></button>
						</div>

						<div class="ct-forms-tab-panels">
							<div class="ct-forms-tab-panel is-active" data-panel="notification" role="tabpanel">
								<div class="ct-forms-settings-grid">
									<p><label><?php esc_html_e( 'Send to email', 'ct-forms' ); ?><br><input type="email" class="regular-text" name="to_email" value="<?php echo esc_attr( $settings['to_email'] ); ?>"></label></p>
									<p><label><?php esc_html_e( 'Reply-To field id (example: email)', 'ct-forms' ); ?><br><input type="text" class="regular-text" name="reply_to_field" value="<?php echo esc_attr( $settings['reply_to_field'] ); ?>"></label></p>
									<p><label><?php esc_html_e( 'CC (comma separated)', 'ct-forms' ); ?><br><input type="text" class="regular-text" name="cc" value="<?php echo esc_attr( $settings['cc'] ); ?>"></label></p>
									<p><label><?php esc_html_e( 'BCC (comma separated)', 'ct-forms' ); ?><br><input type="text" class="regular-text" name="bcc" value="<?php echo esc_attr( $settings['bcc'] ); ?>"></label></p>
								</div>
								<p><label><?php esc_html_e( 'Subject', 'ct-forms' ); ?><br><input type="text" class="large-text" name="email_subject" value="<?php echo esc_attr( self::normalize_template_newlines( $settings['email_subject'] ) ); ?>"></label></p>
								<div class="ct-forms-editor">
									<p style="margin:0 0 6px;"><label><?php esc_html_e( 'Body', 'ct-forms' ); ?></label></p>
									<?php
									$ct_email_body = self::normalize_template_newlines( $settings['email_body'] );
									wp_editor(
										$ct_email_body,
										'ct_forms_email_body',
										array(
											'textarea_name' => 'email_body',
											'textarea_rows' => 10,
											'teeny'     => true,
											'media_buttons' => false,
											'quicktags' => true,
											'tinymce'   => array(
												'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
												'toolbar2' => '',
											),
										)
									);
									?>
								</div>
								<p><label><input type="checkbox" name="attach_uploads" value="1" <?php checked( ! empty( $settings['attach_uploads'] ) ); ?>> <?php esc_html_e( 'Attach uploads to admin email (guarded by size limit)', 'ct-forms' ); ?></label></p>
								<hr style="margin:16px 0;">
								<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Spam protection', 'ct-forms' ); ?></h3>
								<p>
									<label>
										<input type="checkbox" name="recaptcha_enabled" value="1" <?php checked( ! empty( $settings['recaptcha_enabled'] ) ); ?>>
										<?php esc_html_e( 'Enable Google reCAPTCHA for this form', 'ct-forms' ); ?>
									</label>
								</p>
								<p class="description" style="margin-top:0;">
									<?php esc_html_e( 'Requires reCAPTCHA keys in CT Forms – Settings. When enabled, visitors must pass reCAPTCHA before the entry is saved or emailed.', 'ct-forms' ); ?>
								</p>

							</div>

							<div class="ct-forms-tab-panel" data-panel="autoresponder" role="tabpanel">
								<p><label><input type="checkbox" name="autoresponder_enabled" value="1" <?php checked( ! empty( $settings['autoresponder_enabled'] ) ); ?>> <?php esc_html_e( 'Enable autoresponder', 'ct-forms' ); ?></label></p>
								<p><label><?php esc_html_e( 'To field id (example: email)', 'ct-forms' ); ?><br><input type="text" class="regular-text" name="autoresponder_to_field" value="<?php echo esc_attr( $settings['autoresponder_to_field'] ); ?>"></label></p>
								<p><label><?php esc_html_e( 'Subject', 'ct-forms' ); ?><br><input type="text" class="large-text" name="autoresponder_subject" value="<?php echo esc_attr( self::normalize_template_newlines( $settings['autoresponder_subject'] ) ); ?>"></label></p>
								<div class="ct-forms-editor">
									<p style="margin:0 0 6px;"><label><?php esc_html_e( 'Body', 'ct-forms' ); ?></label></p>
									<?php
									$ct_autoresponder_body = self::normalize_template_newlines( $settings['autoresponder_body'] );
									wp_editor(
										$ct_autoresponder_body,
										'ct_forms_autoresponder_body',
										array(
											'textarea_name' => 'autoresponder_body',
											'textarea_rows' => 10,
											'teeny'     => true,
											'media_buttons' => false,
											'quicktags' => true,
											'tinymce'   => array(
												'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
												'toolbar2' => '',
											),
										)
									);
									?>
								</div>
							</div>

							<div class="ct-forms-tab-panel" data-panel="confirmation" role="tabpanel">
								<p><label><?php esc_html_e( 'Confirmation type', 'ct-forms' ); ?><br>
									<select name="confirmation_type">
										<option value="message" <?php selected( $settings['confirmation_type'], 'message' ); ?>><?php esc_html_e( 'Message', 'ct-forms' ); ?></option>
										<option value="redirect" <?php selected( $settings['confirmation_type'], 'redirect' ); ?>><?php esc_html_e( 'Redirect', 'ct-forms' ); ?></option>
									</select>
								</label></p>
								<div class="ct-forms-editor">
									<p style="margin:0 0 6px;"><label><?php esc_html_e( 'Confirmation message', 'ct-forms' ); ?></label></p>
									<?php
									$ct_confirmation_message = self::normalize_template_newlines( $settings['confirmation_message'] );
									wp_editor(
										$ct_confirmation_message,
										'ct_forms_confirmation_message',
										array(
											'textarea_name' => 'confirmation_message',
											'textarea_rows' => 6,
											'teeny'     => true,
											'media_buttons' => false,
											'quicktags' => true,
											'tinymce'   => array(
												'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
												'toolbar2' => '',
											),
										)
									);
									?>
								</div>
								<p><label><?php esc_html_e( 'Redirect URL', 'ct-forms' ); ?><br><input type="url" class="large-text" name="confirmation_redirect" value="<?php echo esc_attr( $settings['confirmation_redirect'] ); ?>"></label></p>
								<p class="description"><?php esc_html_e( 'Tokens available: {form_name}, {entry_id}, {submitted_at}, {all_fields}, {field:your_field_id}', 'ct-forms' ); ?></p>
							</div>

							<div class="ct-forms-tab-panel" data-panel="routing" role="tabpanel">
								<p class="description"><?php esc_html_e( 'Optional rules to override the main recipient. Example: if field "topic" equals "Billing", send to billing@domain.com', 'ct-forms' ); ?></p>
								<textarea class="large-text" rows="8" name="routing_rules_json"><?php echo esc_textarea( wp_json_encode( $settings['routing_rules'], JSON_PRETTY_PRINT ) ); ?></textarea>
							</div>
						</div>

						<div class="ct-forms-settings-footer">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'ct-forms' ); ?></button>
						</div>
					</form>
				</div>
			</div>
		<?php
	}

	/**
	 * save_builder method.
	 *
	 * @return mixed
	 */
	public static function save_builder() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' ); }

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( $form_id <= 0 ) {
			wp_die( 'Invalid form' ); }

		check_admin_referer( 'ct_forms_save_builder_' . $form_id );

		$raw = isset( $_POST['ct_form_definition'] ) ? wp_unslash( $_POST['ct_form_definition'] ) : '';
		$def = json_decode( (string) $raw, true );

		if ( ! is_array( $def ) || empty( $def['fields'] ) || ! is_array( $def['fields'] ) ) {
			wp_die( 'Invalid definition JSON' );
		}

		// Sanitize definition
		$fields = array();
		foreach ( $def['fields'] as $f ) {
			$id   = isset( $f['id'] ) ? sanitize_key( $f['id'] ) : '';
			$type = isset( $f['type'] ) ? sanitize_key( $f['type'] ) : 'text';
			if ( '' === $id ) {
				continue; }

			$allowed_types = array( 'text', 'textarea', 'email', 'number', 'date', 'time', 'select', 'state', 'checkboxes', 'radios', 'file', 'diagnostics' );
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'text'; }

			$field = array(
				'id'          => $id,
				'type'        => $type,
				'label'       => isset( $f['label'] ) ? sanitize_text_field( $f['label'] ) : $id,
				'required'    => ! empty( $f['required'] ),
				'placeholder' => isset( $f['placeholder'] ) ? sanitize_text_field( $f['placeholder'] ) : '',
				'help'        => isset( $f['help'] ) ? sanitize_text_field( $f['help'] ) : '',
				'options'     => array(),
			);

			if ( in_array( $type, array( 'select', 'checkboxes', 'radios' ), true ) && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
				foreach ( $f['options'] as $opt ) {
					$v = isset( $opt['value'] ) ? sanitize_text_field( $opt['value'] ) : '';
					$t = isset( $opt['label'] ) ? sanitize_text_field( $opt['label'] ) : $v;
					if ( '' !== $v ) {
						$field['options'][] = array(
							'value' => $v,
							'label' => $t,
						);
					}
				}
			}

			// Persist file-upload specific settings.
			if ( 'file' === $type ) {
				$field['allowed_types'] = isset( $f['allowed_types'] ) ? sanitize_text_field( $f['allowed_types'] ) : '';
				$field['file_max_mb']   = isset( $f['file_max_mb'] ) ? absint( $f['file_max_mb'] ) : 0;
				$field['file_multiple'] = ! empty( $f['file_multiple'] );
			}

			$fields[] = $field;
		}

		$def_out = array(
			'version' => 1,
			'fields'  => $fields,
		);

		CT_Forms_CPT::save_form_definition( $form_id, $def_out );
		// Ensure the latest definition is used immediately (front-end + admin), even with object caching.
		clean_post_cache( $form_id );
		wp_cache_delete( $form_id, 'posts' );
		if ( function_exists( 'do_action' ) ) {
			// Optional cache-plugin integration points (no-ops if not installed).
			do_action( 'litespeed_purge_post', $form_id );
			do_action( 'wpfc_clear_post_cache_by_id', $form_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ct-forms&edit_form=' . (int) $form_id . '&saved=1' ) );
		exit;
	}

	/**
	 * save_form_settings method.
	 *
	 * @return mixed
	 */
	public static function save_form_settings() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' ); }

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( $form_id <= 0 ) {
			wp_die( 'Invalid form' ); }

		check_admin_referer( 'ct_forms_save_form_settings_' . $form_id );

		// If the builder definition was posted with this request, save it as well.
		if ( isset( $_POST['ct_form_definition'] ) ) {
			$raw_def = wp_unslash( $_POST['ct_form_definition'] );
			$def     = json_decode( $raw_def, true );
			if ( is_array( $def ) ) {
				CT_Forms_CPT::save_form_definition( $form_id, $def );
					clean_post_cache( $form_id );
					wp_cache_delete( $form_id, 'posts' );
			}
		}

		$settings = CT_Forms_CPT::get_form_settings( $form_id );

		$settings['to_email']       = isset( $_POST['to_email'] ) ? sanitize_email( wp_unslash( $_POST['to_email'] ) ) : $settings['to_email'];
		$settings['cc']             = isset( $_POST['cc'] ) ? sanitize_text_field( wp_unslash( $_POST['cc'] ) ) : '';
		$settings['bcc']            = isset( $_POST['bcc'] ) ? sanitize_text_field( wp_unslash( $_POST['bcc'] ) ) : '';
		$settings['reply_to_field'] = isset( $_POST['reply_to_field'] ) ? sanitize_key( wp_unslash( $_POST['reply_to_field'] ) ) : 'email';
		$settings['email_subject']  = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : $settings['email_subject'];
		$settings['email_body']     = isset( $_POST['email_body'] ) ? wp_kses_post( self::normalize_template_newlines( wp_unslash( $_POST['email_body'] ) ) ) : $settings['email_body'];

		$settings['attach_uploads'] = ! empty( $_POST['attach_uploads'] ) ? 1 : 0;

		// Per-form spam protection.
		// Checkbox fields are omitted from POST when unchecked, so default to 0.
		$settings['recaptcha_enabled'] = ! empty( $_POST['recaptcha_enabled'] ) ? 1 : 0;

		$rules_json = isset( $_POST['routing_rules_json'] ) ? wp_unslash( $_POST['routing_rules_json'] ) : '';
		$rules      = json_decode( (string) $rules_json, true );
		if ( is_array( $rules ) ) {
			$clean = array();
			foreach ( $rules as $r ) {
				if ( ! is_array( $r ) ) {
					continue; }
				$field = isset( $r['field'] ) ? sanitize_key( $r['field'] ) : '';
				$op    = isset( $r['operator'] ) ? sanitize_text_field( $r['operator'] ) : 'equals';
				$val   = isset( $r['value'] ) ? sanitize_text_field( $r['value'] ) : '';
				$to    = isset( $r['to_email'] ) ? sanitize_email( $r['to_email'] ) : '';
				if ( '' === $field || '' === $to ) {
					continue; }
				if ( ! in_array( $op, array( 'equals', 'contains' ), true ) ) {
					$op = 'equals'; }
				$clean[] = array(
					'field'    => $field,
					'operator' => $op,
					'value'    => $val,
					'to_email' => $to,
				);
			}
			$settings['routing_rules'] = $clean;
		}

		$settings['autoresponder_enabled']  = ! empty( $_POST['autoresponder_enabled'] ) ? 1 : 0;
		$settings['autoresponder_to_field'] = isset( $_POST['autoresponder_to_field'] ) ? sanitize_key( wp_unslash( $_POST['autoresponder_to_field'] ) ) : 'email';
		$settings['autoresponder_subject']  = isset( $_POST['autoresponder_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['autoresponder_subject'] ) ) : $settings['autoresponder_subject'];
		$settings['autoresponder_body']     = isset( $_POST['autoresponder_body'] ) ? wp_kses_post( self::normalize_template_newlines( wp_unslash( $_POST['autoresponder_body'] ) ) ) : $settings['autoresponder_body'];

		$settings['confirmation_type'] = isset( $_POST['confirmation_type'] ) && in_array( $_POST['confirmation_type'], array( 'message', 'redirect' ), true ) ? sanitize_key( $_POST['confirmation_type'] ) : 'message';
		// Confirmation messages are displayed to end users; allow basic formatting while preserving line breaks.
		$settings['confirmation_message']  = isset( $_POST['confirmation_message'] )
			? wp_kses_post( self::normalize_template_newlines( wp_unslash( $_POST['confirmation_message'] ) ) )
			: $settings['confirmation_message'];
		$settings['confirmation_redirect'] = isset( $_POST['confirmation_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['confirmation_redirect'] ) ) : '';

		CT_Forms_CPT::save_form_settings( $form_id, $settings );
		// Clear any caches so the updated settings are reflected immediately.
		// On some hosts with persistent object caching, the post_meta cache can
		// survive update_post_meta() within the same request, which makes it look
		// like email templates "didn't save" until caches expire.
		clean_post_cache( $form_id );
		wp_cache_delete( $form_id, 'posts' );
		wp_cache_delete( $form_id, 'post_meta' );

		wp_safe_redirect( admin_url( 'admin.php?page=ct-forms&edit_form=' . (int) $form_id . '&saved_settings=1' ) );
		exit;
	}

	/**
	 * page_entries method.
	 *
	 * @return mixed
	 */
	public static function page_entries() {
		if ( ! current_user_can( 'ct_forms_view_entries' ) && ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' ); }

		$entry_id = isset( $_GET['entry_id'] ) ? (int) $_GET['entry_id'] : 0;
		if ( $entry_id > 0 ) {
			self::page_entry_detail( $entry_id );
			return;
		}

		$form_id      = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
		$missing_only = ! empty( $_GET['missing'] ) ? 1 : 0;

		?>
		<div class="wrap ct-forms-wrap">
			<h1><?php esc_html_e( 'Form Entries', 'ct-forms' ); ?></h1>
			<?php self::render_entries_simple_table( $form_id ); ?>
		</div>
		<?php
	}

	/**
	 * page_files method.
	 *
	 * @return mixed
	 */
	public static function page_files() {
		if ( ! current_user_can( 'ct_forms_view_entries' ) && ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' );
		}

		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 25;
		$form_id  = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;

		// Back-compat: older builds used a "missing" checkbox.
		$missing_only = ! empty( $_GET['missing'] ) ? 1 : 0;

		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : 'all';
		if ( $missing_only ) {
			$status = 'missing';
		}
		if ( ! in_array( $status, array( 'all', 'ok', 'missing' ), true ) ) {
			$status = 'all';
		}

		$allowed_statuses = array( 'all', 'ok', 'missing' );

		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		if ( $status_filter !== '' && ! in_array( $status_filter, $allowed_statuses, true ) ) {
			$status_filter = ''; }
		$search = trim( $search );

		$q = CT_Forms_DB::get_entries_with_files(
			array(
				'paged'    => $paged,
				'per_page' => $per_page,
				'form_id'  => $form_id,
				'search'   => $search,
			)
		);

		$total       = isset( $q['total'] ) ? (int) $q['total'] : 0;
		$items       = isset( $q['items'] ) ? (array) $q['items'] : array();
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		// Build file rows.
		$rows       = array();
		$defs_cache = array();

		foreach ( $items as $entry ) {
			$eid      = isset( $entry['entry_id'] ) ? (int) $entry['entry_id'] : ( isset( $entry['id'] ) ? (int) $entry['id'] : 0 );
			$fid_form = isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0;
			$files    = isset( $entry['files'] ) && is_array( $entry['files'] ) ? $entry['files'] : array();
			if ( $eid <= 0 || empty( $files ) ) {
				continue;
			}

			if ( $fid_form > 0 && ! isset( $defs_cache[ $fid_form ] ) ) {
				$defs_cache[ $fid_form ] = CT_Forms_CPT::get_form_definition( $fid_form );
			}

			$labels = array();
			if ( $fid_form > 0 && ! empty( $defs_cache[ $fid_form ]['fields'] ) ) {
				foreach ( (array) $defs_cache[ $fid_form ]['fields'] as $f ) {
					$id = isset( $f['id'] ) ? sanitize_key( (string) $f['id'] ) : '';
					if ( '' === $id ) {
						continue; }
					$labels[ $id ] = isset( $f['label'] ) ? (string) $f['label'] : $id;
				}
			}

			foreach ( $files as $field_key => $file_obj ) {
				$field_key = sanitize_key( (string) $field_key );
				if ( '' === $field_key ) {
					continue; }
				// Stored key might be truitt_field_{id}; normalize for display.
				$display_field_id = $field_key;
				if ( 0 === strpos( $display_field_id, 'truitt_field_' ) ) {
					$display_field_id = substr( $display_field_id, 10 );
				}

				$list = ( is_array( $file_obj ) && isset( $file_obj[0] ) ) ? $file_obj : array( $file_obj );
				foreach ( $list as $idx => $f ) {
					if ( ! is_array( $f ) ) {
						continue; }

					$rows[] = array(
						'entry_id'     => $eid,
						'form_id'      => $fid_form,
						'field_id'     => $display_field_id,
						'field_label'  => isset( $labels[ $display_field_id ] ) ? $labels[ $display_field_id ] : $display_field_id,
						'idx'          => (int) $idx,
						'name'         => isset( $f['original'] ) ? (string) $f['original'] : ( isset( $f['name'] ) ? (string) $f['name'] : '' ),
						'size'         => isset( $f['size'] ) ? (int) $f['size'] : 0,
						'type'         => isset( $f['type'] ) ? (string) $f['type'] : '',
						'submitted_at' => isset( $entry['submitted_at'] ) ? (string) $entry['submitted_at'] : '',
						'path'         => isset( $f['path'] ) ? (string) $f['path'] : ( isset( $f['file'] ) ? (string) $f['file'] : '' ),
						'url'          => isset( $f['url'] ) ? (string) $f['url'] : '',
					);
				}
			}
		}

		// Apply status and filename search filters at the file-row level.
		if ( 'all' !== $status || '' !== $search ) {
			$needle = strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					function ( $r ) use ( $status, $needle ) {
						$p      = isset( $r['path'] ) ? (string) $r['path'] : '';
						$exists = ( '' !== $p ) && CT_Forms_Admin::file_exists_safe( $p );

						if ( 'missing' === $status && $exists ) {
							return false;
						}
						if ( 'ok' === $status && ! $exists ) {
							return false;
						}

						if ( '' !== $needle ) {
							$name = isset( $r['name'] ) ? strtolower( (string) $r['name'] ) : '';
							if ( false === strpos( $name, $needle ) ) {
								return false;
							}
						}

						return true;
					}
				)
			);
			// Note: when filtering, total counts/pagination are based on entries, not files.
		}

		// Forms dropdown for filtering.
		$forms = get_posts(
			array(
				'post_type'   => 'ct_form',
				'post_status' => 'publish',
				'numberposts' => 200,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		$base_url = admin_url( 'admin.php?page=ct-forms-files' );

		$redirect_back = add_query_arg(
			array(
				'page'    => 'ct-forms-files',
				'paged'   => $paged,
				'form_id' => $form_id,
				'status'  => $status,
				's'       => $search,
				'missing' => $missing_only,
			),
			admin_url( 'admin.php' )
		);

		?>
		<div class="wrap ct-forms-wrap">
			<h1><?php echo esc_html__( 'Uploaded Files', 'ct-forms' ); ?></h1>

			<?php if ( ! empty( $_GET['ct_forms_file_deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'File deleted.', 'ct-forms' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['ct_forms_deleted_all'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
						$count = isset( $_GET['ct_forms_deleted_all_count'] ) ? (int) $_GET['ct_forms_deleted_all_count'] : 0;
						/* translators: %d: number of files deleted. */
						echo esc_html( sprintf( _n( '%d file deleted.', '%d files deleted.', $count, 'ct-forms' ), $count ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['ct_forms_bulk_deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
						$count = isset( $_GET['ct_forms_bulk_deleted_count'] ) ? (int) $_GET['ct_forms_bulk_deleted_count'] : 0;
						/* translators: %d: number of files deleted. */
						echo esc_html( sprintf( _n( '%d file deleted.', '%d files deleted.', $count, 'ct-forms' ), $count ) );
					?>
				</p></div>
			<?php endif; ?>



			<form method="get" style="margin: 12px 0 16px;">
				<input type="hidden" name="page" value="ct-forms-files" />
				<label for="truitt_files_form_id" style="margin-right:8px;"><?php echo esc_html__( 'Form:', 'ct-forms' ); ?></label>
				<select name="form_id" id="truitt_files_form_id">
					<option value="0"><?php echo esc_html__( 'All forms', 'ct-forms' ); ?></option>
					<?php foreach ( $forms as $f ) : ?>
						<option value="<?php echo (int) $f->ID; ?>" <?php selected( $form_id, (int) $f->ID ); ?>><?php echo esc_html( $f->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="truitt_files_status" style="margin-left:12px;margin-right:8px;"><?php echo esc_html__( 'Status:', 'ct-forms' ); ?></label>
				<select name="status" id="truitt_files_status">
					<option value="all" <?php selected( $status, 'all' ); ?>><?php echo esc_html__( 'All', 'ct-forms' ); ?></option>
					<option value="ok" <?php selected( $status, 'ok' ); ?>><?php echo esc_html__( 'OK', 'ct-forms' ); ?></option>
					<option value="missing" <?php selected( $status, 'missing' ); ?>><?php echo esc_html__( 'Missing', 'ct-forms' ); ?></option>
				</select>

				<label for="truitt_files_search" style="margin-left:12px;margin-right:8px;"><?php echo esc_html__( 'Filename:', 'ct-forms' ); ?></label>
				<input type="search" id="truitt_files_search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search filename', 'ct-forms' ); ?>" style="min-width:220px;" />

				<button class="button" type="submit" style="margin-left:8px;"><?php echo esc_html__( 'Filter', 'ct-forms' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0;">
				<input type="hidden" name="action" value="ct_forms_bulk_files" />
				<?php wp_nonce_field( 'ct_forms_bulk_files' ); ?>
				<input type="hidden" name="_redirect" value="<?php echo esc_attr( $redirect_back ); ?>" />

				<?php if ( current_user_can( 'ct_forms_manage' ) && ! empty( $rows ) ) : ?>
					<div class="tablenav top" style="margin: 8px 0 10px;">
						<div class="alignleft actions bulkactions">
							<label for="ct_forms_files_bulk_action" class="screen-reader-text"><?php echo esc_html__( 'Select bulk action', 'ct-forms' ); ?></label>
							<select name="bulk_action" id="ct_forms_files_bulk_action">
								<option value="-1"><?php echo esc_html__( 'Bulk actions', 'ct-forms' ); ?></option>
								<option value="delete"><?php echo esc_html__( 'Delete', 'ct-forms' ); ?></option>
							</select>
							<input type="submit" class="button action" value="<?php echo esc_attr__( 'Apply', 'ct-forms' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete selected uploaded files? This cannot be undone.', 'ct-forms' ) ); ?>');" />
						</div>
						<br class="clear" />
					</div>
				<?php endif; ?>

				<table class="widefat fixed striped">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" id="ct-forms-files-cb-all" /></td>
						<th><?php echo esc_html__( 'Uploaded', 'ct-forms' ); ?></th>
						<th><?php echo esc_html__( 'Form', 'ct-forms' ); ?></th>
						<th><?php echo esc_html__( 'Entry', 'ct-forms' ); ?></th>
						<th><?php echo esc_html__( 'Field', 'ct-forms' ); ?></th>
						<th><?php echo esc_html__( 'File', 'ct-forms' ); ?></th>
						<th><?php echo esc_html__( 'Size', 'ct-forms' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'ct-forms' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'ct-forms' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'ct-forms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="10"><?php echo esc_html__( 'No uploaded files found.', 'ct-forms' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php
							$entry_link = admin_url( 'admin.php?page=ct-forms-entries&entry_id=' . (int) $r['entry_id'] );
							$form_title = $r['form_id'] ? get_the_title( (int) $r['form_id'] ) : '';
							$dt         = '';
						if ( ! empty( $r['submitted_at'] ) ) {
							$ts = strtotime( $r['submitted_at'] );
							if ( $ts !== false ) {
								$dt = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
							}
						}

							$download_nonce = wp_create_nonce( 'ct_forms_download_' . (int) $r['entry_id'] . '_' . $r['field_id'] . '_' . (int) $r['idx'] );
							$download_url   = add_query_arg(
								array(
									'action'     => 'ct_forms_download',
									'entry_id'   => (int) $r['entry_id'],
									'field_id'   => $r['field_id'],
									'file_index' => (int) $r['idx'],
									'_wpnonce'   => $download_nonce,
								),
								admin_url( 'admin-post.php' )
							);

							$delete_url = '';
						if ( current_user_can( 'ct_forms_manage' ) ) {
							$delete_nonce  = wp_create_nonce( 'ct_forms_delete_' . (int) $r['entry_id'] . '_' . $r['field_id'] . '_' . (int) $r['idx'] );
							$redirect_back = add_query_arg(
								array(
									'page'    => 'ct-forms-files',
									'form_id' => $form_id,
									'paged'   => $paged,
									'status'  => $status,
									's'       => $search,
									'missing' => $missing_only,
								),
								admin_url( 'admin.php' )
							);
							$delete_url    = add_query_arg(
								array(
									'action'     => 'ct_forms_delete_file',
									'entry_id'   => (int) $r['entry_id'],
									'field_id'   => $r['field_id'],
									'file_index' => (int) $r['idx'],
									'_wpnonce'   => $delete_nonce,
									'_redirect'  => $redirect_back,
								),
								admin_url( 'admin-post.php' )
							);
						}
						?>
						<tr>
							<th scope="row" class="check-column">
								<?php if ( current_user_can( 'ct_forms_manage' ) ) : ?>
									<input type="checkbox" name="selected[]" value="<?php echo esc_attr( (int) $r['entry_id'] . '|' . (string) $r['field_id'] . '|' . (int) $r['idx'] ); ?>" class="ct-forms-files-cb" />
								<?php endif; ?>
							</th>
							<td><?php echo esc_html( $dt ? $dt : '—' ); ?></td>
							<td><?php echo esc_html( $form_title ); ?></td>
							<td><a href="<?php echo esc_url( $entry_link ); ?>"><?php echo esc_html( '#' . (int) $r['entry_id'] ); ?></a></td>
							<td><?php echo esc_html( $r['field_label'] ); ?></td>
							<td><?php echo esc_html( $r['name'] ); ?></td>
							<td><?php echo esc_html( $r['size'] ? size_format( (int) $r['size'], 1 ) : '—' ); ?></td>
							<td><?php echo esc_html( $r['type'] ? $r['type'] : '—' ); ?></td>
							<?php $exists = self::file_exists_safe( $r['path'] ?? '' ); ?>
							<td>
							<?php
							if ( $exists ) :
								?>
								<span class="truitt-file-status truitt-file-status--ok"><?php echo esc_html__( 'OK', 'ct-forms' ); ?></span>
								<?php
else :
	?>
	<span class="truitt-file-status truitt-file-status--missing"><?php echo esc_html__( 'Missing', 'ct-forms' ); ?></span><?php endif; ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( $download_url ); ?>"><?php echo esc_html__( 'Download', 'ct-forms' ); ?></a>
								<?php if ( $delete_url ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('Delete this uploaded file? This cannot be undone.');" style="margin-left:6px;">
										<?php echo esc_html__( 'Delete', 'ct-forms' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			</form>

			<script>
			(function(){
				var all = document.getElementById('ct-forms-files-cb-all');
				if(!all) return;
				all.addEventListener('change', function(){
					var cbs = document.querySelectorAll('.ct-forms-files-cb');
					for (var i=0;i<cbs.length;i++) cbs[i].checked = all.checked;
				});
			})();
			</script>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav" style="margin-top:12px;">
					<div class="tablenav-pages">
						<?php
							$page_links = paginate_links(
								array(
									'base'      => add_query_arg(
										array(
											'paged'   => '%#%',
											'form_id' => $form_id,
											'status'  => $status,
											's'       => $search,
											'missing' => $missing_only,
										),
										$base_url
									),
									'format'    => '',
									'prev_text' => '«',
									'next_text' => '»',
									'total'     => $total_pages,
									'current'   => $paged,
								)
							);
							echo $page_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
				</div>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * page_entry_detail method.
	 *
	 * @param mixed $entry_id Parameter.
	 * @return mixed
	 */
	private static function page_entry_detail( $entry_id ) {
		$entry = CT_Forms_DB::get_entry( $entry_id );
		if ( ! $entry ) {
			echo '<div class="wrap ct-forms-wrap"><p>Entry not found.</p></div>';
			return;
		}

		// Form definition for field labels (used in uploads section).
		$def             = CT_Forms_CPT::get_form_definition( (int) $entry['form_id'] );
		$field_label_map = array();
		if ( is_array( $def ) && ! empty( $def['fields'] ) && is_array( $def['fields'] ) ) {
			foreach ( $def['fields'] as $ff ) {
				if ( ! is_array( $ff ) || empty( $ff['id'] ) ) {
					continue; }
				$field_label_map[ (string) $ff['id'] ] = ! empty( $ff['label'] ) ? (string) $ff['label'] : (string) $ff['id'];
			}
		}

		$form = get_post( (int) $entry['form_id'] );
		/* translators: %d: form ID. */
		$form_name = $form ? $form->post_title : sprintf( __( 'Form #%d', 'ct-forms' ), (int) $entry['form_id'] );

		// Build download links for uploads (supports single and multi-file fields).
		$download_links = array();
		foreach ( (array) ( $entry['files'] ?? array() ) as $fid => $f ) {
			if ( empty( $f ) ) {
				continue;
			}

			// Single file.
			if ( is_array( $f ) && isset( $f['path'] ) ) {
				$idx          = 0;
				$nonce_action = 'ct_forms_download_' . $entry_id . '_' . $fid . '_' . $idx;
				$nonce        = wp_create_nonce( $nonce_action );

				$download_links[ $fid ] = array(
					add_query_arg(
						array(
							'action'     => 'ct_forms_download',
							'entry_id'   => $entry_id,
							'field_id'   => $fid,
							'file_index' => $idx,
							'_wpnonce'   => $nonce,
						),
						admin_url( 'admin-post.php' )
					),
				);
				continue;
			}

			// Multiple files.
			if ( is_array( $f ) ) {
				$download_links[ $fid ] = array();

				foreach ( $f as $idx => $fi ) {
					if ( empty( $fi ) || ! is_array( $fi ) ) {
						continue;
					}

					$nonce_action = 'ct_forms_download_' . $entry_id . '_' . $fid . '_' . (int) $idx;
					$nonce        = wp_create_nonce( $nonce_action );

					$download_links[ $fid ][] = add_query_arg(
						array(
							'action'     => 'ct_forms_download',
							'entry_id'   => $entry_id,
							'field_id'   => $fid,
							'file_index' => (int) $idx,
							'_wpnonce'   => $nonce,
						),
						admin_url( 'admin-post.php' )
					);
				}
			}
		}

		$submitted_raw  = $entry['submitted_at'] ?? ( $entry['created_at'] ?? '' );
		$submitted_html = self::format_submitted_cell_html( $submitted_raw );

		$allowed_statuses = array( 'new', 'reviewed', 'follow_up', 'spam', 'archived' );
		$status           = isset( $entry['status'] ) ? sanitize_key( (string) $entry['status'] ) : 'new';
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'new';
		}
		$status_class = 'truitt-entry-status';
		if ( $status ) {
			$status_class .= ' truitt-entry-status--' . sanitize_html_class( $status );
		}

		?>
	<div class="wrap ct-forms-wrap">
		<?php if ( ! empty( $_GET['ct_forms_file_deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'File deleted.', 'ct-forms' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['ct_forms_status_updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Status updated.', 'ct-forms' ); ?></p></div>
		<?php endif; ?>
		<h1><?php echo esc_html( 'Entry #' . (int) $entry_id ); ?></h1>

		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ct-forms-entries&form_id=' . (int) $entry['form_id'] ) ); ?>">&larr; Back to Entries</a>
		</p>

		<div class="ct-forms-admin-card">
			<p><strong>Form:</strong> <?php echo esc_html( $form_name ); ?></p>
			<p><strong>Submitted:</strong> <?php echo $submitted_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<p>
				<strong>Status:</strong>
				<span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( self::get_status_label( $status ) ); ?></span>
			</p>
			<?php if ( current_user_can( 'ct_forms_manage' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 6px;">
					<input type="hidden" name="action" value="ct_forms_update_entry_status" />
					<input type="hidden" name="entry_id" value="<?php echo esc_attr( (int) $entry_id ); ?>" />
					<input type="hidden" name="_redirect" value="<?php echo esc_attr( admin_url( 'admin.php?page=ct-forms-entries&entry_id=' . (int) $entry_id ) ); ?>" />
					<?php wp_nonce_field( 'ct_forms_update_entry_status_' . (int) $entry_id ); ?>

					<label for="truitt-entry-status" class="screen-reader-text"><?php esc_html_e( 'Change status', 'ct-forms' ); ?></label>
					<select name="status" id="truitt-entry-status">
						<?php
						foreach ( $allowed_statuses as $opt ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $opt ),
								selected( $status, $opt, false ),
								esc_html( self::get_status_label( $opt ) )
							);
						}
						?>
					</select>
					<button type="submit" class="button" style="margin-left:6px;"><?php esc_html_e( 'Update', 'ct-forms' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( ! empty( $entry['page_url'] ) ) : ?>
				<p><strong>Page:</strong> <a href="<?php echo esc_url( $entry['page_url'] ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $entry['page_url'] ); ?></a></p>
			<?php endif; ?>
		</div>

		<div class="ct-forms-admin-card">
			<h2>Fields</h2>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( (array) ( $entry['data'] ?? array() ) as $k => $v ) : ?>
					<tr>
						<th style="width:220px;"><?php echo esc_html( $k ); ?></th>
						<td>
							<?php
							if ( is_array( $v ) ) {
								echo esc_html( implode( ', ', $v ) );
							} else {
								echo nl2br( esc_html( (string) $v ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( ! empty( $entry['files'] ) ) : ?>
			<div class="ct-forms-admin-card">
				<h2>Files</h2>
				<table class="widefat striped">
					<thead>
						<tr><th>Field</th><th>File</th><th>Size</th><th>Type</th><th>Status</th><th>Actions</th></tr>
					</thead>
					<tbody>
					<?php foreach ( (array) $entry['files'] as $fid => $f ) : ?>
						<?php
						$files_to_show = array();
						if ( is_array( $f ) && isset( $f['path'] ) ) {
							$files_to_show[] = $f;
						} elseif ( is_array( $f ) ) {
							foreach ( $f as $fi ) {
								if ( is_array( $fi ) ) {
									$files_to_show[] = $fi; }
							}
						}

						$links = $download_links[ $fid ] ?? array();
						$links = is_array( $links ) ? $links : array( $links );

						$field_label = isset( $field_label_map[ (string) $fid ] ) ? (string) $field_label_map[ (string) $fid ] : (string) $fid;

						$redirect_back = add_query_arg(
							array(
								'page'     => 'ct-forms-entries',
								'entry_id' => (int) $entry_id,
							),
							admin_url( 'admin.php' )
						);
						?>
						<?php foreach ( $files_to_show as $i => $fi ) : ?>
							<tr>
								<td><?php echo esc_html( $field_label ); ?></td>
								<td><?php echo esc_html( isset( $fi['name'] ) ? (string) $fi['name'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $fi['size'] ) ? size_format( (int) $fi['size'] ) : '' ); ?></td>
								<td><?php echo esc_html( isset( $fi['type'] ) && $fi['type'] ? (string) $fi['type'] : '—' ); ?></td>
								<?php $exists = self::file_exists_safe( $fi['path'] ?? '' ); ?>
								<td>
								<?php
								if ( $exists ) :
									?>
									<span class="truitt-file-status truitt-file-status--ok"><?php echo esc_html__( 'OK', 'ct-forms' ); ?></span>
									<?php
else :
	?>
	<span class="truitt-file-status truitt-file-status--missing"><?php echo esc_html__( 'Missing', 'ct-forms' ); ?></span><?php endif; ?></td>
								<td>
									<?php if ( ! empty( $links[ $i ] ) ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $links[ $i ] ); ?>"><?php echo esc_html__( 'Download', 'ct-forms' ); ?></a>
									<?php else : ?>
										<span class="button button-small" style="opacity:.5;cursor:default;" aria-disabled="true"><?php echo esc_html__( 'Download', 'ct-forms' ); ?></span>
									<?php endif; ?>
									<?php if ( current_user_can( 'ct_forms_manage' ) ) : ?>
										<?php
											$delete_nonce = wp_create_nonce( 'ct_forms_delete_' . (int) $entry_id . '_' . (string) $fid . '_' . (int) $i );
											$delete_url   = add_query_arg(
												array(
													'action'   => 'ct_forms_delete_file',
													'entry_id' => (int) $entry_id,
													'field_id' => (string) $fid,
													'file_index' => (int) $i,
													'_wpnonce' => $delete_nonce,
													'_redirect' => $redirect_back,
												),
												admin_url( 'admin-post.php' )
											);
										?>
										<a class="button button-small" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('Delete this uploaded file? This cannot be undone.');" style="margin-left:6px;">
											<?php echo esc_html__( 'Delete', 'ct-forms' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<div class="ct-forms-admin-card">
			<h2>Mail Log</h2>
			<?php echo self::render_mail_log_html( $entry['mail_log'] ?? array() ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ct_forms_resend_admin_' . $entry_id ); ?>
				<input type="hidden" name="action" value="ct_forms_resend_admin">
				<input type="hidden" name="entry_id" value="<?php echo (int) $entry_id; ?>">
				<button class="button" type="submit">Resend admin notification</button>
			</form>
		</div>
	</div>
		<?php
	}

	/**
	 * resend_admin method.
	 *
	 * @return mixed
	 */
	public static function resend_admin() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' ); }

		$entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;
		if ( $entry_id <= 0 ) {
			wp_die( 'Invalid entry' ); }

		check_admin_referer( 'ct_forms_resend_admin_' . $entry_id );

		$entry = CT_Forms_DB::get_entry( $entry_id );
		if ( ! $entry ) {
			wp_die( 'Entry not found' ); }

		$form_settings = CT_Forms_CPT::get_form_settings( $entry['form_id'] );

		// Send only admin email; do not resend autoresponder
		$post      = get_post( $entry['form_id'] );
		$form_name = $post ? $post->post_title : 'Form';

		$tokens     = array(
			'{form_name}'    => $form_name,
			'{entry_id}'     => (string) $entry_id,
			'{submitted_at}' => $entry['submitted_at'],
		);
		$all_fields = '';
		$data       = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
		foreach ( (array) $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = implode( ', ', $v ); }
			$all_fields                    .= $k . ': ' . $v . "\n";
			$tokens[ '{field:' . $k . '}' ] = (string) $v;
		}
		$tokens['{all_fields}'] = trim( $all_fields );

		$subject = strtr( (string) $form_settings['email_subject'], $tokens );
		$body    = strtr( (string) $form_settings['email_body'], $tokens );

		$headers = array();

		// Reply-To best practice: route replies to the submitter (if present)
		$reply_to = '';
		if ( ! empty( $data['email'] ) && is_email( $data['email'] ) ) {
			$reply_to = $data['email'];
		}
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		$from_name  = sanitize_text_field( (string) $form_settings['from_name'] );
		$from_email = sanitize_email( (string) $form_settings['from_email'] );
		if ( $from_email ) {
			$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
		}

		$sent = wp_mail( (string) $form_settings['to_email'], $subject, $body, $headers );

		$mail_log                 = (array) $entry['mail_log'];
		$mail_log['resend_admin'] = array(
			'sent_at' => current_time( 'mysql' ),
			'sent'    => (bool) $sent,
		);
		CT_Forms_DB::update_entry_mail_log( $entry_id, $mail_log );

		wp_safe_redirect( admin_url( 'admin.php?page=ct-forms-entries&entry_id=' . (int) $entry_id ) );
		exit;
	}

	/**
	 * page_settings method.
	 *
	 * @return mixed
	 */
	public static function page_settings() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' ); }
		$s = self::get_settings();
		?>
		<div class="wrap ct-forms-wrap">
			<h1><?php esc_html_e( 'CT Forms Settings', 'ct-forms' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'ct_forms_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Allowed file extensions</th>
						<td>
							<input type="text" class="regular-text" name="ct_forms_settings[allowed_mimes]" value="<?php echo esc_attr( $s['allowed_mimes'] ); ?>">
							<p class="description">Comma-separated extensions, e.g. jpg,jpeg,png,pdf</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Max upload size (MB)</th>
						<td><input type="number" min="1" max="100" name="ct_forms_settings[max_file_mb]" value="<?php echo esc_attr( $s['max_file_mb'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Rate limit</th>
						<td>
							<input type="number" min="0" max="1000" name="ct_forms_settings[rate_limit]" value="<?php echo esc_attr( $s['rate_limit'] ); ?>">
							<span>submissions per</span>
							<input type="number" min="1" max="1440" name="ct_forms_settings[rate_window_minutes]" value="<?php echo esc_attr( $s['rate_window_minutes'] ); ?>">
							<span>minutes per IP per form</span>
						</td>
					</tr>
					<tr>
						<th scope="row">Store IP address</th>
						<td><label><input type="checkbox" name="ct_forms_settings[store_ip]" value="1" <?php checked( ! empty( $s['store_ip'] ) ); ?>> Enable</label></td>
					</tr>
					<tr>
						<th scope="row">Store user agent</th>
						<td><label><input type="checkbox" name="ct_forms_settings[store_user_agent]" value="1" <?php checked( ! empty( $s['store_user_agent'] ) ); ?>> Enable</label></td>
					</tr>
					<tr>
						<th scope="row">Retention (days)</th>
						<td>
							<input type="number" min="0" max="3650" name="ct_forms_settings[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>">
							<p class="description">0 keeps entries indefinitely. If you set a number, old entries will be deleted daily.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">reCAPTCHA</th>
						<td>
							<p class="description">Choose the reCAPTCHA type you created in Google reCAPTCHA. Keys must match the selected type.</p>

							<p style="margin:12px 0 6px;"><label for="ct_forms_recaptcha_type">Type</label></p>
							<select id="ct_forms_recaptcha_type" name="ct_forms_settings[recaptcha_type]">
								<option value="disabled" <?php selected( $s['recaptcha_type'], 'disabled' ); ?>>Disabled</option>
								<option value="v2_checkbox" <?php selected( $s['recaptcha_type'], 'v2_checkbox' ); ?>>reCAPTCHA v2 - checkbox</option>
								<option value="v2_invisible" <?php selected( $s['recaptcha_type'], 'v2_invisible' ); ?>>reCAPTCHA v2 - invisible</option>
								<option value="v3" <?php selected( $s['recaptcha_type'], 'v3' ); ?>>reCAPTCHA v3 (score-based)</option>
							</select>

							<p style="margin:12px 0 6px;"><label for="ct_forms_recaptcha_site_key">Site key</label></p>
							<input id="ct_forms_recaptcha_site_key" type="text" class="regular-text" name="ct_forms_settings[recaptcha_site_key]" value="<?php echo esc_attr( $s['recaptcha_site_key'] ); ?>">

							<p style="margin:12px 0 6px;"><label for="ct_forms_recaptcha_secret_key">Secret key</label></p>
							<input id="ct_forms_recaptcha_secret_key" type="text" class="regular-text" name="ct_forms_settings[recaptcha_secret_key]" value="<?php echo esc_attr( $s['recaptcha_secret_key'] ); ?>">

							<div style="margin-top:12px;padding:10px 12px;border:1px solid #ccd0d4;background:#fff;">
								<p style="margin:0 0 8px;"><strong>v3 options</strong></p>
								<p style="margin:8px 0 6px;"><label for="ct_forms_recaptcha_v3_action">Action name</label></p>
								<input id="ct_forms_recaptcha_v3_action" type="text" class="regular-text" name="ct_forms_settings[recaptcha_v3_action]" value="<?php echo esc_attr( $s['recaptcha_v3_action'] ); ?>">
								<p class="description" style="margin-top:6px;">Used only for v3. Must match the action returned by Google for this token.</p>

								<p style="margin:12px 0 6px;"><label for="ct_forms_recaptcha_v3_threshold">Minimum score (0.0 - 1.0)</label></p>
								<input id="ct_forms_recaptcha_v3_threshold" type="number" step="0.1" min="0" max="1" name="ct_forms_settings[recaptcha_v3_threshold]" value="<?php echo esc_attr( $s['recaptcha_v3_threshold'] ); ?>">
								<p class="description" style="margin-top:6px;">Submissions with a lower score will be rejected.</p>
							</div>
						</td>
					</tr>

<tr>
						<th scope="row">Delete data on uninstall</th>
						<td>
							<label><input type="checkbox" name="ct_forms_settings[delete_on_uninstall]" value="1" <?php checked( ! empty( $s['delete_on_uninstall'] ) ); ?>> Enable</label>
							<p class="description">If enabled, deleting the plugin from WordPress will also delete plugin database tables, options, and uploaded files. Recommended only on test sites.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">From policy</th>
						<td>
							<select name="ct_forms_settings[from_mode]">
								<option value="default" <?php selected( $s['from_mode'], 'default' ); ?>>Use SMTP/site defaults (recommended)</option>
								<option value="site" <?php selected( $s['from_mode'], 'site' ); ?>>Force From = site admin email</option>                            </select>
							<p class="description">Best practice is to leave From controlled by your SMTP plugin and use Reply-To for the submitter. If mail fails when you set a custom From, switch back to the recommended option.</p>
							<label style="display:block;margin-top:8px;">
								<input type="checkbox" name="ct_forms_settings[enforce_from_domain]" value="1" <?php checked( ! empty( $s['enforce_from_domain'] ) ); ?>>
								Only allow custom From addresses on this site’s domain (recommended)
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * page_support method.
	 *
	 * @return mixed
	 */
	public static function page_support() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' );
		}

		$support_email = apply_filters( 'ct_forms_support_email', 'help@christruitt.com' );
		$tip_jar_url   = apply_filters( 'ct_forms_tip_jar_url', 'https://www.christruitt.com/tip-jar' );
		$bmac_url      = apply_filters( 'ct_forms_bmac_url', 'https://buymeacoffee.com/christruitt' );
		$paypal_url    = apply_filters( 'ct_forms_paypal_url', 'https://www.paypal.com/paypalme/ctruit01' );

		$sent = isset( $_GET['ct_support_sent'] ) ? sanitize_key( (string) $_GET['ct_support_sent'] ) : '';
		$msg  = isset( $_GET['ct_support_msg'] ) ? sanitize_text_field( (string) $_GET['ct_support_msg'] ) : '';

		// Build diagnostics text.
		$theme      = wp_get_theme();
		$theme_line = $theme ? ( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ) : '';

		$plugins = array();
		if ( function_exists( 'get_plugins' ) ) {
			$active = (array) get_option( 'active_plugins', array() );
			$all    = get_plugins();
			foreach ( $active as $p ) {
				if ( isset( $all[ $p ] ) ) {
					$plugins[] = $all[ $p ]['Name'] . ' ' . $all[ $p ]['Version'];
				}
			}
		}

		$diag   = array();
		$diag[] = 'CT Forms version: ' . CT_FORMS_VERSION;
		$diag[] = 'Site: ' . home_url();
		$diag[] = 'WP: ' . get_bloginfo( 'version' );
		$diag[] = 'PHP: ' . PHP_VERSION;
		$diag[] = 'Theme: ' . $theme_line;
		$diag[] = 'Active plugins:';
		if ( $plugins ) {
			foreach ( $plugins as $p ) {
				$diag[] = ' - ' . $p;
			}
		} else {
			$diag[] = ' - (unable to list plugins)';
		}
		$diag_text = implode( "\n", $diag );

		?>
		<div class="wrap ct-forms-wrap">
			<h1><?php esc_html_e( 'Support', 'ct-forms' ); ?></h1>

			<?php if ( '1' === $sent ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ? $msg : __( 'Support request sent.', 'ct-forms' ) ); ?></p></div>
			<?php elseif ( '0' === $sent ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $msg ? $msg : __( 'Support request could not be sent.', 'ct-forms' ) ); ?></p></div>
			<?php endif; ?>

			<div class="ct-forms-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:1200px;">

				<div class="postbox" style="padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Get help', 'ct-forms' ); ?></h2>
					<p>
					<?php
						/* translators: %s: support email address. */
						echo esc_html( sprintf( __( 'Email %s or send a request below. Include diagnostics for fastest help.', 'ct-forms' ), $support_email ) );
					?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ct_forms_send_support' ); ?>
						<input type="hidden" name="action" value="ct_forms_send_support" />

						<table class="form-table" role="presentation" style="margin-top:0;">
							<tr>
								<th scope="row"><label for="ct_support_name"><?php esc_html_e( 'Name', 'ct-forms' ); ?></label></th>
								<td><input type="text" class="regular-text" id="ct_support_name" name="ct_support_name" value="" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="ct_support_email"><?php esc_html_e( 'Email', 'ct-forms' ); ?></label></th>
								<td><input type="email" class="regular-text" id="ct_support_email" name="ct_support_email" value="" placeholder="you@example.com" required /></td>
							</tr>
							<tr>
								<th scope="row"><label for="ct_support_subject"><?php esc_html_e( 'Subject', 'ct-forms' ); ?></label></th>
								<td><input type="text" class="regular-text" id="ct_support_subject" name="ct_support_subject" value="" placeholder="CT Forms question" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="ct_support_message"><?php esc_html_e( 'Message', 'ct-forms' ); ?></label></th>
								<td><textarea id="ct_support_message" name="ct_support_message" rows="6" style="width:100%;max-width:560px;" required></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="ct_support_diag"><?php esc_html_e( 'Diagnostics', 'ct-forms' ); ?></label></th>
								<td>
									<textarea id="ct_support_diag" name="ct_support_diag" rows="8" style="width:100%;max-width:560px;" readonly><?php echo esc_textarea( $diag_text ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Copy and paste this into your message or keep it as-is.', 'ct-forms' ); ?></p>
								</td>
							</tr>
						</table>

						<?php submit_button( __( 'Send support request', 'ct-forms' ) ); ?>
					</form>
				</div>

				<div class="postbox" style="padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Support development', 'ct-forms' ); ?></h2>
					<p><?php esc_html_e( 'If CT Forms saves you time, consider supporting ongoing development.', 'ct-forms' ); ?></p>

					<p style="display:flex;gap:10px;flex-wrap:wrap;">
						<a class="button button-primary" href="<?php echo esc_url( $tip_jar_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Tip Jar', 'ct-forms' ); ?></a>
						<a class="button" href="<?php echo esc_url( $bmac_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Buy Me a Coffee', 'ct-forms' ); ?></a>
						<a class="button" href="<?php echo esc_url( $paypal_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'PayPal', 'ct-forms' ); ?></a>
					</p>

					<hr />
					<h3><?php esc_html_e( 'Links', 'ct-forms' ); ?></h3>
					<ul style="margin:0 0 0 18px;">
						<li><a href="https://christruitt.com" target="_blank" rel="noopener">christruitt.com</a></li>
						<li><a href="mailto:<?php echo esc_attr( $support_email ); ?>"><?php echo esc_html( $support_email ); ?></a></li>
					</ul>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * handle_send_support method.
	 *
	 * @return mixed
	 */
	public static function handle_send_support() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( 'Not allowed' );
		}

		check_admin_referer( 'ct_forms_send_support' );

		$support_email = apply_filters( 'ct_forms_support_email', 'help@christruitt.com' );

		$name    = isset( $_POST['ct_support_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ct_support_name'] ) ) : '';
		$email   = isset( $_POST['ct_support_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['ct_support_email'] ) ) : '';
		$subject = isset( $_POST['ct_support_subject'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ct_support_subject'] ) ) : '';
		$message = isset( $_POST['ct_support_message'] ) ? wp_kses_post( wp_unslash( (string) $_POST['ct_support_message'] ) ) : '';
		$diag    = isset( $_POST['ct_support_diag'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['ct_support_diag'] ) ) : '';

		if ( ! is_email( $email ) || '' === trim( $message ) ) {
			$url = add_query_arg(
				array(
					'page'            => 'ct-forms-support',
					'ct_support_sent' => '0',
					'ct_support_msg'  => rawurlencode( __( 'Please enter a valid email and message.', 'ct-forms' ) ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $url );
			exit;
		}

		$subject_line = $subject ? $subject : 'CT Forms Support Request';
		$subject_line = 'CT Forms: ' . $subject_line;

		$lines   = array();
		$lines[] = 'Name: ' . $name;
		$lines[] = 'Email: ' . $email;
		$lines[] = 'Site: ' . home_url();
		$lines[] = 'Time: ' . current_time( 'mysql' );
		$lines[] = '';
		$lines[] = 'Message:';
		$lines[] = wp_strip_all_tags( $message );
		$lines[] = '';
		$lines[] = 'Diagnostics:';
		$lines[] = $diag;

		$headers   = array();
		$headers[] = 'Reply-To: ' . $email;

		$sent = wp_mail( $support_email, $subject_line, implode( "\n", $lines ), $headers );

		$url = add_query_arg(
			array(
				'page'            => 'ct-forms-support',
				'ct_support_sent' => $sent ? '1' : '0',
				'ct_support_msg'  => rawurlencode( $sent ? __( 'Support request sent. Check your inbox for a reply.', 'ct-forms' ) : __( 'Support request could not be sent. Try emailing help@christruitt.com.', 'ct-forms' ) ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * run_retention_cleanup method.
	 *
	 * @return mixed
	 */
	public static function run_retention_cleanup() {
		$s    = self::get_settings();
		$days = isset( $s['retention_days'] ) ? (int) $s['retention_days'] : 0;
		if ( $days > 0 ) {
			CT_Forms_DB::delete_entries_older_than_days( $days );
		}
	}


	/**
	 * render_entries_simple_table method.
	 *
	 * @param mixed $form_id Parameter.
	 * @return mixed
	 */
	private static function render_entries_simple_table( $form_id = 0 ) {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ct-forms' ) );
		}

		global $wpdb;

		$entries_table = CT_Forms_DB::entries_table();

		// Detect schema (supports legacy installs).
		$pk_col      = CT_Forms_DB::entries_pk_column();
		$created_col = CT_Forms_DB::entries_created_column();
		$ip_col      = CT_Forms_DB::entries_ip_column();
		$page_col    = CT_Forms_DB::entries_page_url_column();
		$data_col    = CT_Forms_DB::entries_data_column();

		$entries_cols = CT_Forms_DB::entries_columns();
		$ua_col       = in_array( 'user_agent', $entries_cols, true ) ? 'user_agent' : ( in_array( 'ua', $entries_cols, true ) ? 'ua' : '' );

		// Status column detection (supports legacy installs that used state/entry_status).
		$status_col       = in_array( 'status', $entries_cols, true ) ? 'status' : ( in_array( 'state', $entries_cols, true ) ? 'state' : ( in_array( 'entry_status', $entries_cols, true ) ? 'entry_status' : '' ) );
		$allowed_statuses = array( 'new', 'reviewed', 'follow_up', 'spam', 'archived' );

		// Status filter (optional).
		$status_filter = '';
		if ( $status_col ) {
			$status_filter = isset( $_GET['status'] ) ? sanitize_key( (string) wp_unslash( $_GET['status'] ) ) : '';
			if ( $status_filter !== '' && ! in_array( $status_filter, $allowed_statuses, true ) ) {
				$status_filter = '';
			}
		}

		$select_cols   = array();
		$select_cols[] = "{$pk_col} AS id";
		$select_cols[] = 'form_id';
		if ( $created_col ) {
			$select_cols[] = "{$created_col} AS created_at"; }
		if ( $ip_col ) {
			$select_cols[] = "{$ip_col} AS ip_address"; }
		if ( $page_col ) {
			$select_cols[] = "{$page_col} AS page_url"; }
		if ( $data_col ) {
			$select_cols[] = "{$data_col} AS data_json"; }
		// Attachments (files) column detection.
		if ( in_array( 'files', $entries_cols, true ) ) {
			$select_cols[] = 'files AS files_json'; }
		if ( $status_col ) {
			$select_cols[] = "{$status_col} AS status"; }

		$select_cols_sql = implode( ', ', $select_cols );

		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		$where_parts = array();
		$args        = array();

		if ( $form_id ) {
			$where_parts[] = 'form_id = %d';
			$args[]        = (int) $form_id;
		}

		if ( $status_col && $status_filter !== '' ) {
			$where_parts[] = "{$status_col} = %s";
			$args[]        = $status_filter;
		}

		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$search_cols = array();
			if ( $data_col ) {
				$search_cols[] = "{$data_col} LIKE %s"; }
			if ( $ip_col ) {
				$search_cols[] = "{$ip_col} LIKE %s"; }
			if ( $page_col ) {
				$search_cols[] = "{$page_col} LIKE %s"; }
			if ( $ua_col ) {
				$search_cols[] = "{$ua_col} LIKE %s"; }

			if ( ! empty( $search_cols ) ) {
				$where_parts[] = '(' . implode( ' OR ', $search_cols ) . ')';
				// Add one arg per searchable column.
				for ( $i = 0; $i < count( $search_cols ); $i++ ) {
					$args[] = $like;
				}
			}
		}

		$where_sql = $where_parts ? ( 'WHERE ' . implode( ' AND ', $where_parts ) ) : '';

		// Count total rows.
		$count_sql = 'SELECT COUNT(*) FROM ' . $entries_table . ' ' . $where_sql;
		if ( ! empty( $args ) ) {
			$count_sql = $wpdb->prepare( $count_sql, ...$args );
		}
		$total_items = (int) $wpdb->get_var( $count_sql );

		// Fetch page of results.
		$sql         = 'SELECT ' . $select_cols_sql . ' FROM ' . $entries_table . ' ' . $where_sql . ' ORDER BY ' . $pk_col . ' DESC LIMIT %d OFFSET %d';
		$args_page   = $args;
		$args_page[] = (int) $per_page;
		$args_page[] = (int) $offset;

		$sql  = $wpdb->prepare( $sql, ...$args_page );
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		// Bulk action notice.
		if ( ! empty( $_GET['ct_forms_bulk'] ) && isset( $_GET['ct_forms_bulk_count'] ) ) {
			$bulk_action = sanitize_key( (string) $_GET['ct_forms_bulk'] );
			$bulk_count  = (int) $_GET['ct_forms_bulk_count'];
			if ( $bulk_count > 0 ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				/* translators: %d: number of entries updated. */
				echo esc_html( sprintf( __( 'Bulk action completed: %d item(s) updated.', 'ct-forms' ), $bulk_count ) );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-info is-dismissible"><p>';
				echo esc_html__( 'Bulk action completed. No items were changed.', 'ct-forms' );
				echo '</p></div>';
			}
		}

		// Status views (All / New / Follow-up / Reviewed / Spam / Archived).
		$base_args_for_views = array( 'page' => 'ct-forms-entries' );
		if ( $form_id ) {
			$base_args_for_views['form_id'] = (int) $form_id; }

		$counts = array();
		if ( $status_col ) {
			$count_where = array();
			$count_args  = array();
			if ( $form_id ) {
				$count_where[] = 'form_id = %d';
				$count_args[]  = (int) $form_id;
			}
			$count_where_sql = $count_where ? ( 'WHERE ' . implode( ' AND ', $count_where ) ) : '';
			$count_sql       = "SELECT {$status_col} AS st, COUNT(*) AS c FROM {$entries_table} {$count_where_sql} GROUP BY {$status_col}";
			if ( ! empty( $count_args ) ) {
				$count_sql = $wpdb->prepare( $count_sql, ...$count_args );
			}
			$count_rows = $wpdb->get_results( $count_sql, ARRAY_A );
			if ( is_array( $count_rows ) ) {
				foreach ( $count_rows as $cr ) {
					$st = isset( $cr['st'] ) ? sanitize_key( (string) $cr['st'] ) : '';
					$c  = isset( $cr['c'] ) ? (int) $cr['c'] : 0;
					if ( $st !== '' ) {
						$counts[ $st ] = $c; }
				}
			}
		}

		$all_count = 0;
		foreach ( $counts as $c ) {
			$all_count += (int) $c; }

		$views = array(
			''          => __( 'All', 'ct-forms' ),
			'new'       => __( 'New', 'ct-forms' ),
			'follow_up' => __( 'Follow-up', 'ct-forms' ),
			'reviewed'  => __( 'Reviewed', 'ct-forms' ),
			'spam'      => __( 'Spam', 'ct-forms' ),
			'archived'  => __( 'Archived', 'ct-forms' ),
		);

		echo '<ul class="subsubsub" style="margin: 8px 0 12px;">';
		$i = 0;
		foreach ( $views as $key => $label ) {
			$is_current = ( $key === '' && $status_filter === '' ) || ( $key !== '' && $status_filter === $key );
			$args_view  = $base_args_for_views;
			if ( $key !== '' ) {
				$args_view['status'] = $key; }
			// Do not carry over search term into view counts by default.
			$url = add_query_arg( $args_view, admin_url( 'admin.php' ) );

			$count = ( $key === '' ) ? $all_count : ( $counts[ $key ] ?? 0 );
			$text  = sprintf( '%s <span class="count">(%d)</span>', esc_html( $label ), (int) $count );

			if ( $is_current ) {
				$text = '<strong>' . $text . '</strong>';
			} else {
				$text = '<a href="' . esc_url( $url ) . '">' . $text . '</a>';
			}

			echo '<li class="' . esc_attr( $key === '' ? 'all' : $key ) . '">' . $text . '</li>';
			++$i;
			if ( $i < count( $views ) ) {
				echo ' | '; }
		}
		echo '</ul>';

		// Search box.
		echo '<form method="get" style="margin: 12px 0;">';
		echo '<input type="hidden" name="page" value="ct-forms-entries" />';
		if ( $form_id ) {
			echo '<input type="hidden" name="form_id" value="' . esc_attr( (string) $form_id ) . '" />';
		}
		echo '<p class="search-box">';
		echo '<label class="screen-reader-text" for="ct-forms-entry-search-input">' . esc_html__( 'Search entries', 'ct-forms' ) . '</label>';
		echo '<input type="search" id="ct-forms-entry-search-input" name="s" value="' . esc_attr( $search ) . '" />';
		echo '<select name="status" style="margin-left:8px; max-width:180px;">';
		echo '<option value="">' . esc_html__( 'All statuses', 'ct-forms' ) . '</option>';
		foreach ( $allowed_statuses as $st ) {
			$label = self::get_status_label( $st );
			echo '<option value="' . esc_attr( $st ) . '"' . selected( $status_filter, $st, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		submit_button( esc_html__( 'Search', 'ct-forms' ), '', '', false, array( 'id' => 'search-submit' ) );
		echo '</p>';
		echo '</form>';

		if ( ! empty( $wpdb->last_error ) ) {
			echo '<div class="notice notice-error"><p><code>' . esc_html( $wpdb->last_error ) . '</code></p></div>';
		}

		// Bulk actions + table.
		$redirect_args = array( 'page' => 'ct-forms-entries' );
		if ( $form_id ) {
			$redirect_args['form_id'] = (int) $form_id; }
		if ( $search !== '' ) {
			$redirect_args['s'] = $search; }
		if ( $status_filter !== '' ) {
			$redirect_args['status'] = $status_filter; }
		if ( $paged > 1 ) {
			$redirect_args['paged'] = (int) $paged; }
		$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ct_forms_bulk_entries' );
		echo '<input type="hidden" name="action" value="ct_forms_bulk_entries" />';
		echo '<input type="hidden" name="_redirect" value="' . esc_attr( $redirect_url ) . '" />';

		echo '<div class="tablenav top" style="margin: 10px 0; display:flex; gap:8px; align-items:center;">';
		echo '<select name="bulk_action" id="ct-forms-bulk-action">';
		echo '<option value="">' . esc_html__( 'Bulk actions', 'ct-forms' ) . '</option>';
		echo '<option value="mark_new">' . esc_html__( 'Mark as unread (New)', 'ct-forms' ) . '</option>';
		echo '<option value="mark_reviewed">' . esc_html__( 'Mark reviewed', 'ct-forms' ) . '</option>';
		echo '<option value="mark_follow_up">' . esc_html__( 'Mark follow-up', 'ct-forms' ) . '</option>';
		echo '<option value="mark_spam">' . esc_html__( 'Mark spam', 'ct-forms' ) . '</option>';
		echo '<option value="archive">' . esc_html__( 'Archive', 'ct-forms' ) . '</option>';
		echo '<option value="resend_admin">' . esc_html__( 'Resend admin notification', 'ct-forms' ) . '</option>';
		echo '<option value="delete">' . esc_html__( 'Delete', 'ct-forms' ) . '</option>';
		echo '</select>';
		submit_button( esc_html__( 'Apply', 'ct-forms' ), 'secondary', 'ct_forms_bulk_apply', false, array( 'id' => 'ct-forms-bulk-apply' ) );
		echo '</div>';

		echo '<table class="widefat striped" style="margin-top: 8px;">';
		echo '<thead><tr>';
		echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="ct-forms-select-all" /></td>';
		echo '<th style="width:80px;">' . esc_html__( 'ID', 'ct-forms' ) . '</th>';
		echo '<th style="width:220px;">' . esc_html__( 'Form', 'ct-forms' ) . '</th>';
		echo '<th style="width:170px;">' . esc_html__( 'Submitted', 'ct-forms' ) . '</th>';
		echo '<th style="width:120px;">' . esc_html__( 'Status', 'ct-forms' ) . '</th>';
		echo '<th style="width:140px;">' . esc_html__( 'IP', 'ct-forms' ) . '</th>';
		echo '<th style="width:120px;">' . esc_html__( 'Attachments', 'ct-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Summary', 'ct-forms' ) . '</th>';
		echo '<th style="width:150px;">' . esc_html__( 'Actions', 'ct-forms' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		if ( empty( $rows ) ) {
			echo '<tr class="no-items"><td colspan="9">' . esc_html__( 'No entries found.', 'ct-forms' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$id  = isset( $row['id'] ) ? (int) $row['id'] : 0;
				$fid = isset( $row['form_id'] ) ? (int) $row['form_id'] : 0;

				$form_title = $fid ? get_the_title( $fid ) : '';
				if ( ! $form_title ) {
					if ( $fid ) {
						/* translators: %d: form ID. */
						$form_title = sprintf( __( 'Form #%d', 'ct-forms' ), $fid );
					} else {
						$form_title = '–';
					}
				}
				$submitted_html = self::format_submitted_cell_html( $row['created_at'] ?? '' );

				$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : 'new';
				if ( $status === '' || ! in_array( $status, $allowed_statuses, true ) ) {
					$status = 'new'; }
				$status_label = self::get_status_label( $status );
				$status_html  = '<span class="truitt-entry-status truitt-entry-status--' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>';

				$ip = isset( $row['ip_address'] ) ? (string) $row['ip_address'] : '';

				// Attachments indicator.
				$has_files = false;
				$files_arr = array();
				$files_raw = isset( $row['files_json'] ) ? $row['files_json'] : '';

				if ( is_string( $files_raw ) ) {
					$trim = trim( $files_raw );
					if ( $trim !== '' && $trim !== 'null' && $trim !== '[]' ) {
						$decoded = json_decode( $trim, true );
						if ( is_array( $decoded ) ) {
							$files_arr = $decoded;
							$has_files = ! empty( $files_arr );
						} else {
							// If it isn't valid JSON but something is present, treat as having files.
							$has_files = true;
						}
					}
				} elseif ( is_array( $files_raw ) ) {
					$files_arr = $files_raw;
					$has_files = ! empty( $files_arr );
				}

				$attachments_html = '–';

				if ( $has_files ) {
					$clip = '<span aria-label="Has attachments" title="Has attachments">📎</span>';

					// Default: link to the entry detail view.
					$link_url = admin_url( 'admin.php?page=ct-forms-entries&entry_id=' . (int) $id );

					// If we can identify at least one specific file, link directly to the protected download endpoint.
					if ( ! empty( $files_arr ) && is_array( $files_arr ) ) {
						$first_field_id = '';
						$first_idx      = 0;

						foreach ( $files_arr as $field_id_key => $file_data ) {
							$first_field_id = (string) $field_id_key;

							// Multiple files are stored as a list; single files as an assoc array.
							if ( is_array( $file_data ) && isset( $file_data[0] ) && is_array( $file_data[0] ) ) {
								$first_idx = 0;
							} else {
								$first_idx = 0;
							}
							break;
						}

						if ( $first_field_id !== '' ) {
							$download_nonce = wp_create_nonce( 'ct_forms_download_' . (int) $id . '_' . $first_field_id . '_' . (int) $first_idx );
							$link_url       = add_query_arg(
								array(
									'action'     => 'ct_forms_download',
									'entry_id'   => (int) $id,
									'field_id'   => $first_field_id,
									'file_index' => (int) $first_idx,
									'_wpnonce'   => $download_nonce,
								),
								admin_url( 'admin-post.php' )
							);
						}
					}

					$attachments_html = '<a href="' . esc_url( $link_url ) . '" target="_blank" rel="noopener noreferrer">' . $clip . '</a>';
				}
				$summary = '';
				$raw     = isset( $row['data_json'] ) ? $row['data_json'] : '';
				if ( is_string( $raw ) && $raw !== '' ) {
					$data = json_decode( $raw, true );
					if ( ! is_array( $data ) ) {
						// Try serialized PHP.
						$maybe = @maybe_unserialize( $raw );
						if ( is_array( $maybe ) ) {
							$data = $maybe; }
					}
					if ( is_array( $data ) ) {
						$parts = array();
						foreach ( $data as $k => $v ) {
							if ( is_array( $v ) || is_object( $v ) ) {
								continue; }
							$v = trim( (string) $v );
							if ( $v === '' ) {
								continue; }
							$parts[] = $v;
							if ( count( $parts ) >= 3 ) {
								break; }
						}
						$summary = implode( ' – ', $parts );
					}
				}

				$view_url = $id ? admin_url( 'admin.php?page=ct-forms-entries&entry_id=' . $id ) : '';
				$id_html  = $id ? ( '<a href="' . esc_url( $view_url ) . '">#' . esc_html( (string) $id ) . '</a>' ) : '–';

				// Actions.
				$actions_html = '';
				if ( $id && $view_url ) {
					$actions_html .= '<a class="button button-small" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'ct-forms' ) . '</a> ';
				}
				if ( $id && current_user_can( 'ct_forms_manage' ) ) {
					// Preserve current filters in redirect.
					$redirect_args = array( 'page' => 'ct-forms-entries' );
					if ( $form_id ) {
						$redirect_args['form_id'] = (int) $form_id; }
					if ( $search !== '' ) {
						$redirect_args['s'] = $search; }
					if ( $status_filter !== '' ) {
						$redirect_args['status'] = $status_filter; }
					if ( $paged > 1 ) {
						$redirect_args['paged'] = (int) $paged; }
					$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );

					$delete_url    = add_query_arg(
						array(
							'action'    => 'ct_forms_delete_entry',
							'entry_id'  => (int) $id,
							'_wpnonce'  => wp_create_nonce( 'ct_forms_delete_entry_' . (int) $id ),
							'_redirect' => $redirect_url,
						),
						admin_url( 'admin-post.php' )
					);
					$actions_html .= '<a class="button button-small" href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this entry? This cannot be undone.', 'ct-forms' ) ) . '\');">' . esc_html__( 'Delete', 'ct-forms' ) . '</a>';
				}

				echo '<tr>';
				echo '<th scope="row" class="check-column"><input type="checkbox" name="entry_ids[]" value="' . esc_attr( (string) $id ) . '" /></th>';
				echo '<td>' . $id_html . '</td>';
				echo '<td>' . esc_html( $form_title ) . '</td>';
				echo '<td>' . $submitted_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . $status_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . esc_html( $ip ) . '</td>';
				echo '<td>' . $attachments_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . esc_html( $summary ) . '</td>';
				echo '<td>' . $actions_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo '</form>';

		// Select-all + bulk delete confirmation.
		echo '<script>(function(){\n'
			. 'var all=document.getElementById("ct-forms-select-all");\n'
			. 'if(all){all.addEventListener("change",function(){\n'
			. '  var cbs=document.querySelectorAll("input[name=\\"entry_ids[]\\"]");\n'
			. '  for(var i=0;i<cbs.length;i++){cbs[i].checked=all.checked;}\n'
			. '});}\n'
			. 'var btn=document.getElementById("ct-forms-bulk-apply");\n'
			. 'if(btn){btn.addEventListener("click",function(e){\n'
			. '  var sel=document.getElementById("ct-forms-bulk-action");\n'
			. '  if(!sel||!sel.value){return;}\n'
			. '  if(sel.value==="delete"){\n'
			. '    if(!confirm("Delete the selected entries? This cannot be undone.") ){e.preventDefault();}\n'
			. '  }\n'
			. '});}\n'
			. '})();</script>';

		// Pagination.
		$total_pages = ( $per_page > 0 ) ? (int) ceil( $total_items / $per_page ) : 1;
		if ( $total_pages < 1 ) {
			$total_pages = 1; }

		$base_url_args = array( 'page' => 'ct-forms-entries' );
		if ( $form_id ) {
			$base_url_args['form_id'] = (int) $form_id; }
		if ( $search !== '' ) {
			$base_url_args['s'] = $search; }

		echo '<div class="tablenav"><div class="tablenav-pages" style="margin:10px 0;">';
		echo paginate_links(
			array(
				'base'      => add_query_arg( array_merge( $base_url_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
				'format'    => '',
				'prev_text' => '«',
				'next_text' => '»',
				'total'     => $total_pages,
				'current'   => $paged,
			)
		);
		echo '</div></div>';
	}
	/**
	 * render_entries_fallback_table method.
	 *
	 * @param mixed $form_id Parameter.
	 * @return mixed
	 */
	private static function render_entries_fallback_table( $form_id = 0 ) {
		global $wpdb;

		$table = CT_Forms_DB::entries_table();

		$pk_col      = CT_Forms_DB::entries_pk_column();
		$created_col = CT_Forms_DB::entries_created_column();
		$ip_col      = CT_Forms_DB::entries_ip_column();
		$page_col    = CT_Forms_DB::entries_page_url_column();
		$data_col    = CT_Forms_DB::entries_data_column();

		$select_cols   = array();
		$select_cols[] = "{$pk_col} AS id";
		$select_cols[] = 'form_id';
		if ( $created_col ) {
			$select_cols[] = "{$created_col} AS created_at"; }
		if ( $ip_col ) {
			$select_cols[] = "{$ip_col} AS ip_address"; }
		if ( $page_col ) {
			$select_cols[] = "{$page_col} AS page_url"; }
		if ( $data_col ) {
			$select_cols[] = "{$data_col} AS data_json"; }
		$select_cols_sql = implode( ', ', $select_cols );
		$pk              = $pk_col;

		$where = '';
		$args  = array();
		if ( $form_id > 0 ) {
			$where  = 'WHERE form_id = %d';
			$args[] = (int) $form_id;
		}

		// Simple, most-compatible query.
		$sql  = "SELECT * FROM {$table} " . ( $where ? $wpdb->prepare( $where, ...$args ) : '' ) . " ORDER BY {$pk} DESC LIMIT 50";
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array(); }

		echo '<h2 style="margin-top:18px;">Fallback entries view</h2>';
		echo '<p class="description">The standard entries table did not render on this server. Showing up to the most recent 50 entries.</p>';

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-warning"><p>No rows could be loaded from the entries table.</p></div>';
			if ( ! empty( $wpdb->last_error ) ) {
				echo '<div class="notice notice-error"><p><code>' . esc_html( $wpdb->last_error ) . '</code></p></div>';
			}
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>ID</th><th>Form</th><th>Status</th><th>Submitted</th><th>Page</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$id        = isset( $r[ $pk ] ) ? (int) $r[ $pk ] : 0;
			$fid       = isset( $r['form_id'] ) ? (int) $r['form_id'] : 0;
			$status    = isset( $r['status'] ) ? (string) $r['status'] : '';
			$submitted = isset( $r['submitted_at'] ) ? (string) $r['submitted_at'] : '';
			$page      = isset( $r['page_url'] ) ? (string) $r['page_url'] : '';

			$form_title = $fid ? get_the_title( $fid ) : '';
			$form_title = $form_title ? $form_title : ( $fid ? ( 'Form #' . $fid ) : '–' );

			echo '<tr>';
			echo '<td>' . ( $id ? '<a href="' . esc_url( admin_url( 'admin.php?page=ct-forms-entries&entry_id=' . $id ) ) . '">#' . esc_html( $id ) . '</a>' : '–' ) . '</td>';
			echo '<td>' . esc_html( $form_title ) . '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . $submitted_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . ( $page ? '<a href="' . esc_url( $page ) . '" target="_blank" rel="noopener">' . esc_html( wp_trim_words( $page, 8, '…' ) ) . '</a>' : '–' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}


	/**
	 * is_safe_upload_path method.
	 *
	 * @param mixed $path Parameter.
	 * @return mixed
	 */
	private static function is_safe_upload_path( $path ) {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $base ) {
			return false; }

		$base   = wp_normalize_path( $base );
		$target = wp_normalize_path( (string) $path );

		$allowed_dir = trailingslashit( $base ) . 'ct-forms/';
		$allowed_dir = wp_normalize_path( $allowed_dir );

		$real_allowed = realpath( $allowed_dir );
		$real_target  = realpath( $target );

		if ( $real_allowed ) {
			$allowed_dir = wp_normalize_path( $real_allowed ); }
		if ( $real_target ) {
			$target = wp_normalize_path( $real_target ); }

		return ( 0 === strpos( $target, trailingslashit( $allowed_dir ) ) );
	}

	/**
	 * file_exists_safe method.
	 *
	 * @param mixed $path Parameter.
	 * @return mixed
	 */
	private static function file_exists_safe( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return false; }
		if ( ! self::is_safe_upload_path( $path ) ) {
			return false; }
		return file_exists( $path );
	}
}
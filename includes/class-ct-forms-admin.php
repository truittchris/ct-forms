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
	 * Normalize_template_newlines method.
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
		$value = preg_replace( '/rnrn(?=\{)/', "\n\n", $value );
		$value = preg_replace( '/rnrn(?=Entry\b)/', "\n\n", $value );
		$value = preg_replace( '/(?<=[\}\]\.\)])rnrn/', "\n\n", $value );
		$value = preg_replace( '/(?<=\d)rnrn(?=[A-Za-z])/', "\n\n", $value );
		$value = preg_replace( '/nn(?=\{)/', "\n\n", $value );
		$value = preg_replace( '/nn(?=Entry\b)/', "\n\n", $value );
		$value = preg_replace( '/(?<=[\}\]\.\)])\s*nn/', "\n\n", $value );
		$value = preg_replace( '/(?<=\d)nn(?=[A-Za-z])/', "\n\n", $value );
		$value = preg_replace( '/rn(?=\{)/', "\n", $value );
		$value = preg_replace( '/(?<=[\}\]\.\)])rn/', "\n", $value );
		$value = preg_replace( '/rn(?=Reference\b)/', "\n", $value );
		$value = preg_replace( '/n(?=\{)/', "\n", $value );
		$value = preg_replace( '/(?<=[\}\]\.\)])n/', "\n", $value );
		$value = preg_replace( '/n(?=Reference\b)/', "\n", $value );
		$value = preg_replace( '/(?<=\d)n(?=[A-Za-z])/', "\n\n", $value );

		return $value;
	}

	/**
	 * Format_submitted_cell_html method.
	 *
	 * @param mixed $submitted_raw Parameter.
	 * @return mixed
	 */
	private static function format_submitted_cell_html( $submitted_raw ) {
		if ( empty( $submitted_raw ) || '0000-00-00 00:00:00' === $submitted_raw ) {
			return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">Not recorded</span></span>';
		}

		$ts = strtotime( (string) $submitted_raw );
		if ( false === $ts || 0 >= $ts ) {
			return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">Not recorded</span></span>';
		}

		if ( $ts < 0 ) {
			return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">Not recorded</span></span>';
		}

		$date_fmt = get_option( 'date_format' );
		$time_fmt = get_option( 'time_format' );

		$main = wp_date( $date_fmt . ' \a\t ' . $time_fmt, $ts );
		$ago  = human_time_diff( $ts, time() ) . ' ago';

		return '<span class="truitt-submitted"><span class="truitt-submitted__main">' . esc_html( $main ) . '</span><span class="truitt-submitted__sub">' . esc_html( $ago ) . '</span></span>';
	}

	/**
	 * Get_status_label method.
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
	 * Init method.
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
			wp_die( esc_html__( 'Not allowed', 'ct-forms' ) );
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

				$tokens = array(
					'{form_name}'    => $form_name,
					'{entry_id}'     => (string) $id,
					'{submitted_at}' => isset( $entry['submitted_at'] ) ? (string) $entry['submitted_at'] : '',
				);

				$all_fields = '';
				$data       = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
				foreach ( (array) $data as $k => $v ) {
					if ( is_array( $v ) ) {
						$v = implode( ', ', $v );
					}
					$all_fields                    .= $k . ': ' . $v . "\n";
					$tokens[ '{field:' . $k . '}' ] = (string) $v;
				}
				$tokens['{all_fields}'] = trim( $all_fields );

				$subject = strtr( (string) ( $form_settings['email_subject'] ?? '' ), $tokens );
				$body    = strtr( (string) ( $form_settings['email_body'] ?? '' ), $tokens );

				$headers = array();
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

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Update an individual entry status.
	 */
	public static function update_entry_status() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( esc_html__( 'Not allowed', 'ct-forms' ) );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;
		if ( $entry_id <= 0 ) {
			wp_die( esc_html__( 'Invalid entry.', 'ct-forms' ) );
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
	 * Menu method.
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
	 * Assets method.
	 *
	 * @param string $hook Hook name.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'ct-forms' ) ) {
			return;
		}

		$settings = self::get_settings();

		if ( function_exists( 'wp_enqueue_editor' ) ) {
			wp_enqueue_editor();
		}

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
	 * Register settings.
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
	 * Sanitize settings.
	 *
	 * @param array $input Input settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$out = array();

		$out['allowed_mimes'] = isset( $input['allowed_mimes'] ) ? sanitize_text_field( $input['allowed_mimes'] ) : '';
		$out['max_file_mb']   = isset( $input['max_file_mb'] ) ? max( 1, (int) $input['max_file_mb'] ) : 10;

		$out['recaptcha_type']       = isset( $input['recaptcha_type'] ) ? sanitize_key( $input['recaptcha_type'] ) : 'disabled';
		$out['recaptcha_site_key']   = isset( $input['recaptcha_site_key'] ) ? sanitize_text_field( $input['recaptcha_site_key'] ) : '';
		$out['recaptcha_secret_key'] = isset( $input['recaptcha_secret_key'] ) ? sanitize_text_field( $input['recaptcha_secret_key'] ) : '';

		$out['recaptcha_v3_threshold'] = isset( $input['recaptcha_v3_threshold'] ) ? floatval( $input['recaptcha_v3_threshold'] ) : 0.5;
		$out['recaptcha_v3_action']    = isset( $input['recaptcha_v3_action'] ) ? sanitize_key( $input['recaptcha_v3_action'] ) : 'ct_form_submit';

		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		return $out;
	}

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'allowed_mimes'          => 'jpg,jpeg,png,gif,pdf,doc,docx,zip',
			'max_file_mb'            => 10,
			'recaptcha_type'         => 'disabled',
			'recaptcha_site_key'     => '',
			'recaptcha_secret_key'   => '',
			'recaptcha_v3_threshold' => 0.5,
			'recaptcha_v3_action'    => 'ct_form_submit',
			'delete_on_uninstall'    => 0,
		);

		$saved = get_option( 'ct_forms_settings', array() );
		return array_merge( $defaults, (array) $saved );
	}

	/**
	 * Is_safe_upload_path method.
	 *
	 * @param mixed $path Parameter.
	 * @return bool
	 */
	private static function is_safe_upload_path( $path ) {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $base ) {
			return false;
		}

		$base   = wp_normalize_path( $base );
		$target = wp_normalize_path( (string) $path );

		$allowed_dir = trailingslashit( $base ) . 'ct-forms/';
		$allowed_dir = wp_normalize_path( $allowed_dir );

		$real_allowed = realpath( $allowed_dir );
		$real_target  = realpath( $target );

		if ( $real_allowed ) {
			$allowed_dir = wp_normalize_path( $real_allowed );
		}
		if ( $real_target ) {
			$target = wp_normalize_path( $real_target );
		}

		return 0 === strpos( $target, $allowed_dir );
	}
}

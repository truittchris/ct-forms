<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CT_Forms_Submissions {

    private static function verify_recaptcha_or_throw( $form_id ) {
        $settings = CT_Forms_Admin::get_settings();

        // Per-form toggle: if disabled for this form, skip verification entirely.
        $form_settings = array();
        if ( class_exists( 'CT_Forms_CPT' ) && is_callable( array( 'CT_Forms_CPT', 'get_form_settings' ) ) ) {
            $form_settings = (array) CT_Forms_CPT::get_form_settings( (int) $form_id );
        }
        if ( empty( $form_settings['recaptcha_enabled'] ) ) {
            return;
        }

        $type = isset( $settings['recaptcha_type'] ) ? (string) $settings['recaptcha_type'] : '';
        if ( '' === $type ) {
            // Back-compat: older versions used a boolean recaptcha_enabled option.
            $type = ! empty( $settings['recaptcha_enabled'] ) ? 'v2_checkbox' : 'disabled';
        }

        if ( 'disabled' === $type ) {
            return;
        }

        $secret_key = isset( $settings['recaptcha_secret_key'] ) ? trim( (string) $settings['recaptcha_secret_key'] ) : '';
        if ( empty( $secret_key ) ) {
            // Enabled but not configured – do not block submissions.
            return;
        }

        $recaptcha_response = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
        if ( empty( $recaptcha_response ) ) {
            throw new Exception( 'recaptcha_missing' );
        }

        $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $response = wp_remote_post( $verify_url, array(
            'body' => array(
                'secret'   => $secret_key,
                'response' => $recaptcha_response,
                'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'recaptcha_request_failed' );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data['success'] ) ) {
            throw new Exception( 'recaptcha_failed' );
        }

        // v3 adds score + action – enforce if configured
        if ( 'v3' === $type ) {
            $threshold = isset( $settings['recaptcha_v3_threshold'] ) ? floatval( $settings['recaptcha_v3_threshold'] ) : 0.5;
            if ( $threshold < 0 ) { $threshold = 0; }
            if ( $threshold > 1 ) { $threshold = 1; }

            $score = isset( $data['score'] ) ? floatval( $data['score'] ) : 0.0;
            if ( $score < $threshold ) {
                throw new Exception( 'recaptcha_score_too_low' );
            }

            $expected_action = isset( $settings['recaptcha_v3_action'] ) ? trim( (string) $settings['recaptcha_v3_action'] ) : '';
            if ( '' !== $expected_action && isset( $data['action'] ) && $expected_action !== (string) $data['action'] ) {
                throw new Exception( 'recaptcha_action_mismatch' );
            }
        }
    }

    /**
     * Repair newline artifacts that can appear in email templates.
     *
     * Symptoms look like: "Support Request.nname" or "...message: 123nnEntry ID".
     *
     * We intentionally keep this conservative and only fix cases that occur at
     * obvious template boundaries (e.g., right before <strong> lines from {all_fields}
     * output, or right before the literal "Entry" / "Reference" labels).
     */
    private static function repair_template_newline_artifacts( $text ) {
        $text = is_string( $text ) ? $text : '';

        // Convert escaped sequences to real newlines.
        $text = str_replace( array( "\\r\\n", "\\n", "\\r" ), "\n", $text );
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );

        // Legacy: escaped CRLF sequences that got stripped down to "rn" / "rnrn".
        $text = str_replace( 'rnrn', "\n\n", $text );
        $text = preg_replace( '/rn(?=(<strong>|Entry\\b|Reference\\b))/i', "\n", $text );

        // Common: stripped "\n\n" becomes literal "nn".
        $text = preg_replace( '/nn(?=(<strong>|Entry\\b|Reference\\b))/i', "\n\n", $text );

        // Also seen: a paragraph break after a numeric token (e.g., {entry_id}) gets
        // flattened into the literal letters "nn" before the next paragraph's text.
        $text = preg_replace( '/(\d)nn(?=[A-Z])/', "$1\n\n", $text );

        // Also: paragraph break after punctuation (e.g., ".nnTuesday") gets flattened into the literal letters "nn".
        $text = preg_replace( '/([\.\!\?])nn(?=[A-Z])/', "$1\n\n", $text );

        // Single newline before the {all_fields} block often shows up as ".n<strong>".
        $text = preg_replace( '/\.(?:\s*)n(?=<strong>)/i', ".\n", $text );
        $text = preg_replace( '/n(?=<strong>)/i', "\n", $text );

        // Collapse runs of blank lines.
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );

        return $text;
    }

    private static function normalize_email_body_newlines( $value ) {
        $value = is_string( $value ) ? $value : '';

        // If authored in a WYSIWYG editor, convert basic HTML to plaintext newlines.
        if ( $value !== wp_strip_all_tags( $value ) ) {
            $value = preg_replace( '/<br\s*\/?\s*>/i', "\n", $value );
            $value = preg_replace( '/<\s*\/p\s*>/i', "\n\n", $value );
            $value = preg_replace( '/<\s*p\b[^>]*>/i', "\n", $value );
            $value = wp_strip_all_tags( $value );
        }

        // Fix legacy artifacts where escaped CRLF ended up as literal 'rn' or 'rnrn'.
        $value = str_replace( 'rnrn', "\n\n", $value );
        $value = preg_replace( '/rn(?=\{)/', "\n", $value );
        $value = preg_replace( '/rn(?=Reference\b)/', "\n", $value );

        // Convert escaped sequences (e.g. \"\r\n\") to real newlines.
        $value = str_replace( array( "\\r\\n", "\\n", "\\r" ), "\n", $value );

        // Normalize and collapse multiple blank lines.
        $value = str_replace( array( "\r\n", "\r" ), "\n", $value );
        $value = preg_replace( "/\n{3,}/", "\n\n", $value );

        // Convert to CRLF for transport.
        $value = str_replace( "\n", "\r\n", $value );

        return $value;
    }

    private static function prepare_email_body_html( $html ) {
        $html = (string) $html;
        $html = str_replace( array( "\r\n", "\n", "\r" ), array( "\n", "\n", "\n" ), $html );
        $html = wp_kses_post( $html );
        $html = wpautop( $html );
        return $html;
    }

    private static function prepare_email_body_text( $html ) {
        $html = (string) $html;
        $html = str_replace( array( "\r\n", "\n", "\r" ), array( "\n", "\n", "\n" ), $html );
        $html = preg_replace( '/<\s*br\s*\/?\s*>/i', "\n", $html );
        $html = preg_replace( '/<\s*\/p\s*>/i', "\n\n", $html );
        $text = wp_strip_all_tags( $html );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );
        return str_replace( "\n", "\r\n", $text );
    }

    /**
     * Normalize headers for wp_mail() into a newline-delimited string.
     *
     * In the wild, some SMTP transports/plugins handle header strings more
     * consistently than header arrays when it comes to preserving Cc vs To
     * presentation in recipients.
     *
     * @param string|array $headers Headers.
     * @return string
     */
    private static function normalize_headers_for_wp_mail( $headers ) {
        if ( is_array( $headers ) ) {
            $headers = array_values( array_filter( array_map( 'trim', $headers ) ) );
            return implode( "\r\n", $headers );
        }

        $headers = is_string( $headers ) ? trim( $headers ) : '';
        return $headers;
    }

    /**
     * Parse a recipient list field (To / CC / BCC) into a validated array of emails.
     * Supports commas, semicolons, and newlines.
     *
     * @param mixed $raw
     * @return string[]
     */
    private static function parse_recipient_list( $raw ) {
        $raw = is_string( $raw ) ? $raw : '';
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return array();
        }

        // Split by comma, semicolon, or any newline.
        $parts = preg_split( '/[\s]*[;,\n\r]+[\s]*/', $raw );
        if ( ! is_array( $parts ) ) {
            return array();
        }

        $out = array();
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( $part === '' ) {
                continue;
            }
            $email = sanitize_email( $part );
            if ( $email && is_email( $email ) ) {
                $out[] = $email;
            }
        }

        $out = array_values( array_unique( $out ) );
        return $out;
    }

    /**
     * Build CC/BCC headers from recipient lists.
     *
     * @param string[] $cc
     * @param string[] $bcc
     * @return string[]
     */
    private static function build_cc_bcc_headers( array $cc, array $bcc ) {
        $headers = array();
        if ( ! empty( $cc ) ) {
            $headers[] = 'Cc: ' . implode( ', ', $cc );
        }
        if ( ! empty( $bcc ) ) {
            $headers[] = 'Bcc: ' . implode( ', ', $bcc );
        }
        return $headers;
    }






    public static function init() {
        add_action( 'admin_post_nopriv_ct_forms_submit', array( __CLASS__, 'handle_submit' ) );
        add_action( 'admin_post_ct_forms_submit', array( __CLASS__, 'handle_submit' ) );

        add_action( 'admin_post_ct_forms_download', array( __CLASS__, 'handle_download' ) );
        add_action( 'admin_post_ct_forms_delete_file', array( __CLASS__, 'handle_delete_file' ) );
        add_action( 'admin_post_ct_forms_delete_all_files', array( __CLASS__, 'handle_delete_all_files' ) );
        add_action( 'admin_post_ct_forms_bulk_files', array( __CLASS__, 'handle_bulk_files' ) );
        add_action( 'admin_post_ct_forms_delete_entry', array( __CLASS__, 'handle_delete_entry' ) );
    }

    /**
     * Bulk actions for Uploaded Files screen.
     *
     * Currently supports: delete selected.
     */
    public static function handle_bulk_files() {
        if ( ! current_user_can( 'ct_forms_manage' ) ) {
            wp_die( 'Not allowed' );
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? (string) $_POST['_wpnonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'ct_forms_bulk_files' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( (string) $_POST['bulk_action'] ) : '';
        $selected = isset( $_POST['selected'] ) ? (array) $_POST['selected'] : array();

        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( (string) $_POST['_redirect'] ) : '';
        if ( '' === $redirect ) {
            $redirect = admin_url( 'admin.php?page=ct-forms-files' );
        }

        if ( 'delete' !== $bulk_action || empty( $selected ) ) {
            wp_safe_redirect( $redirect );
            exit;
        }

        $deleted = 0;

        foreach ( $selected as $token_raw ) {
            $token = sanitize_text_field( wp_unslash( (string) $token_raw ) );
            if ( '' === $token ) { continue; }

            // Token format: entry_id|field_id|idx
            $parts = explode( '|', $token );
            if ( count( $parts ) < 3 ) { continue; }

            $entry_id = (int) $parts[0];
            $field_id = sanitize_key( (string) $parts[1] );
            $file_index = max( 0, (int) $parts[2] );

            if ( $entry_id <= 0 || '' === $field_id ) { continue; }

            $entry = CT_Forms_DB::get_entry( $entry_id );
            if ( ! $entry || empty( $entry['files'] ) || ! is_array( $entry['files'] ) ) {
                continue;
            }

            $files = $entry['files'];

            // Support legacy key names.
            $lookup_key = $field_id;
            if ( empty( $files[ $lookup_key ] ) && ! empty( $files[ 'truitt_field_' . $field_id ] ) ) {
                $lookup_key = 'truitt_field_' . $field_id;
            }

            if ( empty( $files[ $lookup_key ] ) ) {
                continue;
            }

            $target = $files[ $lookup_key ];
            $is_multi = ( is_array( $target ) && isset( $target[0] ) );
            $file_obj = $is_multi ? ( $target[ $file_index ] ?? null ) : $target;

            if ( empty( $file_obj ) || ! is_array( $file_obj ) ) {
                continue;
            }

            $path = isset( $file_obj['path'] ) ? (string) $file_obj['path'] : ( isset( $file_obj['file'] ) ? (string) $file_obj['file'] : '' );
            if ( '' !== $path && self::is_safe_upload_path( $path ) && file_exists( $path ) ) {
                @unlink( $path );
            }

            // Remove from entry JSON.
            if ( $is_multi ) {
                unset( $files[ $lookup_key ][ $file_index ] );
                $files[ $lookup_key ] = array_values( array_filter( (array) $files[ $lookup_key ] ) );
                if ( empty( $files[ $lookup_key ] ) ) {
                    unset( $files[ $lookup_key ] );
                }
            } else {
                unset( $files[ $lookup_key ] );
            }

            CT_Forms_DB::update_entry_files( $entry_id, $files );
            $deleted++;
        }

        $redirect = add_query_arg( array( 'ct_forms_bulk_deleted' => 1, 'ct_forms_bulk_deleted_count' => $deleted ), $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handle_submit() {
        $form_id = isset( $_POST['ct_form_id'] ) ? (int) $_POST['ct_form_id'] : 0;
        if ( $form_id <= 0 ) { wp_safe_redirect( home_url() ); exit; }

        $nonce = isset( $_POST['truitt_nonce'] ) ? (string) $_POST['truitt_nonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'ct_forms_submit_' . $form_id ) ) {
            self::redirect_error( $form_id, 'nonce' );
        }

        // Honeypot
        $hp = isset( $_POST['truitt_hp'] ) ? (string) $_POST['truitt_hp'] : '';
        if ( '' !== trim( $hp ) ) {
            self::redirect_success( $form_id ); // silently succeed
        }

        // reCAPTCHA (v2 checkbox)
        try {
            self::verify_recaptcha_or_throw( $form_id );
        } catch ( Exception $e ) {
            self::redirect_error( $form_id, 'recaptcha' );
        }

        // Time-to-submit check (default threshold 2 seconds)
        $ts = isset( $_POST['truitt_ts'] ) ? (int) $_POST['truitt_ts'] : 0;
        $min_seconds = (int) apply_filters( 'ct_forms_min_seconds_to_submit', 2, $form_id );
        if ( $ts > 0 && ( time() - $ts ) < $min_seconds ) {
            self::redirect_success( $form_id ); // silently succeed
        }

        $settings_global = CT_Forms_Admin::get_settings();
        $rate_limit = isset( $settings_global['rate_limit'] ) ? (int) $settings_global['rate_limit'] : 10;
        $rate_window = isset( $settings_global['rate_window_minutes'] ) ? (int) $settings_global['rate_window_minutes'] : 10;

        if ( $rate_limit > 0 && $rate_window > 0 ) {
            $ip = self::get_ip();
            $key = 'ct_forms_rl_' . md5( $ip . '_' . $form_id );
            $count = (int) get_transient( $key );
            if ( $count >= $rate_limit ) {
                self::redirect_error( $form_id, 'rate_limit' );
            }
            set_transient( $key, $count + 1, MINUTE_IN_SECONDS * $rate_window );
        }

        $def = CT_Forms_CPT::get_form_definition( $form_id );
        $form_settings = CT_Forms_CPT::get_form_settings( $form_id );

        // CONFIG VALIDATION
        if ( empty( $form_settings['to_email'] ) || ! is_email( $form_settings['to_email'] ) ) {
            self::redirect_error( $form_id, 'config_to_email' );
        }
        $data = array();
        $errors = array();
        $warnings = array();

        foreach ( $def['fields'] as $field ) {
            $fid = isset( $field['id'] ) ? sanitize_key( $field['id'] ) : '';
            if ( '' === $fid ) { continue; }

            $type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
            $required = ! empty( $field['required'] );
            $name = 'truitt_field_' . $fid;

            if ( 'file' === $type ) {
                // handle later
                continue;
            }

            if ( 'diagnostics' === $type ) {
                // Virtual field – auto-populated server-side.
                $data[ $fid ] = self::build_diagnostics_text();
                continue;
            }

            if ( 'checkboxes' === $type ) {
                $val = isset( $_POST[ $name ] ) ? (array) $_POST[ $name ] : array();
                $val = array_map( 'sanitize_text_field', $val );
                $val = array_values( array_filter( $val, function( $v ){ return '' !== trim( $v ); } ) );
                if ( $required && empty( $val ) ) {
                    $errors[ $fid ] = 'required';
                }
                $data[ $fid ] = $val;
                continue;
            }

            $val = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
            $val = is_string( $val ) ? trim( $val ) : $val;

            if ( $required && '' === $val ) {
                $errors[ $fid ] = 'required';
            }

            if ( 'email' === $type && '' !== $val && ! is_email( $val ) ) {
                $errors[ $fid ] = 'invalid_email';
            }

            $data[ $fid ] = sanitize_textarea_field( (string) $val );
        }

        // Optional Akismet integration
        if ( function_exists( 'akismet_http_post' ) ) {
            $is_spam = apply_filters( 'ct_forms_akismet_check', false, $form_id, $data );
            if ( true === $is_spam ) {
                // We still store, but mark as spam.
                $forced_spam = true;
            }
        }

        // File uploads + validation (required fields, extensions, size, etc.)
        $file_result = self::handle_file_uploads_detailed( $def['fields'] );
        if ( is_wp_error( $file_result ) ) {
            self::redirect_error( $form_id, 'upload' );
        }

        $files = isset( $file_result['files'] ) ? (array) $file_result['files'] : array();
        $file_errors = isset( $file_result['errors'] ) ? (array) $file_result['errors'] : array();
        $file_warnings = isset( $file_result['warnings'] ) ? (array) $file_result['warnings'] : array();

        if ( ! empty( $file_errors ) ) {
            // Merge file errors into normal validation errors.
            foreach ( $file_errors as $fid => $msgs ) {
                $errors[ $fid ] = $msgs;
            }
        }

        if ( ! empty( $errors ) ) {
            $fb_token = self::store_form_feedback( $form_id, array(
                'errors' => $errors,
            ) );
            self::redirect_error( $form_id, 'validation', array( 'ct_forms_fb' => $fb_token ) );
        }

        if ( ! empty( $file_warnings ) ) {
            $warnings = $file_warnings;
        }

        $meta = array(
            'ip'  => self::get_ip(),
            'ua'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            'ref' => isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : '',
            'url' => self::current_url(),
        );

        if ( ! empty( $warnings ) ) {
            $meta['warnings'] = $warnings;
        }

        /**
         * Action: ct_forms_before_entry_insert
         */
        do_action( 'ct_forms_before_entry_insert', $form_id, $data, $files, $meta );

        $entry_id = CT_Forms_DB::insert_entry( $form_id, $data, $files, $meta );

        // Send notifications
        $mail_log = self::send_notifications( $form_id, $entry_id, $data, $files, $form_settings );
        CT_Forms_DB::update_entry_mail_log( $entry_id, $mail_log );

        /**
         * Action: ct_forms_after_submission
         */
        do_action( 'ct_forms_after_submission', $form_id, $entry_id, $data, $files, $meta, $mail_log );

        // Confirmation
        if ( 'redirect' === $form_settings['confirmation_type'] && ! empty( $form_settings['confirmation_redirect'] ) ) {
            wp_safe_redirect( esc_url_raw( $form_settings['confirmation_redirect'] ) );
            exit;
        }

        if ( ! empty( $warnings ) ) {
            $fb_token = self::store_form_feedback( $form_id, array(
                'warnings' => $warnings,
            ) );
            self::redirect_success( $form_id, array( 'ct_forms_fb' => $fb_token ) );
        }

        self::redirect_success( $form_id );
    }

    private static function redirect_success( $form_id, $extra_args = array() ) {
        $url = wp_get_referer();
        if ( ! $url ) { $url = home_url(); }
        $url = remove_query_arg( array( 'ct_forms_success', 'ct_forms_error', 'ct_forms_error_code', 'ct_forms_fb' ), $url );
        $url = add_query_arg( 'ct_forms_success', (int) $form_id, $url );

        if ( is_array( $extra_args ) && ! empty( $extra_args ) ) {
            $url = add_query_arg( $extra_args, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
    private static function redirect_error( $form_id, $code = 'unknown', $extra_args = array() ) {
        $url = wp_get_referer();
        if ( ! $url ) { $url = home_url(); }
        $url = remove_query_arg( array( 'ct_forms_success', 'ct_forms_error', 'ct_forms_error_code', 'ct_forms_fb' ), $url );
        $args = array(
            'ct_forms_error' => (int) $form_id,
            'ct_forms_error_code' => sanitize_key( (string) $code ),
        );
        if ( is_array( $extra_args ) && ! empty( $extra_args ) ) {
            $args = array_merge( $args, $extra_args );
        }
        $url = add_query_arg( $args, $url );
        wp_safe_redirect( $url );
        exit;
    }

    private static function store_form_feedback( $form_id, $payload ) {
        $token = wp_generate_uuid4();
        $key = 'ct_forms_fb_' . (int) $form_id . '_' . $token;
        set_transient( $key, $payload, 10 * MINUTE_IN_SECONDS );
        return $token;
    }

    public static function get_form_feedback( $form_id, $token ) {
        $token = sanitize_text_field( (string) $token );
        if ( '' === $token ) {
            return array();
        }
        $key = 'ct_forms_fb_' . (int) $form_id . '_' . $token;
        $payload = get_transient( $key );
        if ( false !== $payload ) {
            delete_transient( $key );
        }
        return is_array( $payload ) ? $payload : array();
    }
    
    private static function handle_file_uploads_detailed( $fields ) {
        $out = array();
        $errors = array();
        $warnings = array();

        if ( empty( $_FILES ) ) {
            // Still need to handle required file fields with no upload.
            foreach ( (array) $fields as $field ) {
                if ( empty( $field['id'] ) || ( $field['type'] ?? '' ) !== 'file' ) {
                    continue;
                }
                $fid = sanitize_key( (string) $field['id'] );
                if ( '' === $fid ) {
                    continue;
                }
                if ( ! empty( $field['required'] ) ) {
                    $errors[ $fid ] = array( 'This field is required.' );
                }
            }
            return array( 'files' => $out, 'errors' => $errors, 'warnings' => $warnings );
        }

        $settings = CT_Forms_Admin::get_settings();

        // Global limits / allow-list.
        $max_mb_global = isset( $settings['max_file_mb'] ) ? (int) $settings['max_file_mb'] : 8;
        if ( $max_mb_global < 1 ) {
            $max_mb_global = 8;
        }

        $allowed_exts_global = array();
        if ( ! empty( $settings['allowed_mimes'] ) ) {
            $allowed_exts_global = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $settings['allowed_mimes'] ) ) ) );
            $allowed_exts_global = array_map( function( $e ) {
                return ltrim( $e, '.' );
            }, $allowed_exts_global );
        }

        $global_mimes_map = self::build_mimes_map_from_exts( $allowed_exts_global );

        foreach ( (array) $fields as $field ) {
            if ( empty( $field['id'] ) || ( $field['type'] ?? '' ) !== 'file' ) {
                continue;
            }

            // File inputs are named truitt_field_{id} by the renderer.
            $fid = sanitize_key( (string) $field['id'] );
            if ( '' === $fid ) {
                continue;
            }

            $input_name = 'truitt_field_' . $fid;

            if ( ! isset( $_FILES[ $input_name ] ) && ! isset( $_FILES[ $fid ] ) ) {
                // For multiple inputs, the base key is still the name without [].
                continue;
            }

            // Field overrides.
            $field_max_mb = $max_mb_global;
            if ( ! empty( $field['file_max_mb'] ) ) {
                $tmp = (int) $field['file_max_mb'];
                if ( $tmp > 0 ) {
                    $field_max_mb = $tmp;
                }
            }

            // Allowed extensions are controlled globally in Settings.
            $allowed_exts = $allowed_exts_global;

            $mimes_map = self::build_mimes_map_from_exts( $allowed_exts );
            if ( empty( $mimes_map ) ) {
                $mimes_map = $global_mimes_map;
            }

            // Back-compat: older builds may have used the raw field id as the file input name.
            $file_struct = isset( $_FILES[ $input_name ] ) ? $_FILES[ $input_name ] : $_FILES[ $fid ];
            $items = self::normalize_files_array( $file_struct );

            if ( empty( $items ) ) {
                if ( ! empty( $field['required'] ) ) {
                    $errors[ $fid ] = array( 'This field is required.' );
                }
                continue;
            }

            $max_bytes = $field_max_mb * 1024 * 1024;

            $saved = array();

            $field_required = ! empty( $field['required'] );
            $rejected = array();

            foreach ( $items as $file ) {
                if ( ! isset( $file['error'] ) ) {
                    continue;
                }

                if ( (int) $file['error'] === UPLOAD_ERR_NO_FILE ) {
                    continue;
                }

                if ( (int) $file['error'] !== UPLOAD_ERR_OK ) {
                    $msg = 'Upload failed (error code ' . (int) $file['error'] . ').';
                    if ( $field_required ) {
                        $errors[ $fid ] = array( $msg );
                    } else {
                        $warnings[ $fid ][] = $msg;
                    }
                    continue;
                }

                if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
                    $msg = 'File is too large (' . basename( (string) $file['name'] ) . '). Max allowed is ' . (int) $field_max_mb . ' MB.';
                    if ( $field_required ) {
                        $errors[ $fid ] = array( $msg );
                    } else {
                        $warnings[ $fid ][] = $msg;
                    }
                    continue;
                }

                // Validate file extension + real mime where possible.
                if ( ! empty( $mimes_map ) ) {
                    $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes_map );
                    if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
                        $allowed_list = ! empty( $allowed_exts ) ? implode( ', ', array_map( 'sanitize_text_field', (array) $allowed_exts ) ) : '';
                        $msg = 'File type not allowed (' . basename( (string) $file['name'] ) . ').';
                        if ( $allowed_list ) {
                            $msg .= ' Allowed: ' . $allowed_list . '.';
                        }

                        if ( $field_required ) {
                            $rejected[] = $msg;
                        } else {
                            $warnings[ $fid ][] = $msg;
                        }
                        continue;
                    }
                }

                add_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );

                $overrides = array(
                    'test_form' => false,
                );

                if ( ! empty( $mimes_map ) ) {
                    $overrides['mimes'] = $mimes_map;
                }

                $handled = wp_handle_upload( $file, $overrides );

                remove_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );

                if ( isset( $handled['error'] ) ) {
                    if ( $field_required ) {
                        $errors[ $fid ] = array( (string) $handled['error'] );
                    } else {
                        $warnings[ $fid ][] = (string) $handled['error'];
                    }
                    continue;
                }

                // Add basic protection files in the upload directory (best-effort).
                self::ensure_upload_protection();

                $saved[] = array(
                    'name'     => basename( $handled['file'] ),
                    'path'     => $handled['file'],
                    'file'     => $handled['file'], // alias used by some legacy code
                    'url'      => $handled['url'],
                    'type'     => $handled['type'],
                    'original' => $file['name'],
                    'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
                );
            }

            if ( ! empty( $rejected ) ) {
                $errors[ $fid ] = array_values( $rejected );
            }

            // Required but nothing saved (e.g., all rejected).
            if ( $field_required && empty( $saved ) && empty( $errors[ $fid ] ) ) {
                $errors[ $fid ] = array( 'This field is required.' );
            }

            if ( empty( $saved ) ) {
                continue;
            }

            // Single vs multiple.
            if ( ! empty( $field['file_multiple'] ) ) {
                $out[ $fid ] = $saved;
            } else {
                $out[ $fid ] = $saved[0];
            }
        }

        return array( 'files' => $out, 'errors' => $errors, 'warnings' => $warnings );
    }

    private static function normalize_files_array( $file_struct ) {
        // Normalizes both single and multiple <input type="file"> structures into a list of files.
        if ( empty( $file_struct ) || ! is_array( $file_struct ) ) {
            return array();
        }

        if ( ! is_array( $file_struct['name'] ?? null ) ) {
            return array( $file_struct );
        }

        $out = array();
        $names = $file_struct['name'];
        $count = count( $names );

        for ( $i = 0; $i < $count; $i++ ) {
            $out[] = array(
                'name'     => $file_struct['name'][ $i ] ?? '',
                'type'     => $file_struct['type'][ $i ] ?? '',
                'tmp_name' => $file_struct['tmp_name'][ $i ] ?? '',
                'error'    => $file_struct['error'][ $i ] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $file_struct['size'][ $i ] ?? 0,
            );
        }

        return $out;
    }

    private static function build_mimes_map_from_exts( $exts ) {
        $exts = array_filter( array_map( 'trim', (array) $exts ) );
        if ( empty( $exts ) ) {
            return array();
        }

        $wp_mimes = wp_get_mime_types();

        $map = array();

        foreach ( $exts as $ext ) {
            $ext = strtolower( ltrim( (string) $ext, '.' ) );
            if ( $ext === '' ) {
                continue;
            }

            // wp_get_mime_types keys can be "jpg|jpeg|jpe".
            foreach ( $wp_mimes as $k => $mime ) {
                $parts = explode( '|', (string) $k );
                if ( in_array( $ext, $parts, true ) ) {
                    $map[ $k ] = $mime;
                    break;
                }
            }
        }

        return $map;
    }

    private static function ensure_upload_protection() {
        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) ) {
            return;
        }

        $dir = trailingslashit( $uploads['basedir'] ) . 'ct-forms';

        if ( ! file_exists( $dir ) ) {
            return;
        }

        // index.php
        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php
// Silence is golden.
" );
        }

        // .htaccess (Apache) – best effort; Nginx will ignore.
        $ht = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $ht ) ) {
            @file_put_contents( $ht, "Deny from all
" );
        }

        // web.config (IIS) – best effort.
        $wc = trailingslashit( $dir ) . 'web.config';
        if ( ! file_exists( $wc ) ) {
            @file_put_contents(
                $wc,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<configuration>
  <system.webServer>
    <security>
      <authorization>
        <remove users=\"*\" roles=\"\" verbs=\"\" />
        <add accessType=\"Deny\" users=\"*\" />
      </authorization>
    </security>
  </system.webServer>
</configuration>
"
            );
        }
    }
    public static function upload_dir( $dirs ) {
        $subdir = '/ct-forms';
        $dirs['subdir'] = $subdir;
        $dirs['path'] = $dirs['basedir'] . $subdir;
        $dirs['url']  = $dirs['baseurl'] . $subdir;
        return $dirs;
    }

    private static function send_notifications( $form_id, $entry_id, $data, $files, $form_settings ) {
        $post = get_post( $form_id );
        $form_name = $post ? $post->post_title : 'Form';

        // Map field ids to labels/types from the saved form definition.
        $field_labels = array();
        $field_types  = array();
        $raw_def = get_post_meta( $form_id, 'ct_form_definition', true );
        if ( is_string( $raw_def ) && '' !== trim( $raw_def ) ) {
            $def = json_decode( $raw_def, true );
            if ( is_array( $def ) && ! empty( $def['fields'] ) && is_array( $def['fields'] ) ) {
                foreach ( $def['fields'] as $f ) {
                    $fid = isset( $f['id'] ) ? sanitize_key( $f['id'] ) : '';
                    if ( '' === $fid ) {
                        continue;
                    }
                    $field_labels[ $fid ] = isset( $f['label'] ) && '' !== trim( (string) $f['label'] ) ? (string) $f['label'] : $fid;
                    $field_types[ $fid ]  = isset( $f['type'] ) ? sanitize_key( $f['type'] ) : '';
                }
            }
        }

        $log = array(
            'sent_at' => current_time( 'mysql' ),
            'admin' => array(),
            'autoresponder' => array(),
        );

        $tokens = self::build_tokens( $form_id, $entry_id, $data, true );

        // Routing: base recipient + optional rules
        $to = isset( $form_settings['to_email'] ) ? (string) $form_settings['to_email'] : '';
        $rules = isset( $form_settings['routing_rules'] ) && is_array( $form_settings['routing_rules'] ) ? $form_settings['routing_rules'] : array();
        foreach ( $rules as $rule ) {
            $field = isset( $rule['field'] ) ? sanitize_key( $rule['field'] ) : '';
            $op = isset( $rule['operator'] ) ? (string) $rule['operator'] : 'equals';
            $val = isset( $rule['value'] ) ? (string) $rule['value'] : '';
            $dest = isset( $rule['to_email'] ) ? (string) $rule['to_email'] : '';
            if ( '' === $field || '' === $dest ) { continue; }
            if ( ! isset( $data[ $field ] ) ) { continue; }

            $field_val = $data[ $field ];
            $match = false;

            if ( is_array( $field_val ) ) {
                $field_val = implode( ', ', $field_val );
            }

            if ( 'contains' === $op ) {
                $match = ( false !== stripos( (string) $field_val, $val ) );
            } else {
                $match = ( (string) $field_val === (string) $val );
            }

            if ( $match ) {
                $to = $dest;
                break;
            }
        }

        // Normalize / validate recipients.
        $to_list = self::parse_recipient_list( $to );
        if ( empty( $to_list ) ) {
            $to_list = self::parse_recipient_list( (string) get_option( 'admin_email' ) );
        }

        $subject = self::apply_tokens( (string) $form_settings['email_subject'], $tokens, $form_name );

        // Repair newline artifacts before the HTML pass so wpautop() can produce proper paragraphs/line breaks.
        $body_rendered = self::apply_tokens( (string) $form_settings['email_body'], $tokens, $form_name );
        $body_rendered = self::repair_template_newline_artifacts( $body_rendered );
        $body = self::prepare_email_body_html( $body_rendered );
        $headers = array();
        $def = CT_Forms_CPT::get_form_definition( $form_id );
        $from_info = self::build_from_header( $form_id, $form_settings );
        if ( ! empty( $from_info['header'] ) ) {
            $headers[] = $from_info['header'];
        }

        $cc_list  = self::parse_recipient_list( isset( $form_settings['cc'] ) ? (string) $form_settings['cc'] : '' );
        $bcc_list = self::parse_recipient_list( isset( $form_settings['bcc'] ) ? (string) $form_settings['bcc'] : '' );

        if ( ! empty( $cc_list ) ) {
            $headers[] = 'Cc: ' . implode( ', ', $cc_list );
        }

        // NOTE: We intentionally do NOT rely on "Bcc:" headers for delivery.
        // Some SMTP integrations and gateways can drop/ignore Bcc headers.
        // Instead, we send per-recipient BCC copies below so delivery is reliable.

        $reply_to_field = sanitize_key( (string) $form_settings['reply_to_field'] );
        if ( ! $reply_to_field ) {
            foreach ( $def['fields'] as $f ) {
                if ( isset( $f['type'] ) && 'email' === sanitize_key( (string) $f['type'] ) ) {
                    $maybe = isset( $f['id'] ) ? sanitize_key( (string) $f['id'] ) : '';
                    if ( $maybe && isset( $data[ $maybe ] ) && is_email( $data[ $maybe ] ) ) {
                        $reply_to_field = $maybe;
                        break;
                    }
                }
            }
        }
        if ( $reply_to_field && isset( $data[ $reply_to_field ] ) && is_email( $data[ $reply_to_field ] ) ) {
            $headers[] = 'Reply-To: ' . sanitize_email( $data[ $reply_to_field ] );
        }

        $attachments = array();
        $attach_uploads = ! empty( $form_settings['attach_uploads'] );
        $max_attach_mb = (int) apply_filters( 'ct_forms_max_attach_mb', 5, $form_id );
        $max_attach_bytes = $max_attach_mb * 1024 * 1024;

        if ( $attach_uploads && ! empty( $files ) ) {
            foreach ( $files as $f ) {
                if ( ! empty( $f['file'] ) && file_exists( $f['file'] ) ) {
                    $size = filesize( $f['file'] );
                    if ( $size && $size <= $max_attach_bytes ) {
                        $attachments[] = $f['file'];
                    }
                }
            }
        }

        // Build headers for wp_mail. We normalize to a single string because
        // some SMTP transports/plugins treat header strings more predictably
        // for CC display than arrays.
        $headers_for_mail = is_array( $headers ) ? array_values( array_filter( $headers ) ) : array( (string) $headers );

        // Ensure we always have a Content-Type header present.
        $has_content_type = false;
        foreach ( $headers_for_mail as $h ) {
            if ( is_string( $h ) && stripos( $h, 'content-type:' ) === 0 ) {
                $has_content_type = true;
                break;
            }
        }
        if ( ! $has_content_type ) {
            $headers_for_mail[] = 'Content-Type: text/html; charset=UTF-8';
        }

        $admin_sent = wp_mail( $to_list, $subject, $body, self::normalize_headers_for_wp_mail( $headers_for_mail ), $attachments );
        if ( ! $admin_sent && ! empty( $from_info['overridden'] ) ) {
            $headers_no_from = self::strip_from_header( $headers );
            $headers_no_from_for_mail = is_array( $headers_no_from ) ? array_values( array_filter( $headers_no_from ) ) : array( (string) $headers_no_from );

            // Ensure we always have a Content-Type header present.
            $has_content_type = false;
            foreach ( $headers_no_from_for_mail as $h ) {
                if ( is_string( $h ) && stripos( $h, 'content-type:' ) === 0 ) {
                    $has_content_type = true;
                    break;
                }
            }
            if ( ! $has_content_type ) {
                $headers_no_from_for_mail[] = 'Content-Type: text/html; charset=UTF-8';
            }

            $admin_sent = wp_mail( $to_list, $subject, $body, self::normalize_headers_for_wp_mail( $headers_no_from_for_mail ), $attachments );
        }

        // Send BCC as individual messages (more reliable across SMTP plugins).
        // BCC recipients should not be visible to each other or to TO/CC.
        $bcc_sent = array();
        if ( ! empty( $bcc_list ) ) {
            $exclude = array_merge( $to_list, $cc_list );
            $exclude = array_map( 'strtolower', array_filter( array_map( 'trim', $exclude ) ) );

            $headers_bcc_for_mail = array();
            foreach ( $headers_for_mail as $h ) {
                if ( ! is_string( $h ) ) {
                    continue;
                }
                if ( stripos( $h, 'cc:' ) === 0 || stripos( $h, 'bcc:' ) === 0 ) {
                    continue;
                }
                $headers_bcc_for_mail[] = $h;
            }

            foreach ( $bcc_list as $bcc_email ) {
                $bcc_email = strtolower( trim( (string) $bcc_email ) );
                if ( ! $bcc_email || in_array( $bcc_email, $exclude, true ) ) {
                    continue;
                }

                $bcc_ok = wp_mail( $bcc_email, $subject, $body, self::normalize_headers_for_wp_mail( $headers_bcc_for_mail ), $attachments );
                if ( ! $bcc_ok && ! empty( $from_info['overridden'] ) ) {
                    $headers_bcc_no_from = self::strip_from_header( $headers_bcc_for_mail );
                    $bcc_ok = wp_mail( $bcc_email, $subject, $body, self::normalize_headers_for_wp_mail( $headers_bcc_no_from ), $attachments );
                }

                $bcc_sent[ $bcc_email ] = array(
                    'sent'  => (bool) $bcc_ok,
                    'error' => self::last_mail_error(),
                );
            }
        }

        $log['admin'] = array(
            'to' => implode( ', ', $to_list ),
            'subject' => $subject,
            'sent' => (bool) $admin_sent,
            'error' => self::last_mail_error(),
        );

        if ( ! empty( $bcc_sent ) ) {
            $log['bcc'] = $bcc_sent;
        }

        // Autoresponder
        if ( ! empty( $form_settings['autoresponder_enabled'] ) ) {
            $to_field = sanitize_key( (string) $form_settings['autoresponder_to_field'] );
            $submitter = ( $to_field && isset( $data[ $to_field ] ) ) ? (string) $data[ $to_field ] : '';
            if ( is_email( $submitter ) ) {
                $a_subject = self::apply_tokens( (string) $form_settings['autoresponder_subject'], $tokens, $form_name );

                // Repair newline artifacts before the HTML pass so wpautop() can produce proper paragraphs/line breaks.
                $a_body_rendered = self::apply_tokens( (string) $form_settings['autoresponder_body'], $tokens, $form_name );
                $a_body_rendered = self::repair_template_newline_artifacts( $a_body_rendered );
                $a_body = self::prepare_email_body_html( $a_body_rendered );

                $a_headers = array( 'Content-Type: text/html; charset=UTF-8' );
                if ( isset( $from_info ) && ! empty( $from_info['header'] ) ) {
                    $a_headers[] = $from_info['header'];
                }

                $a_sent = wp_mail( $submitter, $a_subject, $a_body, self::normalize_headers_for_wp_mail( $a_headers ) );
                $log['autoresponder'] = array(
                    'to' => $submitter,
                    'subject' => $a_subject,
                    'sent' => (bool) $a_sent,
                    'error' => self::last_mail_error(),
                );
            }
        }

        return $log;
    }

	private static function build_tokens( $form_id, $entry_id, $data, $is_html = false ) {
		$post = get_post( $form_id );
		$form_name = $post ? $post->post_title : 'Form';

		// Map field ids to labels/types from the saved form definition.
		$field_labels = array();
		$field_types  = array();
		$raw_def = get_post_meta( $form_id, 'ct_form_definition', true );
		$def = is_string( $raw_def ) ? json_decode( $raw_def, true ) : ( is_array( $raw_def ) ? $raw_def : array() );
		if ( is_array( $def ) && ! empty( $def['fields'] ) && is_array( $def['fields'] ) ) {
			foreach ( $def['fields'] as $f ) {
				$fid = isset( $f['id'] ) ? sanitize_key( (string) $f['id'] ) : '';
				if ( '' === $fid ) { continue; }
				$field_labels[ $fid ] = isset( $f['label'] ) && $f['label'] !== '' ? (string) $f['label'] : $fid;
				$field_types[ $fid ]  = isset( $f['type'] ) ? sanitize_key( (string) $f['type'] ) : '';
			}
		}

		$all_fields_text_lines = array();
		$all_fields_html_lines = array();

        foreach ( (array) $data as $k => $v ) {
            if ( is_array( $v ) ) {
                $v = implode( ', ', $v );
            }

            $label = isset( $field_labels[ $k ] ) ? (string) $field_labels[ $k ] : (string) $k;
            $field_type = isset( $field_types[ $k ] ) ? (string) $field_types[ $k ] : '';
            $value = (string) $v;

            // Normalize escaped sequences and legacy artifacts sometimes produced by slashing/unslashing.
            $value = str_replace( array( "\\r\\n", "\\n", "\\r" ), "\n", $value );
            $value = preg_replace( "/\r\n|\r/", "\n", $value );

            // Legacy artifacts where escaped newlines got stripped:
            // - "\\r\\n" -> "rn"
            // - "\\n"      -> "n"
            $value = str_replace( 'rnrn', "\n\n", $value );
            $value = preg_replace( '/rn(?=Entry\b)/', "\n", $value );
            $value = preg_replace( '/rn(?=Reference\b)/', "\n", $value );

            $value = preg_replace( '/nn(?=Entry\b)/', "\n\n", $value );
            $value = preg_replace( '/nn(?=Reference\b)/', "\n\n", $value );

            // Common: stripped "\n\n" becomes "nn" at paragraph boundaries.
            $value = preg_replace( '/nn(?=[A-Z])/', "\n\n", $value );

            $value = preg_replace( '/n(?=Entry\b)/', "\n", $value );
            $value = preg_replace( '/n(?=Reference\b)/', "\n", $value );

            // Collapse runs of blank lines.
            $value = preg_replace( "/\n{3,}/", "\n\n", $value );

            $all_fields_text_lines[] = $label . ': ' . $value;
            if ( 'diagnostics' === $field_type ) {
                $all_fields_html_lines[] = '<strong>' . esc_html( $label ) . '</strong>:<br><pre style="margin:6px 0 0;white-space:pre-wrap;">' . esc_html( $value ) . '</pre>';
            } else {
                $all_fields_html_lines[] = '<strong>' . esc_html( $label ) . '</strong>: ' . nl2br( esc_html( $value ) );
            }
        }

		$all_fields_text = implode( "\n", $all_fields_text_lines );
		$all_fields_html = implode( "<br>", $all_fields_html_lines );

        $tokens = array(
            '{form_name}'  => $form_name,
            '{entry_id}'   => (string) $entry_id,
            '{all_fields}' => $is_html ? $all_fields_html : $all_fields_text,
        );

        foreach ( (array) $data as $k => $v ) {
            if ( is_array( $v ) ) {
                $v = implode( ', ', $v );
            }
            $tokens[ '{field:' . $k . '}' ] = (string) $v;
        }

        return $tokens;
    }

    
    private static function site_domain() {
        $home = home_url();
        $host = wp_parse_url( $home, PHP_URL_HOST );
        return $host ? strtolower( preg_replace( '/^www\./', '', $host ) ) : '';
    }

    private static function email_domain( $email ) {
        $email = (string) $email;
        if ( false === strpos( $email, '@' ) ) { return ''; }
        $parts = explode( '@', $email );
        $dom = strtolower( end( $parts ) );
        return preg_replace( '/^www\./', '', $dom );
    }

    private static function strip_from_header( $headers ) {
        $out = array();
        foreach ( (array) $headers as $h ) {
            if ( 0 === stripos( (string) $h, 'From:' ) ) { continue; }
            $out[] = $h;
        }
        return $out;
    }

    private static function build_from_header( $form_id, $form_settings ) {
        $global = CT_Forms_Admin::get_settings();
        $mode = isset( $global['from_mode'] ) ? sanitize_key( (string) $global['from_mode'] ) : 'default';

        // Default: let SMTP / wp_mail defaults control From. Use Reply-To for the submitter.
        if ( 'site' !== $mode ) {
            return array(
                'header' => '',
                'overridden' => false,
            );
        }

        $from_email = sanitize_email( (string) get_option( 'admin_email' ) );
        if ( ! is_email( $from_email ) ) {
            $from_email = '';
        }

        $from_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        return array(
            'header' => ( $from_email !== '' ) ? sprintf( 'From: %s <%s>', $from_name, $from_email ) : '',
            'overridden' => ( $from_email !== '' ),
        );
    }


    private static function apply_tokens( $text, $tokens, $form_name ) {
        $out = strtr( (string) $text, $tokens );

        // Back-compat: {form} token
        $out = str_replace( '{form}', $form_name, $out );

        /**
         * Filter: ct_forms_apply_tokens
         */
        return apply_filters( 'ct_forms_apply_tokens', $out, $tokens );
    }

    private static function last_mail_error() {
        global $phpmailer;
        if ( isset( $phpmailer ) && is_object( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) {
            return (string) $phpmailer->ErrorInfo;
        }
        return '';
    }

    public static function handle_download() {
        if ( ! current_user_can( 'ct_forms_view_entries' ) && ! current_user_can( 'ct_forms_manage' ) ) {
            wp_die( 'Not allowed' );
        }

        $entry_id = isset( $_GET['entry_id'] ) ? (int) $_GET['entry_id'] : 0;
        $field_id = isset( $_GET['field_id'] ) ? sanitize_key( (string) $_GET['field_id'] ) : '';
        $file_index = isset( $_GET['file_index'] ) ? max( 0, (int) $_GET['file_index'] ) : 0;
        $nonce = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';

        if ( $entry_id <= 0 || '' === $field_id ) {
            wp_die( 'Invalid request' );
        }

        if ( ! wp_verify_nonce( $nonce, 'ct_forms_download_' . $entry_id . '_' . $field_id . '_' . $file_index ) ) {
            wp_die( 'Invalid nonce' );
        }

        $entry = CT_Forms_DB::get_entry( $entry_id );
        if ( ! $entry ) {
            wp_die( 'File not found' );
        }

        // Back-compat: some legacy entries may have stored the key with the input name.
        $files_map = isset( $entry['files'] ) && is_array( $entry['files'] ) ? $entry['files'] : array();
        if ( empty( $files_map[ $field_id ] ) && ! empty( $files_map[ 'truitt_field_' . $field_id ] ) ) {
            $field_id = 'truitt_field_' . $field_id;
        }

        if ( empty( $files_map[ $field_id ] ) ) {
            wp_die( 'File not found' );
        }

        $f = $files_map[ $field_id ];
        if ( is_array( $f ) && isset( $f[0] ) ) {
            $f = isset( $f[ $file_index ] ) ? $f[ $file_index ] : $f[0];
        }

        $path = isset( $f['path'] ) ? (string) $f['path'] : ( isset( $f['file'] ) ? (string) $f['file'] : '' );
        if ( '' === $path || ! file_exists( $path ) ) {
            wp_die( 'File not found' );
        }

        if ( ! self::is_safe_upload_path( $path ) ) {
            wp_die( 'Invalid file path' );
        }

        $name = isset( $f['original'] ) ? (string) $f['original'] : ( isset( $f['name'] ) ? (string) $f['name'] : basename( $path ) );
        $type = isset( $f['type'] ) ? (string) $f['type'] : 'application/octet-stream';

        nocache_headers();
        header( 'Content-Type: ' . $type );
        header( 'Content-Disposition: attachment; filename="' . basename( $name ) . '"' );
        header( 'Content-Length: ' . filesize( $path ) );

        readfile( $path );
        exit;
    }

    public static function handle_delete_file() {
        if ( ! current_user_can( 'ct_forms_manage' ) ) {
            wp_die( 'Not allowed' );
        }

        $entry_id = isset( $_GET['entry_id'] ) ? (int) $_GET['entry_id'] : 0;
        $field_id = isset( $_GET['field_id'] ) ? sanitize_key( (string) $_GET['field_id'] ) : '';
        $file_index = isset( $_GET['file_index'] ) ? max( 0, (int) $_GET['file_index'] ) : 0;
        $nonce = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';

        if ( $entry_id <= 0 || '' === $field_id ) {
            wp_die( 'Invalid request' );
        }

        if ( ! wp_verify_nonce( $nonce, 'ct_forms_delete_' . $entry_id . '_' . $field_id . '_' . $file_index ) ) {
            wp_die( 'Invalid nonce' );
        }

        $entry = CT_Forms_DB::get_entry( $entry_id );
        if ( ! $entry || empty( $entry['files'][ $field_id ] ) ) {
            wp_die( 'File not found' );
        }

        $files = $entry['files'];
        $target = $files[ $field_id ];
        $is_multi = ( is_array( $target ) && isset( $target[0] ) );
        $file_obj = $is_multi ? ( $target[ $file_index ] ?? null ) : $target;

        if ( empty( $file_obj ) || ! is_array( $file_obj ) ) {
            wp_die( 'File not found' );
        }

        $path = isset( $file_obj['path'] ) ? (string) $file_obj['path'] : ( isset( $file_obj['file'] ) ? (string) $file_obj['file'] : '' );
        if ( '' === $path ) {
            wp_die( 'File not found' );
        }

        if ( ! self::is_safe_upload_path( $path ) ) {
            wp_die( 'Invalid file path' );
        }

        if ( file_exists( $path ) ) {
            @unlink( $path );
        }

        // Remove from entry JSON.
        if ( $is_multi ) {
            unset( $files[ $field_id ][ $file_index ] );
            $files[ $field_id ] = array_values( array_filter( (array) $files[ $field_id ] ) );
            if ( empty( $files[ $field_id ] ) ) {
                unset( $files[ $field_id ] );
            }
        } else {
            unset( $files[ $field_id ] );
        }

        CT_Forms_DB::update_entry_files( $entry_id, $files );

        $redirect = isset( $_GET['_redirect'] ) ? esc_url_raw( (string) $_GET['_redirect'] ) : '';
        if ( '' === $redirect ) {
            $redirect = admin_url( 'admin.php?page=ct-forms-files' );
        }
        $redirect = add_query_arg( 'ct_forms_file_deleted', 1, $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handle_delete_all_files() {
        if ( ! current_user_can( 'ct_forms_manage' ) ) {
            wp_die( 'Not allowed' );
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? (string) $_POST['_wpnonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'ct_forms_delete_all_files' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
        $status  = isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'all';
        $search  = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['s'] ) ) : '';
        $search  = trim( $search );
        $needle  = strtolower( $search );

        if ( ! in_array( $status, array( 'all', 'ok', 'missing' ), true ) ) {
            $status = 'all';
        }

        $deleted = 0;
        $paged = 1;
        $per_page = 200;

        do {
            $q = CT_Forms_DB::get_entries_with_files( array(
                'paged'    => $paged,
                'per_page' => $per_page,
                'form_id'  => $form_id,
                // leave search empty here; we do filename filtering per-file, not per-entry
                'search'   => '',
            ) );

            $total = isset( $q['total'] ) ? (int) $q['total'] : 0;
            $items = isset( $q['items'] ) ? (array) $q['items'] : array();
            $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

            foreach ( $items as $entry ) {
                $entry_id = isset( $entry['entry_id'] ) ? (int) $entry['entry_id'] : ( isset( $entry['id'] ) ? (int) $entry['id'] : 0 );
                if ( $entry_id <= 0 ) { continue; }

                $full = CT_Forms_DB::get_entry( $entry_id );
                if ( ! $full || empty( $full['files'] ) || ! is_array( $full['files'] ) ) {
                    continue;
                }

                $files = $full['files'];
                $changed = false;

                foreach ( $files as $field_id => $file_obj ) {
                    $field_id = sanitize_key( (string) $field_id );
                    if ( '' === $field_id ) { continue; }

                    $is_multi = ( is_array( $file_obj ) && isset( $file_obj[0] ) );
                    $list = $is_multi ? (array) $file_obj : array( $file_obj );

                    $kept = array();

                    foreach ( $list as $f ) {
                        if ( ! is_array( $f ) ) { continue; }

                        $path = isset( $f['path'] ) ? (string) $f['path'] : ( isset( $f['file'] ) ? (string) $f['file'] : '' );
                        $orig = isset( $f['original'] ) ? (string) $f['original'] : ( isset( $f['name'] ) ? (string) $f['name'] : '' );

                        // Search filter (by original name and path)
                        $matches_search = true;
                        if ( '' !== $needle ) {
                            $hay = strtolower( $orig . ' ' . $path );
                            $matches_search = ( false !== strpos( $hay, $needle ) );
                        }

                        $exists = ( '' !== $path && file_exists( $path ) );
                        $matches_status = true;
                        if ( 'ok' === $status ) {
                            $matches_status = $exists;
                        } elseif ( 'missing' === $status ) {
                            $matches_status = ! $exists;
                        }

                        if ( $matches_search && $matches_status ) {
                            // Delete physical file when present and safe.
                            if ( '' !== $path && self::is_safe_upload_path( $path ) && file_exists( $path ) ) {
                                @unlink( $path );
                            }
                            $deleted++;
                            $changed = true;
                            continue; // remove from entry JSON
                        }

                        $kept[] = $f;
                    }

                    if ( $changed ) {
                        if ( empty( $kept ) ) {
                            unset( $files[ $field_id ] );
                        } else {
                            $files[ $field_id ] = $is_multi ? array_values( $kept ) : $kept[0];
                        }
                    }
                }

                if ( $changed ) {
                    CT_Forms_DB::update_entry_files( $entry_id, $files );
                }
            }

            $paged++;
        } while ( $paged <= $total_pages );

        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( (string) $_POST['_redirect'] ) : '';
        if ( '' === $redirect ) {
            $redirect = admin_url( 'admin.php?page=ct-forms-files' );
        }

        $redirect = add_query_arg( array(
            'ct_forms_deleted_all' => 1,
            'ct_forms_deleted_all_count' => (int) $deleted,
        ), $redirect );

        wp_safe_redirect( $redirect );
        exit;
    }


function handle_delete_entry() {
        if ( ! current_user_can( 'ct_forms_manage' ) ) {
            wp_die( 'Not allowed' );
        }

        $entry_id = isset( $_GET['entry_id'] ) ? (int) $_GET['entry_id'] : 0;
        $nonce    = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';
        $redirect = isset( $_GET['_redirect'] ) ? esc_url_raw( (string) $_GET['_redirect'] ) : '';

        if ( $entry_id <= 0 ) {
            wp_die( 'Invalid request' );
        }
        if ( ! wp_verify_nonce( $nonce, 'ct_forms_delete_entry_' . $entry_id ) ) {
            wp_die( 'Invalid nonce' );
        }

        $entry = CT_Forms_DB::get_entry( $entry_id );
        if ( ! $entry ) {
            wp_die( 'Entry not found' );
        }

        self::delete_entry_and_files( $entry_id );

        if ( '' === $redirect ) {
            $redirect = admin_url( 'admin.php?page=ct-forms-entries&form_id=' . (int) ( $entry['form_id'] ?? 0 ) );
        }
        $redirect = add_query_arg( 'ct_forms_entry_deleted', 1, $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Delete an entry and any associated uploaded files (best effort).
     *
     * This is used by both the single-entry delete action and bulk actions.
     */
    public static function delete_entry_and_files( $entry_id ) {
        $entry_id = (int) $entry_id;
        if ( $entry_id <= 0 ) {
            return false;
        }

        $entry = CT_Forms_DB::get_entry( $entry_id );
        if ( ! $entry ) {
            return false;
        }

        $files = isset( $entry['files'] ) ? $entry['files'] : array();
        if ( is_array( $files ) && ! empty( $files ) ) {
            foreach ( $files as $field_id => $file_val ) {
                if ( is_array( $file_val ) && isset( $file_val[0] ) ) {
                    foreach ( (array) $file_val as $obj ) {
                        self::delete_file_obj_from_disk( $obj );
                    }
                } else {
                    self::delete_file_obj_from_disk( $file_val );
                }
            }
        }

        return CT_Forms_DB::delete_entry( $entry_id );
    }

    private static function delete_file_obj_from_disk( $file_obj ) {
        if ( empty( $file_obj ) || ! is_array( $file_obj ) ) {
            return;
        }
        $path = isset( $file_obj['path'] ) ? (string) $file_obj['path'] : ( isset( $file_obj['file'] ) ? (string) $file_obj['file'] : '' );
        if ( '' === $path ) {
            return;
        }
        if ( ! self::is_safe_upload_path( $path ) ) {
            return;
        }
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    private static function is_safe_upload_path( $path ) {
        $uploads = wp_upload_dir();
        $base = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
        if ( '' === $base ) {
            return false;
        }

        $allowed_root = trailingslashit( $base ) . 'ct-forms';
        $real_allowed = realpath( $allowed_root );
        $real_path = realpath( $path );
        if ( false === $real_allowed || false === $real_path ) {
            return false;
        }

        return ( 0 === strpos( $real_path, $real_allowed ) );
    }

    /**
     * Build a plain-text diagnostics block (WP/PHP/plugin/theme/active plugins).
     * Used by the "diagnostics" virtual field type.
     */
    private static function build_diagnostics_text() {
        $theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
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

        $diag = array();
        if ( defined( 'CT_FORMS_VERSION' ) ) {
            $diag[] = 'CT Forms version: ' . CT_FORMS_VERSION;
        }
        $diag[] = 'Site: ' . home_url();
        $diag[] = 'WP: ' . get_bloginfo( 'version' );
        $diag[] = 'PHP: ' . PHP_VERSION;
        if ( $theme_line ) {
            $diag[] = 'Theme: ' . $theme_line;
        }
        $diag[] = 'Active plugins:';
        if ( $plugins ) {
            foreach ( $plugins as $p ) {
                $diag[] = ' - ' . $p;
            }
        } else {
            $diag[] = ' - (unable to list plugins)';
        }

        return implode( "\n", $diag );
    }

    private static function get_ip() {
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip = trim( $ips[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = (string) $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field( $ip );
    }

    private static function current_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        return esc_url_raw( $scheme . '://' . $host . $uri );
    }
}

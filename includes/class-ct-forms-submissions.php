<?php
/**
 * Form submissions handler.
 *
 * @package CT_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CT_Forms_Submissions class.
 *
 * @package CT_Forms
 */
final class CT_Forms_Submissions {

	/**
	 * Verify reCAPTCHA.
	 *
	 * @param int $form_id Form post ID.
	 * @throws Exception If verification fails.
	 */
	private static function verify_recaptcha_or_throw( $form_id ) {
		// Fixed: Check nonce before processing POST data.
		if ( ! isset( $_POST['ct_form_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ct_form_nonce'] ), 'ct_form_submit_' . $form_id ) ) {
			throw new Exception( 'security_check_failed' );
		}

		$settings = CT_Forms_Admin::get_settings();

		$form_settings = array();
		if ( class_exists( 'CT_Forms_CPT' ) && is_callable( array( 'CT_Forms_CPT', 'get_form_settings' ) ) ) {
			$form_settings = (array) CT_Forms_CPT::get_form_settings( (int) $form_id );
		}
		if ( empty( $form_settings['recaptcha_enabled'] ) ) {
			return;
		}

		$type = isset( $settings['recaptcha_type'] ) ? (string) $settings['recaptcha_type'] : 'disabled';

		if ( 'disabled' === $type ) {
			return;
		}

		$secret_key = isset( $settings['recaptcha_secret_key'] ) ? trim( (string) $settings['recaptcha_secret_key'] ) : '';
		if ( empty( $secret_key ) ) {
			return;
		}

		$recaptcha_response = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
		if ( empty( $recaptcha_response ) ) {
			throw new Exception( 'recaptcha_missing' );
		}

		$verify_url = 'https://www.google.com/recaptcha/api/siteverify';
		$response   = wp_remote_post(
			$verify_url,
			array(
				'body'    => array(
					'secret'   => $secret_key,
					'response' => $recaptcha_response,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'recaptcha_request_failed' );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data['success'] ) ) {
			throw new Exception( 'recaptcha_failed' );
		}

		if ( 'v3' === $type ) {
			$threshold = isset( $settings['recaptcha_v3_threshold'] ) ? floatval( $settings['recaptcha_v3_threshold'] ) : 0.5;
			$score     = isset( $data['score'] ) ? floatval( $data['score'] ) : 0.0;
			if ( $score < $threshold ) {
				throw new Exception( 'recaptcha_score_too_low' );
			}
		}
	}

	/**
	 * Delete entry and associated files.
	 *
	 * @param int $id Entry ID.
	 * @return bool
	 */
	public static function delete_entry_and_files( $id ) {
		$entry = CT_Forms_DB::get_entry( (int) $id );
		if ( ! $entry ) {
			return false;
		}

		if ( ! empty( $entry['data'] ) && is_array( $entry['data'] ) ) {
			foreach ( $entry['data'] as $val ) {
				if ( is_string( $val ) && 0 === strpos( $val, 'FILE:' ) ) {
					$rel     = substr( $val, 5 );
					$up      = wp_upload_dir();
					$basedir = isset( $up['basedir'] ) ? (string) $up['basedir'] : '';
					if ( $basedir ) {
						$path = path_join( $basedir, $rel );
						if ( file_exists( $path ) && is_file( $path ) ) {
							wp_delete_file( $path );
						}
					}
				}
			}
		}

		return CT_Forms_DB::delete_entry( (int) $id );
	}

	/**
	 * Get diagnostic information.
	 *
	 * @return string
	 */
	public static function get_diagnostic_info() {
		$plugins    = array();
		$theme      = wp_get_theme();
		$theme_line = $theme->exists() ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : '';

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
		$diag[] = 'Site: ' . home_url();
		$diag[] = 'WP: ' . get_bloginfo( 'version' );
		$diag[] = 'PHP: ' . PHP_VERSION;
		if ( $theme_line ) {
			$diag[] = 'Theme: ' . $theme_line;
		}
		$diag[] = 'Active plugins:';
		foreach ( $plugins as $p ) {
			$diag[] = ' - ' . $p;
		}

		return implode( "\n", $diag );
	}
}

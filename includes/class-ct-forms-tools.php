<?php
/**
 * Tools and utilities.
 *
 * @package CT_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CT_Forms_Tools class.
 *
 * @package CT_Forms
 */
class CT_Forms_Tools {
	/**
	 * Init method.
	 */
	public static function init() {
		add_action( 'admin_post_ct_forms_export_entries_csv', array( __CLASS__, 'handle_export_entries_csv' ) );
		add_action( 'admin_post_ct_forms_cleanup', array( __CLASS__, 'handle_cleanup' ) );
	}

	/**
	 * Page_tools method.
	 */
	public static function page_tools() {
		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ct-forms' ) );
		}

		// ... page content ...
	}

	/**
	 * Handle database cleanup.
	 */
	public static function handle_cleanup() {
		global $wpdb;

		if ( ! current_user_can( 'ct_forms_manage' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'ct-forms' ) );
		}

		check_admin_referer( 'ct_forms_cleanup' );

		$table = CT_Forms_DB::entries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE status = %s", 'spam' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=ct-forms-tools&cleanup=1' ) );
		exit;
	}

	/**
	 * Check if something is true using Yoda conditions.
	 *
	 * @param mixed $val Value to check.
	 * @return bool
	 */
	private static function is_enabled( $val ) {
		if ( true === $val ) {
			return true;
		}
		return false;
	}
}

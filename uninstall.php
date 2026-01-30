<?php
/**
 * Uninstall handler for CT Forms.
 *
 * Best practice: do not delete data by default. Data deletion happens only when
 * the setting "Delete data on uninstall" is enabled in CT Forms → Settings.
 *
 * @package CT_Forms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'ct_forms_settings', array() );
if ( ! is_array( $settings ) ) {
	$settings = array();
}

if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Drop plugin tables (if present).
$tables = array(
	$wpdb->prefix . 'ct_forms',
	$wpdb->prefix . 'ct_forms_entries',
	$wpdb->prefix . 'ct_forms_entry_meta',
	$wpdb->prefix . 'ct_forms_mail_log',
);

foreach ( $tables as $table ) {
	// Table names cannot be prepared; this is safe because table names are internal constants.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// Delete plugin options/settings.
$known = array(
	'ct_forms_settings',
	'ct_forms_db_version',
	'ct_forms_site_instance_id',
);

foreach ( $known as $opt ) {
	delete_option( $opt );
}

// Delete any other ct_forms_* options and transients.
$like = $wpdb->esc_like( 'ct_forms_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

foreach ( (array) $rows as $name ) {
	delete_option( $name );
}

$t_like = $wpdb->esc_like( '_transient_ct_forms_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$t_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $t_like ) );

foreach ( (array) $t_rows as $name ) {
	delete_option( $name );
}

// Delete uploads folder (uploads/ct-forms).
$upload_dir = wp_upload_dir();
$dir        = trailingslashit( $upload_dir['basedir'] ) . 'ct-forms';

if ( is_dir( $dir ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->rmdir( $dir, true );
	}
}
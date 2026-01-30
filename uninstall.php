<?php
/**
 * Uninstall handler for CT Forms.
 *
 * Best practice: do not delete data by default. Data deletion happens only when the
 * setting 'Delete data on uninstall' is enabled in CT Forms → Settings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$settings = get_option( 'ct_forms_settings', array() );
if ( ! is_array( $settings ) ) { $settings = array(); }

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
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
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
$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
foreach ( (array) $rows as $name ) {
    delete_option( $name );
}

$t_like = $wpdb->esc_like( '_transient_ct_forms_' ) . '%';
$t_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $t_like ) );
foreach ( (array) $t_rows as $name ) {
    delete_option( $name );
}

// Delete uploads folder (uploads/ct-forms).
$upload_dir = wp_upload_dir();
$dir = trailingslashit( $upload_dir['basedir'] ) . 'ct-forms';

if ( is_dir( $dir ) ) {
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
    @rmdir( $dir );
}

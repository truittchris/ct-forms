<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CT_Forms_DB {

    // Bump this when schema/migrations change.
    const SCHEMA_VERSION = '1.0.14';


    public static function entries_table() {
        global $wpdb;
        return $wpdb->prefix . 'ct_form_entries';
    }

    public static function maybe_create_tables() {
        // Back-compat shim
        self::ensure_schema();
    }

    public static function ensure_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table   = self::entries_table();

        // Avoid running dbDelta (and migrations) on every request.
        // Run when:
        // - table does not exist, or
        // - stored schema version is different from current.
        $installed_version = (string) get_option( 'ct_forms_db_version', '' );
        $table_exists      = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        if ( $table_exists && $installed_version === self::SCHEMA_VERSION ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            submitted_at DATETIME NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            referrer TEXT NULL,
            page_url TEXT NULL,
            data LONGTEXT NOT NULL,
            files LONGTEXT NULL,
            mail_log LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY status (status),
            KEY submitted_at (submitted_at)
        ) {$charset};";

        // Run dbDelta to create/alter table.
        dbDelta( $sql );

        // Migrate/backfill from older column names if present.
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        if ( is_array( $cols ) ) {
            // submitted_at backfill
            if ( in_array( 'submitted_at', $cols, true ) ) {
                $source = '';
                foreach ( array( 'submitted', 'created_at', 'created', 'created_on' ) as $candidate ) {
                    if ( in_array( $candidate, $cols, true ) ) { $source = $candidate; break; }
                }
                if ( $source ) {
                    // Only backfill where submitted_at is empty/zero.
                    $wpdb->query( "UPDATE {$table} SET submitted_at = {$source} WHERE submitted_at IS NULL OR submitted_at = '0000-00-00 00:00:00'" );
                }

                // Backfill truly invalid legacy values so the Entries screen has usable timestamps.
                // Some legacy installs stored zero/invalid dates which render as "Not recorded".
                $wpdb->query( "UPDATE {$table} SET submitted_at = NOW() WHERE submitted_at IS NULL OR submitted_at = '0000-00-00 00:00:00' OR submitted_at < '1970-01-01 00:00:00'" );
            }
            // status backfill
            if ( in_array( 'status', $cols, true ) ) {
                $source = '';
                foreach ( array( 'state', 'entry_status' ) as $candidate ) {
                    if ( in_array( $candidate, $cols, true ) ) { $source = $candidate; break; }
                }
                if ( $source ) {
                    $wpdb->query( "UPDATE {$table} SET status = {$source} WHERE status IS NULL OR status = ''" );
                }
            }
        }

        update_option( 'ct_forms_db_version', self::SCHEMA_VERSION );
    }


    /**
     * Return cached list of columns for the entries table.
     */
    public static function entries_columns() {
        static $cached = null;
        if ( is_array( $cached ) ) { return $cached; }
        global $wpdb;
        $table = self::entries_table();
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        $cached = is_array( $cols ) ? $cols : array();
        return $cached;
    }

    /**
     * Return the primary key column for the entries table, supporting legacy schemas.
     * Some early installs used `entry_id` instead of `id`.
     */
    public static function entries_pk_column() {
        $cols = self::entries_columns();
        if ( in_array( 'id', $cols, true ) ) { return 'id'; }
        if ( in_array( 'entry_id', $cols, true ) ) { return 'entry_id'; }
        return 'id';
    }


    /**
     * Return the payload/data column for the entries table (supports legacy schemas).
     */
    public static function entries_data_column() {
        $cols = self::entries_columns();
        foreach ( array( 'data_json', 'data', 'entry_data', 'fields', 'payload', 'submission' ) as $c ) {
            if ( in_array( $c, $cols, true ) ) { return $c; }
        }
        return '';
    }

    /**
     * Return the created/submitted datetime column for the entries table (supports legacy schemas).
     */
    public static function entries_created_column() {
        $cols = self::entries_columns();
        foreach ( array( 'created_at', 'submitted_at', 'date_created', 'created', 'submitted', 'created_on' ) as $c ) {
            if ( in_array( $c, $cols, true ) ) { return $c; }
        }
        return '';
    }

    /**
     * Return the IP column for the entries table (supports legacy schemas).
     */
    public static function entries_ip_column() {
        $cols = self::entries_columns();
        foreach ( array( 'ip_address', 'ip', 'user_ip' ) as $c ) {
            if ( in_array( $c, $cols, true ) ) { return $c; }
        }
        return '';
    }

    /**
     * Return the page URL column for the entries table (supports legacy schemas).
     */
    public static function entries_page_url_column() {
        $cols = self::entries_columns();
        foreach ( array( 'page_url', 'url', 'page' ) as $c ) {
            if ( in_array( $c, $cols, true ) ) { return $c; }
        }
        return '';
    }

    /**
     * Normalize a DB row to always include the expected keys.
     */
    public static function normalize_entry_row( $row ) {
        $row = is_array( $row ) ? $row : array();
        $pk  = self::entries_pk_column();

        if ( ! isset( $row['id'] ) && isset( $row[ $pk ] ) ) {
            $row['id'] = $row[ $pk ];
        }

        if ( ! isset( $row['form_id'] ) && isset( $row['form'] ) ) {
            $row['form_id'] = $row['form'];
        }

        if ( ! isset( $row['submitted_at'] ) ) {
            foreach ( array( 'submitted', 'created_at', 'created', 'created_on' ) as $c ) {
                if ( isset( $row[ $c ] ) ) { $row['submitted_at'] = $row[ $c ]; break; }
            }
        }

        if ( ! isset( $row['status'] ) ) {
            foreach ( array( 'state', 'entry_status' ) as $c ) {
                if ( isset( $row[ $c ] ) ) { $row['status'] = $row[ $c ]; break; }
            }
        }

        if ( ! isset( $row['page_url'] ) ) {
            foreach ( array( 'source_url' ) as $c ) {
                if ( isset( $row[ $c ] ) ) { $row['page_url'] = $row[ $c ]; break; }
            }
        }

        return $row;
    }


    public static function insert_entry( $form_id, $data, $files, $meta ) {
        global $wpdb;

        $table = self::entries_table();

        $row = array(
            'form_id'      => (int) $form_id,
            'status'       => 'new',
            'submitted_at' => current_time( 'mysql' ),
            'ip_address'   => isset( $meta['ip'] ) ? sanitize_text_field( $meta['ip'] ) : null,
            'user_agent'   => isset( $meta['ua'] ) ? wp_strip_all_tags( $meta['ua'] ) : null,
            'referrer'     => isset( $meta['ref'] ) ? esc_url_raw( $meta['ref'] ) : null,
            'page_url'     => isset( $meta['url'] ) ? esc_url_raw( $meta['url'] ) : null,
            'data'         => wp_json_encode( $data ),
            'files'        => $files ? wp_json_encode( $files ) : null,
            'mail_log'     => null,
        );

        $wpdb->insert( $table, $row );
        return (int) $wpdb->insert_id;
    }

    public static function update_entry_status( $entry_id, $status ) {
        global $wpdb;
        $table = self::entries_table();
        $allowed = array( 'new', 'reviewed', 'follow_up', 'spam', 'archived' );
        if ( ! in_array( $status, $allowed, true ) ) {
            return false;
        }
        return (bool) $wpdb->update( $table, array( 'status' => $status ), array( self::entries_pk_column() => (int) $entry_id ) );
    }

    public static function update_entry_mail_log( $entry_id, $mail_log ) {
        global $wpdb;
        $table = self::entries_table();
        return (bool) $wpdb->update( $table, array( 'mail_log' => wp_json_encode( $mail_log ) ), array( self::entries_pk_column() => (int) $entry_id ) );
    }

    public static function update_entry_files( $entry_id, $files ) {
        global $wpdb;
        $table = self::entries_table();

        $value = empty( $files ) ? null : wp_json_encode( $files );
        return (bool) $wpdb->update( $table, array( 'files' => $value ), array( self::entries_pk_column() => (int) $entry_id ) );
    }

    public static function get_entries_with_files( $args = array() ) {
        global $wpdb;
        $table = self::entries_table();

        $defaults = array(
            'paged'    => 1,
            'per_page' => 25,
            'form_id'  => 0,
            'search'   => '',
        );
        $args = wp_parse_args( $args, $defaults );

        $paged = max( 1, (int) $args['paged'] );
        $per_page = max( 1, min( 200, (int) $args['per_page'] ) );
        $offset = ( $paged - 1 ) * $per_page;

        $where = "WHERE files IS NOT NULL AND files <> '' AND files <> 'null'";
        $params = array();

        if ( ! empty( $args['form_id'] ) ) {
            $where .= ' AND form_id = %d';
            $params[] = (int) $args['form_id'];
        }

        $search = isset( $args['search'] ) ? (string) $args['search'] : '';
        $search = trim( wp_strip_all_tags( $search ) );
        if ( '' !== $search ) {
            // NOTE: files is stored as JSON; LIKE provides a pragmatic cross-file search (e.g., filename).
            $where .= ' AND files LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        $sql_count = "SELECT COUNT(*) FROM {$table} {$where}";
        // Only call $wpdb->prepare() when we have placeholders to fill; otherwise it will trigger a WordPress notice.
        if ( ! empty( $params ) ) {
            $sql_count = $wpdb->prepare( $sql_count, ...$params );
        }
        $total = (int) $wpdb->get_var( $sql_count );

        $sql_items = "SELECT * FROM {$table} {$where} ORDER BY submitted_at DESC LIMIT %d OFFSET %d";
        $params_items = array_merge( $params, array( $per_page, $offset ) );
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql_items, ...$params_items ), ARRAY_A );

        foreach ( $rows as &$row ) {
            $row = self::normalize_entry_row( $row );
            $row['data'] = $row['data'] ? json_decode( $row['data'], true ) : array();
            $row['files'] = $row['files'] ? json_decode( $row['files'], true ) : array();
        }

        return array(
            'total' => $total,
            'items' => $rows,
        );
    }

    public static function get_entry( $entry_id ) {
        global $wpdb;
        $table = self::entries_table();
        $pk = self::entries_pk_column();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$pk} = %d", (int) $entry_id ), ARRAY_A );
        $row = self::normalize_entry_row( $row );
        if ( ! $row ) { return null; }
        $row['data'] = json_decode( $row['data'], true );
        $row['files'] = $row['files'] ? json_decode( $row['files'], true ) : array();
        $row['mail_log'] = $row['mail_log'] ? json_decode( $row['mail_log'], true ) : array();
        return $row;
    }

    public static function delete_entry( $entry_id ) {
        global $wpdb;
        $table = self::entries_table();
        $pk = self::entries_pk_column();
        return (bool) $wpdb->delete( $table, array( $pk => (int) $entry_id ) );
    }

    public static function delete_entries_older_than_days( $days ) {
        global $wpdb;
        $table = self::entries_table();
        $days = (int) $days;
        if ( $days <= 0 ) { return 0; }
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE submitted_at < ( NOW() - INTERVAL %d DAY )",
            $days
        ) );
    }
}

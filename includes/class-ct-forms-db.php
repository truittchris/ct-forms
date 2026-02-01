<?php
/**
 * Database helpers for CT Forms.
 *
 * @package CT_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database helper for CT Forms.
 *
 * Provides CRUD helpers and query methods for CT Forms tables.
 */
class CT_Forms_DB {

	/**
	 * Schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1.2.0';

	/**
	 * Get the entries table name.
	 *
	 * @return string
	 */
	public static function entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'ct_forms_entries';
	}

	/**
	 * Get the forms table name.
	 *
	 * @return string
	 */
	public static function forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'ct_forms_forms';
	}

	/**
	 * Ensure schema is installed and up to date.
	 *
	 * @return void
	 */
	public static function maybe_install_schema() {
		global $wpdb;

		$table = self::entries_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

		$installed_version = get_option( 'ct_forms_schema_version', '' );
		if ( $table_exists && version_compare( $installed_version, self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			form_id bigint(20) NOT NULL,
			status varchar(50) DEFAULT 'new' NOT NULL,
			data longtext NOT NULL,
			mail_log longtext DEFAULT NULL,
			page_url text DEFAULT NULL,
			user_agent text DEFAULT NULL,
			remote_ip varchar(100) DEFAULT NULL,
			submitted_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY submitted_at (submitted_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'ct_forms_schema_version', self::SCHEMA_VERSION );
	}

	/**
	 * Create an entry.
	 *
	 * @param array $args Entry arguments.
	 * @return int|false
	 */
	public static function create_entry( array $args ) {
		global $wpdb;

		$data = array(
			'form_id'      => isset( $args['form_id'] ) ? (int) $args['form_id'] : 0,
			'status'       => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'new',
			'data'         => isset( $args['data'] ) ? wp_json_encode( $args['data'] ) : wp_json_encode( array() ),
			'page_url'     => isset( $args['page_url'] ) ? esc_url_raw( $args['page_url'] ) : '',
			'user_agent'   => isset( $args['user_agent'] ) ? sanitize_text_field( $args['user_agent'] ) : '',
			'remote_ip'    => isset( $args['remote_ip'] ) ? sanitize_text_field( $args['remote_ip'] ) : '',
			'submitted_at' => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( self::entries_table(), $data );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get an entry by ID.
	 *
	 * @param int $id Entry ID.
	 * @return array|null
	 */
	public static function get_entry( $id ) {
		global $wpdb;

		$table = self::entries_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		return self::normalize_entry_row( $row );
	}

	/**
	 * Update an entry's status.
	 *
	 * @param int    $id     Entry ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function update_entry_status( $id, $status ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			self::entries_table(),
			array( 'status' => sanitize_key( $status ) ),
			array( 'id' => (int) $id )
		);

		return false !== $result;
	}

	/**
	 * Update an entry's mail log.
	 *
	 * @param int   $id  Entry ID.
	 * @param array $log Log data.
	 * @return bool
	 */
	public static function update_entry_mail_log( $id, array $log ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			self::entries_table(),
			array( 'mail_log' => wp_json_encode( $log ) ),
			array( 'id' => (int) $id )
		);

		return false !== $result;
	}

	/**
	 * Delete an entry.
	 *
	 * @param int $id Entry ID.
	 * @return bool
	 */
	public static function delete_entry( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( self::entries_table(), array( 'id' => (int) $id ) );

		return false !== $result;
	}

	/**
	 * Get entries.
	 *
	 * @param array $filters Filters and pagination.
	 * @return array { count: int, items: array }
	 */
	public static function get_entries( array $filters = array() ) {
		global $wpdb;

		$table  = self::entries_table();
		$where  = array( '1=1' );
		$params = array();
		$per    = isset( $filters['per_page'] ) ? max( 1, (int) $filters['per_page'] ) : 20;
		$page   = isset( $filters['page'] ) ? max( 1, (int) $filters['page'] ) : 1;
		$offset = ( $page - 1 ) * $per;

		if ( ! empty( $filters['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$params[] = (int) $filters['form_id'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table $where_sql", $params ) );

		$sql_params = array_merge( $params, array( $per, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table $where_sql ORDER BY submitted_at DESC, id DESC LIMIT %d OFFSET %d", $sql_params ), ARRAY_A );

		return array(
			'count' => $count,
			'items' => array_map( array( __CLASS__, 'normalize_entry_row' ), (array) $items ),
		);
	}

	/**
	 * Normalize an entry row for consistent downstream use.
	 *
	 * @param array $row Raw entry row from the database.
	 *
	 * @return array Normalized row.
	 */
	public static function normalize_entry_row( $row ) {
		$row = (array) $row;

		$row['id']      = (int) $row['id'];
		$row['form_id'] = (int) $row['form_id'];
		$row['data']    = json_decode( (string) $row['data'], true );
		if ( ! is_array( $row['data'] ) ) {
			$row['data'] = array();
		}

		$row['mail_log'] = json_decode( (string) $row['mail_log'], true );
		if ( ! is_array( $row['mail_log'] ) ) {
			$row['mail_log'] = array();
		}

		return $row;
	}
}

<?php
/**
 * Entries list table.
 *
 * @package CT_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Entries list table.
 *
 * @package CT_Forms
 */
final class CT_Forms_Entries_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get table columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'id'           => esc_html__( 'ID', 'ct-forms' ),
			'form'         => esc_html__( 'Form', 'ct-forms' ),
			'status'       => esc_html__( 'Status', 'ct-forms' ),
			'submitted_at' => esc_html__( 'Submitted', 'ct-forms' ),
			'page_url'     => esc_html__( 'Page', 'ct-forms' ),
		);
	}

	/**
	 * Prepare the items for the table.
	 */
	public function prepare_items() {
		global $wpdb;

		$table        = CT_Forms_DB::entries_table();
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['form_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$where[] = 'form_id = %d';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$params[] = (int) $_REQUEST['form_id'];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['status'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$where[] = 'status = %s';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$params[] = sanitize_key( wp_unslash( $_REQUEST['status'] ) );
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total_items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table $where_sql", $params ) );

		$sql_params = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table $where_sql ORDER BY submitted_at DESC, id DESC LIMIT %d OFFSET %d", $sql_params ), ARRAY_A );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="entry_ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Render the status column.
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status = sanitize_key( $item['status'] );
		return sprintf( '<span class="ct-status-%s">%s</span>', $status, esc_html( $status ) );
	}

	/**
	 * Handle CSV Export.
	 *
	 * @param array $ids Entry IDs.
	 */
	private function export_csv( array $ids ) {
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'ct_forms_export' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ct-forms' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ct-forms-entries-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			wp_die( esc_html__( 'Unable to open export stream.', 'ct-forms' ) );
		}

		fputcsv( $out, array( 'entry_id', 'form_id', 'status', 'submitted_at', 'page_url', 'field', 'value' ) );

		foreach ( $ids as $id ) {
			$entry = CT_Forms_DB::get_entry( $id );
			if ( ! $entry ) {
				continue;
			}

			foreach ( (array) $entry['data'] as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}

				fputcsv(
					$out,
					array(
						$entry['id'],
						$entry['form_id'],
						$entry['status'],
						$entry['submitted_at'],
						$entry['page_url'],
						$key,
						$value,
					)
				);
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}
}

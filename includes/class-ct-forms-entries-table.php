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
	 * Get sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'id'           => array( 'id', true ),
			'submitted_at' => array( 'submitted_at', true ),
			'status'       => array( 'status', false ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		$actions = array(
			'mark_reviewed'  => esc_html__( 'Mark reviewed', 'ct-forms' ),
			'mark_follow_up' => esc_html__( 'Mark follow-up', 'ct-forms' ),
			'mark_spam'      => esc_html__( 'Mark spam', 'ct-forms' ),
			'archive'        => esc_html__( 'Archive', 'ct-forms' ),
		);

		if ( current_user_can( 'ct_forms_export_entries' ) ) {
			$actions['export_csv'] = esc_html__( 'Export CSV', 'ct-forms' );
		}

		return $actions;
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_cb( $item ) {
		$id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		return sprintf( '<input type="checkbox" name="entry_ids[]" value="%d" />', $id );
	}

	/**
	 * Render the ID column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_id( $item ) {
		$id  = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		$url = admin_url( 'admin.php?page=ct-forms-entries&entry_id=' . $id );

		return '<a href="' . esc_url( $url ) . '">#' . (int) $id . '</a>';
	}

	/**
	 * Render the Form column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_form( $item ) {
		$form_id = isset( $item['form_id'] ) ? absint( $item['form_id'] ) : 0;
		$post    = ( $form_id > 0 ) ? get_post( $form_id ) : null;
		$name    = $post ? $post->post_title : __( '(deleted)', 'ct-forms' );

		return esc_html( $name );
	}

	/**
	 * Render the Page column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_page_url( $item ) {
		$url = isset( $item['page_url'] ) ? (string) $item['page_url'] : '';
		if ( '' === $url ) {
			return '';
		}

		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noreferrer">' . esc_html__( 'Open', 'ct-forms' ) . '</a>';
	}

	/**
	 * Render the Status column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status = isset( $item['status'] ) ? (string) $item['status'] : '';
		if ( '' === $status ) {
			return '';
		}

		$labels = array(
			'new'       => __( 'New', 'ct-forms' ),
			'reviewed'  => __( 'Reviewed', 'ct-forms' ),
			'follow_up' => __( 'Follow-up', 'ct-forms' ),
			'spam'      => __( 'Spam', 'ct-forms' ),
			'archived'  => __( 'Archived', 'ct-forms' ),
		);

		$text = isset( $labels[ $status ] ) ? $labels[ $status ] : ucwords( str_replace( array( '-', '_' ), ' ', $status ) );

		return '<span class="truitt-entry-status truitt-entry-status--' . esc_attr( sanitize_key( $status ) ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Render the Submitted column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_submitted_at( $item ) {
		$raw = isset( $item['submitted_at'] ) ? (string) $item['submitted_at'] : '';

		if ( '' === $raw || '0000-00-00 00:00:00' === $raw ) {
			return $this->render_not_recorded();
		}

		// Treat legacy invalid negative/BC dates as missing.
		if ( preg_match( '/^\s*-/', $raw ) ) {
			return $this->render_not_recorded();
		}

		$ts = strtotime( $raw );
		if ( ! $ts || $ts < 0 ) {
			return $this->render_not_recorded();
		}

		$date_fmt = (string) get_option( 'date_format' );
		$time_fmt = (string) get_option( 'time_format' );
		$main     = wp_date( $date_fmt . ' \a\t ' . $time_fmt, $ts );

		$now = current_time( 'timestamp' );
		if ( $ts <= $now ) {
			$sub = human_time_diff( $ts, $now ) . ' ' . __( 'ago', 'ct-forms' );
		} else {
			$sub = __( 'in', 'ct-forms' ) . ' ' . human_time_diff( $now, $ts );
		}

		return '<span class="truitt-submitted"><span class="truitt-submitted__main">' . esc_html( $main ) . '</span><span class="truitt-submitted__sub">' . esc_html( $sub ) . '</span></span>';
	}

	/**
	 * Prepare list table items.
	 */
	public function prepare_items() {
		$this->process_bulk_action();

		global $wpdb;
		$table = CT_Forms_DB::entries_table();

		$cols = CT_Forms_DB::entries_columns();
		$pk   = CT_Forms_DB::entries_pk_column();

		$per_page = 20;
		$paged    = 1;
		if ( isset( $_GET['paged'] ) ) {
			$paged = max( 1, absint( wp_unslash( $_GET['paged'] ) ) );
		}
		$offset   = ( $paged - 1 ) * $per_page;

		$where = 'WHERE 1=1';
		$args  = array();

		$form_id_raw = isset( $_GET['form_id'] ) ? (string) wp_unslash( $_GET['form_id'] ) : '';
		if ( '' !== $form_id_raw ) {
			$where  .= ' AND form_id = %d';
			$args[] = absint( $form_id_raw );
		}

		$search_raw = isset( $_GET['s'] ) ? (string) wp_unslash( $_GET['s'] ) : '';
		if ( '' !== $search_raw ) {
			$search = sanitize_text_field( $search_raw );
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= ' AND (data LIKE %s OR page_url LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}

		$orderby = in_array( 'submitted_at', $cols, true ) ? 'submitted_at' : $pk;
		$order   = 'DESC';

		$orderby_raw = isset( $_GET['orderby'] ) ? (string) wp_unslash( $_GET['orderby'] ) : '';
		if ( '' !== $orderby_raw ) {
			$o = sanitize_key( $orderby_raw );
			if ( in_array( $o, array( 'id', 'submitted_at', 'status' ), true ) ) {
				$candidate = ( 'id' === $o ) ? $pk : $o;
				if ( in_array( $candidate, $cols, true ) ) {
					$orderby = $candidate;
				}
			}
		}

		$order_raw = isset( $_GET['order'] ) ? (string) wp_unslash( $_GET['order'] ) : '';
		if ( '' !== $order_raw ) {
			$ord = strtoupper( sanitize_key( $order_raw ) );
			if ( in_array( $ord, array( 'ASC', 'DESC' ), true ) ) {
				$order = $ord;
			}
		}

		// Total items.
		$sql_total = "SELECT COUNT(*) FROM {$table} {$where}";
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_items = (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $args ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_items = (int) $wpdb->get_var( $sql_total );
		}

		// Page items. Select * (some installs may not have all expected columns yet).
		$sql = "SELECT * FROM {$table}
				{$where}
				ORDER BY {$orderby} {$order}
				LIMIT %d OFFSET %d";

		$items = null;
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				$wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				$wpdb->prepare( $sql, $per_page, $offset ),
				ARRAY_A
			);
		}

		// If ORDER BY column doesn't exist (older schema), fall back to ordering by PK.
		if ( empty( $items ) && ! empty( $wpdb->last_error ) ) {
			$wpdb->last_error = '';
			$sql_fb           = "SELECT * FROM {$table}
						{$where}
						ORDER BY {$pk} {$order}
						LIMIT %d OFFSET %d";
			if ( ! empty( $args ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$items = $wpdb->get_results(
					$wpdb->prepare( $sql_fb, array_merge( $args, array( $per_page, $offset ) ) ),
					ARRAY_A
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$items = $wpdb->get_results(
					$wpdb->prepare( $sql_fb, $per_page, $offset ),
					ARRAY_A
				);
			}
		}

		$items = is_array( $items ) ? $items : array();

		// Normalize row keys so list table columns render consistently across schema revisions.
		$normalized = array();
		foreach ( $items as $row ) {
			$row = CT_Forms_DB::normalize_entry_row( $row );
			$row = is_array( $row ) ? $row : array();

			$id      = isset( $row['id'] ) ? absint( $row['id'] ) : ( isset( $row['entry_id'] ) ? absint( $row['entry_id'] ) : 0 );
			$form_id = isset( $row['form_id'] ) ? absint( $row['form_id'] ) : ( isset( $row['form'] ) ? absint( $row['form'] ) : 0 );

			$status       = isset( $row['status'] ) ? (string) $row['status'] : ( isset( $row['state'] ) ? (string) $row['state'] : '' );
			$submitted_at = isset( $row['submitted_at'] ) ? (string) $row['submitted_at'] : ( isset( $row['created_at'] ) ? (string) $row['created_at'] : '' );
			$page_url     = isset( $row['page_url'] ) ? (string) $row['page_url'] : ( isset( $row['source_url'] ) ? (string) $row['source_url'] : '' );

			$normalized[] = array(
				'id'           => $id,
				'form_id'      => $form_id,
				'status'       => $status,
				'submitted_at' => $submitted_at,
				'page_url'     => $page_url,
			);
		}

		$this->items = $normalized;

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Process bulk actions.
	 */
	public function process_bulk_action() {
		$raw_ids = isset( $_POST['entry_ids'] ) ? (array) wp_unslash( $_POST['entry_ids'] ) : array();
		$ids     = array_filter( array_map( 'absint', $raw_ids ) );

		if ( empty( $ids ) ) {
			return;
		}

		// Verify bulk nonce if present.
		if ( isset( $_POST['_wpnonce'] ) ) {
			check_admin_referer( 'bulk-' . $this->_args['plural'] );
		}

		$action = $this->current_action();
		if ( in_array( $action, array( 'mark_reviewed', 'mark_follow_up', 'mark_spam', 'archive' ), true ) ) {
			$map = array(
				'mark_reviewed'  => 'reviewed',
				'mark_follow_up' => 'follow_up',
				'mark_spam'      => 'spam',
				'archive'        => 'archived',
			);

			$status = $map[ $action ];
			foreach ( $ids as $id ) {
				CT_Forms_DB::update_entry_status( $id, $status );
			}
		}

		if ( 'export_csv' === $action && current_user_can( 'ct_forms_export_entries' ) ) {
			$this->export_csv( $ids );
		}
	}

	/**
	 * Export selected entries as CSV.
	 *
	 * @param int[] $ids Entry IDs.
	 */
	private function export_csv( array $ids ) {
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

		fclose( $out );
		exit;
	}

	/**
	 * Render the "not recorded" timestamp label.
	 *
	 * @return string
	 */
	private function render_not_recorded() {
		return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">' . esc_html__( 'Not recorded', 'ct-forms' ) . '</span></span>';
	}
}

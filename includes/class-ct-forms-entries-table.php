<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class CT_Forms_Entries_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => 'entry',
            'plural'   => 'entries',
            'ajax'     => false,
        ) );
    }

    public function get_columns() {
        return array(
            'cb' => '<input type="checkbox" />',
            'id' => 'ID',
            'form' => 'Form',
            'status' => 'Status',
            'submitted_at' => 'Submitted',
            'page_url' => 'Page',
        );
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="entry_ids[]" value="%d" />', (int) $item['id'] );
    }

    protected function column_id( $item ) {
        $url = admin_url( 'admin.php?page=ct-forms-entries&entry_id=' . (int) $item['id'] );
        return '<a href="' . esc_url( $url ) . '">#' . (int) $item['id'] . '</a>';
    }

    protected function column_form( $item ) {
        $p = get_post( (int) $item['form_id'] );
        $name = $p ? $p->post_title : '(deleted)';
        return esc_html( $name );
    }

    protected function column_page_url( $item ) {
        if ( empty( $item['page_url'] ) ) { return ''; }
        return '<a href="' . esc_url( $item['page_url'] ) . '" target="_blank" rel="noreferrer">Open</a>';
    }
    protected function column_status( $item ) {
        $status = isset( $item['status'] ) ? (string) $item['status'] : '';
        if ( $status === '' ) { return ''; }

        $labels = array(
            'new'       => 'New',
            'reviewed'  => 'Reviewed',
            'follow_up' => 'Follow-up',
            'spam'      => 'Spam',
            'archived'  => 'Archived',
        );

        $text = isset( $labels[ $status ] ) ? $labels[ $status ] : ucwords( str_replace( array( '-', '_' ), ' ', $status ) );

        return '<span class="truitt-entry-status truitt-entry-status--' . esc_attr( sanitize_key( $status ) ) . '">' . esc_html( $text ) . '</span>';
    }

    protected function column_submitted_at( $item ) {
        $raw = isset( $item['submitted_at'] ) ? (string) $item['submitted_at'] : '';

        if ( $raw === '' || $raw === '0000-00-00 00:00:00' ) {
            return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">Not recorded</span></span>';
        }

        // Treat legacy invalid negative/BC dates as missing.
        if ( preg_match( '/^\s*-/', $raw ) ) {
            return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">Not recorded</span></span>';
        }

        $ts = strtotime( $raw );
        if ( ! $ts || $ts < 0 ) {
            return '<span class="truitt-submitted truitt-submitted--empty"><span class="truitt-submitted__main">–</span><span class="truitt-submitted__sub">Not recorded</span></span>';
        }

        $date_fmt = get_option( 'date_format' );
        $time_fmt = get_option( 'time_format' );

        $main = wp_date( $date_fmt . ' \a\t ' . $time_fmt, $ts );

        $now = current_time( 'timestamp' );
        $sub = ( $ts <= $now ) ? ( human_time_diff( $ts, $now ) . ' ago' ) : ( 'in ' . human_time_diff( $now, $ts ) );

        return '<span class="truitt-submitted"><span class="truitt-submitted__main">' . esc_html( $main ) . '</span><span class="truitt-submitted__sub">' . esc_html( $sub ) . '</span></span>';
    }

    protected function get_sortable_columns() {
        return array(
            'id' => array( 'id', true ),
            'submitted_at' => array( 'submitted_at', true ),
            'status' => array( 'status', false ),
        );
    }

    public function get_bulk_actions() {
        $actions = array(
            'mark_reviewed' => 'Mark reviewed',
            'mark_follow_up' => 'Mark follow-up',
            'mark_spam' => 'Mark spam',
            'archive' => 'Archive',
        );
        if ( current_user_can( 'ct_forms_export_entries' ) ) {
            $actions['export_csv'] = 'Export CSV';
        }
        return $actions;
    }

    public function process_bulk_action() {
        if ( empty( $_POST['entry_ids'] ) || ! is_array( $_POST['entry_ids'] ) ) { return; }

        $ids = array_map( 'intval', $_POST['entry_ids'] );
        $action = $this->current_action();

        if ( in_array( $action, array( 'mark_reviewed', 'mark_follow_up', 'mark_spam', 'archive' ), true ) ) {
            $map = array(
                'mark_reviewed' => 'reviewed',
                'mark_follow_up' => 'follow_up',
                'mark_spam' => 'spam',
                'archive' => 'archived',
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

    private function export_csv( $ids ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=ct-forms-entries-' . date( 'Y-m-d' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'entry_id', 'form_id', 'status', 'submitted_at', 'page_url', 'field', 'value' ) );

        foreach ( $ids as $id ) {
            $entry = CT_Forms_DB::get_entry( $id );
            if ( ! $entry ) { continue; }
            foreach ( (array) $entry['data'] as $k => $v ) {
                if ( is_array( $v ) ) { $v = implode( ', ', $v ); }
                fputcsv( $out, array(
                    $entry['id'],
                    $entry['form_id'],
                    $entry['status'],
                    $entry['submitted_at'],
                    $entry['page_url'],
                    $k,
                    $v,
                ) );
            }
        }

        fclose( $out );
        exit;
    }

        public function prepare_items() {
        $this->process_bulk_action();

        global $wpdb;
        $table = CT_Forms_DB::entries_table();

        $cols = CT_Forms_DB::entries_columns();
        $pk   = CT_Forms_DB::entries_pk_column();

        $per_page = 20;
        $paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $offset = ( $paged - 1 ) * $per_page;

        $where = 'WHERE 1=1';
        $args  = array();

        if ( ! empty( $_GET['form_id'] ) ) {
            $where .= ' AND form_id = %d';
            $args[] = (int) $_GET['form_id'];
        }

        if ( ! empty( $_GET['s'] ) ) {
            $s = '%' . $wpdb->esc_like( (string) $_GET['s'] ) . '%';
            $where .= ' AND (data LIKE %s OR page_url LIKE %s)';
            $args[] = $s;
            $args[] = $s;
        }

        $orderby = in_array( 'submitted_at', $cols, true ) ? 'submitted_at' : $pk;
        $order   = 'DESC';

        if ( ! empty( $_GET['orderby'] ) ) {
            $o = sanitize_key( (string) $_GET['orderby'] );
            if ( in_array( $o, array( 'id', 'submitted_at', 'status' ), true ) ) {
                // Map 'id' to actual PK if needed, and ensure the column exists.
                $candidate = ( 'id' === $o ) ? $pk : $o;
                if ( in_array( $candidate, $cols, true ) ) {
                    $orderby = $candidate;
                }
            }
        }
        if ( ! empty( $_GET['order'] ) ) {
            $ord = strtoupper( sanitize_key( (string) $_GET['order'] ) );
            if ( in_array( $ord, array( 'ASC', 'DESC' ), true ) ) {
                $order = $ord;
            }
        }

        // Total
        $sql_total = "SELECT COUNT(*) FROM {$table} {$where}";
        if ( ! empty( $args ) ) {
            $total_items = (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $args ) );
        } else {
            $total_items = (int) $wpdb->get_var( $sql_total );
        }

        // Page items
        // Schema-robust query: select * (some installs may not have all expected columns yet).
        $sql = "SELECT * FROM {$table}
                {$where}
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";

        $items = null;

        if ( ! empty( $args ) ) {
            $items = $wpdb->get_results(
                $wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ),
                ARRAY_A
            );
        } else {
            $items = $wpdb->get_results(
                $wpdb->prepare( $sql, $per_page, $offset ),
                ARRAY_A
            );
        }

        // If ORDER BY column doesn't exist (older schema), fall back to ordering by id.
        if ( empty( $items ) && ! empty( $wpdb->last_error ) ) {
            $wpdb->last_error = '';
            $orderby_fallback = $pk;
            $sql_fb = "SELECT * FROM {$table}
                       {$where}
                       ORDER BY {$orderby_fallback} {$order}
                       LIMIT %d OFFSET %d";
            if ( ! empty( $args ) ) {
                $items = $wpdb->get_results(
                    $wpdb->prepare( $sql_fb, array_merge( $args, array( $per_page, $offset ) ) ),
                    ARRAY_A
                );
            } else {
                $items = $wpdb->get_results(
                    $wpdb->prepare( $sql_fb, $per_page, $offset ),
                    ARRAY_A
                );
            }
        }

        
        // Second-level fallbacks: if schema detection failed (e.g., SHOW COLUMNS blocked), try common PKs and finally no ORDER BY.
        if ( empty( $items ) && ! empty( $wpdb->last_error ) ) {
            $last_err = $wpdb->last_error;
            $wpdb->last_error = '';

            // Try ordering by entry_id if present.
            $sql_try = "SELECT * FROM {$table}
                        {$where}
                        ORDER BY entry_id {$order}
                        LIMIT %d OFFSET %d";
            if ( ! empty( $args ) ) {
                $items = $wpdb->get_results(
                    $wpdb->prepare( $sql_try, array_merge( $args, array( $per_page, $offset ) ) ),
                    ARRAY_A
                );
            } else {
                $items = $wpdb->get_results(
                    $wpdb->prepare( $sql_try, $per_page, $offset ),
                    ARRAY_A
                );
            }

            // If still failing, try ordering by id.
            if ( empty( $items ) && ! empty( $wpdb->last_error ) ) {
                $wpdb->last_error = '';
                $sql_try2 = "SELECT * FROM {$table}
                             {$where}
                             ORDER BY id {$order}
                             LIMIT %d OFFSET %d";
                if ( ! empty( $args ) ) {
                    $items = $wpdb->get_results(
                        $wpdb->prepare( $sql_try2, array_merge( $args, array( $per_page, $offset ) ) ),
                        ARRAY_A
                    );
                } else {
                    $items = $wpdb->get_results(
                        $wpdb->prepare( $sql_try2, $per_page, $offset ),
                        ARRAY_A
                    );
                }
            }

            // Final fallback: no ORDER BY.
            if ( empty( $items ) && ! empty( $wpdb->last_error ) ) {
                $wpdb->last_error = '';
                $sql_try3 = "SELECT * FROM {$table}
                             {$where}
                             LIMIT %d OFFSET %d";
                if ( ! empty( $args ) ) {
                    $items = $wpdb->get_results(
                        $wpdb->prepare( $sql_try3, array_merge( $args, array( $per_page, $offset ) ) ),
                        ARRAY_A
                    );
                } else {
                    $items = $wpdb->get_results(
                        $wpdb->prepare( $sql_try3, $per_page, $offset ),
                        ARRAY_A
                    );
                }
            }

            // Restore last error if we ended up with nothing.
            if ( empty( $items ) && empty( $wpdb->last_error ) ) {
                $wpdb->last_error = $last_err;
            }
        }

$items = is_array( $items ) ? $items : array();

        // Normalize row keys so list table columns render consistently across schema revisions.
        $normalized = array();
        foreach ( $items as $row ) {
            $row = CT_Forms_DB::normalize_entry_row( $row );
            $row = is_array( $row ) ? $row : array();

            $id = isset( $row['id'] ) ? (int) $row['id'] : ( isset( $row['entry_id'] ) ? (int) $row['entry_id'] : 0 );
            $form_id = isset( $row['form_id'] ) ? (int) $row['form_id'] : ( isset( $row['form'] ) ? (int) $row['form'] : 0 );

            $status = isset( $row['status'] ) ? (string) $row['status'] : ( isset( $row['state'] ) ? (string) $row['state'] : '' );
            $submitted_at = isset( $row['submitted_at'] ) ? (string) $row['submitted_at'] : ( isset( $row['created_at'] ) ? (string) $row['created_at'] : '' );
            $page_url = isset( $row['page_url'] ) ? (string) $row['page_url'] : ( isset( $row['source_url'] ) ? (string) $row['source_url'] : '' );

            $normalized[] = array(
                'id' => $id,
                'form_id' => $form_id,
                'status' => $status,
                'submitted_at' => $submitted_at,
                'page_url' => $page_url,
            );
        }

        $this->items = $normalized;

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total_items / $per_page ),
        ) );
    }
}
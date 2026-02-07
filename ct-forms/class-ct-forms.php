<?php
/**
 * Plugin Name:       CT Forms
 * Description:       Lightweight form builder with email notifications, autoresponder, and entry storage.
 * Version:           6.2.0
 * Author:            CT
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CT_Forms {

    const VERSION    = '6.2.0';
    const DB_VERSION = '1.1';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );

        add_action( 'init', array( $this, 'init' ) );
        add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

        add_action( 'admin_menu', array( $this, 'add_admin_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        add_action( 'wp_ajax_ct_save_form', array( $this, 'handle_save_form' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        add_action( 'wp_ajax_ct_submit_form', array( $this, 'handle_frontend_submission' ) );
        add_action( 'wp_ajax_nopriv_ct_submit_form', array( $this, 'handle_frontend_submission' ) );
    }

    public function activate() {
        $this->install_tables();
        update_option( 'ct_forms_db_version', self::DB_VERSION );
    }

    public function maybe_upgrade() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $installed = get_option( 'ct_forms_db_version', '0' );

        if ( version_compare( (string) $installed, self::DB_VERSION, '<' ) ) {
            $this->install_tables();
            update_option( 'ct_forms_db_version', self::DB_VERSION );
        }
    }

    private function install_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $forms_table   = $wpdb->prefix . 'ct_forms';
        $entries_table = $wpdb->prefix . 'ct_form_entries';

        $sql_forms = "CREATE TABLE {$forms_table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title tinytext NOT NULL,
            settings longtext NOT NULL,
            fields longtext NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        $sql_entries = "CREATE TABLE {$entries_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id mediumint(9) NOT NULL,
            created_at datetime NOT NULL,
            ip varchar(45) NULL,
            user_agent text NULL,
            fields_json longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'new',
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY created_at (created_at),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql_forms );
        dbDelta( $sql_entries );
    }

    public function init() {
        add_shortcode( 'ct_form', array( $this, 'render_form_shortcode' ) );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'ct-forms' ) === false ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_editor();

        wp_enqueue_style(
            'ct-forms-builder',
            plugins_url( 'assets/builder.css', __FILE__ ),
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'ct-forms-builder',
            plugins_url( 'assets/builder.js', __FILE__ ),
            array(
                'jquery',
                'jquery-ui-sortable',
                'jquery-ui-draggable',
                'jquery-ui-droppable',
            ),
            self::VERSION,
            true
        );

        wp_localize_script(
            'ct-forms-builder',
            'ctFormsBuilder',
            array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'ct_form_builder_nonce' ),
            )
        );
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_style(
            'ct-forms-frontend',
            plugins_url( 'assets/frontend.css', __FILE__ ),
            array(),
            self::VERSION
        );

        // Ensure jQuery is available; many themes dequeue it.
        wp_enqueue_script( 'jquery' );

        wp_enqueue_script(
            'ct-forms-frontend',
            plugins_url( 'assets/frontend.js', __FILE__ ),
            array( 'jquery' ),
            self::VERSION,
            true
        );

        wp_localize_script(
            'ct-forms-frontend',
            'ctFrontend',
            array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'ct_frontend_submit_nonce' ),
            )
        );
    }

    public function add_admin_menus() {
        add_menu_page(
            'CT Forms',
            'CT Forms',
            'manage_options',
            'ct-forms-list',
            array( $this, 'render_forms_list' ),
            'dashicons-forms',
            30
        );

        add_submenu_page(
            'ct-forms-list',
            'Add New',
            'Add New',
            'manage_options',
            'ct-forms-builder',
            array( $this, 'render_builder' )
        );

        add_submenu_page(
            'ct-forms-list',
            'Entries',
            'Entries',
            'manage_options',
            'ct-forms-entries',
            array( $this, 'render_entries_router' )
        );
    }

    public function render_forms_list() {
        global $wpdb;

        $table = $wpdb->prefix . 'ct_forms';

        $sql   = $wpdb->prepare( "SELECT id, title FROM {$table} WHERE %d = %d ORDER BY id DESC", 1, 1 );
        $forms = $wpdb->get_results( $sql );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Management', 'ct-forms' ) . '</h1>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Title', 'ct-forms' ) . '</th>';
        echo '<th>' . esc_html__( 'Shortcode', 'ct-forms' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'ct-forms' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( ! empty( $forms ) ) {
            foreach ( $forms as $f ) {
                $edit_url = add_query_arg(
                    array(
                        'page' => 'ct-forms-builder',
                        'edit' => absint( $f->id ),
                    ),
                    admin_url( 'admin.php' )
                );

                echo '<tr>';
                echo '<td>' . esc_html( $f->title ) . '</td>';
                echo '<td><code>[ct_form id="' . esc_attr( $f->id ) . '"]</code></td>';
                echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'ct-forms' ) . '</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">' . esc_html__( 'No forms found.', 'ct-forms' ) . '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    public function render_builder() {
        global $wpdb;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;

        $table = $wpdb->prefix . 'ct_forms';
        $form  = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ) ) : null;

        $s = $form ? json_decode( (string) $form->settings, true ) : array();
        $s = is_array( $s ) ? $s : array();

        ?>
        <div class="wrap ct-pro-builder">
            <div class="ct-builder-container">
                <div class="ct-sidebar">
                    <div class="ct-palette-card">
                        <h3><?php echo esc_html__( 'Field Library', 'ct-forms' ); ?></h3>
                        <div class="ct-palette-grid">
                            <div class="ct-palette-item" data-type="text"><?php echo esc_html__( 'Short Text', 'ct-forms' ); ?></div>
                            <div class="ct-palette-item" data-type="textarea"><?php echo esc_html__( 'Paragraph', 'ct-forms' ); ?></div>
                            <div class="ct-palette-item" data-type="email"><?php echo esc_html__( 'Email Address', 'ct-forms' ); ?></div>
                            <div class="ct-palette-item" data-type="tel"><?php echo esc_html__( 'US Phone', 'ct-forms' ); ?></div>
                            <div class="ct-palette-item" data-type="select"><?php echo esc_html__( 'Dropdown', 'ct-forms' ); ?></div>
                            <div class="ct-palette-item" data-type="checkbox"><?php echo esc_html__( 'Checkboxes', 'ct-forms' ); ?></div>
                            <div class="ct-palette-item" data-type="states"><?php echo esc_html__( 'US States', 'ct-forms' ); ?></div>
                            <div class="ct-palette-item" data-type="number"><?php echo esc_html__( 'Number Field', 'ct-forms' ); ?></div>
                            <div class="ct-palette-item" data-type="date"><?php echo esc_html__( 'Date Picker', 'ct-forms' ); ?></div>
                            <div class="ct-palette-item" data-type="file"><?php echo esc_html__( 'File Upload', 'ct-forms' ); ?></div>
                        </div>
                    </div>
                </div>

                <div class="ct-main-canvas">
                    <div class="ct-hero-title-wrap">
                        <input
                            type="text"
                            id="ct-form-title"
                            class="ct-hero-input"
                            value="<?php echo $form ? esc_attr( $form->title ) : ''; ?>"
                            placeholder="<?php echo esc_attr__( 'Form Title…', 'ct-forms' ); ?>"
                        >
                        <input type="hidden" id="ct-edit-id" value="<?php echo esc_attr( $edit_id ); ?>">
                    </div>

                    <div
                        id="ct-active-fields"
                        class="ct-dropzone"
                        data-existing="<?php echo esc_attr( $form ? (string) $form->fields : '[]' ); ?>"
                    >
                        <div class="ct-empty-msg"><?php echo esc_html__( 'Drag fields here…', 'ct-forms' ); ?></div>
                    </div>

                    <div class="ct-settings-card">
                        <div class="ct-tabs-nav">
                            <button class="ct-tab-btn active" data-tab="tab-notif" type="button"><?php echo esc_html__( 'Notifications', 'ct-forms' ); ?></button>
                            <button class="ct-tab-btn" data-tab="tab-ar" type="button"><?php echo esc_html__( 'Autoresponder', 'ct-forms' ); ?></button>
                            <button class="ct-tab-btn" data-tab="tab-app" type="button"><?php echo esc_html__( 'Appearance', 'ct-forms' ); ?></button>
                            <button class="ct-tab-btn" data-tab="tab-success" type="button"><?php echo esc_html__( 'Success Logic', 'ct-forms' ); ?></button>
                        </div>

                        <div id="tab-notif" class="ct-tab-content active">
                            <label for="ct-email-to"><?php echo esc_html__( 'Admin Email:', 'ct-forms' ); ?></label>
                            <input
                                type="text"
                                id="ct-email-to"
                                value="<?php echo esc_attr( isset( $s['admin_email'] ) ? $s['admin_email'] : '' ); ?>"
                                style="width:100%; margin-bottom:10px;"
                            >
                            <?php
                            wp_editor(
                                isset( $s['notif_body'] ) ? $s['notif_body'] : '',
                                'ctnotifbody',
                                array( 'textarea_rows' => 6 )
                            );
                            ?>
                        </div>

                        <div id="tab-ar" class="ct-tab-content">
                            <label>
                                <input type="checkbox" id="ct-ar-enabled" <?php checked( isset( $s['ar_enabled'] ) ? $s['ar_enabled'] : 'no', 'yes' ); ?>>
                                <?php echo esc_html__( 'Enable Autoresponder', 'ct-forms' ); ?>
                            </label>
                            <?php
                            wp_editor(
                                isset( $s['ar_body'] ) ? $s['ar_body'] : '',
                                'ctarbody',
                                array( 'textarea_rows' => 6 )
                            );
                            ?>
                        </div>

                        <div id="tab-app" class="ct-tab-content">
                            <?php echo esc_html__( 'Appearance settings coming soon.', 'ct-forms' ); ?>
                        </div>

                        <div id="tab-success" class="ct-tab-content">
                            <label><?php echo esc_html__( 'Success Message:', 'ct-forms' ); ?></label>
                            <?php
                            wp_editor(
                                isset( $s['success_msg'] ) ? $s['success_msg'] : '',
                                'ctsuccessmsg',
                                array( 'textarea_rows' => 4 )
                            );
                            ?>
                        </div>
                    </div>

                    <button id="ct-save-form" class="button button-primary" style="margin-top:20px; width:100%; height:50px;">
                        <?php echo esc_html__( 'Lock Design & Save', 'ct-forms' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_save_form() {
        check_ajax_referer( 'ct_form_builder_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }

        global $wpdb;

        $admin_email = isset( $_POST['email_to'] ) ? sanitize_email( wp_unslash( $_POST['email_to'] ) ) : '';
        $notif_body  = isset( $_POST['notif_body'] ) ? wp_kses_post( wp_unslash( $_POST['notif_body'] ) ) : '';
        $ar_enabled  = isset( $_POST['ar_enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['ar_enabled'] ) ) : 'no';
        $ar_body     = isset( $_POST['ar_body'] ) ? wp_kses_post( wp_unslash( $_POST['ar_body'] ) ) : '';
        $success_msg = isset( $_POST['success_msg'] ) ? wp_kses_post( wp_unslash( $_POST['success_msg'] ) ) : '';

        $settings = wp_json_encode(
            array(
                'admin_email' => $admin_email,
                'notif_body'  => $notif_body,
                'ar_enabled'  => ( 'yes' === $ar_enabled ) ? 'yes' : 'no',
                'ar_body'     => $ar_body,
                'success_msg' => $success_msg,
            )
        );

        $title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $fields = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '[]';

        $data = array(
            'title'    => $title,
            'fields'   => $fields,
            'settings' => $settings,
        );

        $table = $wpdb->prefix . 'ct_forms';

        if ( ! empty( $_POST['edit_id'] ) ) {
            $wpdb->update(
                $table,
                $data,
                array( 'id' => absint( $_POST['edit_id'] ) ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $table,
                $data,
                array( '%s', '%s', '%s' )
            );
        }

        wp_send_json_success();
    }

    private function insert_entry( $form_id, $fields_array ) {
        global $wpdb;

        $entries_table = $wpdb->prefix . 'ct_form_entries';

        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $wpdb->insert(
            $entries_table,
            array(
                'form_id'     => absint( $form_id ),
                'created_at'  => current_time( 'mysql' ),
                'ip'          => $ip,
                'user_agent'  => $ua,
                'fields_json' => wp_json_encode( $fields_array ),
                'status'      => 'new',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    public function handle_frontend_submission() {
        check_ajax_referer( 'ct_frontend_submit_nonce', 'security' );

        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

        global $wpdb;

        $table = $wpdb->prefix . 'ct_forms';
        $form  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ) );

        if ( ! $form ) {
            wp_send_json_error( array( 'message' => 'Form not found' ), 404 );
        }

        $s = json_decode( (string) $form->settings, true );
        $s = is_array( $s ) ? $s : array();

        $user_email   = '';
        $all_fields   = '';
        $fields_store = array();

        foreach ( $_POST as $k => $v ) {
            if ( in_array( $k, array( 'action', 'form_id', 'security' ), true ) ) {
                continue;
            }

            $label = ucwords( str_replace( array( '-', '_' ), ' ', (string) $k ) );

            if ( is_array( $v ) ) {
                $clean_vals = array_map(
                    static function( $item ) {
                        return sanitize_text_field( wp_unslash( $item ) );
                    },
                    $v
                );
                $value_str = implode( ', ', $clean_vals );
                $fields_store[ $label ] = $clean_vals;
            } else {
                $value_str = sanitize_text_field( wp_unslash( $v ) );
                $fields_store[ $label ] = $value_str;
            }

            $all_fields .= '<strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $value_str ) . '<br>';

            if ( is_string( $v ) && is_email( $v ) ) {
                $user_email = $v;
            }
        }

        // Persist entry.
        $this->insert_entry( $form_id, $fields_store );

        $find = array( '{all_fields}', '{form_title}' );
        $repl = array( $all_fields, (string) $form->title );

        $admin_email = isset( $s['admin_email'] ) ? sanitize_email( $s['admin_email'] ) : '';
        $notif_body  = isset( $s['notif_body'] ) ? (string) $s['notif_body'] : '';
        $ar_enabled  = isset( $s['ar_enabled'] ) ? (string) $s['ar_enabled'] : 'no';
        $ar_body     = isset( $s['ar_body'] ) ? (string) $s['ar_body'] : '';
        $success_msg = isset( $s['success_msg'] ) ? (string) $s['success_msg'] : '';

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        if ( ! empty( $admin_email ) ) {
            wp_mail(
                $admin_email,
                'New Submission: ' . (string) $form->title,
                str_replace( $find, $repl, $notif_body ),
                $headers
            );
        }

        if ( 'yes' === $ar_enabled && ! empty( $user_email ) ) {
            wp_mail(
                $user_email,
                'We received your request',
                str_replace( $find, $repl, $ar_body ),
                $headers
            );
        }

        wp_send_json_success( str_replace( $find, $repl, $success_msg ) );
    }

    public function render_form_shortcode( $atts ) {
        global $wpdb;

        $a = shortcode_atts(
            array(
                'id' => 0,
            ),
            $atts
        );

        $form_id = absint( $a['id'] );

        $table = $wpdb->prefix . 'ct_forms';
        $form  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ) );

        if ( ! $form ) {
            return '';
        }

        $fields = json_decode( (string) $form->fields, true );
        $fields = is_array( $fields ) ? $fields : array();

        ob_start();
        ?>
        <div class="ct-form-wrapper" id="ct-form-<?php echo esc_attr( $form_id ); ?>">
            <div class="ct-success-msg" style="display:none;"></div>

            <form class="ct-live-form">
                <input type="hidden" name="action" value="ct_submit_form">
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
                <input type="hidden" name="security" value="<?php echo esc_attr( wp_create_nonce( 'ct_frontend_submit_nonce' ) ); ?>">

                <?php foreach ( $fields as $f ) : ?>
                    <?php
                    $label       = isset( $f['label'] ) ? (string) $f['label'] : '';
                    $type        = isset( $f['type'] ) ? (string) $f['type'] : 'text';
                    $required    = isset( $f['required'] ) ? (string) $f['required'] : 'no';
                    $placeholder = isset( $f['placeholder'] ) ? (string) $f['placeholder'] : '';
                    $options     = isset( $f['options'] ) ? (string) $f['options'] : '';

                    $req_attr = ( 'yes' === $required ) ? 'required' : '';
                    $sid      = sanitize_title( $label );
                    ?>
                    <div class="ct-form-row">
                        <label>
                            <?php echo esc_html( $label ); ?>
                            <?php if ( 'required' === $req_attr ) : ?>
                                <span class="ct-req">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if ( 'select' === $type ) : ?>
                            <select name="<?php echo esc_attr( $sid ); ?>" <?php echo esc_attr( $req_attr ); ?>>
                                <option value=""><?php echo esc_html__( '– Select –', 'ct-forms' ); ?></option>
                                <?php
                                $opts = array_filter( array_map( 'trim', explode( ',', $options ) ) );
                                foreach ( $opts as $o ) {
                                    echo '<option value="' . esc_attr( $o ) . '">' . esc_html( $o ) . '</option>';
                                }
                                ?>
                            </select>
                        <?php elseif ( 'textarea' === $type ) : ?>
                            <textarea name="<?php echo esc_attr( $sid ); ?>" <?php echo esc_attr( $req_attr ); ?>></textarea>
                        <?php else : ?>
                            <input
                                type="<?php echo esc_attr( $type ); ?>"
                                name="<?php echo esc_attr( $sid ); ?>"
                                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                                <?php echo esc_attr( $req_attr ); ?>
                            >
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="ct-submit-btn"><?php echo esc_html__( 'Submit Request', 'ct-forms' ); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_entries_router() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $entry_id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;

        if ( $entry_id > 0 ) {
            $this->render_entry_view( $entry_id );
            return;
        }

        $this->render_entries_list();
    }

    private function get_forms_for_filter() {
        global $wpdb;

        $forms_table = $wpdb->prefix . 'ct_forms';

        $sql = $wpdb->prepare( "SELECT id, title FROM {$forms_table} WHERE %d = %d ORDER BY title ASC", 1, 1 );
        return $wpdb->get_results( $sql );
    }

    private function render_entries_list() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'ct-forms' ) );
        }

        global $wpdb;

        $entries_table = $wpdb->prefix . 'ct_form_entries';
        $forms_table   = $wpdb->prefix . 'ct_forms';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

        $per_page = 20;
        $offset   = ( $paged - 1 ) * $per_page;

        $where_sql  = ' WHERE %d = %d ';
        $where_args = array( 1, 1 );

        if ( $filter_form_id > 0 ) {
            $where_sql  .= ' AND e.form_id = %d ';
            $where_args[] = $filter_form_id;
        }

        // Deletion action.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['ct_delete_entry'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $delete_id = absint( $_GET['ct_delete_entry'] );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

            if ( $delete_id > 0 && wp_verify_nonce( $nonce, 'ct_delete_entry_' . $delete_id ) ) {
                $wpdb->delete(
                    $entries_table,
                    array( 'id' => $delete_id ),
                    array( '%d' )
                );
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Entry deleted.', 'ct-forms' ) . '</p></div>';
            }
        }

        // Total count.
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$entries_table} e {$where_sql}",
            $where_args
        );
        $total = (int) $wpdb->get_var( $count_sql );

        // Fetch rows.
        $rows_sql = $wpdb->prepare(
            "SELECT e.id, e.form_id, e.created_at, e.status, f.title AS form_title
             FROM {$entries_table} e
             LEFT JOIN {$forms_table} f ON f.id = e.form_id
             {$where_sql}
             ORDER BY e.id DESC
             LIMIT %d OFFSET %d",
            array_merge( $where_args, array( $per_page, $offset ) )
        );

        $rows = $wpdb->get_results( $rows_sql );

        $forms = $this->get_forms_for_filter();

        $base_url = admin_url( 'admin.php?page=ct-forms-entries' );

        $total_pages = (int) ceil( max( 1, $total ) / $per_page );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Entries', 'ct-forms' ) . '</h1>';

        // Filter form.
        echo '<form method="get" style="margin: 12px 0;">';
        echo '<input type="hidden" name="page" value="ct-forms-entries" />';
        echo '<label for="ct-filter-form" style="margin-right:8px;">' . esc_html__( 'Form:', 'ct-forms' ) . '</label>';
        echo '<select id="ct-filter-form" name="form_id">';
        echo '<option value="0">' . esc_html__( 'All forms', 'ct-forms' ) . '</option>';
        foreach ( $forms as $f ) {
            $selected = selected( $filter_form_id, (int) $f->id, false );
            echo '<option value="' . esc_attr( (int) $f->id ) . '"' . $selected . '>' . esc_html( (string) $f->title ) . '</option>';
        }
        echo '</select> ';
        echo '<button class="button">' . esc_html__( 'Filter', 'ct-forms' ) . '</button>';
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:90px;">' . esc_html__( 'ID', 'ct-forms' ) . '</th>';
        echo '<th>' . esc_html__( 'Form', 'ct-forms' ) . '</th>';
        echo '<th style="width:220px;">' . esc_html__( 'Submitted', 'ct-forms' ) . '</th>';
        echo '<th style="width:120px;">' . esc_html__( 'Status', 'ct-forms' ) . '</th>';
        echo '<th style="width:140px;">' . esc_html__( 'Actions', 'ct-forms' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $r ) {
                $view_url = add_query_arg(
                    array(
                        'page'  => 'ct-forms-entries',
                        'entry' => absint( $r->id ),
                    ),
                    admin_url( 'admin.php' )
                );

                $delete_url = wp_nonce_url(
                    add_query_arg(
                        array(
                            'page'            => 'ct-forms-entries',
                            'ct_delete_entry' => absint( $r->id ),
                            'form_id'         => $filter_form_id,
                            'paged'           => $paged,
                        ),
                        admin_url( 'admin.php' )
                    ),
                    'ct_delete_entry_' . absint( $r->id )
                );

                echo '<tr>';
                echo '<td>' . esc_html( (string) absint( $r->id ) ) . '</td>';
                echo '<td>' . esc_html( (string) $r->form_title ) . '</td>';
                echo '<td>' . esc_html( (string) $r->created_at ) . '</td>';
                echo '<td>' . esc_html( (string) $r->status ) . '</td>';
                echo '<td><a href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'ct-forms' ) . '</a> | ';
                echo '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Delete this entry?', 'ct-forms' ) ) ) . ');">' . esc_html__( 'Delete', 'ct-forms' ) . '</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="5">' . esc_html__( 'No entries found.', 'ct-forms' ) . '</td></tr>';
        }

        echo '</tbody></table>';

        // Pagination.
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav" style="margin-top:12px;">';
            echo '<div class="tablenav-pages">';

            $args = array(
                'page'    => 'ct-forms-entries',
                'form_id' => $filter_form_id,
            );

            if ( $paged > 1 ) {
                $prev_url = add_query_arg( array_merge( $args, array( 'paged' => $paged - 1 ) ), admin_url( 'admin.php' ) );
                echo '<a class="button" href="' . esc_url( $prev_url ) . '">' . esc_html__( 'Prev', 'ct-forms' ) . '</a> ';
            }

            echo '<span style="margin:0 8px;">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'ct-forms' ), $paged, $total_pages ) ) . '</span>';

            if ( $paged < $total_pages ) {
                $next_url = add_query_arg( array_merge( $args, array( 'paged' => $paged + 1 ) ), admin_url( 'admin.php' ) );
                echo '<a class="button" href="' . esc_url( $next_url ) . '">' . esc_html__( 'Next', 'ct-forms' ) . '</a>';
            }

            echo '</div></div>';
        }

        echo '</div>';
    }

    private function render_entry_view( $entry_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'ct-forms' ) );
        }

        global $wpdb;

        $entries_table = $wpdb->prefix . 'ct_form_entries';
        $forms_table   = $wpdb->prefix . 'ct_forms';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT e.*, f.title AS form_title
                 FROM {$entries_table} e
                 LEFT JOIN {$forms_table} f ON f.id = e.form_id
                 WHERE e.id = %d",
                $entry_id
            )
        );

        if ( ! $row ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Entry not found', 'ct-forms' ) . '</h1></div>';
            return;
        }

        // Mark as read when viewed.
        if ( 'new' === (string) $row->status ) {
            $wpdb->update(
                $entries_table,
                array( 'status' => 'read' ),
                array( 'id' => absint( $entry_id ) ),
                array( '%s' ),
                array( '%d' )
            );
            $row->status = 'read';
        }

        $fields = json_decode( (string) $row->fields_json, true );
        $fields = is_array( $fields ) ? $fields : array();

        $back_url = admin_url( 'admin.php?page=ct-forms-entries' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Entry', 'ct-forms' ) . ' #' . esc_html( (string) absint( $row->id ) ) . '</h1>';
        echo '<p><a class="button" href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to Entries', 'ct-forms' ) . '</a></p>';

        echo '<table class="widefat striped" style="max-width: 900px;">';
        echo '<tbody>';
        echo '<tr><th style="width:220px;">' . esc_html__( 'Form', 'ct-forms' ) . '</th><td>' . esc_html( (string) $row->form_title ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Submitted', 'ct-forms' ) . '</th><td>' . esc_html( (string) $row->created_at ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Status', 'ct-forms' ) . '</th><td>' . esc_html( (string) $row->status ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'IP', 'ct-forms' ) . '</th><td>' . esc_html( (string) $row->ip ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'User Agent', 'ct-forms' ) . '</th><td>' . esc_html( (string) $row->user_agent ) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2 style="margin-top:18px;">' . esc_html__( 'Submitted Fields', 'ct-forms' ) . '</h2>';
        echo '<table class="widefat striped" style="max-width: 900px;">';
        echo '<thead><tr><th style="width:260px;">' . esc_html__( 'Field', 'ct-forms' ) . '</th><th>' . esc_html__( 'Value', 'ct-forms' ) . '</th></tr></thead><tbody>';

        if ( ! empty( $fields ) ) {
            foreach ( $fields as $k => $v ) {
                echo '<tr>';
                echo '<td>' . esc_html( (string) $k ) . '</td>';
                if ( is_array( $v ) ) {
                    echo '<td>' . esc_html( implode( ', ', array_map( 'strval', $v ) ) ) . '</td>';
                } else {
                    echo '<td>' . esc_html( (string) $v ) . '</td>';
                }
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="2">' . esc_html__( 'No field data found.', 'ct-forms' ) . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}

CT_Forms::get_instance();

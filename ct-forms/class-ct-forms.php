<?php
/**
 * Plugin Name:       CT Forms
 * Description:       v6.1.2 FIX: Front-end AJAX + email/autoresponder.
 * Version:           6.1.2
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CT_Forms {
    private static $instance = null;
    public static function get_instance() { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }
    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_ct_save_form', array( $this, 'handle_save_form' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'wp_ajax_ct_submit_form', array( $this, 'handle_frontend_submission' ) );
        add_action( 'wp_ajax_nopriv_ct_submit_form', array( $this, 'handle_frontend_submission' ) );
    }
    public function activate() {
        global $wpdb; $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ct_forms (id mediumint(9) NOT NULL AUTO_INCREMENT, title tinytext NOT NULL, settings longtext NOT NULL, fields longtext NOT NULL, PRIMARY KEY (id))");
    }
    public function init() { add_shortcode( 'ct_form', array( $this, 'render_form_shortcode' ) ); }
    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'ct-forms' ) === false ) return;
        wp_enqueue_media(); wp_enqueue_editor();
        wp_enqueue_style( 'ct-style', plugins_url( 'assets/builder.css', __FILE__ ), array(), '6.1.2' );
        wp_enqueue_script( 'ct-js', plugins_url( 'assets/builder.js', __FILE__ ), array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-draggable', 'jquery-ui-droppable' ), '6.1.2', true );
        wp_localize_script( 'ct-js', 'ctFormsBuilder', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ct_form_builder_nonce' ) ));
    }
    public function enqueue_frontend_assets() {
        // Ensure jQuery exists on the front-end (many modern themes do not enqueue it by default).
        wp_enqueue_script( 'jquery' );

        wp_enqueue_style( 'ct-fe', plugins_url( 'assets/frontend.css', __FILE__ ), array(), '6.1.2' );

        wp_enqueue_script(
            'ct-forms-frontend',
            plugins_url( 'assets/frontend.js', __FILE__ ),
            array( 'jquery' ),
            '6.1.2',
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
        add_menu_page( 'CT Forms', 'CT Forms', 'manage_options', 'ct-forms-list', array( $this, 'render_forms_list' ), 'dashicons-forms', 30 );
        add_submenu_page( 'ct-forms-list', 'Add New', 'Add New', 'manage_options', 'ct-forms-builder', array( $this, 'render_builder' ) );
    }
    public function render_forms_list() {
        global $wpdb; $forms = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ct_forms ORDER BY id DESC");
        echo '<div class="wrap"><h1>Management</h1><table class="widefat striped"><thead><tr><th>Title</th><th>Shortcode</th><th>Actions</th></tr></thead><tbody>';
        foreach($forms as $f) { echo "<tr><td>".esc_html($f->title)."</td><td><code>[ct_form id=\"{$f->id}\"]</code></td><td><a href='admin.php?page=ct-forms-builder&edit={$f->id}'>Edit</a></td></tr>"; }
        echo '</tbody></table></div>';
    }
    public function render_builder() {
        global $wpdb; $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $form = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ct_forms WHERE id = %d", $edit_id)) : null;
        $s = $form ? json_decode($form->settings, true) : array();
        ?>
        <div class="wrap ct-pro-builder"><div class="ct-builder-container">
            <div class="ct-sidebar"><div class="ct-palette-card"><h3>Field Library</h3><div class="ct-palette-grid">
                <div class="ct-palette-item" data-type="text">Short Text</div><div class="ct-palette-item" data-type="textarea">Paragraph</div>
                <div class="ct-palette-item" data-type="email">Email Address</div><div class="ct-palette-item" data-type="tel">US Phone</div>
                <div class="ct-palette-item" data-type="select">Dropdown</div><div class="ct-palette-item" data-type="checkbox">Checkboxes</div>
                <div class="ct-palette-item" data-type="states">US States</div><div class="ct-palette-item" data-type="number">Number Field</div>
                <div class="ct-palette-item" data-type="date">Date Picker</div><div class="ct-palette-item" data-type="file">File Upload</div>
            </div></div></div>
            <div class="ct-main-canvas">
                <div class="ct-hero-title-wrap"><input type="text" id="ct-form-title" class="ct-hero-input" value="<?php echo $form?esc_attr($form->title):''; ?>" placeholder="Form Title..."><input type="hidden" id="ct-edit-id" value="<?php echo $edit_id; ?>"></div>
                <div id="ct-active-fields" class="ct-dropzone" data-existing='<?php echo esc_attr($form?$form->fields:'[]'); ?>'><div class="ct-empty-msg">Drag fields here...</div></div>
                <div class="ct-settings-card">
                    <div class="ct-tabs-nav">
                        <button class="ct-tab-btn active" data-tab="tab-notif">Notifications</button>
                        <button class="ct-tab-btn" data-tab="tab-ar">Autoresponder</button>
                        <button class="ct-tab-btn" data-tab="tab-app">Appearance</button>
                        <button class="ct-tab-btn" data-tab="tab-success">Success Logic</button>
                    </div>
                    <div id="tab-notif" class="ct-tab-content active">
                        <label>Admin Email:</label><input type="text" id="ct-email-to" value="<?php echo esc_attr($s['admin_email']??''); ?>" style="width:100%; margin-bottom:10px;">
                        <?php wp_editor($s['notif_body']??'', 'ctnotifbody', array('textarea_rows'=>6)); ?>
                    </div>
                    <div id="tab-ar" class="ct-tab-content">
                        <label><input type="checkbox" id="ct-ar-enabled" <?php checked($s['ar_enabled']??'no','yes'); ?>> Enable Autoresponder</label>
                        <?php wp_editor($s['ar_body']??'', 'ctarbody', array('textarea_rows'=>6)); ?>
                    </div>
                    <div id="tab-app" class="ct-tab-content">Appearance settings coming soon.</div>
                    <div id="tab-success" class="ct-tab-content">
                        <label>Success Message:</label><?php wp_editor($s['success_msg']??'', 'ctsuccessmsg', array('textarea_rows'=>4)); ?>
                    </div>
                </div>
                <button id="ct-save-form" class="button button-primary" style="margin-top:20px; width:100%; height:50px;">Lock Design & Save</button>
            </div>
        </div></div>
        <?php
    }
    public function handle_save_form() {
        check_ajax_referer('ct_form_builder_nonce', 'security'); global $wpdb;
        $settings = wp_json_encode(array(
            'admin_email' => sanitize_email($_POST['email_to']),
            'notif_body' => wp_kses_post(stripslashes($_POST['notif_body'])),
            'ar_enabled' => $_POST['ar_enabled'],
            'ar_body' => wp_kses_post(stripslashes($_POST['ar_body'])),
            'success_msg' => wp_kses_post(stripslashes($_POST['success_msg']))
        ));
        $data = array('title' => sanitize_text_field($_POST['title']), 'fields' => wp_unslash($_POST['fields']), 'settings' => $settings);
        if(!empty($_POST['edit_id'])) { $wpdb->update("{$wpdb->prefix}ct_forms", $data, array('id' => absint($_POST['edit_id']))); }
        else { $wpdb->insert("{$wpdb->prefix}ct_forms", $data); }
        wp_send_json_success();
    }
    public function handle_frontend_submission() {
        check_ajax_referer( 'ct_frontend_submit_nonce', 'security' );

        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => 'Missing form_id' ), 400 );
        }

        global $wpdb;
        $form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ct_forms WHERE id = %d", $form_id ) );
        if ( ! $form ) {
            wp_send_json_error( array( 'message' => 'Form not found' ), 404 );
        }

        $s = json_decode( (string) $form->settings, true );
        $s = is_array( $s ) ? $s : array();

        $user_email = '';
        $all_fields = '';

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
            } else {
                $value_str = sanitize_text_field( wp_unslash( $v ) );
            }

            $all_fields .= '<strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $value_str ) . '<br>';

            if ( is_string( $v ) && is_email( $v ) ) {
                $user_email = $v;
            }
        }

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
    public function render_form_shortcode($atts) {
        global $wpdb; $a = shortcode_atts(array('id' => 0), $atts);
        $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ct_forms WHERE id = %d", $a['id']));
        if(!$form) return ''; $fields = json_decode($form->fields, true); ob_start();
        ?>
        <div class="ct-form-wrapper" id="ct-form-<?php echo $a['id']; ?>">
            <div class="ct-success-msg" style="display:none; padding:20px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; margin-bottom:20px;"></div>
            <form class="ct-live-form">
                <input type="hidden" name="action" value="ct_submit_form">
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $a['id'] ); ?>">
                <input type="hidden" name="security" value="<?php echo esc_attr( wp_create_nonce( 'ct_frontend_submit_nonce' ) ); ?>">
                <?php foreach($fields as $f): 
                    $req = ($f['required'] === 'yes') ? 'required' : '';
                    $sid = sanitize_title($f['label']);
                    echo "<div class='ct-form-row'><label>".esc_html($f['label']).($req?' <span class="ct-req">*</span>':'')."</label>";
                    if($f['type']==='select') {
                        echo "<select name='$sid' $req><option value=''>-- Select --</option>";
                        foreach(explode(',', $f['options']) as $o) { echo "<option value='".trim($o)."'>".trim($o)."</option>"; }
                        echo "</select>";
                    } elseif($f['type']==='textarea') { echo "<textarea name='$sid' $req></textarea>"; }
                    else { echo "<input type='{$f['type']}' name='$sid' placeholder='".esc_attr($f['placeholder'])."' $req>"; }
                    echo "</div>";
                endforeach; ?>
                <button type="submit" class="ct-submit-btn">Submit Request</button>
            </form>
        </div>
        <!-- Front-end submission handled by assets/frontend.js -->
        <?php return ob_get_clean();
    }
}
CT_Forms::get_instance();

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CT_Forms_Renderer {

    private static $current_field_errors = array();
    private static $current_field_warnings = array();

        public static function render_block( $attributes, $content = '' ) {
        $form_id = isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0;
        if ( $form_id <= 0 ) { return ''; }
        return self::shortcode( array( 'id' => $form_id ) );
    }

public static function shortcode( $atts ) {
		// Forms are dynamic (nonces, validation errors, user-provided content) and should not be
		// cached as full-page HTML. This helps prevent stale form markup when caching layers are enabled.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		// LiteSpeed cache (if present)
		if ( function_exists( 'do_action' ) ) {
			// These actions are ignored if LiteSpeed is not installed.
			do_action( 'litespeed_control_set_nocache' );
		}
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts, 'ct_form' );

        $form_id = (int) $atts['id'];
        if ( $form_id <= 0 ) { return ''; }

        return self::render_form( $form_id );
    }

    public static function render_form( $form_id ) {
        $post = get_post( $form_id );
        if ( ! $post || 'ct_form' !== $post->post_type ) { return ''; }

        $def = CT_Forms_CPT::get_form_definition( $form_id );
        $settings = CT_Forms_CPT::get_form_settings( $form_id );

        wp_enqueue_style( 'ct-forms-frontend', CT_FORMS_PLUGIN_URL . 'assets/css/frontend.css', array(), CT_FORMS_VERSION );
        wp_enqueue_script( 'ct-forms-frontend', CT_FORMS_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), CT_FORMS_VERSION, true );

        // reCAPTCHA – enqueue only if enabled globally AND for this form
        $global_settings = CT_Forms_Admin::get_settings();

        $recaptcha_type = isset( $global_settings['recaptcha_type'] ) ? $global_settings['recaptcha_type'] : '';
        if ( '' === $recaptcha_type ) {
            // Back-compat: older versions used a boolean recaptcha_enabled option.
            $recaptcha_type = ! empty( $global_settings['recaptcha_enabled'] ) ? 'v2_checkbox' : 'disabled';
        }

        $has_keys = ( ! empty( $global_settings['recaptcha_site_key'] ) && ! empty( $global_settings['recaptcha_secret_key'] ) );

        if ( 'disabled' !== $recaptcha_type && $has_keys && ! empty( $settings['recaptcha_enabled'] ) ) {

            // Provide config to the frontend script (used for v2 invisible and v3).
            wp_localize_script( 'ct-forms-frontend', 'ctFormsRecaptcha', array(
                'type'    => $recaptcha_type,
                'siteKey' => $global_settings['recaptcha_site_key'],
                'action'  => isset( $global_settings['recaptcha_v3_action'] ) ? $global_settings['recaptcha_v3_action'] : 'ct_forms_submit',
            ) );

            if ( 'v3' === $recaptcha_type ) {
                wp_enqueue_script(
                    'ct-forms-recaptcha',
                    'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $global_settings['recaptcha_site_key'] ),
                    array(),
                    CT_FORMS_VERSION,
                    true
                );
            } else {
                // v2 checkbox or v2 invisible
                wp_enqueue_script(
                    'ct-forms-recaptcha',
                    'https://www.google.com/recaptcha/api.js',
                    array(),
                    CT_FORMS_VERSION,
                    true
                );
            }
        }

$form_uid = 'ct-form-' . $form_id . '-' . wp_generate_uuid4();

        $action = esc_url( admin_url( 'admin-post.php' ) );
        $nonce = wp_create_nonce( 'ct_forms_submit_' . $form_id );

        $success = isset( $_GET['ct_forms_success'] ) && (int) $_GET['ct_forms_success'] === $form_id;
        $error = isset( $_GET['ct_forms_error'] ) && (int) $_GET['ct_forms_error'] === $form_id;

        $error_code = '';
        if ( $error ) {
            $error_code = isset( $_GET['ct_forms_error_code'] ) ? sanitize_key( (string) $_GET['ct_forms_error_code'] ) : '';
        }

        // Detailed field-level feedback (errors/warnings) via transient token.
        self::$current_field_errors = array();
        self::$current_field_warnings = array();

        $fb_token = isset( $_GET['ct_forms_fb'] ) ? sanitize_text_field( (string) $_GET['ct_forms_fb'] ) : '';
        if ( '' !== $fb_token && class_exists( 'CT_Forms_Submissions' ) && method_exists( 'CT_Forms_Submissions', 'get_form_feedback' ) ) {
            $payload = CT_Forms_Submissions::get_form_feedback( $form_id, $fb_token );
            if ( is_array( $payload ) ) {
                if ( ! empty( $payload['errors'] ) && is_array( $payload['errors'] ) ) {
                    self::$current_field_errors = $payload['errors'];
                }
                if ( ! empty( $payload['warnings'] ) && is_array( $payload['warnings'] ) ) {
                    self::$current_field_warnings = $payload['warnings'];
                }
            }
        }


        ob_start();
        ?>
        <div class="ct-forms-wrap<?php echo $success ? ' ct-forms-state-success' : ( $error ? ' ct-forms-state-error' : '' ); ?>" id="<?php echo esc_attr( $form_uid ); ?>">
            <?php if ( $success ) : ?>
                <div class="ct-forms-confirmation" role="status" aria-live="polite"><?php
                    $cm = (string) $settings['confirmation_message'];
                    // Back-compat: if stored with literal "\n" sequences, convert to real newlines.
                    $cm = str_replace( array( "\\r\\n", "\\n", "\\r" ), "\n", $cm );
                    // Repair legacy stripped-newline artifacts in safe contexts (e.g., ".nnNext").
                    $cm = preg_replace( '/(?<=[\}\]\.\)])\s*nn(?=[A-Z0-9])/', "\n\n", $cm );
                    echo wp_kses_post( wpautop( $cm ) );
                ?></div>
                <?php if ( ! empty( self::$current_field_warnings ) ) : ?>
                    <div class="ct-forms-confirmation ct-forms-confirmation-warning" role="status" aria-live="polite"><?php echo esc_html( self::friendly_warning_message( self::$current_field_warnings ) ); ?></div>
                <?php endif; ?>
            <?php elseif ( $error ) : ?>
                <div class="ct-forms-confirmation ct-forms-confirmation-error" role="alert"><?php echo esc_html( self::friendly_error_message( $error_code ) ); ?></div>
            <?php endif; ?>

            <?php if ( ! $success ) : ?>
            <form class="ct-forms-form" method="post" enctype="multipart/form-data" action="<?php echo $action; ?>" novalidate>
                <input type="hidden" name="action" value="ct_forms_submit">
                <input type="hidden" name="ct_form_id" value="<?php echo (int) $form_id; ?>">
                <input type="hidden" name="truitt_nonce" value="<?php echo esc_attr( $nonce ); ?>">
                <input type="hidden" name="truitt_ts" value="<?php echo esc_attr( time() ); ?>">
                <input type="text" name="truitt_hp" value="" class="ct-forms-hp" autocomplete="off" tabindex="-1" aria-hidden="true">

                <?php foreach ( $def['fields'] as $field ) :
                    echo self::render_field( $field );
                endforeach; ?>

                <?php
                $recaptcha_site_key = isset( $global_settings['recaptcha_site_key'] ) ? trim( (string) $global_settings['recaptcha_site_key'] ) : '';
                $recaptcha_type = isset( $global_settings['recaptcha_type'] ) ? (string) $global_settings['recaptcha_type'] : '';
                if ( '' === $recaptcha_type ) {
                    // Back-compat: older versions used a boolean recaptcha_enabled option.
                    $recaptcha_type = ! empty( $global_settings['recaptcha_enabled'] ) ? 'v2_checkbox' : 'disabled';
                }

                $recaptcha_enabled_for_form = ( 'disabled' !== $recaptcha_type && ! empty( $recaptcha_site_key ) && ! empty( $settings['recaptcha_enabled'] ) );

                if ( $recaptcha_enabled_for_form ) :
                    ?>
                    <div class="ct-forms-field ct-forms-field-recaptcha">
                        <?php if ( 'v3' === $recaptcha_type ) : ?>
                            <input type="hidden" name="g-recaptcha-response" value="">
                        <?php elseif ( 'v2_invisible' === $recaptcha_type ) : ?>
                            <div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $recaptcha_site_key ); ?>" data-size="invisible"></div>
                        <?php else : ?>
                            <div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $recaptcha_site_key ); ?>"></div>
                        <?php endif; ?>
                    </div>
                    <?php
                endif;
                ?>


                <button type="submit" class="ct-forms-submit"><?php echo esc_html__( 'Submit', 'ct-forms' ); ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    
    private static function friendly_error_message( $code ) {
        switch ( (string) $code ) {
            case 'nonce':
                return __( 'Security check failed. Please refresh the page and try again.', 'ct-forms' );
            case 'rate_limit':
                return __( 'Too many submissions. Please wait a few minutes and try again.', 'ct-forms' );
            case 'validation':
                return __( 'Please complete all required fields and try again.', 'ct-forms' );
            case 'upload':
                return __( 'One or more files could not be uploaded. Please try again.', 'ct-forms' );
            case 'recaptcha':
                return __( 'Please complete the reCAPTCHA check and try again.', 'ct-forms' );
            case 'config_to_email':
                return __( 'Form is not configured correctly (recipient email). Please contact the site owner.', 'ct-forms' );            default:
                return __( 'There was a problem submitting the form. Please try again.', 'ct-forms' );
        }
    }

    private static function friendly_warning_message( $warnings ) {
        // Keep this concise – details are shown inline in admin and via field messages on error.
        $count = 0;
        foreach ( (array) $warnings as $fid => $msgs ) {
            $count += is_array( $msgs ) ? count( $msgs ) : 1;
        }
        if ( $count <= 0 ) {
            return __( 'Your submission was received.', 'ct-forms' );
        }
        if ( 1 === $count ) {
            return __( 'Your submission was received, but one attachment was skipped.', 'ct-forms' );
        }
        /* translators: %d: number of attachments skipped. */
        return sprintf( __( 'Your submission was received, but %d attachments were skipped.', 'ct-forms' ), (int) $count );
    }

public static function render_field( $field ) {
        $id = isset( $field['id'] ) ? sanitize_key( $field['id'] ) : '';
        if ( '' === $id ) { return ''; }

        $type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
        // Diagnostics fields are virtual and should not render on the public form.
        if ( 'diagnostics' === $type ) {
            return '';
        }
        $label = isset( $field['label'] ) ? (string) $field['label'] : '';
        $required = ! empty( $field['required'] );
        $placeholder = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
        $help = isset( $field['help'] ) ? (string) $field['help'] : '';
        $options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

        $name = 'truitt_field_' . $id;
        $field_id = 'truitt-field-' . $id . '-' . wp_generate_uuid4();

        ob_start();
        ?>
        <div class="ct-forms-field ct-forms-field-<?php echo esc_attr( $type ); ?>">
            <label for="<?php echo esc_attr( $field_id ); ?>">
                <?php echo esc_html( $label ); ?>
                <?php if ( $required ) : ?><span class="ct-forms-required">*</span><?php endif; ?>
            </label>

            <?php
            $common = array(
                'id' => $field_id,
                'name' => $name,
                'required' => $required ? 'required' : '',
                'placeholder' => $placeholder,
            );

            switch ( $type ) {
                case 'textarea':
                    printf(
                        '<textarea id="%1$s" name="%2$s" %3$s placeholder="%4$s"></textarea>',
                        esc_attr( $common['id'] ),
                        esc_attr( $common['name'] ),
                        $required ? 'required' : '',
                        esc_attr( $common['placeholder'] )
                    );
                    break;


                case 'date':
                    echo '<input type="date" id="' . esc_attr( $common['id'] ) . '" name="' . esc_attr( $common['name'] ) . '" ' . ( $required ? 'required' : '' ) . ' placeholder="' . esc_attr( $common['placeholder'] ) . '">';
                    break;

                case 'time':
                    echo '<input type="time" id="' . esc_attr( $common['id'] ) . '" name="' . esc_attr( $common['name'] ) . '" ' . ( $required ? 'required' : '' ) . ' placeholder="' . esc_attr( $common['placeholder'] ) . '">';
                    break;

                case 'state':
                    $states = self::get_us_states();
                    echo '<select id="' . esc_attr( $common['id'] ) . '" name="' . esc_attr( $common['name'] ) . '" ' . ( $required ? 'required' : '' ) . '>';
                    echo '<option value="">' . esc_html__( 'Select state', 'ct-forms' ) . '</option>';
                    foreach ( $states as $abbr => $name_label ) {
                        echo '<option value="' . esc_attr( $abbr ) . '">' . esc_html( $name_label ) . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'select':
                    echo '<select id="' . esc_attr( $common['id'] ) . '" name="' . esc_attr( $common['name'] ) . '" ' . ( $required ? 'required' : '' ) . '>';
                    echo '<option value="">' . esc_html__( 'Select', 'ct-forms' ) . '</option>';
                    foreach ( $options as $opt ) {
                        $v = isset( $opt['value'] ) ? (string) $opt['value'] : '';
                        $t = isset( $opt['label'] ) ? (string) $opt['label'] : $v;
                        echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $t ) . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'checkboxes':
                    echo '<div class="ct-forms-options">';
                    foreach ( $options as $i => $opt ) {
                        $v = isset( $opt['value'] ) ? (string) $opt['value'] : '';
                        $t = isset( $opt['label'] ) ? (string) $opt['label'] : $v;
                        $cid = $field_id . '-' . $i;
                        echo '<label class="ct-forms-option"><input type="checkbox" id="' . esc_attr( $cid ) . '" name="' . esc_attr( $common['name'] ) . '[]" value="' . esc_attr( $v ) . '"> ' . esc_html( $t ) . '</label>';
                    }
                    echo '</div>';
                    break;

                case 'radios':
                    echo '<div class="ct-forms-options">';
                    foreach ( $options as $i => $opt ) {
                        $v = isset( $opt['value'] ) ? (string) $opt['value'] : '';
                        $t = isset( $opt['label'] ) ? (string) $opt['label'] : $v;
                        $rid = $field_id . '-' . $i;
                        echo '<label class="ct-forms-option"><input type="radio" id="' . esc_attr( $rid ) . '" name="' . esc_attr( $common['name'] ) . '" value="' . esc_attr( $v ) . '" ' . ( $required ? 'required' : '' ) . '> ' . esc_html( $t ) . '</label>';
                    }
                    echo '</div>';
                    break;

                case 'file':
                    $accept_attr = '';
                    $multiple_attr = '';
                    $file_name = $common['name'];
                    // Pull file rules from the global Settings page.
                    // Use the same defaults as Admin::get_settings() so public forms
                    // always have a consistent allow-list even if the settings option
                    // has not been saved yet.
                    if ( class_exists( 'CT_Forms_Admin' ) && method_exists( 'CT_Forms_Admin', 'get_settings' ) ) {
                        $settings = CT_Forms_Admin::get_settings();
                    } else {
                        $settings = get_option( 'ct_forms_settings', array() );
                        if ( ! is_array( $settings ) ) { $settings = array(); }
                        $settings = array_merge( array(
                            'allowed_mimes' => 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt',
                            'max_file_mb'   => 10,
                        ), $settings );
                    }

                    $global_allowed = isset( $settings['allowed_mimes'] ) ? (string) $settings['allowed_mimes'] : '';
                    $global_max_mb  = isset( $settings['max_file_mb'] ) ? (int) $settings['max_file_mb'] : 0;
                    if ( $global_max_mb <= 0 ) { $global_max_mb = 10; }

                    $global_exts = array();
                    if ( '' !== trim( $global_allowed ) ) {
                        $global_exts = array_filter( array_map( 'trim', explode( ',', strtolower( $global_allowed ) ) ) );
                        $global_exts = array_map( function( $e ) {
                            $e = ltrim( $e, '.' );
                            return $e ? '.' . $e : '';
                        }, $global_exts );
                        $global_exts = array_filter( $global_exts );
                    }

                    // File extensions are controlled globally in Settings.
                    if ( '' === $accept_attr && ! empty( $global_exts ) ) {
                        $accept_attr = ' accept="' . esc_attr( implode( ',', $global_exts ) ) . '"';
                    }

					$is_multi = ! empty( $field['file_multiple'] );
					if ( $is_multi ) {
						// Use an array name so PHP receives multiple uploaded files.
						$file_name = $file_name . '[]';
						// We intentionally do NOT rely on the browser's single multi-select UI.
						// Instead, we render multiple single-file inputs (added dynamically via JS)
						// to make it obvious to users that they can attach additional files.
						$multiple_attr = '';
					}

                    // Max file size for this field (defaults to global settings).
                    $max_mb = $global_max_mb;
                    if ( ! empty( $field['file_max_mb'] ) ) {
                        $tmp = (int) $field['file_max_mb'];
                        if ( $tmp > 0 ) { $max_mb = $tmp; }
                    }

                    $allowed_no_dots = array();
                    if ( ! empty( $global_exts ) ) {
                        $allowed_no_dots = array_map( function( $e ) { return ltrim( (string) $e, '.' ); }, $global_exts );
                    }
                    $allowed_no_dots = array_filter( array_map( 'sanitize_text_field', (array) $allowed_no_dots ) );

                    $data_allowed = ! empty( $allowed_no_dots ) ? ' data-truitt-allowed="' . esc_attr( implode( ',', $allowed_no_dots ) ) . '"' : '';
                    $data_max_mb  = $max_mb > 0 ? ' data-truitt-max-mb="' . esc_attr( (string) (int) $max_mb ) . '"' : '';

					if ( $is_multi ) {
						echo '<div class="ct-forms-file-multi" data-ct-forms-multi="1">';
					}
					echo '<input type="file" id="' . esc_attr( $common['id'] ) . '" name="' . esc_attr( $file_name ) . '" class="ct-forms-file-input"' . $accept_attr . $multiple_attr . $data_allowed . $data_max_mb . ' ' . ( $required ? 'required' : '' ) . '>';
					if ( $is_multi ) {
						echo '</div>';
					}

                    // Inline help: show allowed types and max size as defined in Settings.
                    $help_exts = array();
                    if ( ! empty( $global_exts ) ) {
                        $help_exts = array_map( function( $e ) { return ltrim( $e, '.' ); }, $global_exts );
                    }
                    $help_exts = array_filter( array_map( function( $e ) { return ltrim( (string) $e, '.' ); }, $help_exts ) );

                    $help_bits = array();
                    if ( ! empty( $help_exts ) ) {
                        $help_bits[] = 'Allowed file types: ' . esc_html( implode( ', ', $help_exts ) );
                    } elseif ( ! empty( $global_allowed ) ) {
                        $help_bits[] = 'Allowed file types: ' . esc_html( $global_allowed );
                    }

                    if ( $max_mb > 0 ) {
                        $help_bits[] = 'Max size: ' . (int) $max_mb . ' MB';
                    }

                    if ( ! empty( $help_bits ) ) {
                        echo '<p class="truitt-field-help truitt-field-help--file">' . implode( ' · ', $help_bits ) . '</p>';
                    }

                    // Placeholder for client-side validation messages.
                    echo '<div class="ct-forms-field-message" aria-live="polite"></div>';
                    break;

                case 'email':
                    echo '<input type="email" id="' . esc_attr( $common['id'] ) . '" name="' . esc_attr( $common['name'] ) . '" ' . ( $required ? 'required' : '' ) . ' placeholder="' . esc_attr( $common['placeholder'] ) . '">';
                    break;

                case 'number':
                    echo '<input type="number" id="' . esc_attr( $common['id'] ) . '" name="' . esc_attr( $common['name'] ) . '" ' . ( $required ? 'required' : '' ) . ' placeholder="' . esc_attr( $common['placeholder'] ) . '">';
                    break;

                default:
                    echo '<input type="text" id="' . esc_attr( $common['id'] ) . '" name="' . esc_attr( $common['name'] ) . '" ' . ( $required ? 'required' : '' ) . ' placeholder="' . esc_attr( $common['placeholder'] ) . '">';
                    break;
            }
            ?>

            <?php if ( '' !== trim( $help ) ) : ?>
                <div class="ct-forms-help"><?php echo esc_html( $help ); ?></div>
            <?php endif; ?>

            <?php
            // Field-level errors (displayed after redirect back to the form).
            if ( ! empty( self::$current_field_errors ) && isset( self::$current_field_errors[ $id ] ) ) {
                $msg = self::$current_field_errors[ $id ];
                $messages = array();

                if ( is_array( $msg ) ) {
                    $messages = $msg;
                } elseif ( is_string( $msg ) && '' !== trim( $msg ) ) {
                    $messages = array( self::field_error_message( $msg ) );
                }

                $messages = array_values( array_filter( array_map( function( $m ) { return is_string( $m ) ? trim( $m ) : ''; }, $messages ) ) );
                if ( ! empty( $messages ) ) {
                    echo '<div class="ct-forms-field-error" role="alert">' . esc_html( implode( ' ', $messages ) ) . '</div>';
                }
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }


    private static function get_us_states() {
        return array(
            'AL' => 'Alabama',
            'AK' => 'Alaska',
            'AZ' => 'Arizona',
            'AR' => 'Arkansas',
            'CA' => 'California',
            'CO' => 'Colorado',
            'CT' => 'Connecticut',
            'DE' => 'Delaware',
            'DC' => 'District of Columbia',
            'FL' => 'Florida',
            'GA' => 'Georgia',
            'HI' => 'Hawaii',
            'ID' => 'Idaho',
            'IL' => 'Illinois',
            'IN' => 'Indiana',
            'IA' => 'Iowa',
            'KS' => 'Kansas',
            'KY' => 'Kentucky',
            'LA' => 'Louisiana',
            'ME' => 'Maine',
            'MD' => 'Maryland',
            'MA' => 'Massachusetts',
            'MI' => 'Michigan',
            'MN' => 'Minnesota',
            'MS' => 'Mississippi',
            'MO' => 'Missouri',
            'MT' => 'Montana',
            'NE' => 'Nebraska',
            'NV' => 'Nevada',
            'NH' => 'New Hampshire',
            'NJ' => 'New Jersey',
            'NM' => 'New Mexico',
            'NY' => 'New York',
            'NC' => 'North Carolina',
            'ND' => 'North Dakota',
            'OH' => 'Ohio',
            'OK' => 'Oklahoma',
            'OR' => 'Oregon',
            'PA' => 'Pennsylvania',
            'RI' => 'Rhode Island',
            'SC' => 'South Carolina',
            'SD' => 'South Dakota',
            'TN' => 'Tennessee',
            'TX' => 'Texas',
            'UT' => 'Utah',
            'VT' => 'Vermont',
            'VA' => 'Virginia',
            'WA' => 'Washington',
            'WV' => 'West Virginia',
            'WI' => 'Wisconsin',
            'WY' => 'Wyoming',
        );
    }

    private static function field_error_message( $code ) {
        switch ( (string) $code ) {
            case 'required':
                return __( 'This field is required.', 'ct-forms' );
            case 'invalid_email':
                return __( 'Please enter a valid email address.', 'ct-forms' );
            default:
                return (string) $code;
        }
    }
}
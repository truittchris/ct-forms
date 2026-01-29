<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CT_Forms_CPT {

    private static function normalize_template_text( $text ) {
        $text = (string) $text;
        // Fix common newline corruption where \r\n becomes rnrn after JSON/slash handling.
        $text = str_replace( array( "\\r\\n", "\\n", "\n", "\\r" ), "\n", $text );
        $text = preg_replace( "/rnrn/i", "\n\n", $text );
        $text = preg_replace( "/rn(\{|Reference:)/i", "\n$1", $text );
        // If any remaining literal 'rn' sequences exist, convert common double-newline patterns.
        $text = preg_replace( "/rn\s*rn/i", "\n\n", $text );
        // Repair stripped-newline artifacts where "nn" appears after punctuation (e.g., ". nnIMPORTANT").
        $text = preg_replace( "/(?<=[\}\]\.\)])\s*nn(?=[A-Z0-9])/i", "\n\n", $text );
        return $text;
    }


    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        $labels = array(
            'name'               => __( 'Forms', 'ct-forms' ),
            'singular_name'      => __( 'Form', 'ct-forms' ),
            'add_new'            => __( 'Add New', 'ct-forms' ),
            'add_new_item'       => __( 'Add New Form', 'ct-forms' ),
            'edit_item'          => __( 'Edit Form', 'ct-forms' ),
            'new_item'           => __( 'New Form', 'ct-forms' ),
            'view_item'          => __( 'View Form', 'ct-forms' ),
            'search_items'       => __( 'Search Forms', 'ct-forms' ),
            'not_found'          => __( 'No forms found.', 'ct-forms' ),
            'not_found_in_trash' => __( 'No forms found in Trash.', 'ct-forms' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => false, // We'll add our own top-level menu.
            'capability_type'    => array( 'ct_form', 'ct_forms' ),
            'map_meta_cap'       => true,
            'supports'           => array( 'title' ),
        );

        register_post_type( 'ct_form', $args );
    }

    public static function default_form_definition() {
        return array(
            'version' => 1,
            'fields'  => array(
                array(
                    'id' => 'name',
                    'type' => 'text',
                    'label' => 'Name',
                    'required' => true,
                    'placeholder' => '',
                    'help' => '',
                    'options' => array(),
                ),
                array(
                    'id' => 'email',
                    'type' => 'email',
                    'label' => 'Email',
                    'required' => true,
                    'placeholder' => '',
                    'help' => '',
                    'options' => array(),
                ),
                array(
                    'id' => 'message',
                    'type' => 'textarea',
                    'label' => 'Message',
                    'required' => true,
                    'placeholder' => '',
                    'help' => '',
                    'options' => array(),
                ),
            )
        );
    }

    public static function get_form_definition( $form_id ) {
        $json = get_post_meta( $form_id, '_ct_form_definition', true );
        if ( ! $json ) {
            return self::default_form_definition();
        }
        $def = json_decode( $json, true );
        if ( ! is_array( $def ) || empty( $def['fields'] ) ) {
            return self::default_form_definition();
        }
        return $def;
    }

    public static function save_form_definition( $form_id, $definition ) {
        update_post_meta( $form_id, '_ct_form_definition', wp_json_encode( $definition ) );
    }

    public static function get_form_settings( $form_id ) {
        $defaults = array(
            'to_email' => get_option( 'admin_email' ),
            'email_subject' => 'New form submission: {form_name}',
            'email_body' => "You have a new submission for {form_name}.\n\n{all_fields}\n\nEntry ID: {entry_id}",            'cc' => '',
            'bcc' => '',
            'reply_to_field' => 'email',
            'attach_uploads' => 0,
            'autoresponder_enabled' => 0,
            'autoresponder_to_field' => 'email',
            'autoresponder_subject' => 'We received your message',
            'autoresponder_body' => "Thanks for reaching out. We received your submission and will respond as soon as possible.\n\nReference: {entry_id}",
            'routing_rules' => array(), // each rule: {field, operator, value, to_email}
            'confirmation_type' => 'message', // message|redirect
            'confirmation_message' => 'Thanks. Your message has been sent.',
            'confirmation_redirect' => '',
            'recaptcha_enabled' => 0,
        );

        $raw = get_post_meta( $form_id, '_ct_form_settings', true );
        if ( ! $raw ) { return $defaults; }

        $s = json_decode( $raw, true );
        if ( ! is_array( $s ) ) { return $defaults; }

        $s = array_merge( $defaults, $s );

        // Normalize template text to avoid literal "rnrn" artifacts.
        if ( isset( $s['email_body'] ) ) { $s['email_body'] = self::normalize_template_text( $s['email_body'] ); }
        if ( isset( $s['autoresponder_body'] ) ) { $s['autoresponder_body'] = self::normalize_template_text( $s['autoresponder_body'] ); }
        if ( isset( $s['confirmation_message'] ) ) { $s['confirmation_message'] = self::normalize_template_text( $s['confirmation_message'] ); }

        return $s;
    }

    public static function save_form_settings( $form_id, $settings ) {
        update_post_meta( $form_id, '_ct_form_settings', wp_json_encode( $settings ) );

        // Ensure caches are invalidated consistently.
        clean_post_cache( $form_id );
        wp_cache_delete( $form_id, 'posts' );
        wp_cache_delete( $form_id, 'post_meta' );
    }
}
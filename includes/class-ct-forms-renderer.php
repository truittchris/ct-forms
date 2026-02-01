<?php
/**
 * Block renderer for CT Forms.
 *
 * @package CT_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * CT Forms block renderer.
 */
final class CT_Forms_Renderer {
	/**
	 * Validation errors keyed by field id.
	 *
	 * @var array<string,string>
	 */
	private static $current_field_errors = array();

	/**
	 * Validation warnings keyed by field id.
	 *
	 * @var array<string,string>
	 */
	private static $current_field_warnings = array();

	/**
	 * Posted values from a verified submission attempt.
	 *
	 * @var array
	 */
	private static $current_posted = array();

	/**
	 * Render the block markup.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$form_id = isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0;
		if ( $form_id <= 0 ) {
			return '';
		}

		$settings = get_post_meta( $form_id, '_ct_form_settings', true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Security: Nonce verification.
		if ( isset( $_POST['ct_form_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['ct_form_nonce'] ), 'ct_form_submit_' . $form_id ) ) {
				self::$current_field_errors['form'] = __( 'Security check failed. Please refresh the page.', 'ct-forms' );
			} else {
				self::handle_submission( $form_id, $attributes, $settings );
			}
		}

		ob_start();
		// ... render form HTML ...
		return ob_get_clean();
	}

	/**
	 * Handle submission.
	 *
	 * @param int   $form_id    Form ID.
	 * @param array $attributes Attributes.
	 * @param array $settings   Settings.
	 */
	private static function handle_submission( $form_id, $attributes, $settings ) {
		// Verify nonce again locally if needed, though checked in render_block.
		if ( ! isset( $_POST['ct_form_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ct_form_nonce'] ), 'ct_form_submit_' . $form_id ) ) {
			return;
		}

		$fields = self::get_fields_from_attributes( $attributes );
		$data   = array();

		foreach ( $fields as $field ) {
			$id       = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			$label    = isset( $field['name'] ) ? sanitize_text_field( (string) $field['name'] ) : '';
			$required = ! empty( $field['required'] );

			if ( '' === $id ) {
				continue;
			}

			$raw = isset( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : '';
			$val = is_array( $raw ) ? implode( ', ', array_map( 'sanitize_text_field', $raw ) ) : sanitize_text_field( (string) $raw );

			if ( $required && '' === $val ) {
				self::$current_field_errors[ $id ] = sprintf(
					/* translators: %s: Field label. */
					__( '%s is required.', 'ct-forms' ),
					$label
				);
			}
			$data[ $id ] = $val;
		}

		// If no errors, process.
		if ( empty( self::$current_field_errors ) ) {
			CT_Forms_Submissions::process( $form_id, $data, $settings );
		}
	}

	/**
	 * Dummy method to simulate attribute field retrieval.
	 *
	 * @param array $attributes Attributes.
	 * @return array
	 */
	private static function get_fields_from_attributes( $attributes ) {
		return isset( $attributes['fields'] ) ? (array) $attributes['fields'] : array();
	}
}

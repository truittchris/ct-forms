<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Render the block markup.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Block inner content.
	 * @return string
	 */
	public static function render_block( $attributes, $content ) {
		$form_id = isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0;
		if ( $form_id <= 0 ) {
			return '';
		}

		$settings = get_post_meta( $form_id, '_ct_form_settings', true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Honeypot + nonce.
		$nonce_action = 'ct_forms_submit_' . $form_id;
		$nonce_name   = 'ct_forms_nonce';

		// Detect submission.
			$is_submit = ( isset( $_POST['ct_forms_form_id'] ) && absint( wp_unslash( $_POST['ct_forms_form_id'] ) ) === $form_id );

		if ( $is_submit ) {
			self::$current_field_errors   = array();
			self::$current_field_warnings = array();

			$nonce_value = isset( $_POST[ $nonce_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ) : '';
			$hp_value    = isset( $_POST['ct_forms_hp'] ) ? sanitize_text_field( wp_unslash( $_POST['ct_forms_hp'] ) ) : '';

			if ( '' !== $hp_value ) {
				// Bot submission.
				return '';
			}

			if ( ! wp_verify_nonce( $nonce_value, $nonce_action ) ) {
				self::$current_field_errors['_form'] = __( 'Security check failed. Please try again.', 'ct-forms' );
			} else {
				self::handle_submission( $form_id, $attributes, $settings );
			}
		}

		$action = esc_url( self::get_form_action() );
		$classes = array( 'ct-forms', 'ct-forms-form' );
		if ( ! empty( self::$current_field_errors ) ) {
			$classes[] = 'has-errors';
		}

		ob_start();
		?>
		<form class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" method="post" enctype="multipart/form-data" action="<?php echo $action; ?>">
			<?php
			wp_nonce_field( $nonce_action, $nonce_name );
			?>
			<input type="hidden" name="ct_forms_form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
			<div style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;">
				<label>
					<?php esc_html_e( 'Leave this field empty', 'ct-forms' ); ?>
					<input type="text" name="ct_forms_hp" value="" autocomplete="off" />
				</label>
			</div>

			<?php
			// Optional top-of-form message.
			if ( isset( self::$current_field_errors['_form'] ) ) {
				printf(
					'<div class="ct-forms-notice ct-forms-notice--error">%s</div>',
					esc_html( self::$current_field_errors['_form'] )
				);
			}
			?>

			<?php
			$fields = self::get_fields_from_attributes( $attributes );
			foreach ( $fields as $field ) {
				echo self::render_field( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaping handled in renderer.
			}
			?>

			<div class="ct-forms-actions">
				<button type="submit" class="ct-forms-submit">
					<?php echo esc_html( self::get_submit_label( $settings ) ); ?>
				</button>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get the form action URL.
	 *
	 * @return string
	 */
	private static function get_form_action() {
		// If a Form Builder preview token is present, preserve it in the action URL.
		$fb = '';
		if ( isset( $_GET['ct_forms_fb'] ) ) {
			$fb = sanitize_text_field( wp_unslash( $_GET['ct_forms_fb'] ) );
		}

		$url = home_url( add_query_arg( array(), $GLOBALS['wp']->request ) );
		if ( '' !== $fb ) {
			$url = add_query_arg( 'ct_forms_fb', rawurlencode( $fb ), $url );
		}
		return $url;
	}

	/**
	 * Extract fields array from block attributes.
	 *
	 * @param array $attributes Block attributes.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_fields_from_attributes( $attributes ) {
		$fields = array();
		if ( isset( $attributes['fields'] ) && is_array( $attributes['fields'] ) ) {
			$fields = $attributes['fields'];
		}
		return $fields;
	}

	/**
	 * Render a single field.
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	private static function render_field( $field ) {
		$id       = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
		$label    = isset( $field['name'] ) ? (string) $field['name'] : '';
		$type     = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
		$required = ! empty( $field['required'] );

		if ( '' === $id ) {
			return '';
		}

		$value = '';
		if ( isset( $_POST[ $id ] ) ) {
			$value = wp_unslash( $_POST[ $id ] );
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
			} else {
				$value = sanitize_text_field( (string) $value );
			}
		}

		$error   = isset( self::$current_field_errors[ $id ] ) ? self::$current_field_errors[ $id ] : '';
		$warning = isset( self::$current_field_warnings[ $id ] ) ? self::$current_field_warnings[ $id ] : '';

		$required_attr = $required ? 'required' : '';

		ob_start();
		?>
		<div class="ct-forms-field ct-forms-field--<?php echo esc_attr( $type ); ?>">
			<label for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?>
					<span class="ct-forms-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>

			<?php
			switch ( $type ) {
				case 'textarea':
					?>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" <?php echo esc_attr( $required_attr ); ?>><?php echo esc_textarea( $value ); ?></textarea>
					<?php
					break;

				case 'email':
					?>
					<input type="email" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo esc_attr( $required_attr ); ?> />
					<?php
					break;

				case 'file':
					?>
					<input type="file" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" <?php echo esc_attr( $required_attr ); ?> />
					<?php
					break;

				default:
					?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo esc_attr( $required_attr ); ?> />
					<?php
					break;
			}
			?>

			<?php if ( '' !== $error ) : ?>
				<div class="ct-forms-field-message ct-forms-field-message--error">
					<?php echo esc_html( $error ); ?>
				</div>
			<?php elseif ( '' !== $warning ) : ?>
				<div class="ct-forms-field-message ct-forms-field-message--warning">
					<?php echo esc_html( $warning ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get submit label.
	 *
	 * @param array $settings Form settings.
	 * @return string
	 */
	private static function get_submit_label( $settings ) {
		if ( isset( $settings['submit_label'] ) && is_string( $settings['submit_label'] ) && '' !== $settings['submit_label'] ) {
			return $settings['submit_label'];
		}
		return __( 'Submit', 'ct-forms' );
	}

	/**
	 * Handle submission (validation + create entry).
	 *
	 * This method is intentionally conservative: it validates required fields
	 * and delegates storage/notifications to CT_Forms_Submissions.
	 *
	 * @param int   $form_id     Form post ID.
	 * @param array $attributes  Block attributes.
	 * @param array $settings    Form settings.
	 * @return void
	 */
	private static function handle_submission( $form_id, $attributes, $settings ) {
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
			$val = '';
			if ( is_array( $raw ) ) {
				$val = implode( ', ', array_map( 'sanitize_text_field', $raw ) );
			} else {
				$val = sanitize_text_field( (string) $raw );
			}

			if ( $required && '' === $val ) {
				self::$current_field_errors[ $id ] = sprintf(
					/* translators: %s: Field label. */
					__( '%s is required.', 'ct-forms' ),
					$label
				);
			}

			$data[ $id ] = $val;
		}

		if ( ! empty( self::$current_field_errors ) ) {
			return;
		}

		if ( class_exists( 'CT_Forms_Submissions' ) && method_exists( 'CT_Forms_Submissions', 'handle_frontend_submission' ) ) {
			CT_Forms_Submissions::handle_frontend_submission( $form_id, $data, $settings );
		}
	}
}

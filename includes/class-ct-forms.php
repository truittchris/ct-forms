<?php
/**
 * Core plugin loader.
 *
 * @package CT_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once CT_FORMS_PLUGIN_DIR . 'includes/class-ct-forms-db.php';
require_once CT_FORMS_PLUGIN_DIR . 'includes/class-ct-forms-cpt.php';
require_once CT_FORMS_PLUGIN_DIR . 'includes/class-ct-forms-renderer.php';
require_once CT_FORMS_PLUGIN_DIR . 'includes/class-ct-forms-submissions.php';
require_once CT_FORMS_PLUGIN_DIR . 'includes/class-ct-forms-admin.php';
require_once CT_FORMS_PLUGIN_DIR . 'includes/class-ct-forms-tools.php';
require_once CT_FORMS_PLUGIN_DIR . 'includes/class-ct-forms-entries-table.php';

// Block registration handled via CT_Forms::register_block()

/**
 * Main plugin class.
 */
final class CT_Forms {

	/**
	 * Singleton instance.
	 *
	 * @var CT_Forms|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return CT_Forms
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Ensure DB schema is up to date (runs lightweight dbDelta only when needed).
		CT_Forms_DB::ensure_schema();

		CT_Forms_CPT::init();
		CT_Forms_Submissions::init();
		CT_Forms_Admin::init();
		CT_Forms_Tools::init();

		add_shortcode( 'ct_form', array( 'CT_Forms_Renderer', 'shortcode' ) );
		// Back-compat: previously used shortcode.
		add_shortcode( 'ctc_form', array( 'CT_Forms_Renderer', 'shortcode' ) );

		// Dynamic block (optional in editor). If block assets are missing, shortcode still works.
		add_action( 'init', array( $this, 'register_block' ) );

		add_filter( 'upload_mimes', array( $this, 'filter_upload_mimes' ) );
	}

	/**
	 * Register the block type.
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Global guard to prevent duplicate registrations even if the plugin is loaded twice.
		if ( defined( 'CT_FORMS_BLOCK_REGISTERED' ) ) {
			return;
		}
		define( 'CT_FORMS_BLOCK_REGISTERED', true );

		$name = 'ct-forms/ct-form';
		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			$registry = WP_Block_Type_Registry::get_instance();
			if ( $registry && $registry->is_registered( $name ) ) {
				return;
			}
		}

		$block_path = CT_FORMS_PLUGIN_DIR . 'blocks/ct-form/block.json';
		if ( file_exists( $block_path ) ) {
			register_block_type(
				$block_path,
				array(
					'render_callback' => array( 'CT_Forms_Renderer', 'render_block' ),
				)
			);
		}
	}

	/**
	 * Activation hook handler.
	 */
	public static function activate() {
		CT_Forms_DB::maybe_create_tables();
		CT_Forms_CPT::register();
		flush_rewrite_rules();

		// Site instance marker (useful for distinguishing installs during renames/cutovers).
		if ( ! get_option( 'ct_forms_site_instance_id', '' ) && function_exists( 'wp_generate_uuid4' ) ) {
			add_option( 'ct_forms_site_instance_id', wp_generate_uuid4() );
		}

		// Add capabilities to administrators.
		$role = get_role( 'administrator' );
		if ( $role ) {
			$caps = array(
				'ct_forms_manage',
				'ct_forms_view_entries',
				'ct_forms_export_entries',
			);
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}

		// Create uploads subfolder.
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'ct-forms';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Attempt to discourage direct access (works on Apache).
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
		}
	}

	/**
	 * Deactivation hook handler.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Filter allowed MIME types.
	 *
	 * @param array $mimes Allowed MIME types.
	 * @return array
	 */
	public function filter_upload_mimes( $mimes ) {
		$settings = CT_Forms_Admin::get_settings();
		$allowed  = isset( $settings['allowed_mimes'] ) ? (string) $settings['allowed_mimes'] : '';
		if ( '' === trim( $allowed ) ) {
			return $mimes;
		}

		// Admin-defined allowlist, e.g. "jpg,jpeg,png,pdf".
		$exts = array_filter( array_map( 'trim', explode( ',', strtolower( $allowed ) ) ) );
		if ( empty( $exts ) ) {
			return $mimes;
		}

		$new = array();
		foreach ( $mimes as $ext => $mime ) {
			$pieces = explode( '|', $ext );
			foreach ( $pieces as $p ) {
				if ( in_array( strtolower( $p ), $exts, true ) ) {
					$new[ $ext ] = $mime;
					break;
				}
			}
		}

		/**
		 * Filter: ct_forms_allowed_mimes
		 * Lets developers adjust the final MIME map.
		 */
		return apply_filters( 'ct_forms_allowed_mimes', $new, $mimes, $exts );
	}
}

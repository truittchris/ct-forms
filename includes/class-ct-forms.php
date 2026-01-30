<?php
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

/**
 * Core plugin class.
 *
 * @package CT_Forms
 */
final class CT_Forms {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
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
	 *
	 * @return void
	 */
	public function init(): void {
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
	 * Register the optional dynamic block.
	 *
	 * @return void
	 */
	public function register_block(): void {
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
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate(): void {
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
			self::maybe_write_htaccess( $htaccess );
		}
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Use WP_Filesystem (when available) to write a small .htaccess file.
	 *
	 * @param string $path File path.
	 * @return void
	 */
	private static function maybe_write_htaccess( string $path ): void {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return;
		}

		$contents = "Options -Indexes\nDeny from all\n";
		$wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
	}

	/**
	 * Restrict MIME uploads to the configured allowlist.
	 *
	 * @param array $mimes Current allowed MIME types.
	 * @return array
	 */
	public function filter_upload_mimes( array $mimes ): array {
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
			$pieces = explode( '|', (string) $ext );
			foreach ( $pieces as $piece ) {
				if ( in_array( strtolower( $piece ), $exts, true ) ) {
					$new[ $ext ] = $mime;
					break;
				}
			}
		}

		/**
		 * Filter the final allowed MIME map.
		 *
		 * @param array    $new   Filtered map.
		 * @param array    $mimes Original map.
		 * @param string[] $exts  Allowed extensions.
		 */
		return apply_filters( 'ct_forms_allowed_mimes', $new, $mimes, $exts );
	}
}

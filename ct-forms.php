<?php
/**
 * CT Forms
 *
 * A lightweight forms builder for WordPress – designed for simple, reliable
 * contact and support workflows. Includes file uploads, admin notifications,
 * autoresponders, entry storage, and an admin-side files manager.
 *
 * @since             1.0.0
 * @package           CT_Forms
 *
 * @wordpress-plugin
 * Plugin Name:       CT Forms
 * Plugin URI:        https://christruitt.com
 * Description:       Create, embed, and manage forms with file uploads, notifications, autoresponders, and entry storage.
 * Version:           1.0.46
 * Author:            Christopher Truitt
 * Author URI:        https://christruitt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ct-forms
 * Domain Path:       /languages
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CT_FORMS_VERSION', '1.0.46' );

define( 'CT_FORMS_PLUGIN_FILE', __FILE__ );
define( 'CT_FORMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CT_FORMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CT_FORMS_PLUGIN_DIR . 'includes/class-ct-forms.php';

register_activation_hook( __FILE__, array( 'CT_Forms', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CT_Forms', 'deactivate' ) );

add_action( 'plugins_loaded', function() {
    CT_Forms::instance();
} );

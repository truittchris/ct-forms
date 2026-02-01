=== CT Forms ===
Contributors: truittchris
Tags: forms, contact form, file uploads, entries, notifications
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.53
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight forms plugin with a modern builder, entry storage, file uploads, notifications, autoresponders, and a Support page with diagnostics.

== Description ==

CT Forms is a lightweight WordPress forms plugin designed for reliability and admin usability:

* Visual form builder (text, textarea, select, radios, checkboxes, file upload)
* Entry storage in a dedicated database table
* Entries list with search, bulk actions, CSV export, and attachment indicator
* File uploads management screen (list, download, delete, bulk delete)
* Admin notifications + autoresponders with token support (e.g., {form_name}, {all_fields}, {entry_id})
* Support admin page with a support request form and system diagnostics

Shortcode:
* [ct_form id="123"]

== Installation ==

1. Upload the plugin zip in WordPress (Plugins - Add New - Upload Plugin), then activate.
2. Go to CT Forms - Forms and create a form.
3. Copy the shortcode and paste it into a page/post.

== Frequently Asked Questions ==

= Where do I find entries? =
CT Forms - Entries.

= Where do uploaded files go? =
Uploads are stored under wp-content/uploads/ct-forms/ (site-specific paths may vary).

== Changelog ==

= 1.0.53 =
* Packaging: synchronized composer.json and composer.lock dependencies for CI/CD workflows.
* Security: fixed a JSON parse error in plugin configuration.

= 1.0.52 =
* Code style: address PHPCS baseline issues (file/class/method docblocks, tabs, guard formatting).
* Uninstall: silence false-positive prepared SQL warning for internal table names and add schema-change ignore.
* Blocks: WPCS file docblock and guard normalization.

= 1.0.51 =
* Version: bump and ensure version parity (plugin header, constants, readme).
* Security: use WP_Filesystem for writing .htaccess on activation and for uninstall cleanup.
* I18n: make default field labels translatable.

== Upgrade Notice ==

= 1.0.53 =
Composer dependency synchronization and JSON formatting fixes.

= 1.0.52 =
PHPCS/WPCS cleanup and uninstall query lint adjustments.
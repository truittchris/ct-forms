=== CT Forms ===
Contributors: truittchris
Tags: forms, contact form, file uploads, entries, notifications
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.50
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

= 1.0.49 =
* Confirmation: make the confirmation message field a WYSIWYG editor.
* Fix: normalize stripped newline artifacts so "nn" no longer appears in confirmation output.

= 1.0.48 =
* Builder: add Date, Time, and State (US) field types.
* I18n: fix placeholder ordering, add translators comments, and add missing text domain args.

= 1.0.47 =
* Frontend: remove the default confirmation border styling to avoid a nested "double box" look in common themes.

= 1.0.46 =
* Fix: resolve a PHP parse error when viewing Forms after adding reCAPTCHA type options.

= 1.0.45 =
* Settings: add selectable reCAPTCHA type (Disabled, v2 checkbox, v2 invisible, v3)
* Frontend: support v3 token generation and v2 invisible execution
* Backend: verify v3 score/action when enabled

= 1.0.40 =
* Packaging: align plugin version values, add WordPress readme, remove dev scripts from distribution zip.

== Upgrade Notice ==

= 1.0.49 =
WYSIWYG confirmation message editor and newline artifact fixes.

= 1.0.48 =
Adds Date/Time/State fields and includes i18n compliance fixes.

= 1.0.47 =
Removes default confirmation border styling to better match modern themes.

= 1.0.40 =
Packaging and version alignment improvements.

# CT Forms
CT Forms is a lightweight WordPress form builder with a drag-and-drop admin builder, email notifications, and entry storage.

Version: 6.2.1  
License: GPL-2.0-or-later

## Features
- Drag and drop form builder (admin)
- Common field types: short text, paragraph, email, US phone, dropdown, checkboxes, US states, number, date picker, file upload
- Admin notification email
- Optional autoresponder email
- Success message display after submission
- Entry storage (submissions saved in WordPress)
- Shortcode-based embedding

## Requirements
- WordPress 6.0+ recommended
- PHP 7.4+ recommended

## Installation
1. Upload the plugin ZIP via WordPress Admin – Plugins – Add New – Upload Plugin, or upload the plugin folder to /wp-content/plugins/.
2. Activate CT Forms.
3. Go to CT Forms – Add New to create a form.

## Usage
1. Create or edit a form in CT Forms – Add New.
2. Drag fields from the Field Library into the canvas.
3. Configure Notifications, Autoresponder, and Success Logic.
4. Click Lock Design & Save.
5. Embed the form with the shortcode:

[ct_form id="123"]

Replace 123 with your form ID shown in CT Forms – CT Forms.

## Entries
You can view submissions in WP Admin under CT Forms – Entries.
- Filter entries by form
- Click View to see the submitted fields and metadata
- Click Delete to remove an entry (nonce-protected)

## Email delivery notes
CT Forms uses wp_mail(). Some hosts restrict outbound mail or require SMTP.
If emails do not arrive:
- install and configure an SMTP plugin (example: WP Mail SMTP)
- confirm your site’s admin email address is valid
- check spam and mail logs if available

## Security
- Builder save requests are protected with a nonce.
- Front-end submissions are protected with a nonce verified server-side.
- Inputs are sanitized and outputs are escaped where appropriate.

## Changelog
See CHANGELOG.md.

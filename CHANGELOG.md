## 1.0.43 - 2026-01-28
- Fix: per-form reCAPTCHA checkbox on Edit Form now saves correctly.

## 1.0.42
- Add per-form reCAPTCHA toggle and render widget on forms.
- Only enforce reCAPTCHA on forms where enabled.

## 1.0.41
- Add optional Google reCAPTCHA v2 checkbox support (front-end widget + server-side verification).

# CT Forms Changelog


## 1.0.40 - 2026-01-28
- Packaging: align plugin header version with CT_FORMS_VERSION.
- Packaging: add WordPress-standard readme.txt (lowercase).
- Packaging: remove developer build/bump scripts from distribution zip.

## v1.0.39 – Attachments column link
- Entries list: make the attachment paperclip a link (downloads first attachment when possible, otherwise opens entry detail).



## 1.0.38 - 2026-01-28
- Entries list: add an Attachments column (paperclip indicator) when an entry includes file uploads.

## 1.0.37 - 2026-01-28
- Builder UI: align the Field ID and Type controls on the same baseline.
- New field type: diagnostics (virtual) – captures site + environment info on submission (WP/PHP/CT Forms version, theme, active plugins) and includes it in notifications via {all_fields}.

## 1.0.35 - 2026-01-28
- Improved options editor for select, checkboxes, and radios: separate Value and Label inputs with add/remove.

## 1.0.34 – 2026-01-27
- Add version bump helper scripts: bump-version.sh and bump-version.ps1 (updates versions and builds zip).
- Standardize README version header.
## 1.0.33 – 2026-01-27
- Fixed plugin header/constant version placeholders so the plugin reports the correct version in WordPress.
- Added release packaging scripts for Git workflows: build-zip.sh and build-zip.ps1.

## 1.0.32
- Repo hygiene: add .gitignore, .editorconfig, .gitattributes, and LICENSE (GPL-2.0-or-later).
- Documentation: update README.txt to match current version.

## 1.0.31
- Add CT Forms -> Support admin page (support request form + diagnostics block).
- Add donation links (Tip Jar, Buy Me a Coffee, PayPal).

## 1.0.30
- Uploaded Files screen: checkbox selection + bulk actions (Delete) and Apply.

## 1.0.29
- Fix: resolve PHP parse/fatal from stray access modifier in submissions handler.

## 1.0.28
- Uploaded Files screen: introduce bulk-delete capability.

## 1.0.27
- Fix: normalize/repair "nn" newline artifacts in autoresponder templates.

## 1.0.23
- Fix: normalize/repair "nn" newline artifacts in admin notification templates.

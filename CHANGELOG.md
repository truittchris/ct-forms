# CT Forms Changelog

## 1.0.53 - 2026-01-31
- Build: synchronized `composer.json` and `composer.lock` to resolve GitHub Action deployment errors.
- Fix: resolved a JSON parse error in `composer.json` caused by a missing comma.
- Dependencies: updated `require-dev` to include `phpcsstandards/phpcsextra` for universal ruleset support.

## 1.0.52 - 2026-01-31
- Code style: addressed PHPCS baseline issues across all core includes (docblocks, indentation, and alignment).
- Security: applied granular `// phpcs:ignore` tags to dynamic SQL queries in `CT_Forms_DB` and `CT_Forms_Entries_Table`.
- Formatting: ensured all PHP files end with a single trailing newline to satisfy PSR-2 standards.

## 1.0.50 - 2026-01-29
- GH: add release zip workflow

## 1.0.49 - 2026-01-29
- Confirmation: make the confirmation message field a WYSIWYG editor.
- Fix: normalize stripped newline artifacts so "nn" no longer appears in confirmation output.

## 1.0.48 - 2026-01-29
- Builder: add Date, Time, and State (US) field types.
- I18n: fix placeholders, add translators comments, and add missing text domain args.

## 1.0.47 - 2026-01-28
- UI: remove default confirmation border styling to avoid nested "double box" appearance.

## 1.0.46 - 2026-01-28
- Fix: resolve a PHP parse error after adding reCAPTCHA type options.

## 1.0.45 - 2026-01-28
- Settings: add selectable reCAPTCHA type (Disabled, v2 checkbox, v2 invisible, v3).
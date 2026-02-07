# Changelog
All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog.
This project follows Semantic Versioning.

## [6.2.0] – 2026-02-06
### Added
- Entries storage: submissions are now saved to a dedicated database table.
- Entries admin screen under CT Forms – Entries:
  - Filter by form
  - View entry details (submitted fields, metadata)
  - Delete entries (nonce-protected)

### Improved
- Viewing an entry marks it as read.

## [6.1.2] – 2026-02-06
### Fixed
- Builder drag and drop now works reliably by enqueueing jquery-ui-droppable on the builder screen.
- Builder tabs (Notifications, Autoresponder, Appearance, Success Logic) now switch correctly.

### Added
- Front-end AJAX submission via a dedicated frontend script, ensuring submissions hit admin-ajax.php consistently across themes.
- Front-end nonce field and server-side nonce verification for submissions.

### Improved
- Success message now displays after successful submission (AJAX response is rendered into the success container).
- Email notification and autoresponder logic now executes reliably because submissions are processed through the AJAX handler.

## [6.1.1] – 2026-02-06
### Fixed
- Admin builder drop zone acceptance by adding missing jquery-ui-droppable dependency.

## [6.1.0] – 2026-02-06
### Changed
- Clean build with conditional logic removed.

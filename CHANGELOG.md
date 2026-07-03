# AIMate Changelog

## 2.1.0 - 2026-07-03
### Added
- Added AI-powered focal point generation for image assets.

## 2.0.2 - 2026-07-03
### Fixed
- Fixed a template injection vulnerability where a request-supplied `custom` prompt template was rendered as a Twig object template.
- Fixed missing authorization checks on the prompt and alt text generation controller actions, which let any logged-in user operate on elements they weren't permitted to edit.
- Fixed the prompt controller reflecting the rendered prompt and raw exception messages back to the client.
- Fixed CSRF protection being disabled on the prompt controller.
- Fixed the alt text image lookup so resolved local file paths are confined to the webroot.

### Changed
- Changed the bulk alt text generation action to cap how many assets can be queued per request and to skip assets the user can't edit.

## 2.0.1 - 2026-04-17
### Added
- Added `safeImageFormats` config setting and check. 

## 2.0.0 - 2026-01-27
### Added
- Initial stable release for Craft 5

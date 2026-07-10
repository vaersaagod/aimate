# AIMate Changelog

## Unreleased
### Added
- Added AI-powered keyword generation for image assets, via the new `AssetService::getKeywordsForAsset()` method. Unlike the alt text and focal point features, the generated keywords are returned to the caller instead of being saved to the asset.
- Added the `CpHelper::EVENT_DEFINE_ELEMENT_ACTIONS` event (with the new `DefineAiActionsEvent` class), enabling plugins and modules to add their own actions to the "AI" menu on element edit pages.
- Added the `AssetService::analyzeImage()` method, which generates any combination of alt text, keywords and a focal point in a single OpenAI vision call (instead of one call per task). Returns the results to the caller without saving.

### Changed
- Changed the default model to `gpt-5.4-mini`, as OpenAI has deprecated `gpt-5-mini` (API access shuts down in December 2026).
- OpenAI reasoning models are now instructed to use the lowest supported reasoning effort for alt text, focal point and keyword generation, significantly reducing latency and cost.

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

# AIMate Changelog

## 2.4.0 - 2026-09-29
### Added
- Added `DefineImageTransformEvent::$imageUrl`, enabling a listener to supply the image sent to OpenAI itself: a URL, absolute or relative to the webroot. For assets that can't be transformed the usual way, e.g. images on a CDN that can't resize them. When set, AIMate doesn't transform the asset.

## 2.3.0 - 2026-09-28
### Added
- Added the `AssetService::EVENT_DEFINE_IMAGE_TRANSFORM` event (with the new `DefineImageTransformEvent` class), enabling plugins and modules to change the transform for the image sent to OpenAI, or pass transform defaults on to Imager X – e.g. a Bunny transformer profile per volume.

### Changed
- AIMate now requires `openai-php/client` 0.21. The previous 0.10 line returned OpenAI errors sent as `text/plain` (e.g. an invalid API key) as a raw string, which surfaced as a `CreateResponse::from()` type error instead of OpenAI's own error message.

### Fixed
- Fixed base64-encoded images being typed from the asset's mime type instead of their actual contents. A transform to JPG of a PNG was sent as `image/png`, and an error page served with a 200 in place of the image was sent to OpenAI as an image; it's now logged and skipped.

## 2.2.0 - 2026-08-07
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

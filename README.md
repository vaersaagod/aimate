# AIMate

Ai ai, mate.

AIMate brings OpenAI-powered content generation to the Craft CMS control panel:
generate **alt text**, detect **focal points** and produce search **keywords** for
image assets, and run your own **text prompts** against any supported field — from
element edit pages, the element index, the console, or your own code.

## Requirements

- Craft CMS 5.9.0 or later
- PHP 8.2 or later
- An [OpenAI API key](https://platform.openai.com/api-keys)

AIMate talks to OpenAI's Chat Completions (vision) API via
[`openai-php/client`](https://github.com/openai-php/client). Until an API key is
configured the plugin stays completely inert — no menus, actions or listeners are
registered.

## Installation

```bash
composer require vaersaagod/aimate
php craft plugin/install _aimate
```

## Configuration

AIMate has **no control-panel settings screen** — it's configured entirely through
a `config/aimate.php` (or, matching the plugin handle, `config/_aimate.php`) file,
which behaves like any other [Craft config file](https://craftcms.com/docs/5.x/configure.html#config-files)
(multi-environment arrays are supported). At minimum you need an API key:

```php
<?php

use craft\helpers\App;

return [
    'openAIApiKey' => App::env('OPENAI_API_KEY'),
];
```

### Settings

| Setting | Type | Default | Description |
|---|---|---|---|
| `openAIApiKey` | `string` | – | Your OpenAI API key. Best set from an environment variable. |
| `model` | `string` | `gpt-5.4-mini` | Default OpenAI model used for every call. |
| `prompts` | `array` | `null` | Reusable text-prompt definitions, keyed by handle (see [Prompts](#prompts)). |
| `fields` | `array` | `[]` | Which fields the prompt actions appear on, keyed by field handle, field class or `'*'`. Set an entry to `false` to disable a field. |
| `altTextHandle` | `string` | `alt` | The asset attribute/field that generated alt text is written to (Craft's native `alt`, or a custom field handle). |
| `autoAltTextEnabled` | `bool` | `false` | Automatically generate alt text when an image asset is saved or replaced without any. |
| `autoFocalPointEnabled` | `bool` | `false` | Automatically detect a focal point when an image asset is saved or replaced without one. |
| `safeImageFormats` | `array` | `['jpg','jpeg','png','gif','webp']` | File extensions eligible for automatic processing. |
| `thumbSize` | `int` | `512` | Max width/height (px) of the JPEG transform sent to OpenAI. Smaller = cheaper/faster. |
| `useImagerIfInstalled` | `bool` | `true` | Use Imager/ImagerX for the outgoing transform when the plugin is installed. |
| `base64EncodeImage` | `string` | `auto` | Whether the image is inlined as a `data:` URI (`always`), sent as a URL (`never`), or decided automatically (`auto`). |
| `temperature` | `float` | `0.7` | Default temperature for text prompts. |
| `maxWordsMultiplier` | `float` | `1.5` | Multiplier applied to a prompt's target word count. |

> Auto alt text and auto focal point only run for **new** image assets (and on file
> replace) whose extension is in `safeImageFormats`, and only when the value is
> missing — existing alt text and manually-set focal points are never overwritten.
> Both run as queue jobs.

### Prompts

`prompts` defines reusable text prompts that surface as actions on element edit
pages and on supported fields. Each prompt is keyed by its handle:

```php
'prompts' => [
    'improveWriting' => [
        'handle'   => 'improveWriting',      // required
        'name'     => 'Improve writing',     // required — the menu label
        'template' => 'Improve the writing of the following text, keeping its meaning: <text>', // required
        // Optional:
        'model'       => 'gpt-5.4',          // override the default model for this prompt
        'temperature' => 0.4,
        'maxWords'    => 60,                  // int, or false for no limit
        'maxWordsMultiplier' => 1.5,
        'sites'       => ['default'],         // limit the prompt to certain sites
        'rules'       => [],                  // extra instructions passed to the model
    ],
],
```

The `<text>` placeholder is replaced with the field's current value. A prompt whose
template contains no `<text>` placeholder can generate content from scratch (e.g.
"Write a product description for …").

### Restricting prompt fields

Use `fields` to control where prompt actions appear:

```php
'fields' => [
    '*'            => true,   // default for every field
    'internalNote' => false,  // …except this one
],
```

Prompt actions are supported on the native **Entry title**, **Asset title** and
**Alt** fields, and on **Plain Text**, **Table** (single/multi-line columns) and
**CKEditor / Redactor** custom fields.

## Features

### Alt text

Generates WCAG-minded, non-decorative alt text in the asset's site language and
writes it to `altTextHandle`. Available:

- **Automatically** on save/replace when `autoAltTextEnabled` is on;
- **Element index** → select assets → **Generate alt text** (bulk, queued);
- **Element edit page** → the **AI** button → **Generate alt text** (single, synchronous);
- **Console:** `php craft _aimate/asset/alt-text`;
- **Programmatically:** `AssetService::generateAltTextForAsset()`.

### Focal point

Detects the most salient subject (faces prioritised) and sets the asset's native
focal point, clearing existing transforms so they regenerate. Same entry points as
alt text: auto (`autoFocalPointEnabled`), the **Generate focal point** index action,
the **AI** menu, `php craft _aimate/asset/focal-point`, and
`AssetService::getFocalPointForAsset()`.

### Keywords

Generates a de-duplicated list of search keywords for an image. Unlike alt text and
focal point, keywords are **returned to the caller, not saved** — this is a building
block for DAM-style keyword/tagging workflows. See
`AssetService::getKeywordsForAsset()`.

### Combined analysis

`AssetService::analyzeImage()` produces any combination of alt text, keywords and a
focal point in a **single** vision call rather than one call per task — cheaper and
faster when you want more than one. Results are returned, not saved.

## Programmatic API

All methods live on the `asset` service component
(`\vaersaagod\aimate\AIMate::getInstance()->asset`):

```php
use vaersaagod\aimate\AIMate;

$asset = \craft\elements\Asset::find()->kind('image')->one();
$service = AIMate::getInstance()->asset;

// Generate + save alt text (returns bool)
$service->generateAltTextForAsset($asset);

// Detect + save a focal point, clearing transforms (returns bool)
$service->getFocalPointForAsset($asset);

// Generate keywords — returned, not saved (returns ?array)
$keywords = $service->getKeywordsForAsset($asset, maxKeywords: 20, language: 'en', existingKeywords: []);

// Everything in one vision call — returned, not saved
$result = $service->analyzeImage($asset, include: ['altText', 'keywords', 'focalPoint'], opts: [
    'language'    => 'en',
    'maxKeywords' => 20,
    'altMaxChars' => 140,
]);
// $result => ['altText' => ?string, 'keywords' => ?array, 'focalPoint' => ?array{x, y}]

// Queue the work instead of running it inline
$service->createGenerateAltTextJob($asset);
$service->createGenerateFocalPointJob($asset, forced: false);

// Has the asset already got alt text?
$service->hasAltText($asset);
```

`getKeywordsForAsset()` and `analyzeImage()` throw `InvalidArgumentException` if the
asset isn't an image. For GPT‑5 reasoning models, AIMate automatically requests the
lowest supported reasoning effort for the image tasks to keep latency and cost down.

## Extending the AI menu

The **AI** disclosure menu on element edit pages is extensible. Listen for
`CpHelper::EVENT_DEFINE_ELEMENT_ACTIONS` and add your own items via the
`DefineAiActionsEvent`:

```php
use yii\base\Event;
use vaersaagod\aimate\helpers\CpHelper;
use vaersaagod\aimate\events\DefineAiActionsEvent;

Event::on(
    CpHelper::class,
    CpHelper::EVENT_DEFINE_ELEMENT_ACTIONS,
    static function (DefineAiActionsEvent $event) {
        // $event->element is the element the menu is being built for
        $event->actions[] = [
            'label' => 'My custom AI action',
            'attributes' => ['data-my-action' => '1'],
        ];
    }
);
```

Items use Craft's `Cp::disclosureMenu()` format. AIMate only **renders** the items —
your plugin/module is responsible for handling the click (typically a `data-*`
attribute plus a delegated JS handler).

## Console commands

```bash
# Generate alt text for image assets
php craft _aimate/asset/alt-text  [--volume=<handle>] [--site=<handle>] [--assetId=<id>] [--onlyEmpty=1]

# Generate focal points for image assets
php craft _aimate/asset/focal-point [--volume=<handle>] [--site=<handle>] [--assetId=<id>] [--onlyEmpty=1]
```

`--volume` defaults to `*` (all volumes) and `--onlyEmpty` defaults to `true` (skip
assets that already have a value).

## Price, license and support

Released under the Craft license and may be subject to license fees. Made for
Værsågod and friends; no support is given, though submitted issues are resolved when
they scratch an itch.

## Changelog

See [CHANGELOG.md](https://raw.githubusercontent.com/vaersaagod/aimate/master/CHANGELOG.md).

## Credits

Brought to you by [Værsågod](https://www.vaersaagod.no).

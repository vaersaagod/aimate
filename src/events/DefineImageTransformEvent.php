<?php

namespace vaersaagod\aimate\events;

use craft\elements\Asset;

use yii\base\Event;

class DefineImageTransformEvent extends Event
{
    /** @var Asset|null The image asset being sent to OpenAI */
    public ?Asset $asset = null;

    /** @var array The transform used for the image AIMate sends to OpenAI */
    public array $transform = [];

    /** @var array|null Transform defaults passed on to Imager X, e.g. `['transformerParams' => ['profile' => 'storage']]`. Ignored without Imager. */
    public ?array $transformDefaults = null;

    /**
     * @var string|null A ready-made image to send instead, for when the asset can't be transformed the usual way:
     * its URL, absolute or relative to the webroot. When set, AIMate doesn't transform the asset, and `transform`
     * and `transformDefaults` are ignored. Size it from `transform`.
     */
    public ?string $imageUrl = null;
}

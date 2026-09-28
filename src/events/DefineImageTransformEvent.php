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
}

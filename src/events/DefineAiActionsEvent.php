<?php

namespace vaersaagod\aimate\events;

use craft\base\ElementInterface;

use yii\base\Event;

class DefineAiActionsEvent extends Event
{
    /** @var ElementInterface|null The element the AI actions are for */
    public ?ElementInterface $element = null;

    /** @var array[] The AI action menu items, in the format expected by \craft\helpers\Cp::disclosureMenu() */
    public array $actions = [];
}

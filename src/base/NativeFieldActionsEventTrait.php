<?php
namespace vaersaagod\aimate\base;

use craft\base\ElementInterface;
use craft\events\DefineMenuItemsEvent;

trait NativeFieldActionsEventTrait
{
    public const EVENT_DEFINE_NATIVE_FIELD_ACTION_MENU_ITEMS = 'eventDefineNativeFieldActionMenuItems';

    public function actionMenuItems(?ElementInterface $element = null, bool $static = false): array
    {
        $items = parent::actionMenuItems($element, $static);

        // Fire a 'eventDefineNativeFieldActionMenuItems' event
        if ($this->hasEventHandlers(self::EVENT_DEFINE_NATIVE_FIELD_ACTION_MENU_ITEMS)) {
            $event = new class([
                'items' => $items,
                'element' => $element,
                'static' => $static,
            ]) extends DefineMenuItemsEvent
            {
                public ?ElementInterface $element = null;
                public ?bool $static = false;
            };
            $this->trigger(self::EVENT_DEFINE_NATIVE_FIELD_ACTION_MENU_ITEMS, $event);
            return $event->items;
        }

        return $items;
    }

    public function getLabel(): ?string
    {
        return $this->showLabel() ? $this->label() : null;
    }
}

<?php

namespace vaersaagod\aimate\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;

use vaersaagod\aimate\AIMate;
use vaersaagod\aimate\events\DefineAiActionsEvent;

use yii\base\Event;

final class CpHelper
{
    /**
     * Event that enables plugins and modules to add their own actions to the "AI" disclosure menu on element edit pages.
     * AIMate only renders the menu items; consumers are responsible for handling clicks on their own actions (typically
     * via a data attribute and a delegated JS event handler).
     */
    public const EVENT_DEFINE_ELEMENT_ACTIONS = 'defineElementActions';

    public static function getElementActions(ElementInterface $element): array
    {
        $actions = [];

        if ($element instanceof Asset && $element->kind === Asset::KIND_IMAGE) {
            $actions[] = [
                'label' => Craft::t('_aimate', 'Generate alt text'),
                'attributes' => [
                    'data' => [
                        'aimate-element-action' => 'generate-alt-text',
                        'element' => $element->id,
                        'site' => $element->siteId,
                    ],
                ],
            ];

            $actions[] = [
                'label' => Craft::t('_aimate', 'Generate focal point'),
                'attributes' => [
                    'data' => [
                        'aimate-element-action' => 'generate-focal-point',
                        'element' => $element->id,
                        'site' => $element->siteId,
                    ],
                ],
            ];
        }

        $settings = AIMate::getInstance()->getSettings();
        $prompts = $settings['prompts'] ?? [];

        foreach ($prompts as $promptConfig) {
            $actions[] = [
                'html' => AIMateHelper::getPromptLabel($promptConfig),
                'attributes' => [
                    'data' => [
                        'aimate-element-action' => 'prompt',
                        'element' => $element?->id ?? false,
                        'site' => $element?->siteId ?? false,
                        'prompt' => $promptConfig->handle,
                        'prompt-settings' => [
                            'allowBlank' => $promptConfig->allowBlank,
                        ],
                    ],
                ],
            ];
        }

        $event = new DefineAiActionsEvent([
            'element' => $element,
            'actions' => $actions,
        ]);
        Event::trigger(self::class, self::EVENT_DEFINE_ELEMENT_ACTIONS, $event);

        return $event->actions;
    }
}

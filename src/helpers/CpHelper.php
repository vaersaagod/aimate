<?php

namespace vaersaagod\aimate\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;

use vaersaagod\aimate\AIMate;

final class CpHelper
{
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
                    ]
                ],
            ];
        }

        $settings = AIMate::getInstance()->getSettings();
        $prompts = $settings['prompts'] ?? [];

        foreach ($prompts as $promptConfig) {
            $actions[] = [
                'label' => Craft::t('site', $promptConfig->name),
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
                ]
            ];
        }

        return $actions;
    }
}

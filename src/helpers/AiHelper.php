<?php

namespace vaersaagod\aimate\helpers;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldLayoutElement;
use craft\helpers\StringHelper;
use vaersaagod\aimate\AIMate;

class AiHelper
{
    public static function getFieldPromptActions(FieldLayoutElement $fieldLayoutElement, ?ElementInterface $element, ?Field $field): array
    {
        $settings = AIMate::getInstance()->getSettings();
        $fieldConfig = $field && $element ? FieldHelper::getFieldConfig($field, $element) : null;

        $prompts = $fieldConfig['prompts'] ?? $settings->prompts ?? [];

        $namespace = \Craft::$app->getView()->getNamespace();
        $label = $fieldLayoutElement->label();
        if ($label === '__blank__') {
            $label = null;
        }
        
        foreach ($prompts as $prompt) {
            $r[] = [
                'id' => 'aimate-prompt-field-' . $fieldLayoutElement->uid,
                'icon' => 'wand',
                'label' => \Craft::t('transmate', $prompt->name),
                'attributes' => [
                    'data' => [
                        'aimate-prompt-button' => true,
                        'prompt' => $prompt->handle,
                        'field' => $field->id,
                        'element' => $element->id,
                        'site' => $element->siteId,
                        'layout-element' => $fieldLayoutElement->uid,
                        'label' => $label,
                        'namespace' => ($namespace && $namespace !== 'fields')
                            ? StringHelper::removeRight($namespace, '[fields]')
                            : null,
                    ],
                ],
            ];
        }

        return $r;
    }
}

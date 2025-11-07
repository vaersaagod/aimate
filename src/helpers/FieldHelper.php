<?php

namespace vaersaagod\aimate\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldLayoutElement;
use craft\fieldlayoutelements\assets\AltField;
use craft\fieldlayoutelements\assets\AssetTitleField;
use craft\fieldlayoutelements\BaseNativeField;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\htmlfield\HtmlField;

use Illuminate\Support\Collection;

use vaersaagod\aimate\AIMate;
use vaersaagod\aimate\base\NativeFieldActionsEventTrait;
use vaersaagod\aimate\models\PromptConfig;


class FieldHelper
{
    /** @var array|string[] */
    private const array SUPPORTED_NATIVE_FIELDS = [
        EntryTitleField::class,
        AssetTitleField::class,
        AltField::class,
    ];

    /** @var array|string[] */
    private const array SUPPORTED_CUSTOM_FIELD_TYPES = [
        PlainText::class,
        Table::class,
        HtmlField::class,
    ];

    /**
     * @param FieldLayoutElement $fieldLayoutElement
     * @return bool
     */
    public static function isFieldSupported(FieldLayoutElement $fieldLayoutElement): bool
    {
        if ($fieldLayoutElement instanceof BaseNativeField) {
            return array_reduce(self::SUPPORTED_NATIVE_FIELDS, fn($carry, $class) => $carry || $fieldLayoutElement instanceof $class, false);
        }

        if ($fieldLayoutElement instanceof CustomField) {
            $field = $fieldLayoutElement->field;

            // Only a subset of custom field types are supported
            if (!array_reduce(self::SUPPORTED_CUSTOM_FIELD_TYPES, fn($carry, $class) => $carry || $field instanceof $class, false)) {
                return false;
            }

            // Tables are tricky. Let's make sure it has at least one textual column
            if ($field instanceof Table && !Collection::make($field->getSettings()['columns'] ?? [])->first(static fn(array $column) => in_array($column['type'], ['singleline', 'multiline'], true))) {
                return false;
            }

            return true;
        }

        return true;
    }

    /**
     * @param FieldLayoutElement $fieldLayoutElement
     * @return array|null
     */
    public static function getFieldConfig(FieldLayoutElement $fieldLayoutElement): ?array
    {
        $fieldsConfig = AIMate::getInstance()->getSettings()->fields;

        // Is this layout element something we support at all?
        if (!static::isFieldSupported($fieldLayoutElement)) {
            return null;
        }

        if (empty($fieldsConfig)) {
            // They didn't configure anything, so anything goes!
            return [];
        }

        // Get field handle and class name
        if ($fieldLayoutElement instanceof CustomField) {
            $fieldHandle = $fieldLayoutElement->handle;
            $fieldClass = $fieldLayoutElement->field::class;
        } else {
            $fieldHandle = $fieldLayoutElement->attribute ?? null;
            $fieldClass = $fieldLayoutElement::class;
        }
        if (empty($fieldHandle) || empty($fieldClass)) {
            return null;
        }

        // TBD: Removed matrix syntax check for now. Should we support it here?
        // Look for config by field handle, field class, or a global config ("*")
        $fieldConfig = $fieldsConfig[$fieldHandle] ?? $fieldsConfig[$fieldClass] ?? $fieldsConfig['*'] ?? null;
        if ($fieldConfig === false) {
            // This field is explicitly not supported in the config
            return null;
        }

        return is_array($fieldConfig) ? $fieldConfig : [];
    }

    /**
     * This method is used to monkey-patch in a custom event for certain native fields, to let us add field actions to them
     *
     * @param FieldLayoutElement $fieldLayoutElement
     * @return FieldLayoutElement
     */
    public static function getPromptableFieldLayoutElement(FieldLayoutElement $fieldLayoutElement): FieldLayoutElement
    {
        $nativeFieldClassMapping = [
            EntryTitleField::class => fn($config) => new class($config) extends EntryTitleField {
                use NativeFieldActionsEventTrait;
            },
            AssetTitleField::class => fn($config) => new class($config) extends AssetTitleField {
                use NativeFieldActionsEventTrait;
            },
            AltField::class => fn($config) => new class($config) extends AltField {
                use NativeFieldActionsEventTrait;
            },
        ];

        foreach ($nativeFieldClassMapping as $className => $factory) {
            if ($fieldLayoutElement instanceof $className) {
                return $factory($fieldLayoutElement->getAttributes());
            }
        }

        return $fieldLayoutElement;
    }

    /**
     * @param FieldLayoutElement $fieldLayoutElement
     * @param ElementInterface|null $element
     * @return array
     */
    public static function getFieldPromptActions(FieldLayoutElement $fieldLayoutElement, ?ElementInterface $element = null): array
    {
        $settings = AIMate::getInstance()->getSettings();
        $fieldConfig = static::getFieldConfig($fieldLayoutElement);
        if ($fieldConfig === null) { // A null field config means this field should not have any prompts at all
            return [];
        }

        // Get prompts from field config or settings
        $prompts = $fieldConfig['prompts'] ?? $settings->prompts ?? [];

        $label = $fieldLayoutElement->label();
        if ($label === '__blank__') {
            $label = null;
        }

        return array_map(static fn(PromptConfig $prompt) => [
            'id' => 'aimate-prompt-field-' . $fieldLayoutElement->uid,
            'icon' => 'wand',
            'label' => Craft::t('_aimate', $prompt->name),
            'attributes' => [
                'data' => [
                    'aimate-prompt-button' => true,
                    'prompt' => $prompt->handle,
                    'element' => $element?->id ?? false,
                    'site' => $element?->siteId ?? false,
                    'label' => $label ?: Craft::t('app', 'Field'),
                ],
            ],
        ], $prompts);
    }

}

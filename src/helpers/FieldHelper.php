<?php

namespace vaersaagod\aimate\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\helpers\Db;

use Illuminate\Support\Collection;
use vaersaagod\aimate\AIMate;


class FieldHelper
{
    /**
     * @param Field $field
     * @return bool
     */
    public static function isFieldSupported(Field $field): bool
    {
        // Only a subset of field types are supported
        if (!in_array(get_class($field), [
            PlainText::class,
            Table::class,
            \craft\ckeditor\Field::class,
            \craft\redactor\Field::class,
        ], true)) {
            return false;
        }
        // Tables are tricky. Let's make sure it has at least one textual column
        if ($field instanceof Table && !Collection::make($field->getSettings()['columns'] ?? [])->first(static fn(array $column) => in_array($column['type'], ['singleline', 'multiline'], true))) {
            return false;
        }
        return true;
    }

    /**
     * @param Field $field
     * @param ElementInterface $element
     * @return array|null
     */
    public static function getFieldConfig(Field $field, ElementInterface $element): ?array
    {
        $fieldsConfig = AIMate::getInstance()->getSettings()->fields;
        
        if (empty($fieldsConfig)) {
            // They didn't configure anything, so anything goes!
            return [];
        }
        
        // TBD: Removed matrix syntax check for now. Should we support it here?
        $fieldHandle = $field->handle;

        if (!$fieldHandle) {
            return null;
        }
        
        // Look for config by field handle, field type, or a global config ("*")
        $fieldConfig = $fieldsConfig[$fieldHandle] ?? $fieldsConfig[get_class($field)] ?? $fieldsConfig['*'] ?? null;
        
        if ($fieldConfig === false) {
            return null;
        }
        
        return is_array($fieldConfig) ? $fieldConfig : [];
    }

}

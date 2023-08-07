<?php

namespace vaersaagod\aimate;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\CreateFieldLayoutFormEvent;
use craft\events\DefineFieldHtmlEvent;
use craft\events\TemplateEvent;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\log\MonologTarget;
use craft\models\FieldLayout;
use craft\records\MatrixBlockType;
use craft\web\View;

use Illuminate\Support\Collection;

use Monolog\Formatter\LineFormatter;

use Psr\Log\LogLevel;

use vaersaagod\aimate\helpers\OpenAiHelper;
use vaersaagod\aimate\models\Prompt;
use vaersaagod\aimate\models\Settings;

use yii\base\Event;

/**
 * AIMate plugin
 *
 * @method static AIMate getInstance()
 * @method Settings getSettings()
 */
class AIMate extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Register a custom log target, keeping the format as simple as possible.
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => '_aimate',
            'categories' => ['_aimate', 'vaersaagod\\aimate\\*'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
            'formatter' => new LineFormatter(
                format: "%datetime% %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function () {
            $this->attachEventHandlers();
            // ...
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    private function attachEventHandlers(): void
    {

        // Make sure there's an OpenAI key
        if (!OpenAiHelper::getOpenAiApiKey()) {
            return;
        }

        // Register event handlers here ...
        // (see https://craftcms.com/docs/4.x/extend/events.html to get started)

        // Add AIMate buttons to custom fields
        Event::on(
            Field::class,
            Field::EVENT_DEFINE_INPUT_HTML,
            static function (DefineFieldHtmlEvent $event) {
                $element = $event->element;
                if ($event->static || !$element instanceof ElementInterface || $element->getIsRevision()) {
                    return;
                }
                $field = $event->sender;
                if (!$field instanceof Field || !static::isFieldSupported($field)) {
                    return;
                }
                $fieldConfig = static::getFieldConfig($field, $element);
                if ($fieldConfig === null) {
                    return;
                }
                $prompts = $fieldConfig['prompts'] ?? null;
                $customPrompt = $fieldConfig['customPrompt'] ?? null;
                $buttonHtml = Craft::$app->view->renderTemplate('_aimate/button.twig', ['field' => $field, 'element' => $element, 'prompts' => $prompts, 'customPrompt' => $customPrompt], View::TEMPLATE_MODE_CP);
                if ($field instanceof \craft\ckeditor\Field) {
                    $event->html = str_replace('</textarea>', "</textarea>$buttonHtml", $event->html);
                } else {
                    $event->html = $buttonHtml . $event->html;
                }
            }
        );

        // Add a button to the title field as well (this is pretty hacky but YOLO)
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_CREATE_FORM,
            static function (CreateFieldLayoutFormEvent $event) {
                $element = $event->element;
                if ($event->static || !$element instanceof ElementInterface || $element->getIsRevision()) {
                    return;
                }
                $settings = AIMate::getInstance()->getSettings();
                $fieldsConfig = $settings->fields ?? [];
                $titleFieldConfig = $fieldsConfig['title'] ?? $fieldsConfig['*'] ?? null;
                if ($titleFieldConfig === false) {
                    return;
                }
                if (!is_array($titleFieldConfig)) {
                    $titleFieldConfig = [];
                }
                $prompts = $titleFieldConfig['title']['prompts'] ?? null;
                $customPrompt = $titleFieldConfig['title']['customPrompt'] ?? null;
                Event::on(
                    View::class,
                    View::EVENT_AFTER_RENDER_TEMPLATE,
                    static function (TemplateEvent $event) use ($element, $prompts, $customPrompt) {
                        if ($event->templateMode !== View::TEMPLATE_MODE_CP || $event->template !== '_includes/forms/text.twig') {
                            return;
                        }
                        if (!StringHelper::startsWith($event->output, '<input type="text" id="title" ')) {
                            return;
                        }
                        $buttonHtml = Craft::$app->view->renderTemplate('_aimate/button.twig', ['field' => ['id' => 'title'], 'element' => $element, 'prompts' => $prompts, 'customPrompt' => $customPrompt], View::TEMPLATE_MODE_CP);
                        $event->output = $buttonHtml . $event->output;
                    }
                );
            }
        );

    }

    /**
     * @param Field $field
     * @return bool
     */
    private static function isFieldSupported(Field $field): bool
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
        if ($field instanceof Table && !Collection::make($field->getSettings()['columns'] ?? [])->first(static fn (array $column) => in_array($column['type'], ['singleline', 'multiline'], true))) {
            return false;
        }
        return true;
    }

    /**
     * @param Field $field
     * @param ElementInterface $element
     * @return array|null
     * @throws \yii\base\InvalidConfigException
     */
    private static function getFieldConfig(Field $field, ElementInterface $element): ?array
    {
        $fieldsConfig = AIMate::getInstance()->getSettings()->fields;
        if (empty($fieldsConfig)) {
            // They didn't configure anything, so anything goes!
            return [];
        }
        $fieldHandle = null;
        if ($field->context !== 'global') {
            $contextParts = explode(':', $field->context);
            if ($contextParts[0] ?? null === 'matrixBlockType' && !empty($contextParts[1])) {
                $matrixBlockType = \Craft::$app->getMatrix()->getBlockTypeById(Db::idByUid(MatrixBlockType::tableName(), $contextParts[1]));
                $matrixField = $matrixBlockType->getField();
                $fieldHandle = $matrixField->handle . '.' . $matrixBlockType->handle . ':' . $field->handle;
            }
        } else {
            $fieldHandle = $field->handle;
        }
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

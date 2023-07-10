<?php

namespace vaersaagod\aimate;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldLayoutElement;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\CreateFieldLayoutFormEvent;
use craft\events\DefineFieldHtmlEvent;
use craft\events\TemplateEvent;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fieldlayoutelements\TitleField;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\helpers\StringHelper;
use craft\log\MonologTarget;
use craft\models\FieldLayout;
use craft\web\View;

use Illuminate\Support\Collection;
use Monolog\Formatter\LineFormatter;

use Psr\Log\LogLevel;

use vaersaagod\aimate\models\Settings;
use vaersaagod\aimate\services\OpenAI;

use yii\base\Event;

/**
 * AIMate plugin
 *
 * @property OpenAI $openAI
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
                'openAI' => OpenAI::class,
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
                if (!$field instanceof Field || !static::isFieldEnabled($field)) {
                    return;
                }
                $buttonHtml = Craft::$app->view->renderTemplate('_aimate/button.twig', ['field' => $field, 'element' => $element], View::TEMPLATE_MODE_CP);
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
                if (!$element instanceof ElementInterface) {
                    return;
                }
                Event::on(
                    View::class,
                    View::EVENT_AFTER_RENDER_TEMPLATE,
                    static function (TemplateEvent $event) use ($element) {
                        if ($event->template !== '_includes/forms/text.twig') {
                            return;
                        }
                        if (!StringHelper::startsWith($event->output, '<input type="text" id="title" ')) {
                            return;
                        }
                        $buttonHtml = Craft::$app->view->renderTemplate('_aimate/button.twig', ['field' => ['id' => 'title'], 'element' => $element], View::TEMPLATE_MODE_CP);
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
    private static function isFieldEnabled(Field $field): bool
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

}

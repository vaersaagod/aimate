<?php

namespace vaersaagod\aimate;

use Craft;
use craft\base\Field;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\DefineFieldHtmlEvent;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\log\MonologTarget;
use craft\web\View;

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

        Event::on(
            Field::class,
            Field::EVENT_DEFINE_INPUT_HTML,
            static function (DefineFieldHtmlEvent $event) {
                if ($event->static) {
                    return;
                }
                $element = $event->element;
                if ($element?->getIsRevision()) {
                    return;
                }
                $field = $event->sender;
                if (!$field instanceof Field || !in_array(get_class($field), [
                        PlainText::class,
                        \craft\ckeditor\Field::class,
                        \craft\redactor\Field::class,
                    ], true)) {
                    return;
                }
                $event->html .= Craft::$app->view->renderTemplate('_aimate/button.twig', ['field' => $field, 'element' => $element], View::TEMPLATE_MODE_CP);
            }
        );
    }
}

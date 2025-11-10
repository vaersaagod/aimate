<?php

namespace vaersaagod\aimate;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldLayoutElement;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineFieldHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\DefineMenuItemsEvent;
use craft\events\ElementEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\ReplaceAssetEvent;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;
use craft\log\MonologTarget;
use craft\models\FieldLayout;
use craft\services\Assets;
use craft\services\Elements;

use Monolog\Formatter\LineFormatter;

use Psr\Log\LogLevel;

use vaersaagod\aimate\helpers\CpHelper;
use vaersaagod\aimate\helpers\FieldHelper;
use vaersaagod\aimate\helpers\OpenAiHelper;
use vaersaagod\aimate\models\Settings;
use vaersaagod\aimate\services\AltTextService;
use vaersaagod\aimate\actions\GenerateAltText;

use yii\base\Event;

/**
 * AIMate plugin
 *
 * @method static AIMate getInstance()
 * @property AltTextService $altText
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
                'altText' => AltTextService::class,
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

        $settings = $this->getSettings();

        // Element action
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_ACTIONS,
            function (RegisterElementActionsEvent $event) {
                $event->actions[] = GenerateAltText::class;
            }
        );

        if (Craft::$app->getRequest()->getIsCpRequest() && !Craft::$app->getRequest()->getIsLoginRequest()) {
            Craft::$app->view->registerAssetBundle(AiMateBundle::class);
        }

        // Add AI field action to field layout elements
        // We wrap this in a FieldLayout::EVENT_DEFINE_INPUT_HTML event to access the element (which unfortunately is not exposed for the new Field::EVENT_DEFINE_ACTION_MENU_ITEMS event in Craft 5.7)
        // This only works for custom fields!
        Event::on(
            Field::class,
            Field::EVENT_DEFINE_INPUT_HTML,
            static function (DefineFieldHtmlEvent $event) {
                $field = $event->sender;
                if (!$field instanceof Field || $event->static || $event->inline) {
                    return;
                }

                $element = $event->element;
                if (!$element instanceof ElementInterface) {
                    return;
                }

                $layoutElement = $field->layoutElement;
                if (!$layoutElement instanceof FieldLayoutElement) {
                    return;
                }

                Event::on(
                    Field::class,
                    Field::EVENT_DEFINE_ACTION_MENU_ITEMS,
                    static function (DefineMenuItemsEvent $event) use ($element, $layoutElement) {
                        if ($event->sender?->layoutElement->uid !== $layoutElement->uid) {
                            return;
                        }

                        // Filter out any existing prompt actions
                        $event->items = array_filter($event->items, static fn (array $action) => empty($action['attributes']['data']['aimate-field-action']));

                        // Get prompt actions for this field
                        $promptActions = FieldHelper::getFieldActions($layoutElement, $element);
                        if (empty($promptActions)) {
                            return;
                        }

                        // Try to put the prompt actions before the "Field settings" action, if it exists
                        $fieldSettingsActionIndex = array_search(true, array_map(fn($id) => str_starts_with($id, 'action-edit-'), array_column($event->items, 'id')));
                        if ($fieldSettingsActionIndex !== false) {
                            array_splice($event->items, $fieldSettingsActionIndex, 0, $promptActions);
                        } else {
                            $event->items = [...$event->items, ...$promptActions];
                        }
                    }
                );
            }
        );

        // Monkey-patched in AI field actions for native fields; title and alt
        // This is a (hopefully) temporary fix – https://github.com/craftcms/cms/discussions/16779
        Event::on(
            FieldLayout::class,
            Model::EVENT_INIT,
            static function (Event $event) {
                $fieldLayout = &$event->sender;
                foreach ($fieldLayout->tabs as $tab) {
                    if (empty($tab->elements)) {
                        return;
                    }
                    $tab->elements = array_map([FieldHelper::class, 'getPromptableFieldLayoutElement'], $tab->elements);
                }
            }
        );

        // This would never fire if not for the monkey patch above
        Event::on(
            BaseNativeField::class,
            'eventDefineNativeFieldActionMenuItems',
            static function (DefineMenuItemsEvent $event) {
                if (!empty($event->static) || !property_exists($event, 'element')) {
                    return;
                }
                /** @var FieldLayoutElement $layoutElement */
                $layoutElement = $event->sender;
                $promptActions = FieldHelper::getFieldActions($layoutElement, $event->element);
                $event->items = array_filter([...$event->items, ...$promptActions]);
            }
        );

        // "AI" button
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
            function (DefineHtmlEvent $event) {
                $element = $event->sender;
                if (!$element instanceof ElementInterface) {
                    return;
                }

                $actions = CpHelper::getElementActions($element);
                if (empty($actions)) {
                    return;
                }

                $event->html .= Cp::disclosureMenu($actions, [
                    'buttonLabel' => Craft::t('_aimate', 'AI'),
                    'buttonAttributes' => [
                        'class' => 'btn menubtn aimate-btn',
                        'data-icon' => true,
                    ],
                    'buttonSpinner' => true,
                ]);
            }
        );


        if ($settings->autoAltTextEnabled) {
            Event::on(Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                static function (ElementEvent $event) {
                    /** @var \craft\base\Element $element */
                    $element = $event->element;

                    if ($element instanceof Asset && $element->kind === Asset::KIND_IMAGE && $element->isNewForSite && $element->getScenario() !== Asset::SCENARIO_INDEX) {
                        if (!self::getInstance()->altText->hasAltText($element)) {
                            self::getInstance()->altText->createGenerateAltTextJob($element);
                        }
                    }
                }
            );

            Event::on(Assets::class,
                Assets::EVENT_AFTER_REPLACE_ASSET,
                static function (ReplaceAssetEvent $event) {
                    if (!self::getInstance()->altText->hasAltText($event->asset)) {
                        self::getInstance()->altText->createGenerateAltTextJob($event->asset);
                    }
                }
            );
        }
    }

}

<?php

namespace vaersaagod\aimate;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldLayoutElement;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineFieldActionsEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\ReplaceAssetEvent;
use craft\fieldlayoutelements\BaseField;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\log\MonologTarget;
use craft\services\Assets;
use craft\services\Elements;

use Monolog\Formatter\LineFormatter;

use Psr\Log\LogLevel;

use vaersaagod\aimate\actions\GenerateAltText;
use vaersaagod\aimate\actions\GenerateFocalPoint;
use vaersaagod\aimate\helpers\CpHelper;
use vaersaagod\aimate\helpers\FieldHelper;
use vaersaagod\aimate\helpers\OpenAiHelper;
use vaersaagod\aimate\models\Settings;
use vaersaagod\aimate\services\AssetService;

use yii\base\Event;

/**
 * AIMate plugin
 *
 * @method static AIMate getInstance()
 * @property AssetService $asset
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
                'asset' => AssetService::class,
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
        Craft::$app->onInit(function() {
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

        /** @var Settings $settings */
        $settings = $this->getSettings();

        // Element action
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                $event->actions[] = GenerateAltText::class;
                $event->actions[] = GenerateFocalPoint::class;
            }
        );

        if (Craft::$app->getRequest()->getIsCpRequest() && !Craft::$app->getRequest()->getIsLoginRequest()) {
            Craft::$app->view->registerAssetBundle(AiMateBundle::class);
        }

        // Add AI field action to field layout elements
        Event::on(
            BaseField::class,
            BaseField::EVENT_DEFINE_ACTION_MENU_ITEMS,
            static function(DefineFieldActionsEvent $event) {
                if ($event->static || !$event->sender instanceof FieldLayoutElement) {
                    return;
                }

                // Get prompt actions for this field
                $promptActions = FieldHelper::getFieldActions($event->sender, $event->element);
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

        // "AI" button
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
            function(DefineHtmlEvent $event) {
                $element = $event->sender;
                if (!$element instanceof ElementInterface || ElementHelper::isRevision($element) || $event->static) {
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


        if ($settings->autoAltTextEnabled || $settings->autoFocalPointEnabled) {
            Event::on(Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                static function(ElementEvent $event) use ($settings) {
                    /** @var \craft\base\Element $element */
                    $element = $event->element;

                    if ($element instanceof Asset && $element->kind === Asset::KIND_IMAGE && in_array($element->extension, $settings->safeImageFormats, true) && $element->isNewForSite && $element->getScenario() !== Asset::SCENARIO_INDEX) {
                        if ($settings->autoAltTextEnabled) {
                            if (!self::getInstance()->asset->hasAltText($element)) {
                                self::getInstance()->asset->createGenerateAltTextJob($element);
                            }
                        }
                        
                        // The focal point is per-asset, not per-site, so only queue a job for the originating save (unlike alt text, which is site-specific)
                        if ($settings->autoFocalPointEnabled && !$element->propagating) {
                            if (!$element->getHasFocalPoint()) {
                                self::getInstance()->asset->createGenerateFocalPointJob($element);
                            }
                        }
                    }
                }
            );

            Event::on(Assets::class,
                Assets::EVENT_AFTER_REPLACE_ASSET,
                static function(ReplaceAssetEvent $event) use ($settings) {
                    if ($settings->autoAltTextEnabled) {
                        if (!self::getInstance()->asset->hasAltText($event->asset)) {
                            self::getInstance()->asset->createGenerateAltTextJob($event->asset);
                        }
                    }

                    if ($settings->autoFocalPointEnabled && $event->asset->kind === Asset::KIND_IMAGE) {
                        if (!$event->asset->getHasFocalPoint()) {
                            self::getInstance()->asset->createGenerateFocalPointJob($event->asset);
                        }
                    }
                }
            );
        }
    }
}

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
use craft\events\CreateFieldLayoutFormEvent;
use craft\events\DefineFieldHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\DefineMenuItemsEvent;
use craft\events\ElementEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\ReplaceAssetEvent;
use craft\events\TemplateEvent;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\log\MonologTarget;
use craft\models\FieldLayout;
use craft\records\MatrixBlockType;
use craft\services\Assets;
use craft\services\Elements;
use craft\web\View;

use Illuminate\Support\Collection;

use Monolog\Formatter\LineFormatter;

use Psr\Log\LogLevel;

use vaersaagod\aimate\helpers\AiHelper;
use vaersaagod\aimate\helpers\FieldHelper;
use vaersaagod\aimate\helpers\OpenAiHelper;
use vaersaagod\aimate\models\Prompt;
use vaersaagod\aimate\models\Settings;

use vaersaagod\aimate\services\AltTextService;
use vaersaagod\aimate\actions\GenerateAltText;
use vaersaagod\aimate\web\assets\AiMateAsset;
use yii\base\Event;

/**
 * AIMate plugin
 *
 * @method static AIMate getInstance()
 * @property \vaersaagod\aimate\services\AltTextService $altText
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

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Craft::$app->view->registerAssetBundle(AIMateAsset::class);
        }

        // Add AI field action to field layout elements
        // We wrap this in a FieldLayout::EVENT_DEFINE_INPUT_HTML event to access the element (which unfortunately is not exposed for the new Field::EVENT_DEFINE_ACTION_MENU_ITEMS event in Craft 5.7)
        Event::on(
            Field::class,
            Field::EVENT_DEFINE_INPUT_HTML,
            static function (DefineFieldHtmlEvent $event) use ($settings) {
                if (!$event->sender instanceof Field || $event->static || $event->inline) {
                    return;
                }

                $element = $event->element;
                if (!$element instanceof ElementInterface) {
                    return;
                }

                $layoutElement = $event->sender->layoutElement;
                if (!$layoutElement instanceof FieldLayoutElement) {
                    return;
                }
                
                $field = $event->sender;

                Event::on(
                    Field::class,
                    Field::EVENT_DEFINE_ACTION_MENU_ITEMS,
                    static function (DefineMenuItemsEvent $event) use ($settings, $element, $layoutElement, $field) {
                        if ($event->sender?->layoutElement->uid !== $layoutElement->uid) {
                            return;
                        }

                        if (!FieldHelper::isFieldSupported($field)) {
                            return;
                        }
                        
                        $promptActions = AiHelper::getFieldPromptActions($layoutElement, $element, $field);
                        
                        $event->items = array_filter([...$event->items, ...$promptActions]);
                    }
                );
            }
        );
        
        // Monkey-patched in AI field actions for native fields; title and alt
        // This is a (hopefully) temporary fix – https://github.com/craftcms/cms/discussions/16779
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_CREATE_FORM,
            static function (CreateFieldLayoutFormEvent $event) {
                if ($event->static) {
                    return;
                }

                foreach ($event->tabs as $tab) {
                    if (empty($tab->elements)) {
                        return;
                    }
                    //$tab->elements = array_map([TranslateHelper::class, 'getTranslatableFieldLayoutElement'], $tab->elements);
                }
            }
        );
        


        /*
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
        */
        
        // Alt text button
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
            function (DefineHtmlEvent $event) {
                $element = $event->sender;

                if (!($element instanceof Asset && $element->kind === Asset::KIND_IMAGE)) {
                    return;
                }

                $template = Craft::$app->getView()->renderTemplate('_aimate/generate-alt-text-button.twig', [
                    'element' => $element,
                    'pluginSettings' => $this->getSettings()
                ]);

                $event->html .= $template;
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

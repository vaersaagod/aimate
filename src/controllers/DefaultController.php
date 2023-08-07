<?php

namespace vaersaagod\aimate\controllers;

use craft\elements\Entry;
use craft\web\Controller;

use Illuminate\Support\Collection;

use vaersaagod\aimate\AIMate;
use vaersaagod\aimate\models\Prompt;
use vaersaagod\aimate\models\PromptConfig;

use yii\web\BadRequestHttpException;
use yii\web\Response;

class DefaultController extends Controller
{

    /** @var array|bool|int */
    public array|bool|int $allowAnonymous = true; // TODO remove this

    /** @var bool */
    public $enableCsrfValidation = false;

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws \Throwable
     * @throws \yii\base\Exception
     */
    public function actionDoPrompt(): ?Response
    {

        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $prompt = $this->_getPromptFromRequest();

        try {
            $result = $prompt->complete();
        } catch (\Throwable $e) {
            \Craft::error($e, __METHOD__);
            return $this->asFailure(message: $e->getMessage());
        }

        if (empty($result)) {
            return $this->asFailure(message: "Unable to provide prompt result", data: [
                'prompt' => $prompt->getPrompt(),
            ]);
        }

        return $this->asSuccess(data: [
            'prompt' => $prompt->getPrompt(),
            'text' => $result,
        ]);

    }

    /**
     * @return Prompt
     * @throws BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     */
    private function _getPromptFromRequest(): Prompt
    {

        $settings = AIMate::getInstance()->getSettings();
        $textInput = trim($this->request->getBodyParam('text', ''));

        $custom = $this->request->getBodyParam('custom');

        if ($custom) {

            $config = new PromptConfig([
                'handle' => 'custom',
                'name' => 'Custom',
                'template' => $custom,
            ]);

        } else {
            $handle = $this->request->getRequiredBodyParam('prompt');
            $config = Collection::make($settings->prompts ?? [])
                ->firstWhere('handle', $handle);
            if (!$config) {
                throw new BadRequestHttpException("Invalid prompt \"$handle\"");
            }
        }

        /** @var Prompt $prompt */
        $prompt = \Craft::createObject([
            'class' => Prompt::class,
            'config' => $config,
        ]);

        if ($textInput) {
            $prompt->text = $textInput;
        }

        // If there's an element, set it to the prompt to enable object template renderin'
        if ($elementId = (int)$this->request->getBodyParam('elementId')) {
            $siteId = ((int)$this->request->getBodyParam('siteId')) ?: null;
            $elementType = \Craft::$app->getElements()->getElementTypeById($elementId);
            if ($elementType === Entry::class) {
                $draftId = (int)$this->request->getBodyParam('draftId');
                $isProvisional = $draftId && $this->request->getBodyParam('isProvisionalDraft');
                $entryQuery = Entry::find()
                    ->id($elementId)
                    ->siteId($siteId);
                if ($draftId) {
                    $entryQuery->draftId($draftId);
                }
                if ($isProvisional) {
                    $entryQuery->provisionalDrafts();
                }
                $element = $entryQuery->one();
            } else {
                $element = \Craft::$app->getElements()->getElementById($elementId, $elementType, $siteId);
            }
            $prompt->element = $element ?? null;
        }

        if (!$prompt->validate()) {
            throw new \RuntimeException("Invalid prompt: " . $prompt->getFirstError(array_keys($prompt->getErrors())[0]));
        }

        return $prompt;

    }

}

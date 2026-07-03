<?php

namespace vaersaagod\aimate\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\web\Controller;

use Illuminate\Support\Collection;

use vaersaagod\aimate\AIMate;
use vaersaagod\aimate\models\Prompt;

use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class PromptController extends Controller
{
    /** @var string */
    public $defaultAction = 'prompt';

    /** @var array<int|string>|bool|int */
    public array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @param \yii\base\Action $action
     * @return bool
     * @throws BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        return true;
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws \Throwable
     * @throws \yii\base\Exception
     */
    public function actionPrompt(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $prompt = $this->_getPromptFromRequest();

        try {
            $result = $prompt->complete();
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            return $this->asFailure(message: Craft::t('_aimate', 'An error occurred while generating the prompt result.'));
        }

        if (empty($result)) {
            return $this->asFailure(message: Craft::t('_aimate', 'Unable to provide prompt result'));
        }

        return $this->asSuccess(data: [
            'text' => $result,
        ]);
    }

    /**
     * @return Prompt
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws \yii\base\InvalidConfigException
     */
    private function _getPromptFromRequest(): Prompt
    {
        $settings = AIMate::getInstance()->getSettings();
        $textInput = trim($this->request->getBodyParam('text', ''));

        // Prompts can only be run from admin-defined configs, never from request-supplied templates
        $handle = $this->request->getRequiredBodyParam('prompt');
        $config = Collection::make($settings->prompts ?? [])
            ->firstWhere('handle', $handle);
        if (!$config) {
            throw new BadRequestHttpException("Invalid prompt \"$handle\"");
        }

        /** @var Prompt $prompt */
        $prompt = Craft::createObject([
            'class' => Prompt::class,
            'config' => $config,
        ]);

        if ($textInput) {
            $prompt->text = $textInput;
        }

        // If there's an element, set it to the prompt to enable object template renderin'
        $prompt->element = $this->_getElementFromRequest();

        // Make sure the current user is actually allowed to edit the element the prompt operates on
        if ($prompt->element !== null) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            if (!$currentUser || !Craft::$app->getElements()->canSave($prompt->element, $currentUser)) {
                throw new ForbiddenHttpException('You do not have permission to run AI prompts on this element.');
            }
        }

        if (!$prompt->validate()) {
            throw new \RuntimeException("Invalid prompt: " . $prompt->getFirstError(array_keys($prompt->getErrors())[0]));
        }

        return $prompt;
    }

    /**
     * @return ElementInterface|null
     */
    private function _getElementFromRequest(): ?ElementInterface
    {
        $elementId = (int)$this->request->getBodyParam('elementId');
        if (empty($elementId)) {
            return null;
        }

        $siteId = (int)$this->request->getBodyParam('siteId');
        $elementType = Craft::$app->getElements()->getElementTypeById($elementId);

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

            return $entryQuery->one();
        }

        return Craft::$app->getElements()->getElementById($elementId, $elementType, $siteId);
    }
}

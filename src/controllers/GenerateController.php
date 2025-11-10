<?php

namespace vaersaagod\aimate\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;

use vaersaagod\aimate\AIMate;

use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Generate controller
 */
class GenerateController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @param PromptController $defaultController
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws \yii\web\MethodNotAllowedHttpException
     */
    public function actionGenerateAltText(PromptController $defaultController): ?Response
    {
        $defaultController->requirePostRequest();
        $defaultController->requireAcceptsJson();

        $elementId = (int)$defaultController->request->getBodyParam('elementId');
        $siteId = (int)$defaultController->request->getBodyParam('siteId');

        $element = Asset::find()->id($elementId)->siteId($siteId)->one();

        try {
            $result = AIMate::getInstance()->altText->generateAltTextForAsset($element);
        } catch (\Throwable $e) {
            \Craft::error($e, __METHOD__);
            return $defaultController->asFailure(message: $e->getMessage());
        }

        // TODO : Needs to be more robust
        return $result ? $defaultController->asSuccess() : $defaultController->asFailure();
    }

    /**
     * @param PromptController $defaultController
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws \yii\web\MethodNotAllowedHttpException
     */
    public function actionGenerateAltTextJobs(PromptController $defaultController): ?Response
    {
        $defaultController->requirePostRequest();
        $defaultController->requireAcceptsJson();

        $elementIds = explode(',', $defaultController->request->getBodyParam('elementIds'));
        $siteId = $defaultController->request->getBodyParam('siteId');

        try {
            foreach ($elementIds as $elementId) {
                $element = Asset::find()->id((int)$elementId)->siteId((int)$siteId)->one();
                AIMate::getInstance()->altText->createGenerateAltTextJob($element, true);

            }
        } catch (\Throwable $e) {
            \Craft::error($e, __METHOD__);
            return $defaultController->asFailure(message: $e->getMessage());
        }

        return $defaultController->asSuccess(\Craft::t('_aimate', 'Alt text generation jobs queued'));
    }
}

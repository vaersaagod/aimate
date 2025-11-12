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
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws \yii\web\MethodNotAllowedHttpException
     */
    public function actionGenerateAltText(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $elementId = (int)$this->request->getBodyParam('elementId');
        $siteId = (int)$this->request->getBodyParam('siteId');

        $element = Asset::find()->id($elementId)->siteId($siteId)->one();

        try {
            $result = AIMate::getInstance()->altText->generateAltTextForAsset($element);
        } catch (\Throwable $e) {
            \Craft::error($e, __METHOD__);
            return $this->asFailure(message: $e->getMessage());
        }

        // TODO : Needs to be more robust
        return $result ? $this->asSuccess() : $this->asFailure();
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws \yii\web\MethodNotAllowedHttpException
     */
    public function actionGenerateAltTextJobs(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $elementIds = explode(',', $this->request->getBodyParam('elementIds'));
        $siteId = $this->request->getBodyParam('siteId');

        try {
            foreach ($elementIds as $elementId) {
                $element = Asset::find()->id((int)$elementId)->siteId((int)$siteId)->one();
                AIMate::getInstance()->altText->createGenerateAltTextJob($element, true);

            }
        } catch (\Throwable $e) {
            \Craft::error($e, __METHOD__);
            return $this->asFailure(message: $e->getMessage());
        }

        return $this->asSuccess(\Craft::t('_aimate', 'Alt text generation jobs queued'));
    }
}

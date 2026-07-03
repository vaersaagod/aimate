<?php

namespace vaersaagod\aimate\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;

use vaersaagod\aimate\AIMate;

use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Generate controller
 */
class GenerateController extends Controller
{
    /** @var array<int|string>|bool|int */
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /** @var int Maximum number of assets that can be queued for alt text generation in a single request */
    private const MAX_BATCH_SIZE = 1000;

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
     * @throws \yii\web\MethodNotAllowedHttpException
     */
    public function actionGenerateAltText(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $elementId = (int)$this->request->getBodyParam('elementId');
        $siteId = (int)$this->request->getBodyParam('siteId');

        $asset = Asset::find()->id($elementId)->siteId($siteId)->one();

        if (!$asset) {
            throw new BadRequestHttpException('Invalid asset.');
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser || !Craft::$app->getElements()->canSave($asset, $currentUser)) {
            throw new ForbiddenHttpException('You do not have permission to edit this asset.');
        }

        try {
            $result = AIMate::getInstance()->altText->generateAltTextForAsset($asset);
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            return $this->asFailure(message: Craft::t('_aimate', 'An error occurred while generating alt text.'));
        }

        // TODO : Needs to be more robust
        return $result ? $this->asSuccess() : $this->asFailure();
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws \yii\web\MethodNotAllowedHttpException
     */
    public function actionGenerateAltTextJobs(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = (int)$this->request->getBodyParam('siteId');

        $elementIds = array_values(array_unique(array_filter(array_map(
            static fn($id) => (int)$id,
            explode(',', (string)$this->request->getBodyParam('elementIds'))
        ))));

        if (empty($elementIds)) {
            throw new BadRequestHttpException('No assets provided.');
        }

        if (count($elementIds) > self::MAX_BATCH_SIZE) {
            throw new BadRequestHttpException(Craft::t('_aimate', 'Too many assets selected. Select fewer than {max}, or use the “alt-text/generate” console command for larger batches.', [
                'max' => self::MAX_BATCH_SIZE,
            ]));
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser) {
            throw new ForbiddenHttpException();
        }

        $elementsService = Craft::$app->getElements();

        try {
            foreach ($elementIds as $elementId) {
                $asset = Asset::find()->id($elementId)->siteId($siteId)->one();
                // Silently skip assets that don't exist or that the user isn't allowed to edit
                if (!$asset || !$elementsService->canSave($asset, $currentUser)) {
                    continue;
                }
                AIMate::getInstance()->altText->createGenerateAltTextJob($asset, true);
            }
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            return $this->asFailure(message: Craft::t('_aimate', 'An error occurred while queueing alt text generation.'));
        }

        return $this->asSuccess(Craft::t('_aimate', 'Alt text generation jobs queued'));
    }
}

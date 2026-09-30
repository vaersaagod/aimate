<?php

namespace vaersaagod\aimate\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use craft\queue\QueueInterface;

use vaersaagod\aimate\AIMate;
use yii\base\InvalidConfigException;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

class GenerateFocalPointJob extends BaseJob implements RetryableJobInterface
{
    // Constants
    // =========================================================================

    /**
     * @var int Seconds a single attempt may run before the queue considers it failed
     */
    public const TTR = 300;

    /**
     * @var int Total number of times the job is attempted, including the first run
     */
    public const MAX_ATTEMPTS = 3;

    // Public Properties
    // =========================================================================

    /**
     * @var null|int
     */
    public ?int $assetId = null;

    /**
     * @var null|int
     */
    public ?int $siteId = null;

    /**
     * @var bool Whether to generate a focal point even if the asset already has one
     */
    public bool $forced = false;


    // Public Methods
    // =========================================================================

    /**
     * @param QueueInterface|Queue $queue
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function execute($queue): void
    {
        $criteria = [];
        if ($this->assetId === null) {
            throw new InvalidConfigException(Craft::t('_aimate', 'Asset ID in focal point job was null'));
        }

        $query = Asset::find();
        $criteria['id'] = $this->assetId;
        $criteria['siteId'] = $this->siteId;
        $criteria['status'] = null;
        Craft::configure($query, $criteria);

        $asset = $query->one();

        if (!$asset) {
            return;
        }

        // Skip if a focal point has been set since this job was queued, e.g. by a duplicate job
        if (!$this->forced && $asset->getHasFocalPoint()) {
            return;
        }

        AIMate::getInstance()->asset->getFocalPointForAsset($asset);
    }

    /**
     * @return int
     */
    public function getTtr(): int
    {
        return self::TTR;
    }

    /**
     * @param int $attempt
     * @param \Throwable|null $error
     * @return bool
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     *
     * @return string|null The default task description
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('_aimate', 'Generating focal point');
    }
}

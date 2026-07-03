<?php

namespace vaersaagod\aimate\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use craft\queue\QueueInterface;

use vaersaagod\aimate\AIMate;
use yii\base\InvalidConfigException;
use yii\queue\Queue;

class GenerateFocalPointJob extends BaseJob
{
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

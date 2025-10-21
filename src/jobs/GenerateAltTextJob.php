<?php

namespace vaersaagod\aimate\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use craft\queue\QueueInterface;

use vaersaagod\aimate\AIMate;
use yii\base\InvalidConfigException;
use yii\queue\Queue;

class GenerateAltTextJob extends BaseJob
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
            throw new InvalidConfigException(Craft::t('_aimate', 'Asset ID in transform job was null'));
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
        
        AIMate::getInstance()->altText->generateAltTextForAsset($asset);
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
        return Craft::t('_aimate', 'Generation alt text');
    }
}

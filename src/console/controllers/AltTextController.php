<?php

namespace vaersaagod\aimate\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\ConfigHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\Volume;

use vaersaagod\aimate\AIMate;
use vaersaagod\assetmate\helpers\ContentTablesHelper;

use yii\base\InlineAction;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;

/**
 * Purge controller
 */
class AltTextController extends Controller
{

    /** @var string */
    public $defaultAction = 'generate';

    public ?int $assetId = null;
    public int|string|null $site = null;
    public string $volume = '*';
    public bool $onlyEmpty = true;

    /**
     * @param $actionID
     * @return array|string[]
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        switch ($actionID) {
            case 'generate':
                $options[] = 'assetId';
                $options[] = 'site';
                $options[] = 'volume';
                $options[] = 'onlyEmpty';
                break;
        }
        return $options;
    }

    public function actionGenerate(): int
    {
        $config = AIMate::getInstance()->getSettings();
        $query = Asset::find();
        
        if (!empty($this->assetId)) {
            $query->id($this->assetId);
        }
        
        if (!empty($this->site)) {
            if (is_numeric($this->site)) {
                $query->siteId($this->site);
            } else {
                $query->site($this->site);
            }
        }
        
        if ($this->volume !== '*') {
            $volume = $this->_getVolumeFromHandle($this->volume);
            if (!$volume) {
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $query
                ->andWhere('assets.volumeId = :volumeId', [
                    ':volumeId' => $volume->id,
                ]);
        }

        $totalAssetsCount = $query->count();
        $processingAssetsCount = $totalAssetsCount;

        if ($this->onlyEmpty) {
            if ($config->altTextHandle === 'alt') {
                $query->hasAlt(false);
            } else {
                $query->{$config->altTextHandle}(':empty:');
            }

            $processingAssetsCount = $query->count();
        }

        $this->stdout(PHP_EOL);
        $this->stdout("Processing {$processingAssetsCount} of {$totalAssetsCount} assets.", BaseConsole::FG_CYAN);
        $this->stdout(PHP_EOL.PHP_EOL);
        
        $assets = $query->all();
        
        foreach ($assets as $asset) {
            $this->stdout("Generating alt text for asset {$asset->id}... ", BaseConsole::FG_CYAN);
            $result = AIMate::getInstance()->altText->generateAltTextForAsset($asset);
            $this->stdout($result ? 'OK' : 'ERROR', $result ? BaseConsole::FG_GREEN : BaseConsole::FG_RED);
            $this->stdout(PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * @param string $handle
     * @return Volume|null
     */
    private function _getVolumeFromHandle(string $handle): ?Volume
    {
        if (!$volume = Craft::$app->getVolumes()->getVolumeByHandle($handle)) {
            $allVolumes = array_map(static fn (Volume $volume) => $volume->handle, Craft::$app->getVolumes()->getAllVolumes());
            $this->stderr("Volume \"$handle\" does not exist. Valid volumes are \n" . implode("\n", $allVolumes) . PHP_EOL, BaseConsole::FG_RED);
            return null;
        }
        return $volume;
    }

}

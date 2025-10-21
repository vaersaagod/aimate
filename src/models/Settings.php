<?php

namespace vaersaagod\aimate\models;

use Craft;
use craft\base\Model;

/**
 * AIMate settings
 */
class Settings extends Model
{

    /** @var string */
    public string $openAIApiKey;

    /** @var string */
    public string $model = 'gpt-5-mini';

    /** @var PromptConfig[]|null  */
    public ?array $prompts = null;

    /** @var float|null */
    public ?float $maxWordsMultiplier = 1.5;

    /** @var float|null */
    public ?float $temperature = 0.7;

    /** @var array */
    public array $fields = [];
        
    /** @var string */
    public string $altTextHandle = 'alt';
    
    /** @var bool */
    public bool $useImagerIfInstalled = true;
    
    /** @var bool */
    public bool $autoAltTextEnabled = true;
    
    /** @var int */
    public int $thumbSize = 512; 

    /** @var int */
    public int $clientTimeout = 120;

    /** @var string */
    public string $base64EncodeImage = 'auto'; // always, never, auto



    /**
     * @param $values
     * @param $safeOnly
     * @return void
     * @throws \yii\base\InvalidConfigException
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        // Create and validate prompt configs
        $values['prompts'] = array_reduce(array_keys($values['prompts'] ?? []), static function (array $carry, string $handle) use ($values) {
            $config = $values['prompts'][$handle] ?? [];
            /** @var PromptConfig $prompt */
            $prompt = Craft::createObject(array_merge([
                'class' => PromptConfig::class,
                'handle' => $handle,
            ], $config));
            if (!$prompt->validate()) {
                throw new \Exception("Invalid prompt configuration: " . $prompt->getFirstError(array_keys($prompt->getErrors())[0]));
            }
            $carry[] = $prompt;
            return $carry;
        }, []);
        parent::setAttributes($values, $safeOnly);
    }

}

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
    public string $model = 'gpt-3.5-turbo';

    /** @var PromptConfig[]|null  */
    public ?array $prompts = null;

    /** @var float|null */
    public ?float $maxWordsMultiplier = 1.5;

    /** @var float|null */
    public ?float $temperature = 0.7;

    /** @var array */
    public array $fields = [];

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

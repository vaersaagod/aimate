<?php

namespace vaersaagod\aimate\helpers;

use vaersaagod\aimate\AIMate;

class OpenAiHelper
{

    /**
     * @return \OpenAI\Client
     * @throws \Exception
     */
    public static function getClient(): \OpenAI\Client
    {
        $openAiApiKey = static::getOpenAiApiKey();
        if (!$openAiApiKey) {
            throw new \Exception('No OpenAI API key');
        }
        return \OpenAI::client($openAiApiKey);
    }

    /**
     * @return string|null
     */
    public static function getOpenAiApiKey(): ?string
    {
        $settings = AIMate::getInstance()->getSettings();
        if (!isset($settings->openAIApiKey)) {
            return null;
        }
        return $settings->openAIApiKey;
    }

}

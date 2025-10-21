<?php

namespace vaersaagod\aimate\helpers;

use GuzzleHttp\Client;
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
        
        $guzzleClient = new Client([
            'timeout' => 300, 
        ]);

        return \OpenAI::factory()
            ->withApiKey($openAiApiKey)
            ->withHttpClient($guzzleClient)
            ->make();
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

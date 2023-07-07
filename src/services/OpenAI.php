<?php

namespace vaersaagod\aimate\services;

use craft\base\Component;

use vaersaagod\aimate\AIMate;
use vaersaagod\aimate\models\Prompt;

class OpenAI extends Component
{

    /**
     * @param Prompt $prompt
     * @return string|null
     * @throws \Throwable
     * @throws \yii\base\Exception
     */
    public function getCompletion(Prompt $prompt): ?string
    {
        $client = $this->_getClient();
        $params = [
            'model' => $prompt->getModel(),
            'temperature' => $prompt->getTemperature(),
            'messages' => [
                ['role' => 'user', 'content' => $prompt->getPrompt()],
            ],
        ];
        $result = $client->chat()->create($params);
        $result = $result['choices'][0]['message']['content'] ?? null;
        if (!$result || $result === $prompt->getPrompt()) {
            return null;
        }
        return $result;
    }

    /**
     * @return \OpenAI\Client
     * @throws \Exception
     */
    private function _getClient(): \OpenAI\Client
    {
        $apiKey = AIMate::getInstance()->getSettings()->openAIApiKey;
        if (!$apiKey) {
            throw new \Exception('No OpenAI API key');
        }
        return \OpenAI::client($apiKey);
    }

}

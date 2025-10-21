<?php

namespace vaersaagod\aimate\services;

use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use Illuminate\Support\Collection;
use OpenAI\Client;
use spacecatninja\imagerx\ImagerX;
use vaersaagod\aimate\AIMate;
use vaersaagod\aimate\helpers\OpenAiHelper;
use vaersaagod\aimate\jobs\GenerateAltTextJob;

class AltTextService extends Component
{
    public function createGenerateAltTextJob(Asset $asset, bool $forced = false): void
    {
        $queue = \Craft::$app->getQueue();

        $jobId = $queue->push(new GenerateAltTextJob([
            'description' => \Craft::t('_aimate', 'Generating alt text for asset "' . $asset->filename . '" (ID ' . $asset->id . ', ' . $asset->site->language . ')'),
            'assetId' => $asset->id,
            'siteId' => $asset->siteId,
        ]));

        \Craft::info('Created generate alt text job for asset with id ' . $asset->id . ' (job id is ' . $jobId . ')', __METHOD__);
    }
    
    
    public function generateAltTextForAsset(Asset $asset): bool
    {
        $settings = AIMate::getInstance()->getSettings();

        $client = OpenAiHelper::getClient();

        $imageUrl = $this->getAssetUrl($asset);

        if (empty($imageUrl)) {
            \Craft::error('Could not get image URL for asset ' . $asset->id, __METHOD__);
            return false;
        }

        $messages = $this->buildAltTextPrompt(
            $imageUrl,
            language: $asset->getSite()->language ?? \Craft::$app->getSites()->getCurrentSite()->language,
            altMaxChars: 140,
            longDescription: false,
            decorative: false,
            imageType: '',
            context: ''
        );
        
        // TODO: Add file name and path to context?
        
        $result = $client->chat()->create([
            'model' => $settings->model,
            'messages' => $messages,
        ]);

        $response = Collection::make($result['choices'] ?? [])->first(static fn(array $choice) => $choice['finish_reason'] === 'stop' && !empty($choice['message']['content'] ?? null));
        
        if (!$response) {
            \Craft::error('Invalid response from OpenAI for asset ' . $asset->id . ': ' . print_r($response, true), __METHOD__);
            
            return false;
        }
        
        $message = trim($response['message']['content']);
        
        try {
            $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            \Craft::error('Invalid JSON response from OpenAI for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
            return false;
        }
        
        if (isset($data['alt_text']) || isset($data['output_format']['alt_text'])) { // For some obscure reason, the API sometimes returns the whole request structure back.
            $altText = $data['alt_text'] ?? $data['output_format']['alt_text'];
            
            if ($settings->altTextHandle === 'alt') {
                $asset->alt = $altText;
            } else {
                $asset->{$settings->altTextHandle} = $altText;
            }

            return \Craft::$app->getElements()->saveElement($asset);
        } 
            
        \Craft::error('No alt text found in response from OpenAI for asset ' . $asset->id . ': ' . print_r($data, true), __METHOD__);
        
        return false;
    }

    public function hasAltText(Asset $asset): bool
    {
        $settings = AIMate::getInstance()->getSettings();
        $altText = $asset->{$settings->altTextHandle} ?? null;
        return !empty($altText);
    }

    /**
     * Build OpenAI chat messages for generating alt text from an image.
     *
     * @param string      $imageUrl        URL of the image to describe.
     * @param string      $language        Language code for alt text (default: 'en').
     * @param int         $altMaxChars     Maximum character length for the alt text (default: 130).
     * @param bool        $longDescription Whether to include a long description (default: false).
     * @param bool        $decorative      Whether the image is decorative (default: false).
     * @param string      $imageType       Type of image (photo, logo, chart, etc.).
     * @param string|null $context         Optional context, e.g., page title or caption.
     *
     * @return array The `messages` array to send to OpenAI’s Chat API.
     * @throws \JsonException
     */
    public function buildAltTextPrompt(
        string $imageUrl,
        string $language = 'en',
        int $altMaxChars = 130,
        bool $longDescription = false,
        bool $decorative = false,
        string $imageType = 'photo',
        ?string $context = null
    ): array {

        $systemPrompt = <<<EOT
You are an expert accessibility writer. Write concise, accurate, non-hallucinated alternative text for images that meets WCAG 2.2 and ARIA guidance.
- Never include “image of” or “picture of”.
- Avoid sensitive inferences (race, nationality, disability, etc.).
- If the image is decorative or redundant, return an empty alt string.
- Prefer verifiable visual facts over guesses.
- Use sentence case, no trailing period unless multiple sentences.
- Return output in valid JSON format exactly as specified.
EOT;

        $userPrompt = [
            "role" => "user",
            "content" => [
                [
                    "type" => "text",
                    "text" => json_encode([
                        "language" => $language,
                        "alt_max_chars" => $altMaxChars,
                        "long_description" => $longDescription,
                        "decorative" => $decorative,
                        "image_type" => $imageType,
                        "context" => $context,
                        "output_format" => [
                            "alt_text" => "string",
                            "long_description" => "string or empty",
                            "confidence" => "float 0–1",
                            "warnings" => "array of strings",
                            "language" => $language
                        ],
                        "rules" => [
                            "1" => "Be factual, not speculative.",
                            "2" => "Include on-image text if essential.",
                            "3" => "Skip SEO terms, camera data, filenames.",
                            "4" => "Make sure the returned alt text is in the correct language."
                        ]
                    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
                ],
                [
                    "type" => "image_url",
                    "image_url" => ["url" => $imageUrl]
                ]
            ]
        ];

        return [
            [
                "role" => "system",
                "content" => $systemPrompt
            ],
            $userPrompt
        ];
    }

    public function getAssetUrl(Asset $asset): ?string
    {
        $settings = AIMate::getInstance()->getSettings();

        $plugins = \Craft::$app->getPlugins();
        $imagerPlugin = $plugins->getPlugin('imager-x') ?? $plugins->getPlugin('imager');

        $transform = [
            'width' => $settings->thumbSize,
            'height' => $settings->thumbSize,
            'mode' => 'fit',
            'format' => 'jpg',
            'quality' => 70,
        ];
        
        if ($settings->useImagerIfInstalled && ($imagerPlugin instanceof \aelvan\imager\Imager || $imagerPlugin instanceof \spacecatninja\imagerx\ImagerX)) {
            $transformedImageUrl = ImagerX::getInstance()->imager->transformImage($asset, $transform)?->getUrl();
        } else {
            $transformedImageUrl = $asset->getUrl($transform);
        }
        
        if ($transformedImageUrl === null) {
            return null;
        }
        
        // TODO: We assume that if it's an absolute URL, it's publicly available. Maybe add something to check for dev-sounding domains?
        if (UrlHelper::isAbsoluteUrl($transformedImageUrl)) {
            if ($settings->base64EncodeImage === 'always') {
                $assetContents = @file_get_contents($transformedImageUrl);

                if ($assetContents !== false) {
                    $assetMimeType = strtolower($asset->getMimeType());
                    $base64Image = base64_encode($assetContents);
                    return "data:$assetMimeType;base64,$base64Image";
                }
            }
            
            return $transformedImageUrl;
        }
        
        if ($settings->base64EncodeImage === 'never') {
            return null;
        }
        
        // We assume this is a path relative to the webroot
        if (str_starts_with($transformedImageUrl, '/')) {
            $transformedImagePath = strtok($transformedImageUrl, '?');
            
            $filename = App::parseEnv('@webroot'.$transformedImagePath);
            if (file_exists($filename)) {
                $assetContents = file_get_contents($filename);
                $assetMimeType = strtolower($asset->getMimeType());
                $base64Image = base64_encode($assetContents);
                return "data:$assetMimeType;base64,$base64Image";
            }
        }
        
        // TODO : What more can we do?
        
        return null;
    }

}

<?php

namespace vaersaagod\aimate\services;

use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;

use Illuminate\Support\Collection;

use spacecatninja\imagerx\ImagerX;

use vaersaagod\aimate\AIMate;
use vaersaagod\aimate\helpers\OpenAiHelper;
use vaersaagod\aimate\jobs\GenerateAltTextJob;
use vaersaagod\aimate\jobs\GenerateFocalPointJob;

class AssetService extends Component
{
    public function getFocalPointForAsset(Asset $asset): bool
    {
        $settings = AIMate::getInstance()->getSettings();

        $client = OpenAiHelper::getClient();

        $imageUrl = $this->getAssetUrl($asset);

        if (empty($imageUrl)) {
            \Craft::error('Could not get image URL for asset ' . $asset->id, __METHOD__);
            return false;
        }

        $messages = $this->buildFocalPointPrompt($imageUrl);

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

        $focalPoint = $data['focal_point'] ?? $data['output_format']['focal_point'] ?? null; // For some obscure reason, the API sometimes returns the whole request structure back.

        if (!isset($focalPoint['x'], $focalPoint['y'])) {
            // The model couldn't determine a focal point (e.g. no discernible subject); leave the asset untouched
            \Craft::info('No focal point determined by OpenAI for asset ' . $asset->id, __METHOD__);
            return false;
        }

        if (!is_numeric($focalPoint['x']) || !is_numeric($focalPoint['y'])) {
            \Craft::error('No valid focal point found in response from OpenAI for asset ' . $asset->id . ': ' . print_r($data, true), __METHOD__);
            return false;
        }

        // Clamp to the 0–1 range – Asset::setFocalPoint() silently discards out-of-range values
        $asset->setFocalPoint([
            'x' => min(1.0, max(0.0, round((float)$focalPoint['x'], 4))),
            'y' => min(1.0, max(0.0, round((float)$focalPoint['y'], 4))),
        ]);

        if (!\Craft::$app->getElements()->saveElement($asset)) {
            return false;
        }

        // Already generated transforms aren't invalidated when the focal point changes on a plain element save
        \Craft::$app->getImageTransforms()->deleteCreatedTransformsForAsset($asset);

        return true;
    }

    public function createGenerateFocalPointJob(Asset $asset, bool $forced = false): void
    {
        $queue = \Craft::$app->getQueue();

        $jobId = $queue->push(new GenerateFocalPointJob([
            'description' => \Craft::t('_aimate', 'Generating focal point for asset "' . $asset->filename . '" (ID ' . $asset->id . ')'),
            'assetId' => $asset->id,
            'siteId' => $asset->siteId,
            'forced' => $forced,
        ]));

        \Craft::info('Created generate focal point job for asset with id ' . $asset->id . ' (job id is ' . $jobId . ')', __METHOD__);
    }

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
        ?string $context = null,
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
                            "language" => $language,
                        ],
                        "rules" => [
                            "Be factual, not speculative.",
                            "Include on-image text if essential.",
                            "Skip SEO terms, camera data, filenames.",
                            "Make sure the returned alt text is in the correct language.",
                        ],
                    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
                ],
                [
                    "type" => "image_url",
                    "image_url" => ["url" => $imageUrl],
                ],
            ],
        ];

        return [
            [
                "role" => "system",
                "content" => $systemPrompt,
            ],
            $userPrompt,
        ];
    }

    /**
     * Build OpenAI chat messages for detecting the focal point of an image.
     *
     * @param string      $imageUrl URL of the image to analyze.
     * @param string|null $context  Optional context, e.g., page title or caption.
     *
     * @return array The `messages` array to send to OpenAI’s Chat API.
     * @throws \JsonException
     */
    public function buildFocalPointPrompt(
        string $imageUrl,
        ?string $context = null,
    ): array {
        $systemPrompt = <<<EOT
You are an expert photo editor. Identify the single most important focal point of an image, used to anchor crops when the image is displayed at different aspect ratios.
- The focal point is the spot a viewer’s eye should be drawn to. Priority order: human faces, then people or animals, then the main subject, then the area of sharpest focus or highest contrast.
- Coordinates are relative to the image dimensions: x is the horizontal position measured from the left edge (0.0 = left, 1.0 = right); y is the vertical position measured from the top edge (0.0 = top, 1.0 = bottom).
- Aim for the center of the subject (for faces, the point between the eyes).
- If there is no discernible subject (flat textures, abstract gradients, uniform patterns), return null for both coordinates.
- Return output in valid JSON format exactly as specified.
EOT;

        $userPrompt = [
            "role" => "user",
            "content" => [
                [
                    "type" => "text",
                    "text" => json_encode([
                        "context" => $context,
                        "output_format" => [
                            "focal_point" => [
                                "x" => "float 0–1, or null",
                                "y" => "float 0–1, or null",
                            ],
                            "confidence" => "float 0–1",
                            "warnings" => "array of strings",
                        ],
                        "rules" => [
                            "x and y must both be between 0 and 1.",
                            "Prefer the most prominent human face when several subjects are present.",
                            "Be precise; do not default to x 0.5 and y 0.5 unless the subject is genuinely centered.",
                        ],
                    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
                ],
                [
                    "type" => "image_url",
                    "image_url" => ["url" => $imageUrl],
                ],
            ],
        ];

        return [
            [
                "role" => "system",
                "content" => $systemPrompt,
            ],
            $userPrompt,
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

            $webroot = App::parseEnv('@webroot');
            $realWebroot = realpath($webroot);
            $filename = realpath(FileHelper::normalizePath($webroot . $transformedImagePath));

            // Only read the file if it resolves to a real file inside the webroot
            if ($realWebroot !== false && $filename !== false && str_starts_with($filename, $realWebroot . DIRECTORY_SEPARATOR) && is_file($filename)) {
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

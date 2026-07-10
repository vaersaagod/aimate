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

        $requestParams = [
            'model' => $settings->model,
            'messages' => $messages,
        ];

        if ($reasoningEffort = self::getMinimumReasoningEffort($settings->model)) {
            $requestParams['reasoning_effort'] = $reasoningEffort;
        }

        $result = $client->chat()->create($requestParams);

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

        $requestParams = [
            'model' => $settings->model,
            'messages' => $messages,
        ];

        if ($reasoningEffort = self::getMinimumReasoningEffort($settings->model)) {
            $requestParams['reasoning_effort'] = $reasoningEffort;
        }

        $result = $client->chat()->create($requestParams);

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
     * Generate suggested keywords for an image asset, e.g. for search/tagging purposes in a DAM.
     * Unlike the alt text and focal point methods, this doesn't save anything – the keywords are returned to the caller.
     *
     * @param Asset      $asset            The image asset to generate keywords for.
     * @param int        $maxKeywords      Maximum number of keywords to return.
     * @param string|null $language         Language for the keywords (defaults to the asset's site language).
     * @param array|null $existingKeywords Keywords already describing the asset; the model is asked to complement these, not repeat them.
     *
     * @return array|null The generated keywords, or null on failure.
     * @throws \Exception
     */
    public function getKeywordsForAsset(Asset $asset, int $maxKeywords = 20, ?string $language = null, ?array $existingKeywords = null): ?array
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            throw new \InvalidArgumentException("Asset \"$asset->filename\" is not an image");
        }

        $settings = AIMate::getInstance()->getSettings();

        $client = OpenAiHelper::getClient();

        $imageUrl = $this->getAssetUrl($asset);

        if (empty($imageUrl)) {
            \Craft::error('Could not get image URL for asset ' . $asset->id, __METHOD__);
            return null;
        }

        $messages = $this->buildKeywordsPrompt(
            $imageUrl,
            language: $language ?? $asset->getSite()->language ?? \Craft::$app->getSites()->getCurrentSite()->language,
            maxKeywords: $maxKeywords,
            existingKeywords: $existingKeywords,
        );

        $requestParams = [
            'model' => $settings->model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
        ];

        if ($reasoningEffort = self::getMinimumReasoningEffort($settings->model)) {
            $requestParams['reasoning_effort'] = $reasoningEffort;
        }

        $result = $client->chat()->create($requestParams);

        $response = Collection::make($result['choices'] ?? [])->first(static fn(array $choice) => $choice['finish_reason'] === 'stop' && !empty($choice['message']['content'] ?? null));

        if (!$response) {
            \Craft::error('Invalid response from OpenAI for asset ' . $asset->id . ': ' . print_r($result, true), __METHOD__);
            return null;
        }

        $message = trim($response['message']['content']);

        try {
            $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            \Craft::error('Invalid JSON response from OpenAI for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
            return null;
        }

        $keywords = $data['keywords'] ?? $data['output_format']['keywords'] ?? null; // For some obscure reason, the API sometimes returns the whole request structure back.

        if (!is_array($keywords)) {
            \Craft::error('No keywords found in response from OpenAI for asset ' . $asset->id . ': ' . print_r($data, true), __METHOD__);
            return null;
        }

        $keywords = Collection::make($keywords)
            ->filter(static fn($keyword) => is_string($keyword))
            ->map(static fn(string $keyword) => trim($keyword))
            ->filter()
            ->unique()
            ->take($maxKeywords)
            ->values()
            ->all();

        return $keywords ?: null;
    }

    /**
     * Analyse an image and return any combination of alt text, keywords and a focal point in a
     * SINGLE OpenAI vision call (rather than one call per task). Nothing is saved – the results are
     * returned to the caller, which decides what to do with them.
     *
     * @param Asset $asset   The image asset to analyse.
     * @param array $include Which tasks to run – any of 'altText', 'keywords', 'focalPoint'.
     * @param array $opts    Optional: 'language' (string), 'maxKeywords' (int), 'existingKeywords' (array), 'altMaxChars' (int).
     *
     * @return array A map with the requested keys: 'altText' => ?string, 'keywords' => ?array,
     *               'focalPoint' => ?array{x: float, y: float}. Missing/failed tasks are null or absent.
     * @throws \Exception
     */
    public function analyzeImage(Asset $asset, array $include = ['altText', 'keywords', 'focalPoint'], array $opts = []): array
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            throw new \InvalidArgumentException("Asset \"$asset->filename\" is not an image");
        }

        $include = array_values(array_intersect(['altText', 'keywords', 'focalPoint'], $include));
        if (empty($include)) {
            return [];
        }

        $settings = AIMate::getInstance()->getSettings();

        $client = OpenAiHelper::getClient();

        $imageUrl = $this->getAssetUrl($asset);

        if (empty($imageUrl)) {
            \Craft::error('Could not get image URL for asset ' . $asset->id, __METHOD__);
            return [];
        }

        $language = $opts['language'] ?? $asset->getSite()->language ?? \Craft::$app->getSites()->getCurrentSite()->language;
        $maxKeywords = $opts['maxKeywords'] ?? 20;

        $messages = $this->buildAnalysisPrompt($imageUrl, $include, [
            'language' => $language,
            'maxKeywords' => $maxKeywords,
            'existingKeywords' => $opts['existingKeywords'] ?? null,
            'altMaxChars' => $opts['altMaxChars'] ?? 140,
        ]);

        $requestParams = [
            'model' => $settings->model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
        ];

        if ($reasoningEffort = self::getMinimumReasoningEffort($settings->model)) {
            $requestParams['reasoning_effort'] = $reasoningEffort;
        }

        $result = $client->chat()->create($requestParams);

        $response = Collection::make($result['choices'] ?? [])->first(static fn(array $choice) => $choice['finish_reason'] === 'stop' && !empty($choice['message']['content'] ?? null));

        if (!$response) {
            \Craft::error('Invalid response from OpenAI for asset ' . $asset->id . ': ' . print_r($result, true), __METHOD__);
            return [];
        }

        try {
            $data = json_decode(trim($response['message']['content']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            \Craft::error('Invalid JSON response from OpenAI for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
            return [];
        }

        // The API sometimes echoes the whole request structure back under "output_format"
        $data = $data['output_format'] ?? $data;

        $out = [];

        // Each field is parsed independently, so a malformed field doesn't discard the others
        if (in_array('altText', $include, true)) {
            $out['altText'] = isset($data['alt_text']) && is_string($data['alt_text']) ? (trim($data['alt_text']) ?: null) : null;
        }

        if (in_array('keywords', $include, true)) {
            $keywords = is_array($data['keywords'] ?? null)
                ? Collection::make($data['keywords'])
                    ->filter(static fn($keyword) => is_string($keyword))
                    ->map(static fn(string $keyword) => trim($keyword))
                    ->filter()
                    ->unique()
                    ->take($maxKeywords)
                    ->values()
                    ->all()
                : [];
            $out['keywords'] = $keywords ?: null;
        }

        if (in_array('focalPoint', $include, true)) {
            $focalPoint = $data['focal_point'] ?? null;
            if (isset($focalPoint['x'], $focalPoint['y']) && is_numeric($focalPoint['x']) && is_numeric($focalPoint['y'])) {
                // Clamp to the 0–1 range – Asset::setFocalPoint() silently discards out-of-range values
                $out['focalPoint'] = [
                    'x' => min(1.0, max(0.0, round((float)$focalPoint['x'], 4))),
                    'y' => min(1.0, max(0.0, round((float)$focalPoint['y'], 4))),
                ];
            } else {
                $out['focalPoint'] = null;
            }
        }

        return $out;
    }

    /**
     * Build OpenAI chat messages for the combined analyzeImage() call, requesting only the given tasks.
     *
     * @param string $imageUrl URL of the image to analyse.
     * @param array  $include  Any of 'altText', 'keywords', 'focalPoint'.
     * @param array  $opts     'language', 'maxKeywords', 'existingKeywords', 'altMaxChars'.
     *
     * @return array The `messages` array to send to OpenAI’s Chat API.
     * @throws \JsonException
     */
    public function buildAnalysisPrompt(string $imageUrl, array $include, array $opts = []): array
    {
        $language = $opts['language'] ?? 'en';
        $maxKeywords = $opts['maxKeywords'] ?? 20;
        $existingKeywords = $opts['existingKeywords'] ?? null;
        $altMaxChars = $opts['altMaxChars'] ?? 140;

        $roles = [];
        $rules = [];
        $outputFormat = [];
        $userContent = ['language' => $language];

        if (in_array('altText', $include, true)) {
            $roles[] = 'an expert accessibility writer';
            $rules[] = "alt_text: concise, accurate, non-hallucinated alternative text meeting WCAG 2.2 / ARIA guidance, in the language \"$language\". Never include \"image of\" or \"picture of\". Avoid sensitive inferences (race, nationality, disability, etc.). Use sentence case with no trailing period unless multiple sentences. Return an empty string if the image is purely decorative. Max $altMaxChars characters.";
            $outputFormat['alt_text'] = 'string';
        }

        if (in_array('keywords', $include, true)) {
            $roles[] = 'an expert photo librarian';
            $rules[] = "keywords: up to $maxKeywords single- or short two-word search keywords, ordered most to least relevant, covering the main subjects/objects, then setting/scene, activities, season/time of day, and mood. Verifiable visual facts only; no camera data, filenames or SEO terms. Do not repeat or trivially rephrase the existing_keywords. Language \"$language\", lowercase except proper nouns.";
            $outputFormat['keywords'] = 'array of strings';
            $userContent['existing_keywords'] = array_values($existingKeywords ?? []);
        }

        if (in_array('focalPoint', $include, true)) {
            $roles[] = 'an expert photo editor';
            $rules[] = 'focal_point: the single most important point for anchoring crops. Priority order: human faces, then people/animals, then the main subject, then the sharpest/highest-contrast area. x is horizontal (0.0 = left, 1.0 = right), y is vertical (0.0 = top, 1.0 = bottom); aim for the centre of the subject (between the eyes for faces). Return null for both x and y if there is no discernible subject. Language-independent.';
            $outputFormat['focal_point'] = ['x' => 'float 0–1, or null', 'y' => 'float 0–1, or null'];
        }

        $roleList = count($roles) > 1
            ? implode(', ', array_slice($roles, 0, -1)) . ' and ' . end($roles)
            : ($roles[0] ?? 'an expert image analyst');

        $systemPrompt = "You are $roleList. Analyse the image and return the requested fields. Prefer verifiable visual facts over guesses. Return output in valid JSON format exactly as specified, with no extra keys.";

        $userContent['output_format'] = $outputFormat;
        $userContent['rules'] = $rules;

        return [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($userContent, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => ['url' => $imageUrl],
                    ],
                ],
            ],
        ];
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
     * Build OpenAI chat messages for generating search keywords from an image.
     *
     * @param string      $imageUrl         URL of the image to generate keywords for.
     * @param string      $language         Language code for the keywords (default: 'en').
     * @param int         $maxKeywords      Maximum number of keywords (default: 20).
     * @param array|null  $existingKeywords Existing keywords the model should complement, not repeat.
     * @param string|null $context          Optional context, e.g., page title or caption.
     *
     * @return array The `messages` array to send to OpenAI’s Chat API.
     * @throws \JsonException
     */
    public function buildKeywordsPrompt(
        string $imageUrl,
        string $language = 'en',
        int $maxKeywords = 20,
        ?array $existingKeywords = null,
        ?string $context = null,
    ): array {
        $systemPrompt = <<<EOT
You are an expert photo librarian for a digital asset management (DAM) system. Generate accurate, non-hallucinated keywords that make images easy to find via search.
- Keywords should be single words or short phrases (max two words).
- Cover the most important subjects and objects first, then setting and scene, activities, season and time of day, concepts and mood.
- Prefer verifiable visual facts over guesses.
- Avoid sensitive inferences (race, nationality, disability, etc.).
- Skip camera data, filenames and SEO terms.
- Return output in valid JSON format exactly as specified.
EOT;

        $userPrompt = [
            "role" => "user",
            "content" => [
                [
                    "type" => "text",
                    "text" => json_encode([
                        "language" => $language,
                        "max_keywords" => $maxKeywords,
                        "existing_keywords" => array_values($existingKeywords ?? []),
                        "context" => $context,
                        "output_format" => [
                            "keywords" => "array of strings",
                            "confidence" => "float 0–1",
                            "warnings" => "array of strings",
                        ],
                        "rules" => [
                            "Return at most max_keywords keywords, ordered from most to least relevant.",
                            "Do not repeat or trivially rephrase any of the existing_keywords.",
                            "Use lowercase, except for proper nouns.",
                            "Make sure the returned keywords are in the correct language.",
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

    /**
     * The image-based asset tasks (alt text, focal point, keywords) don't benefit from reasoning – dialing it
     * down keeps latency and cost reasonable.
     *
     * @param string $model
     * @return string|null The lowest supported reasoning effort for the given model, or null if it isn't a known reasoning model.
     */
    private static function getMinimumReasoningEffort(string $model): ?string
    {
        if (!str_starts_with($model, 'gpt-5') || str_contains($model, '-chat')) {
            return null;
        }
        // The lowest supported reasoning effort is "minimal" for the original GPT-5 models, "none" for GPT-5.1 and later
        return str_starts_with($model, 'gpt-5.') ? 'none' : 'minimal';
    }
}

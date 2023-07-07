<?php

namespace vaersaagod\aimate\models;

use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use vaersaagod\aimate\AIMate;
use yii\base\Model as ModelAlias;

class Prompt extends Model
{

    /** @var string|null The input text, if any */
    public ?string $text = null;

    /** @var ElementInterface|null */
    public ?ElementInterface $element = null;

    /** @var PromptConfig */
    private PromptConfig $config;

    /** @var int */
    private int $maxWords;

    /** @var bool */
    private bool $isHtml;

    public function rules(): array
    {
        $rules = parent::rules();
        if (!$this->text && str_contains($this->config->template ?? '', '<text>')) {
            $rules[] = ['text', 'required'];
        }
        return $rules;
    }

    /**
     * @return string
     */
    public function getHandle(): string
    {
        return $this->config->handle;
    }

    /**
     * @return string
     * @throws \Throwable
     * @throws \yii\base\Exception
     */
    public function getPrompt(): string
    {

        // Get template
        $template = $this->config->template;

        // Replace text tokens
        $template = str_replace('<text>', $this->text ?? '', $template);

        // Render the prompt as an object template, in case we have an element
        $prompt = \Craft::$app->getView()->renderObjectTemplate($template, $this->element);

        // Parse the directives
        $directives = [];

        // Figure out the max number of words we want
        $maxWords = $this->getMaxWords();
        if ($maxWords) {
            $directives[] = "In about $maxWords words or less";
        }

        // Retain HTML?
        if ($this->getIsHtml()) {
            $directives[] = 'preserving HTML tags';
        }

        if (empty($directives)) {
            return $prompt;
        }

        $directives = implode(' and ', $directives);

        return implode(', ', [$directives, $prompt]);

    }

    /**
     * @param PromptConfig $config
     * @return void
     */
    public function setConfig(PromptConfig $config): void
    {
        $this->config = $config;
    }

    /**
     * @return bool
     */
    public function getIsHtml(): bool
    {
        if (isset($this->isHtml)) {
            return $this->isHtml;
        }
        if (!empty($this->text)) {
            return StringHelper::isHtml($this->text);
        }
        return false;
    }

    /**
     * @param bool $value
     * @return void
     */
    public function setIsHtml(bool $value): void
    {
        $this->isHtml = $value;
    }

    /**
     * @return int|null
     */
    public function getMaxWords(): ?int
    {
        $maxWords = null;
        if (isset($this->maxWords)) {
            $maxWords = $this->maxWords;
        } else if (!empty($this->config->maxWords) && $maxWords !== false) {
            $maxWords = $this->config->maxWords;
        } else if (!empty($this->text)) {
            $maxWords = StringHelper::countWords($this->text);
        }
        if (!$maxWords) {
            return null;
        }
        $multiplier = $this->config->maxWordsMultiplier ?? AIMate::getInstance()->getSettings()->maxWordsMultiplier;
        if (!empty($multiplier)) {
            return round($maxWords * $multiplier);
        }
        return $maxWords;
    }

    /**
     * @param int $maxWords
     * @return void
     */
    public function setMaxWords(int $maxWords): void
    {
        $this->maxWords = $maxWords;
    }

    /**
     * @return string
     */
    public function getModel(): string
    {
        return $this->config->model ?? AIMate::getInstance()->getSettings()->model;
    }

    /**
     * @return float
     */
    public function getTemperature(): float
    {
        return 0.7; // TODO
    }

}

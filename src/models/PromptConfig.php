<?php

namespace vaersaagod\aimate\models;

use craft\base\Model;

class PromptConfig extends Model
{

    /** @var string|null */
    public ?string $handle = null;

    /** @var string|null */
    public ?string $name = null;

    /** @var string|null */
    public ?string $template = null;

    /** @var string|null */
    public ?string $model = null;

    /** @var float|null */
    public ?float $temperature = null;

    /** @var int|bool|null */
    public int|bool|null $maxWords = null;

    /** @var float|null */
    public ?float $maxWordsMultiplier = null;

    /** @var array|null The sites this prompt will be active for */
    public ?array $sites = null;

    /**
     * @return array
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['handle', 'name', 'template'], 'required'];
        $rules[] = ['model', function ($attribute, $params, $validator) {
            $this->addError($attribute, 'The token must contain letters or digits.');
        }];
        return $rules;
    }

}

<?php

namespace vaersaagod\aimate\helpers;

use Craft;
use craft\helpers\Html;

use vaersaagod\aimate\models\PromptConfig;

final class AIMateHelper
{
    public static function getPromptLabel(PromptConfig $promptConfig): string
    {
        $label = Html::tag('span', Craft::t('site', $promptConfig->name), [
            'style' => 'margin-right: auto; flex: 0 0 auto;',
        ]);
        $model = $promptConfig->model;
        if (!empty($model)) {
            $label .= Html::tag('span', $model, [
                'style' => 'margin-left: 10px; text-align: right; flex: 0 0 auto; color: var(--fg-subtle); font-family: SFMono-Regular, Consolas, Liberation Mono, Menlo, Courier, monospace; font-size: .6875rem;',
            ]);
        }
        return Html::tag('span', $label, [
            'style' => 'width: 100%; display: flex; align-items: center;',
        ]);
    }
}

<?php

namespace vaersaagod\aimate;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class AiMateBundle extends AssetBundle
{

    public function init()
    {
        $this->sourcePath = '@vaersaagod/aimate/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            //'TranslateElementsTo.js',
            //'TranslateFieldModal.js',
            'aimate.js',
        ];

        $this->css = [
            'aimate.css',
        ];

        parent::init();
    }

}

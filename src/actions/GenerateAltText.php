<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace vaersaagod\aimate\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Entry;
use yii\base\Exception;


class GenerateAltText extends ElementAction
{
    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('app', 'Generate alt text');
    }

    /**
     * @inheritdoc
     */
    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
(() => {
    new Craft.ElementActionTrigger({
        type: $type,
        bulk: true,
        validateSelection: (selectedItems, elementIndex) => {
          return true;
        },
        activate: (selectedItems, elementIndex) => {
          let elementIds = [];
          
          for (let i = 0; i < selectedItems.length; i++) {
            elementIds.push(selectedItems.eq(i).find('.element').data('id'));
          }
          
            Craft.sendActionRequest(
                'POST',
                '_aimate/generate/generate-alt-text-jobs',
                {
                    data: { 
                        elementIds: elementIds.join(','),
                        siteId: elementIndex.siteId,
                     }
                }
            ).then(response => {
                Craft.cp.displayNotice(response.message || response.data.message);
            }).catch(({ response }) => {
                Craft.cp.displayError(response.message || response.data.message);
            }).catch(error => {
                console.error(error);
            }).then(() => {

            });
          
          
          
        },
    });
})();
JS, [static::class]);

        return null;
    }
}

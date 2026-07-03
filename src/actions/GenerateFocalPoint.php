<?php

namespace vaersaagod\aimate\actions;

use Craft;
use craft\base\ElementAction;

class GenerateFocalPoint extends ElementAction
{
    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('_aimate', 'Generate focal point');
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
                '_aimate/generate/generate-focal-point-jobs',
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
            });
        },
    });
})();
JS, [static::class]);

        return null;
    }
}

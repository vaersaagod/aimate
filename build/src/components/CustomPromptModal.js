const CustomPromptModal = Garnish.Modal.extend({
    init: function () {

        this.base();
        this.setSettings({
            resizable: true,
        });

        const $modalContainerDiv = $(
            '<div class="modal fitted aimate-customprompt"></div>'
        )
            .addClass()
            .appendTo(Garnish.$bod);

        const $body = $(`
                <div class="body" style="display:flex;flex-direction:column;min-height:100%;">
                    <div class="header" style="flex: none;">
                        <h1 style="margin-bottom:10px;">Custom prompt</h1>
                        <p class="notice has-icon">
                            <span class="icon" aria-hidden="true"></span>
                            <span>Add a &lt;text&gt; token to the prompt to include the current value in the field.</span>
                        </p>
                    </div>
                    <form method="post" accept-charset="UTF-8" style="display:flex;height:100%;flex: 1 1 auto;position:relative;">
                        <textarea style="width:420px;min-width:100%;max-width:100%;resize:none;padding:10px;"></textarea>
                    </form>
                </div>
            `).appendTo(
            $modalContainerDiv.empty()
        );

        const $footer = $('<div class="footer"/>').appendTo($body);
        const $footerBtnContainer = $('<div class="buttons right"/>').appendTo($footer);
        const $cancelBtn = $('<button/>', {
            type: 'button',
            class: 'btn',
            text: Craft.t('app', 'Cancel'),
        }).appendTo($footerBtnContainer);

        const $applyBtn = Craft.ui
            .createSubmitButton({ label: Craft.t('app', 'Apply') })
            .appendTo($footerBtnContainer);

        this.$textarea = $body.find('textarea');

        this.addListener($cancelBtn, 'click', 'onCancel');
        this.addListener($applyBtn, 'click', 'onApply');

        const _this = this;

        this.$textarea = $modalContainerDiv.find('textarea');

        this.setContainer($modalContainerDiv);

    },

    onShow: function () {
        const _this = this;
        this.promise = new Promise((resolve, reject) => {
            _this.reject = reject;
            _this.resolve = resolve;
        });
    },

    onHide: function () {
        this.$textarea.val('');
    },

    onCancel: function () {
        this.resolve();
        this.hide();
    },

    onApply: function () {
        this.resolve(this.$textarea.val());
        this.hide();
    },

    getText: function () {
        return this.promise;
    }

});

export default CustomPromptModal;

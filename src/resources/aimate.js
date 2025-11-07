// import CustomPromptModal from '../../build/src/components/CustomPromptModal';
//
// let customPromptModal;

$(document).ready(() => {
    const onFieldActionPromptClick = async e => {
        const { currentTarget: promptButton } = e;

        // Get field
        const { element: elementId, site: siteId } = promptButton.dataset;
        const $fieldActionsMenuTrigger = $(promptButton)
            .closest('.menu')
            .data('disclosureMenu')
            ?.$trigger;
        const $field = $fieldActionsMenuTrigger ? $fieldActionsMenuTrigger.closest('.field') : null;
        if (!$field.length) {
            Craft.cp.displayError('Field not found');
            return;
        }

        const fieldType = $field.data('type');

        let input;
        if (fieldType === 'craft\\fields\\Table') {
            // Does not work yet
            // input = menuButton.closest('td.textual').querySelector('input,textarea');
        } else {
            input = $field.find('.input input,textarea').get(0);
        }

        if (!input) {
            Craft.cp.displayError('Input not found');
            return;
        }

        const inputValue = input.value;
        if (!inputValue.trim().length) {
            $fieldActionsMenuTrigger.data('disclosureMenu')?.hide();
            $fieldActionsMenuTrigger.focus();
            return;
        }

        let params = {};

        if (promptButton.hasAttribute('data-custom')) {
            // if (!customPromptModal) {
            //     customPromptModal = new CustomPromptModal();
            // }
            // customPromptModal.show();
            // const customPrompt = await customPromptModal.getText();
            // if (!customPrompt) {
            //     return;
            // }
            // params.custom = customPrompt;
        } else {
            const { prompt } = promptButton.dataset;

            if (!prompt) {
                Craft.cp.displayError('No prompt');
                return;
            }
            params.prompt = prompt;
        }

        const elementEditor = $(input.closest('[data-element-editor]')).data('elementEditor');

        params = {
            ...params,
            elementId,
            siteId
        };

        if (elementEditor) {
            params = {
                ...params,
                draftId: elementEditor.settings.draftId,
                isProvisionalDraft: elementEditor.settings.isProvisionalDraft
            };
        }

        // Add a spinner to the field actions disclosure menu trigger, if it doesn't have one
        if (!$fieldActionsMenuTrigger.find('.spinner').length) {
            $fieldActionsMenuTrigger.append('<span class="spinner spinner-absolute" style="--size:80%;"/>');
        }
        $fieldActionsMenuTrigger.addClass('loading');
        $fieldActionsMenuTrigger.data('disclosureMenu')?.hide();
        $fieldActionsMenuTrigger.focus();

        Craft.sendActionRequest(
            'POST',
            '_aimate/default/do-prompt',
            {
                data: {
                    text: inputValue,
                    ...params
                }
            }
        ).then(res => {
            const { data } = res;
            if (!data.text) {
                return;
            }
            const field = input.closest('.field');
            const { type: fieldType } = field.dataset;
            if (fieldType === 'craft\\ckeditor\\Field') {
                const editable = field.querySelector('.ck-editor__editable');
                const ckEditorInstance = editable ? editable.ckeditorInstance : null;
                if (!ckEditorInstance) {
                    throw new Error('Unable to find CKEditor instance in DOM');
                }
                ckEditorInstance.focus();
                ckEditorInstance.execute('selectAll');
                const viewFragment = ckEditorInstance.data.processor.toView(data.text);
                const modelFragment = ckEditorInstance.data.toModel(viewFragment);
                ckEditorInstance.model.insertContent(modelFragment);
            } else if (fieldType === 'craft\\redactor\\Field') {
                const redactorInstance = $R(`#${input.id}`);
                redactorInstance.editor.focus();
                redactorInstance.insertion.set(data.text);
                redactorInstance.source.getElement().val(data.text);
            } else {
                input.focus();
                input.select();
                if (!document.execCommand('insertText', false, data.text)) {
                    input.setRangeText(data.text);
                }
            }
            if (elementEditor) {
                elementEditor.checkForm();
            }
        }).catch(({ response }) => {
            Craft.cp.displayError(response.message || response.data.message);
        }).catch(error => {
            console.error(error);
        }).then(() => {
            $fieldActionsMenuTrigger.removeClass('loading');
            input.focus();
        });
    };

    const onGenerateAltTextClick = e => {
        e.preventDefault();

        const { currentTarget: generateButton } = e;
        const { element: elementId, site: siteId } = generateButton.dataset;

        const params = {
            elementId,
            siteId
        };

        generateButton.classList.add('loading');

        Craft.sendActionRequest(
            'POST',
            '_aimate/default/generate-alt-text',
            {
                data: { ...params }
            }
        ).then(res => {
            const { data } = res;
            window.location.reload();
        }).catch(({ response }) => {
            Craft.cp.displayError(response.message || response.data.message);
        }).catch(error => {
            console.error(error);
        }).then(() => {
            generateButton.classList.remove('loading');
        });
    }

    /*
    const initTableRow = $tr => {
        const field = $tr.get(0).closest('.field');
        if (!field) {
            return;
        }
        const aimateButton = field.querySelector('button[data-aimate]');
        if (!aimateButton) {
            return;
        }
        const tds = $tr.children().get().filter(td => td.classList.contains('singleline-cell', 'multiline-cell'));
        const trId = $tr.index();
        tds.forEach(td => {
            const $aimateButton = $(aimateButton).clone(false, true);
            const menuId = $aimateButton.attr('aria-controls');
            const $menu = $($(`#${menuId}`)).clone(false, true);
            const textarea = td.querySelector('textarea');
            const tdId = `${trId}-${$(td).index()}`;
            $menu.attr('id', `${$menu.attr('id')}-${trId}-${tdId}`);
            $('body').append($menu);
            $aimateButton.attr('aria-controls', $menu.attr('id'));
            $(textarea.parentNode).prepend($aimateButton.disclosureMenu());
        });
    };
    */

    /*
    $('.field table.editable tbody tr').each(function () {
        initTableRow($(this));
    });

    const rowInitFn = Craft.EditableTable.Row.prototype.init;
    Craft.EditableTable.Row.prototype.init = function () {
        rowInitFn.apply(this, arguments);
        initTableRow(this.$tr);
    };
    */

    $('body').on('click', '[data-aimate-prompt-button]', onFieldActionPromptClick);
    $('body').on('click', '[data-aimate-generate-alt-text-button]', onGenerateAltTextClick);

});

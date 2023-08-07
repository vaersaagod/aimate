import CustomPromptModal from "./components/CustomPromptModal.js";

import './aimate.scss';

$(() => {

    let customPromptModal;

    const onPromptClick = async e => {
        e.preventDefault();
        const {currentTarget: promptLink} = e;
        const menu = promptLink.closest('[data-aimate-menu]');
        const menuButton = document.querySelector(`button[aria-controls="${menu.id}"]`);
        if (menuButton.classList.contains('loading')) {
            return;
        }
        $(menuButton).trigger('click');
        const {field: fieldId, element: elementId, site: siteId} = menuButton.dataset;
        const field = menuButton.closest('.field');
        if (!field) {
            Craft.cp.displayError('Field not found');
            return;
        }
        const {type: fieldType} = field.dataset;
        let input;
        if (fieldType === 'craft\\fields\\Table') {
            input = menuButton.closest('td.textual').querySelector('input,textarea');
        } else {
            input = menuButton.closest('.input').querySelector('input,textarea');
        }
        if (!input) {
            Craft.cp.displayError('Input not found');
            return;
        }
        let params = {};
        if (promptLink.hasAttribute('data-custom')) {
            if (!customPromptModal) {
                customPromptModal = new CustomPromptModal();
            }
            customPromptModal.show();
            const customPrompt = await customPromptModal.getText();
            if (!customPrompt) {
                return;
            }
            params.custom = customPrompt;
        } else {
            const {prompt} = promptLink.dataset;
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
        menuButton.classList.add('loading');
        Craft.sendActionRequest(
            'POST',
            '_aimate/default/do-prompt',
            {
                data: {
                    text: input.value,
                    ...params
                }
            }
        )
            .then(res => {
                const {data} = res;
                if (!data.text) {
                    return;
                }
                const field = input.closest('.field');
                const {type: fieldType} = field.dataset;
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
            })
            .catch(({response}) => {
                Craft.cp.displayError(response.message || response.data.message);
            })
            .catch(error => {
                console.error(error);
            })
            .then(() => {
                menuButton.classList.remove('loading');
                input.focus();
            });
    };

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

    $('.field table.editable tbody tr').each(function () {
        initTableRow($(this));
    });

    const rowInitFn = Craft.EditableTable.Row.prototype.init;
    Craft.EditableTable.Row.prototype.init = function () {
        rowInitFn.apply(this, arguments);
        initTableRow(this.$tr);
    };

    $('body').on('click', '[data-aimate-menu] a', onPromptClick);

});

// Accept HMR as per: https://vitejs.dev/guide/api-hmr.html
if (import.meta.hot) {
    import.meta.hot.accept();
}

import $ from 'jquery';
import __ from 'orotranslation/js/translator';

export interface DwlInputOptions {
    /** Initial text. */
    value: string;
    /** Initial mode (ignored when `fixed`): true = the text is a (single-line) DWL expression. */
    dwl: boolean;
    /** Always DWL: no switch is rendered and {@link DwlInput.isDwl} is always true. */
    fixed?: boolean;
    /** Accessible name of the input — the visible label belongs to the host's layout. */
    label?: string;
    placeholder?: string;
    maxlength?: number;
    /** Extra class(es) for the input. */
    inputClass?: string;
}

export interface DwlInput {
    /** The whole component (input + the switch inside its left end) — append this. */
    $el: any;
    /** The text input, for host event bindings/focus. */
    $input: any;
    /** The pure-text/DWL switch's checkbox (detached when `fixed`). */
    $toggle: any;
    value: () => string;
    isDwl: () => boolean;
}

/**
 * Single-line "text or DWL" input: the one-line sibling of {@see createDwlField} (which renders a
 * textarea under its own title row). A plain text input with a small pure-text/DWL switch INSIDE
 * its left end (so the mode reads before the value); while the switch is on the text is a single-line DWL expression the host
 * evaluates at run time, shown in code style with spellcheck off. A pasted multi-line expression
 * is flattened by the input itself (text inputs drop line breaks).
 *
 * The host stores the text and the switch state side by side (e.g. `path` + `path_dwl`); the
 * visible label stays the host's, so the component fits any row layout. A `fixed` input is DWL
 * only (e.g. Read File's reader options): code style, no switch, nothing to store beside it.
 */
export default function createDwlInput(options: DwlInputOptions): DwlInput {
    const fixed = Boolean(options.fixed);
    const $toggle = $('<input/>', {type: 'checkbox'});
    $toggle.prop('checked', fixed || options.dwl);
    const isDwl = (): boolean => fixed || Boolean($toggle.prop('checked'));

    const $input = $('<input/>', {
        type: 'text',
        'class': ('form-control aaxis-dwl-input__input ' + (options.inputClass || '')).trim(),
        autocomplete: 'off'
    });
    if (options.label) {
        $input.attr('aria-label', options.label);
    }
    if (options.placeholder) {
        $input.attr('placeholder', options.placeholder);
    }
    if (options.maxlength) {
        $input.attr('maxlength', options.maxlength);
    }
    $input.val(options.value);

    const syncStyle = (): void => {
        $input.toggleClass('aaxis-dwl-input__input--code', isDwl());
        $input.attr('spellcheck', isDwl() ? 'false' : 'true');
    };
    $toggle.on('change', syncStyle);
    syncStyle();

    // Same visuals as the flow editor's small switch (and createDwlField's).
    const $switch = $('<label/>', {
        'class': 'aaxis-flow-editor__switch aaxis-flow-editor__switch--small aaxis-dwl-input__switch',
        title: __('aaxis.ontology.flow_editor.dwl_toggle_title')
    }).append(
        $toggle,
        $('<span/>', {'class': 'aaxis-flow-editor__switch-track'})
            .append($('<span/>', {'class': 'aaxis-flow-editor__switch-thumb'})),
        $('<span/>', {'class': 'aaxis-flow-editor__switch-text', text: __('aaxis.ontology.flow_editor.dwl_toggle_label')})
    );

    const $el = $('<div/>', {'class': 'aaxis-dwl-input' + (fixed ? ' aaxis-dwl-input--fixed' : '')})
        .append($input, fixed ? null : $switch);

    return {$el, $input, $toggle, value: () => String($input.val() || ''), isDwl};
}

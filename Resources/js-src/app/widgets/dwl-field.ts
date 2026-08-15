import $ from 'jquery';
import __ from 'orotranslation/js/translator';

export interface DwlFieldOptions {
    /** Already-translated title shown on the row above the textarea. */
    label: string;
    /** Initial textarea content. */
    value: string;
    /** Initial mode (ignored when `fixed`): true = the content is DWL code. */
    dwl: boolean;
    /** Always-DWL fields (e.g. the transform's code): the pure-text/DWL switch is hidden. */
    fixed?: boolean;
    /** Extra class(es) for the textarea — every host brings its own sizing/layout class. */
    editorClass?: string;
    /** Extra class(es) for the title element / the title row, to match the host's look. */
    labelClass?: string;
    rowClass?: string;
    /** Optional host controls rendered on the row's right side, before the switch. */
    $tools?: any;
}

export interface DwlField {
    /** The whole component (title row + textarea) — append this. */
    $el: any;
    /** The textarea, for host event bindings/focus. */
    $textarea: any;
    value: () => string;
    isDwl: () => boolean;
}

/**
 * Pretty-prints DWL code by re-indenting each line to its brace/bracket/paren depth (2 spaces per
 * level). Line structure is kept — only leading whitespace changes — and characters inside
 * strings or after a // comment never count as delimiters.
 */
export function prettyPrintDwl(code: string): string {
    let depth = 0;
    return code.split('\n').map(raw => {
        const line = raw.trim();
        const leadingClosers = (/^[)\]}]+/.exec(line) || [''])[0].length;
        const indented = line === '' ? '' : '  '.repeat(Math.max(0, depth - leadingClosers)) + line;
        let str: string | null = null;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (str !== null) {
                if (ch === '\\') {
                    i++;
                } else if (ch === str) {
                    str = null;
                }
                continue;
            }
            if (ch === '"' || ch === "'") {
                str = ch;
            } else if (ch === '/' && line[i + 1] === '/') {
                break;
            } else if (ch === '{' || ch === '[' || ch === '(') {
                depth++;
            } else if (ch === '}' || ch === ']' || ch === ')') {
                depth = Math.max(0, depth - 1);
            }
        }
        return indented;
    }).join('\n');
}

/**
 * Reusable "text or DWL" field: one component holding the title row — label on the left, host
 * tools and the pure-text/DWL switch always right-aligned over the textarea — plus the textarea
 * itself. Whenever DWL mode is active (switch turned on, or the field opens with it on — `fixed`
 * fields always are), the content is pretty-printed and shown with code styling.
 */
export default function createDwlField(options: DwlFieldOptions): DwlField {
    const fixed = Boolean(options.fixed);
    const $toggleInput = $('<input/>', {type: 'checkbox'});
    $toggleInput.prop('checked', fixed || options.dwl);
    const isDwl = (): boolean => fixed || Boolean($toggleInput.prop('checked'));

    const $textarea = $('<textarea/>', {
        'class': ('form-control aaxis-dwl-field__textarea ' + (options.editorClass || '')).trim(),
        autocomplete: 'off',
        'aria-label': options.label
    });
    $textarea.val(options.value); // a textarea's value must be set via .val()

    const syncStyle = (): void => {
        $textarea.attr('spellcheck', isDwl() ? 'false' : 'true');
        $textarea.toggleClass('aaxis-dwl-field__textarea--code', isDwl());
    };
    $toggleInput.on('change', () => {
        syncStyle();
        if (isDwl()) {
            $textarea.val(prettyPrintDwl(String($textarea.val() || '')));
        }
    });
    if (isDwl()) {
        $textarea.val(prettyPrintDwl(String($textarea.val() || '')));
    }
    syncStyle();

    const $right = $('<div/>', {'class': 'aaxis-dwl-field__tools'});
    if (options.$tools) {
        $right.append(options.$tools);
    }
    if (!fixed) {
        // Same visuals as the flow editor's small switch.
        $right.append($('<label/>', {
            'class': 'aaxis-flow-editor__switch aaxis-flow-editor__switch--small',
            title: __('aaxis.ontology.flow_editor.dwl_toggle_title')
        }).append(
            $toggleInput,
            $('<span/>', {'class': 'aaxis-flow-editor__switch-track'})
                .append($('<span/>', {'class': 'aaxis-flow-editor__switch-thumb'})),
            $('<span/>', {'class': 'aaxis-flow-editor__switch-text', text: __('aaxis.ontology.flow_editor.dwl_toggle_label')})
        ));
    }

    const $row = $('<div/>', {'class': ('aaxis-dwl-field__row ' + (options.rowClass || '')).trim()}).append(
        $('<span/>', {'class': ('aaxis-dwl-field__label ' + (options.labelClass || '')).trim(), text: options.label}),
        $right
    );
    const $el = $('<div/>', {'class': 'aaxis-dwl-field'}).append($row, $textarea);

    return {$el, $textarea, value: () => String($textarea.val() || ''), isDwl};
}

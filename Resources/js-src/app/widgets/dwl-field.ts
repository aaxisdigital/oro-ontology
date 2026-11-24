import $ from 'jquery';
import __ from 'orotranslation/js/translator';

export interface DwlFieldOptions {
    /** Already-translated title shown on the row above the textarea. */
    label: string;
    /** Initial textarea content. */
    value: string;
    /** Initial mode (ignored when `fixed`): true = the content is DWL code. */
    dwl: boolean;
    /** Always-DWL fields (e.g. the transform's code): the pure-text/DWL switch shows ON and locked. */
    fixed?: boolean;
    /** Extra class(es) for the textarea — every host brings its own sizing/layout class. */
    editorClass?: string;
    /** Extra class(es) for the title element / the title row, to match the host's look. */
    labelClass?: string;
    rowClass?: string;
    /** Optional host controls rendered on the row's right side, before the switch. */
    $tools?: any;
    /** The "format" wand before the switch (default on; it acts only while DWL mode is active). */
    format?: boolean;
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

/** Object literals break when they have several entries; lists only past this length or holding objects. */
const INLINE_LIST_LIMIT = 60;

/**
 * Formats DWL code (the field's wand): object literals with several entries get one entry per
 * line — lists too when long or holding objects — then every line is re-indented
 * ({@see prettyPrintDwl}). Strings and comments are never touched, parentheses (calls, lambdas)
 * never broken, existing line breaks are kept, and a comment trailing a comma stays on its line.
 * Formatting formatted code changes nothing.
 */
export function formatDwl(code: string): string {
    const n = code.length;
    // Characters inside strings or comments (never structure).
    const literal = new Array<boolean>(n).fill(false);
    for (let i = 0; i < n; i++) {
        const ch = code[i];
        let end = -1;
        if (ch === '"' || ch === "'" || ch === '`') {
            end = i + 1;
            while (end < n && code[end] !== ch) {
                end += code[end] === '\\' ? 2 : 1;
            }
        } else if (ch === '/' && code[i + 1] === '/') {
            end = code.indexOf('\n', i);
            end = (end < 0 ? n : end) - 1;
        } else if (ch === '/' && code[i + 1] === '*') {
            end = code.indexOf('*/', i + 2);
            end = end < 0 ? n - 1 : end + 1;
        }
        if (end >= 0) {
            for (let k = i; k <= Math.min(end, n - 1); k++) {
                literal[k] = true;
            }
            i = end;
        }
    }

    // Bracket pairs and the commas directly inside each.
    const closers = new Map<number, number>();
    const commas = new Map<number, number[]>();
    const stack: number[] = [];
    for (let i = 0; i < n; i++) {
        if (literal[i]) {
            continue;
        }
        if ('{[('.includes(code[i])) {
            stack.push(i);
            commas.set(i, []);
        } else if ('}])'.includes(code[i])) {
            const open = stack.pop();
            if (open !== undefined) {
                closers.set(open, i);
            }
        } else if (code[i] === ',' && stack.length) {
            commas.get(stack[stack.length - 1])?.push(i);
        }
    }

    const breakAfter = new Set<number>();
    const breakBefore = new Set<number>();
    closers.forEach((close, open) => {
        const groupCommas = commas.get(open) || [];
        const inner = code.slice(open, close + 1);
        if (code[open] === '(' || !groupCommas.length
            || (code[open] === '[' && inner.length <= INLINE_LIST_LIMIT && !inner.includes('{'))
        ) {
            return;
        }
        breakAfter.add(open);
        groupCommas.forEach(c => breakAfter.add(c));
        breakBefore.add(close);
    });

    let out = '';
    for (let i = 0; i < n; i++) {
        if (breakBefore.has(i)) {
            out = out.replace(/[ \t]+$/, '');
            if (!out.endsWith('\n')) {
                out += '\n';
            }
        }
        out += code[i];
        if (breakAfter.has(i)) {
            let j = i + 1;
            while (j < n && (code[j] === ' ' || code[j] === '\t')) {
                j++;
            }
            const trailingComment = code[j] === '/' && code[j + 1] === '/';
            if (j < n && code[j] !== '\n' && !trailingComment && !breakBefore.has(j)) {
                out += '\n';
                i = j - 1;
            }
        }
    }

    return prettyPrintDwl(out);
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
    if (options.format !== false) {
        // The wand (the Data View's "Format" glyph): formats the code — while DWL mode is on.
        const label = __('aaxis.ontology.flow_editor.dwl_format');
        const $wand = $('<button/>', {
            type: 'button', 'class': 'aaxis-dwl-field__wand', 'data-role': 'dwl-format', title: label, 'aria-label': label
        }).append($('<span/>', {'class': 'fa fa-magic', 'aria-hidden': 'true'}));
        $wand.on('click', (e: any) => {
            e.preventDefault();
            $textarea.val(formatDwl(String($textarea.val() || ''))).trigger('input');
        });
        const syncWand = (): void => {
            $wand.prop('disabled', !isDwl() || Boolean($textarea.prop('disabled')));
        };
        $toggleInput.on('change', syncWand);
        // A host may disable the whole field later (e.g. HTTP Request body while Body = Empty).
        $textarea.on('aaxis:dwl-sync', syncWand);
        syncWand();
        $right.append($wand);
    }
    // Same visuals as the flow editor's small switch. An always-DWL (`fixed`) field shows it ON and
    // LOCKED, so every DWL textarea reads the same way: format wand, then the DWL switch.
    $toggleInput.prop('disabled', fixed);
    $right.append($('<label/>', {
        'class': 'aaxis-flow-editor__switch aaxis-flow-editor__switch--small' + (fixed ? ' is-locked' : ''),
        title: __(fixed ? 'aaxis.ontology.flow_editor.dwl_fixed_title' : 'aaxis.ontology.flow_editor.dwl_toggle_title')
    }).append(
        $toggleInput,
        $('<span/>', {'class': 'aaxis-flow-editor__switch-track'})
            .append($('<span/>', {'class': 'aaxis-flow-editor__switch-thumb'})),
        $('<span/>', {'class': 'aaxis-flow-editor__switch-text', text: __('aaxis.ontology.flow_editor.dwl_toggle_label')})
    ));

    const $row = $('<div/>', {'class': ('aaxis-dwl-field__row ' + (options.rowClass || '')).trim()}).append(
        $('<span/>', {'class': ('aaxis-dwl-field__label ' + (options.labelClass || '')).trim(), text: options.label}),
        $right
    );
    const $el = $('<div/>', {'class': 'aaxis-dwl-field'}).append($row, $textarea);

    return {$el, $textarea, value: () => String($textarea.val() || ''), isDwl};
}

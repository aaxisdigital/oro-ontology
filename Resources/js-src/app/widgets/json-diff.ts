/**
 * Shared JSON render/diff helpers — extracted verbatim from the Data View's version dialog so the
 * flow editor's "View source" / "Flow history" popups render the exact same coloured JSON and
 * change highlighting (`.aaxis-json-*` classes in ontology.scss). Pure functions, no DOM.
 */

export function escapeHtml(text: string): string {
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

export function isPlainObject(value: any): boolean {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** Colours a pretty-printed JSON string (keys/strings/numbers/bools/null spans). */
export function highlightJson(json: string): string {
    const escaped = json
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    return escaped.replace(
        /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false)\b|\bnull\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
        (match: string) => {
            let cls = 'aaxis-json-num';
            if (/^"/.test(match)) {
                cls = /:$/.test(match) ? 'aaxis-json-key' : 'aaxis-json-str';
            } else if (/true|false/.test(match)) {
                cls = 'aaxis-json-bool';
            } else if (/null/.test(match)) {
                cls = 'aaxis-json-null';
            }
            return '<span class="' + cls + '">' + match + '</span>';
        }
    );
}

/** Pretty-prints a JS value to coloured JSON HTML, aligning continuation lines to `indent`. */
function valueToHtml(value: any, indent: number): string {
    const json = JSON.stringify(value, null, 2);
    const colored = highlightJson(json);
    const pad = '  '.repeat(indent);
    return colored.split('\n').map((line, i) => (i === 0 ? line : pad + line)).join('\n');
}

function decorateValue(html: string, changed: boolean): string {
    return changed ? '<span class="aaxis-json-changed">' + html + '</span>' : html;
}

function renderObjectDiff(sel: any, prev: any, indent: number): string {
    const pad = '  '.repeat(indent);
    const padIn = '  '.repeat(indent + 1);
    const selObj = isPlainObject(sel);
    const prevObj = isPlainObject(prev);

    // Show the selected version's keys first (this is how the record looked then), then append
    // any keys that only existed in the previous version (removed at this version).
    const keys: string[] = [];
    if (selObj) {
        Object.keys(sel).forEach(k => keys.push(k));
    }
    if (prevObj) {
        Object.keys(prev).forEach(k => {
            if (!keys.includes(k)) {
                keys.push(k);
            }
        });
    }
    if (keys.length === 0) {
        return '{}';
    }

    const lines = keys.map((key, idx) => {
        const comma = idx < keys.length - 1 ? ',' : '';
        const keySpan = '<span class="aaxis-json-key">"' + escapeHtml(key) + '":</span>';
        const keyHtml = keySpan + ' ';
        const selHas = selObj && Object.prototype.hasOwnProperty.call(sel, key);
        const prevHas = prevObj && Object.prototype.hasOwnProperty.call(prev, key);

        if (selHas && prevHas) {
            // Diff the value itself, so only the changed leaves get highlighted.
            return padIn + keyHtml + renderValueDiff(sel[key], prev[key], indent + 1) + comma;
        }

        if (selHas && !prevHas) {
            // New key at this version: highlight both the key and the value.
            return padIn + decorateValue(keySpan, true) + ' '
                + decorateValue(valueToHtml(sel[key], indent + 1), true) + comma;
        }

        // Existed in the previous version, removed at this one: show it struck through.
        const removed = keyHtml + valueToHtml(prev[key], indent + 1) + comma;
        return padIn + '<span class="aaxis-json-absent">' + removed + '</span>';
    });

    return '{\n' + lines.join('\n') + '\n' + pad + '}';
}

/**
 * Diffs two arrays positionally (element by element). Matching positions are diffed recursively,
 * extra elements in the selected version are highlighted, and elements that were dropped since
 * the previous version are shown struck through.
 */
function renderArrayDiff(sel: any[], prev: any[], indent: number): string {
    const pad = '  '.repeat(indent);
    const padIn = '  '.repeat(indent + 1);
    const len = Math.max(sel.length, prev.length);
    if (len === 0) {
        return '[]';
    }

    const lines: string[] = [];
    for (let i = 0; i < len; i++) {
        const comma = i < len - 1 ? ',' : '';
        const selHas = i < sel.length;
        const prevHas = i < prev.length;

        if (selHas && prevHas) {
            lines.push(padIn + renderValueDiff(sel[i], prev[i], indent + 1) + comma);
        } else if (selHas && !prevHas) {
            // Element added at this version.
            lines.push(padIn + decorateValue(valueToHtml(sel[i], indent + 1), true) + comma);
        } else {
            // Element removed at this version.
            lines.push(padIn + '<span class="aaxis-json-absent">' + valueToHtml(prev[i], indent + 1) + comma + '</span>');
        }
    }

    return '[\n' + lines.join('\n') + '\n' + pad + ']';
}

/**
 * Diffs a single value (object, array or scalar) against its previous counterpart. Objects and
 * arrays recurse so only the differing leaves are highlighted; scalars (or type mismatches) are
 * highlighted as a whole when they differ.
 */
function renderValueDiff(sel: any, prev: any, indent: number): string {
    if (isPlainObject(sel) && isPlainObject(prev)) {
        return renderObjectDiff(sel, prev, indent);
    }
    if (Array.isArray(sel) && Array.isArray(prev)) {
        return renderArrayDiff(sel, prev, indent);
    }
    const changed = JSON.stringify(sel) !== JSON.stringify(prev);
    return decorateValue(valueToHtml(sel, indent), changed);
}

/**
 * Renders the selected snapshot as pretty JSON HTML, decorated against the given previous (older)
 * snapshot. Only the values that are actually new or changed are highlighted in yellow, recursing
 * into nested objects and diffing arrays element by element; keys/elements that existed in the
 * previous version but are gone are shown struck through.
 */
export function renderVersionDiffHtml(selected: any, previous: any): string {
    return renderValueDiff(selected, previous, 0);
}

/**
 * Reduces a pair of snapshots to only what differs, so "diff only" can reuse the normal diff
 * renderer and still emit VALID json (filtering the rendered lines would cut multi-line
 * highlight spans in half).
 *
 * Objects are pruned key by key and ARRAYS element by element, both recursively — a change
 * deep inside one array element keeps only that element, pruned to its changed attributes
 * (kept pairs stay index-aligned between the two sides, so the diff renderer matches them up).
 * The trade-off: a diff-only array is COMPACTED, so element positions are not the payload's —
 * the full view is where positions are exact. Scalars (and type mismatches) are kept whole.
 * Returns null when the two snapshots are identical.
 */
export function pruneToDiff(sel: any, prev: any): {sel: any; prev: any} | null {
    if (JSON.stringify(sel) === JSON.stringify(prev)) {
        return null;
    }
    if (Array.isArray(sel) && Array.isArray(prev)) {
        const outSelList: any[] = [];
        const outPrevList: any[] = [];
        const length = Math.max(sel.length, prev.length);
        for (let i = 0; i < length; i++) {
            if (i < sel.length && i < prev.length) {
                const branch = pruneToDiff(sel[i], prev[i]);
                if (branch !== null) {
                    outSelList.push(branch.sel);
                    outPrevList.push(branch.prev);
                }
                continue;
            }
            // A trailing element only one side has: added (highlighted) or removed (struck).
            if (i < sel.length) {
                outSelList.push(sel[i]);
            } else {
                outPrevList.push(prev[i]);
            }
        }
        return {sel: outSelList, prev: outPrevList};
    }
    if (!isPlainObject(sel) || !isPlainObject(prev)) {
        return {sel, prev};
    }

    const outSel: Record<string, any> = {};
    const outPrev: Record<string, any> = {};
    const keys: string[] = Object.keys(sel);
    Object.keys(prev).forEach(key => {
        if (!keys.includes(key)) {
            keys.push(key);
        }
    });

    keys.forEach(key => {
        // hasOwnProperty via call, not Object.hasOwn: the build targets ES2020.
        const hasSel = Object.prototype.hasOwnProperty.call(sel, key);
        const hasPrev = Object.prototype.hasOwnProperty.call(prev, key);
        if (hasSel && hasPrev) {
            const branch = pruneToDiff(sel[key], prev[key]);
            if (branch !== null) {
                outSel[key] = branch.sel;
                outPrev[key] = branch.prev;
            }
            return;
        }
        // Added (renders highlighted) or removed (renders struck through).
        if (hasSel) {
            outSel[key] = sel[key];
        } else {
            outPrev[key] = prev[key];
        }
    });

    return {sel: outSel, prev: outPrev};
}

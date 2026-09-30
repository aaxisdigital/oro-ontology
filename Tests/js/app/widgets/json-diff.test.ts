import {describe, expect, it} from 'vitest';
import {
    escapeHtml, highlightJson, isPlainObject, pruneToDiff, renderVersionDiffHtml
} from '../../../../Resources/js-src/app/widgets/json-diff';

const span = (cls: string, text: string): string => `<span class="${cls}">${text}</span>`;

describe('escapeHtml', () => {
    it('escapes the three HTML-significant characters, ampersand first', () => {
        expect(escapeHtml('a & <b> "c"')).toBe('a &amp; &lt;b&gt; "c"');
        expect(escapeHtml('&lt;')).toBe('&amp;lt;');
    });

    it('stringifies non-string input', () => {
        expect(escapeHtml(42 as any)).toBe('42');
    });
});

describe('isPlainObject', () => {
    it.each([
        [{}, true],
        [{a: 1}, true],
        [[], false],
        [null, false],
        [undefined, false],
        ['text', false],
        [3, false]
    ])('%j -> %s', (value, expected) => {
        expect(isPlainObject(value)).toBe(expected);
    });
});

describe('highlightJson', () => {
    it('wraps keys, strings, numbers, booleans and null in their own spans', () => {
        const html = highlightJson('{\n  "name": "Ann",\n  "age": 30,\n  "ok": true,\n  "no": false,\n  "x": null\n}');

        expect(html).toContain(span('aaxis-json-key', '"name":'));
        expect(html).toContain(span('aaxis-json-str', '"Ann"'));
        expect(html).toContain(span('aaxis-json-num', '30'));
        expect(html).toContain(span('aaxis-json-bool', 'true'));
        expect(html).toContain(span('aaxis-json-bool', 'false'));
        expect(html).toContain(span('aaxis-json-null', 'null'));
    });

    it('colours a key whose colon is separated by whitespace as a key', () => {
        expect(highlightJson('"a" : 1')).toBe(span('aaxis-json-key', '"a" :') + ' ' + span('aaxis-json-num', '1'));
    });

    it.each(['0', '-12', '3.5', '-0.25', '1e5', '1E+5', '2.5e-3', '7.'])('colours the number %s as one token', num => {
        expect(highlightJson(num)).toBe(span('aaxis-json-num', num));
    });

    it('escapes HTML before colouring so injected markup stays inert', () => {
        const html = highlightJson('"<b>&</b>"');

        expect(html).toBe(span('aaxis-json-str', '"&lt;b&gt;&amp;&lt;/b&gt;"'));
    });

    it('keeps escaped quotes and unicode escapes inside one string token', () => {
        expect(highlightJson('"say \\"hi\\" \\u00e9\\n"')).toBe(span('aaxis-json-str', '"say \\"hi\\" \\u00e9\\n"'));
    });

    it('does not colour bare words that merely contain true/false/null', () => {
        expect(highlightJson('nullable trueish')).toBe('nullable trueish');
    });

    it('leaves punctuation and whitespace untouched', () => {
        expect(highlightJson('[\n  1,\n  2\n]')).toBe(
            '[\n  ' + span('aaxis-json-num', '1') + ',\n  ' + span('aaxis-json-num', '2') + '\n]'
        );
    });
});

describe('renderVersionDiffHtml', () => {
    const changed = (html: string): string => span('aaxis-json-changed', html);
    const key = (name: string): string => span('aaxis-json-key', `"${name}":`);
    const num = (n: number): string => span('aaxis-json-num', String(n));

    it('renders identical snapshots without any change marker', () => {
        const html = renderVersionDiffHtml({a: 1, b: 'x'}, {a: 1, b: 'x'});

        expect(html).not.toContain('aaxis-json-changed');
        expect(html).not.toContain('aaxis-json-absent');
        expect(html).toBe('{\n  ' + key('a') + ' ' + num(1) + ',\n  ' + key('b') + ' ' + span('aaxis-json-str', '"x"') + '\n}');
    });

    it('highlights only the changed leaf of an object', () => {
        const html = renderVersionDiffHtml({a: 1, b: 2}, {a: 1, b: 3});

        expect(html).toContain(key('a') + ' ' + num(1) + ',');
        expect(html).toContain(key('b') + ' ' + changed(num(2)));
    });

    it('highlights both the key and the value of a key that is new in this version', () => {
        const html = renderVersionDiffHtml({a: 1, added: true}, {a: 1});

        expect(html).toContain(changed(key('added')) + ' ' + changed(span('aaxis-json-bool', 'true')));
    });

    it('shows a key that only existed before struck through, after the current keys', () => {
        const html = renderVersionDiffHtml({a: 1}, {a: 1, gone: 'x'});

        expect(html).toContain(
            '<span class="aaxis-json-absent">' + key('gone') + ' ' + span('aaxis-json-str', '"x"') + '</span>'
        );
        expect(html.indexOf('"a"')).toBeLessThan(html.indexOf('"gone"'));
    });

    it('recurses into nested objects so only the deep leaf is highlighted', () => {
        const html = renderVersionDiffHtml({o: {x: 1, y: 2}}, {o: {x: 1, y: 9}});

        expect(html).toContain(key('x') + ' ' + num(1) + ',');
        expect(html).toContain(key('y') + ' ' + changed(num(2)));
        expect(html).not.toContain(changed('{'));
    });

    it('diffs arrays positionally: changed, added and removed elements', () => {
        const html = renderVersionDiffHtml([1, 5, 7], [1, 2]);

        expect(html).toBe('[\n  ' + num(1) + ',\n  ' + changed(num(5)) + ',\n  ' + changed(num(7)) + '\n]');
        const removed = renderVersionDiffHtml([1], [1, 2]);
        expect(removed).toBe('[\n  ' + num(1) + ',\n  <span class="aaxis-json-absent">' + num(2) + '</span>\n]');
    });

    it('renders empty containers compactly', () => {
        expect(renderVersionDiffHtml({}, {})).toBe('{}');
        expect(renderVersionDiffHtml([], [])).toBe('[]');
    });

    it('highlights a whole value when its type changed', () => {
        const html = renderVersionDiffHtml({v: [1]}, {v: {a: 1}});

        expect(html).toContain(key('v') + ' ' + changed('[\n    ' + num(1) + '\n  ]'));
    });

    it('treats a non-object previous side as "everything is new"', () => {
        const html = renderVersionDiffHtml({a: 1}, 'old');

        expect(html).toBe(changed('{\n  ' + key('a') + ' ' + num(1) + '\n}'));
    });

    it('indents continuation lines of nested new values to the depth of their key', () => {
        const html = renderVersionDiffHtml({o: {n: {k: 1}}}, {o: {}});

        expect(html).toContain(changed(key('n')) + ' ' + changed('{\n      ' + key('k') + ' ' + num(1) + '\n    }'));
    });
});

describe('pruneToDiff', () => {
    it('returns null for identical snapshots', () => {
        expect(pruneToDiff({a: [1, {b: 2}]}, {a: [1, {b: 2}]})).toBeNull();
    });

    it('keeps only the keys that differ, on both sides', () => {
        expect(pruneToDiff({a: 1, b: 2, c: 3}, {a: 1, b: 9, c: 3})).toEqual({sel: {b: 2}, prev: {b: 9}});
    });

    it('keeps added keys on the selected side and removed keys on the previous side only', () => {
        expect(pruneToDiff({a: 1, added: 2}, {a: 1, gone: 3})).toEqual({
            sel: {added: 2},
            prev: {gone: 3}
        });
    });

    it('prunes nested objects recursively', () => {
        expect(pruneToDiff({o: {x: 1, y: 2}, k: 1}, {o: {x: 1, y: 3}, k: 1})).toEqual({
            sel: {o: {y: 2}},
            prev: {o: {y: 3}}
        });
    });

    it('compacts arrays element by element, dropping equal pairs and keeping index alignment of kept pairs', () => {
        expect(pruneToDiff([1, 2, 3], [1, 9, 3])).toEqual({sel: [2], prev: [9]});
        expect(pruneToDiff([{a: 1, b: 1}, {a: 2}], [{a: 1, b: 1}, {a: 3}])).toEqual({
            sel: [{a: 2}],
            prev: [{a: 3}]
        });
    });

    it('keeps trailing elements only one side has', () => {
        expect(pruneToDiff([1, 2, 3], [1])).toEqual({sel: [2, 3], prev: []});
        expect(pruneToDiff([1], [1, 2, 3])).toEqual({sel: [], prev: [2, 3]});
    });

    it('keeps scalars and type mismatches whole', () => {
        expect(pruneToDiff(1, 2)).toEqual({sel: 1, prev: 2});
        expect(pruneToDiff({a: 1}, [1])).toEqual({sel: {a: 1}, prev: [1]});
        expect(pruneToDiff({a: {b: 1}}, {a: 'text'})).toEqual({sel: {a: {b: 1}}, prev: {a: 'text'}});
    });
});

import {describe, expect, it} from 'vitest';
import {
    buildClipboardDoc, CLIPBOARD_FORMAT, CLIPBOARD_VERSION, parseClipboardDoc, pastedName
} from '../../../../Resources/js-src/app/widgets/flow-clipboard';

const TYPES = {
    known: (type: string) => ['cron', 'subflow', 'logger', 'choice'].includes(type),
    trigger: (type: string) => type === 'cron' || type === 'subflow',
    ports: (type: string) => (type === 'choice' ? 2 : 1)
};

const step = (id: string, type = 'logger', extra: Record<string, any> = {}): any =>
    ({id, type, name: id, x: 0, y: 0, config: null, ...extra});

const doc = (steps: any[], links: any[] = [], extra: Record<string, any> = {}): string =>
    JSON.stringify({format: CLIPBOARD_FORMAT, version: CLIPBOARD_VERSION, steps, links, ...extra});

describe('flow-clipboard — buildClipboardDoc', () => {
    it('keeps positions relative to the selection, inner links only and deep-copied configs', () => {
        const config = {message: 'hi', nested: {a: 1}};
        const built = buildClipboardDoc([
            {id: 'a', type: 'logger', name: 'A', x: 300, y: 200, config, invalid: true},
            {id: 'b', type: 'choice', name: 'B', x: 500, y: 100, config: null, inSide: 'north', outSides: [undefined, 'east']}
        ], [
            {from: 'a', fromPort: 0, to: 'b'},
            {from: 'b', fromPort: 0, to: 'outside'},
            {from: 'outside', fromPort: 0, to: 'a'}
        ]);

        expect(built).toEqual({
            format: CLIPBOARD_FORMAT,
            version: CLIPBOARD_VERSION,
            steps: [
                {id: 'a', type: 'logger', name: 'A', x: 0, y: 100, config: {message: 'hi', nested: {a: 1}}, invalid: true},
                {id: 'b', type: 'choice', name: 'B', x: 200, y: 0, config: null, inSide: 'north', outSides: [null, 'east']}
            ],
            links: [{from: 'a', fromPort: 0, to: 'b'}]
        });
        expect(built.steps[0].config).not.toBe(config);
    });
});

describe('flow-clipboard — parseClipboardDoc', () => {
    it('reads back a valid copy, trimming names and dropping unknown keys', () => {
        const parsed = parseClipboardDoc(doc(
            [step('t', 'cron'), {...step('a'), name: '  A  ', extra: 1, invalid: true}],
            [{from: 't', fromPort: 0, to: 'a', junk: true}]
        ), TYPES);

        expect(parsed).toEqual({
            format: CLIPBOARD_FORMAT,
            version: CLIPBOARD_VERSION,
            steps: [
                {id: 't', type: 'cron', name: 't', x: 0, y: 0, config: null},
                {id: 'a', type: 'logger', name: 'A', x: 0, y: 0, config: null, invalid: true}
            ],
            links: [{from: 't', fromPort: 0, to: 'a'}]
        });
    });

    it('accepts a copy without links', () => {
        const parsed = parseClipboardDoc(JSON.stringify({format: CLIPBOARD_FORMAT, version: CLIPBOARD_VERSION, steps: [step('a')]}), TYPES);

        expect(parsed?.links).toEqual([]);
    });

    it.each([
        ['plain text', 'hello'],
        ['a JSON array', '[]'],
        ['another format', doc([step('a')], [], {format: 'something-else'})],
        ['another version', doc([step('a')], [], {version: 2})],
        ['no steps', doc([])],
        ['an unknown type', doc([step('a', 'teleport')])],
        ['a missing id', doc([{...step('a'), id: ''}])],
        ['a blank name', doc([{...step('a'), name: ' '}])],
        ['a name over 64 characters', doc([{...step('a'), name: 'x'.repeat(65)}])],
        ['a negative position', doc([{...step('a'), x: -10}])],
        ['a non-object config', doc([{...step('a'), config: [1]}])],
        ['a repeated id', doc([step('a'), {...step('a'), name: 'other'}])],
        ['a repeated name', doc([step('a'), {...step('b'), name: 'A'}])],
        ['two triggers', doc([step('t', 'cron'), step('s', 'subflow')])],
        ['links that are not a list', doc([step('a')], {} as any)],
        ['a link to a missing step', doc([step('a')], [{from: 'a', fromPort: 0, to: 'z'}])],
        ['a self link', doc([step('a')], [{from: 'a', fromPort: 0, to: 'a'}])],
        ['a port the step does not have', doc([step('a'), step('b')], [{from: 'a', fromPort: 1, to: 'b'}])],
        ['a link into a trigger', doc([step('a'), step('t', 'cron')], [{from: 'a', fromPort: 0, to: 't'}])],
        ['a port driving two links', doc([step('a'), step('b'), step('c')], [{from: 'a', fromPort: 0, to: 'b'}, {from: 'a', fromPort: 0, to: 'c'}])],
        ['two links into one step', doc([step('a'), step('b'), step('c')], [{from: 'a', fromPort: 0, to: 'c'}, {from: 'b', fromPort: 0, to: 'c'}])]
    ])('refuses %s', (_label, text) => {
        expect(parseClipboardDoc(text, TYPES)).toBeNull();
    });
});

describe('flow-clipboard — pastedName', () => {
    it('keeps a free non-trigger name and suffixes a taken one', () => {
        expect(pastedName('log', false, new Set(['other']))).toBe('log');
        expect(pastedName('Log', false, new Set(['log']))).toBe('Log (copy)');
        expect(pastedName('log', false, new Set(['log', 'log (copy)']))).toBe('log (copy 2)');
    });

    it('always suffixes a trigger, even when its name is free', () => {
        expect(pastedName('Nightly', true, new Set())).toBe('Nightly (copy)');
        expect(pastedName('Nightly', true, new Set(['nightly (copy)']))).toBe('Nightly (copy 2)');
    });

    it('trims the base so the result stays within 64 characters', () => {
        const name = pastedName('x'.repeat(64), true, new Set());

        expect(name).toBe(`${'x'.repeat(57)} (copy)`);
        expect(name).toHaveLength(64);
    });
});

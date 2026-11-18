import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {CLIPBOARD_FORMAT, CLIPBOARD_VERSION} from '../../../../Resources/js-src/app/widgets/flow-clipboard';
import {
    client, clickMenu, configOf, contextMenu, createEditor, designOf, FlowDialog, flowRecord, flush, linksOf, marquee,
    menuLabels, mockFetch, positionOf, selectedNames, st, tile, tileNames, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

/** The system clipboard as a plain variable the stubbed API reads and writes. */
function stubClipboard(initial = ''): {text: string} {
    const board = {text: initial};
    vi.stubGlobal('navigator', {clipboard: {
        writeText: vi.fn((text: string) => {
            board.text = text;
            return Promise.resolve();
        }),
        readText: vi.fn(() => Promise.resolve(board.text))
    }});
    return board;
}

function canvas(): HTMLElement {
    return document.querySelector('[data-role="canvas"]') as HTMLElement;
}

/** Right-clicks the empty canvas at canvas coordinates (x, y) and picks Paste. */
async function pasteAt(x: number, y: number): Promise<void> {
    contextMenu(canvas(), ...client(x, y));
    clickMenu(KEY('menu_paste'));
    await flush();
}

const copyDoc = (steps: any[], links: any[] = []): string =>
    JSON.stringify({format: CLIPBOARD_FORMAT, version: CLIPBOARD_VERSION, steps, links});

describe('OntologyFlowEditorComponent — copy / paste', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('copies the selection with its inner links to the clipboard', async () => {
        const board = stubClipboard();
        createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 0, 0, {enabled: true}),
            st('a', 'logger', 'log', 200, 100, {message: 'hi', log_event: false}),
            st('b', 'choice', 'pick', 400, 100, {expression: 'x'})
        ], [{from: 't', fromPort: 0, to: 'a'}, {from: 'a', fromPort: 0, to: 'b'}])});
        marquee(150, 50, 600, 300);

        contextMenu(tile('log'));
        expect(menuLabels().slice(0, 2)).toEqual([KEY('menu_remove'), KEY('menu_copy')]);
        clickMenu(KEY('menu_copy'));
        await flush();

        expect(JSON.parse(board.text)).toEqual({
            format: CLIPBOARD_FORMAT,
            version: CLIPBOARD_VERSION,
            steps: [
                {id: 'a', type: 'logger', name: 'log', x: 0, y: 0, config: {message: 'hi', log_event: false}},
                {id: 'b', type: 'choice', name: 'pick', x: 200, y: 0, config: {expression: 'x'}}
            ],
            links: [{from: 'a', fromPort: 0, to: 'b'}]
        });
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', KEY('copy_done'));
    });

    it('flashes an error when the browser refuses the copy', async () => {
        vi.stubGlobal('navigator', {clipboard: {writeText: () => Promise.reject(new Error('denied'))}});
        createEditor({flow: flowRecord(1, 'F', [st('a', 'logger', 'log', 0, 0)])});

        contextMenu(tile('log'));
        clickMenu(KEY('menu_copy'));
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('copy_failed'));
    });

    it('offers Paste only on the empty canvas', () => {
        createEditor({flow: flowRecord(1, 'F', [st('a', 'logger', 'log', 0, 0)])});

        contextMenu(canvas(), ...client(500, 400));
        expect(menuLabels()).toEqual([KEY('menu_paste')]);

        contextMenu(tile('log'));
        expect(menuLabels()).not.toContain(KEY('menu_paste'));
    });

    it('pastes a copy into the same flow at the click point, renaming taken names and relinking', async () => {
        stubClipboard();
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 0, 0, {enabled: true}),
            st('a', 'logger', 'log', 200, 0, {message: 'hi'}),
            st('b', 'logger', 'note', 400, 0, {message: 'yo'})
        ], [{from: 't', fromPort: 0, to: 'a'}, {from: 'a', fromPort: 0, to: 'b'}])});
        marquee(150, -10, 600, 90);
        contextMenu(tile('log'));
        clickMenu(KEY('menu_copy'));
        await flush();

        await pasteAt(300, 300);

        expect(tileNames()).toEqual(['tick', 'log', 'note', 'log (copy)', 'note (copy)']);
        expect(positionOf('log (copy)')).toEqual([300, 300]);
        expect(positionOf('note (copy)')).toEqual([500, 300]);
        expect(linksOf(editor)).toEqual(['tick:0->log', 'log:0->note', 'log (copy):0->note (copy)']);
        expect(configOf(editor, 'note (copy)')).toEqual({message: 'yo'});
        expect(selectedNames()).toEqual(['log (copy)', 'note (copy)']);
        expect(new Set(designOf(editor).steps.map((s: any) => s.id)).size).toBe(5);
        expect(tile('log (copy)').classList.contains('is-unreachable')).toBe(true);
    });

    it('suffixes a pasted trigger with (copy), past the names of other flows too', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: {records: [{id: 7, name: 'Nightly (copy)', type: 'flow'}]}}});
        stubClipboard(copyDoc([
            {id: 't', type: 'cron', name: 'Nightly', x: 0, y: 0, config: {enabled: true, mode: 'cron', expression: '* * * * *'}},
            {id: 'a', type: 'logger', name: 'log', x: 200, y: 0, config: {message: 'hi'}}
        ], [{from: 't', fromPort: 0, to: 'a'}]));
        const editor = createEditor({flow: flowRecord(null, '', [])});
        await flush();

        await pasteAt(0, 0);

        expect(tileNames()).toEqual(['Nightly (copy 2)', 'log']);
        expect(linksOf(editor)).toEqual(['Nightly (copy 2):0->log']);
        expect(configOf(editor, 'Nightly (copy 2)')).toEqual({enabled: true, mode: 'cron', expression: '* * * * *'});
        expect(tile('log').classList.contains('is-unreachable')).toBe(false);
    });

    it('leaves the copied trigger out when the flow already has one', async () => {
        stubClipboard(copyDoc([
            {id: 't', type: 'cron', name: 'Other', x: 0, y: 0, config: {enabled: true}},
            {id: 'a', type: 'logger', name: 'log', x: 200, y: 0, config: {message: 'hi'}, invalid: true}
        ], [{from: 't', fromPort: 0, to: 'a'}]));
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {enabled: true})])});

        await pasteAt(0, 200);

        expect(tileNames()).toEqual(['tick', 'log']);
        expect(linksOf(editor)).toEqual([]);
        expect(tile('log').classList.contains('is-config-invalid')).toBe(true);
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('warning', KEY('paste_trigger_skipped'));
    });

    it('replaces the sub-flow start marker when a trigger is pasted', async () => {
        stubClipboard(copyDoc([{id: 't', type: 'subflow', name: 'Entry', x: 0, y: 0, config: {enabled: true}}]));
        const editor = createEditor({flow: {id: 2, name: 'S', enabled: true, type: 'subflow', steps: [],
            design: {version: 2, steps: [st('a', 'logger', 'a', 300, 0)], links: [], start: 'a'}}});

        await pasteAt(0, 200);

        expect(tileNames()).toEqual(['a', 'Entry (copy)']);
        expect(designOf(editor).start).toBeNull();
    });

    it.each([
        ['plain text', 'just some text'],
        ['a copy of something else', JSON.stringify({format: 'other', version: 1, steps: []})]
    ])('explains in a popup that %s is not a valid copy', async (_label, text) => {
        stubClipboard(text);
        createEditor({flow: flowRecord(1, 'F', [st('a', 'logger', 'log', 0, 0)])});

        await pasteAt(400, 300);

        expect(tileNames()).toEqual(['log']);
        const dialog = FlowDialog.last();
        expect(dialog.options.title).toBe(KEY('menu_paste'));
        expect(dialog.$content.text()).toContain(KEY('paste_invalid'));
        dialog.$content.find('.btn-primary').trigger('click');
        expect(dialog.closed).toBe(true);
    });

    it('explains in a popup when the clipboard cannot be read', async () => {
        vi.stubGlobal('navigator', {clipboard: {readText: () => Promise.reject(new Error('denied'))}});
        createEditor({flow: flowRecord(1, 'F', [])});

        await pasteAt(400, 300);

        expect(FlowDialog.last().$content.text()).toContain(KEY('paste_unreadable'));
        expect($('[data-role="step"]').length).toBe(0);
    });
});

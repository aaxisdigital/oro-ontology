import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    clickMenu, contextMenu, createEditor, flowRecord, flush, menuLabels, mockFetch, root, sentTo, simpleFlow, st, tile,
    useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;
const STORE = 'aaxis.ontology.flowEditor.breakpoints';
const CRON = {enabled: true, mode: 'interval', value: 1, unit: 'day'};

const tick = (extra: Record<string, any> = {}): any => ({body: {
    success: true, context: {}, contextKey: 'k1', index: 0, total: 2, done: false,
    step: {id: 't1', name: 'nightly'}, executedIds: ['t1'], next: {id: 'd1', name: 'shape', type: 'dwl_transform'},
    frame: {flowId: 7, flowName: 'Nightly', depth: 0, canvasDepth: 0, innerId: null}, transition: null, ...extra
}});

function queue(...replies: any[]): () => any {
    let i = 0;
    return () => replies[Math.min(i++, replies.length - 1)];
}

const variableLines = (): string[] => $('.aaxis-flow-editor__debugger .aaxis-json-tree__more').map((_i: number, el: HTMLElement) => el.textContent).get();
const status = (): string => $('.aaxis-flow-editor__debugger-status').text();

describe('OntologyFlowEditorComponent — the Variables pane shows up to 100 entries', () => {
    useFlowEditorEnv(FlowEditorComponent);

    async function startWith(context: Record<string, any>, truncated: any): Promise<void> {
        mockFetch({aaxis_ontology_flow_debug_step: tick({context, truncated})});
        const editor = createEditor({flow: simpleFlow()});
        await flush();
        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
    }

    it('shows the true size of what the server cut and a line for the rest', async () => {
        await startWith({rows: Array.from({length: 100}, (_v, i) => i), flowUuid: 'u'}, {'/rows': 2500});

        const $rows = $('.aaxis-flow-editor__debugger .aaxis-json-tree__node--collapsible').first();
        expect($rows.find('> .aaxis-json-tree__line .aaxis-json-tree__preview').text()).toContain('2500 items');
        expect($rows.find('> .aaxis-json-tree__children > .aaxis-json-tree__node')).toHaveLength(100);
        expect(variableLines()).toEqual([KEY('debug_more_items')]);
    });

    it('cuts what it renders itself, at any depth and at the top level', async () => {
        const wide: Record<string, number> = {};
        for (let i = 0; i < 150; i++) {
            wide[`k${i}`] = i;
        }
        const top: Record<string, any> = {nested: {inner: wide}};
        for (let i = 0; i < 120; i++) {
            top[`v${i}`] = i;
        }
        await startWith(top, []);

        // 100 top-level entries + one line for the other 21; the nested object shows 100 of 150.
        expect($('.aaxis-flow-editor__debugger .aaxis-json-tree > .aaxis-json-tree__node')).toHaveLength(100);
        expect(variableLines()).toHaveLength(2);
        const $inner = $('.aaxis-flow-editor__debugger .aaxis-json-tree__node--collapsible').eq(1);
        expect($inner.find('> .aaxis-json-tree__line .aaxis-json-tree__preview').text()).toContain('150 keys');
        expect($inner.find('> .aaxis-json-tree__children > .aaxis-json-tree__node')).toHaveLength(100);
    });

    it('takes the cut-down context of a Run Now result too', async () => {
        mockFetch({aaxis_ontology_flow_debug: {body: {
            success: true, output: {rows: Array.from({length: 100}, (_v, i) => i)}, truncated: {'/rows': 300},
            executedIds: ['t1', 'd1'], contextKey: 'k'
        }}});
        const editor = createEditor({flow: simpleFlow()});
        await flush();

        root(editor).find('[data-role="debug"]').trigger('click');
        await flush();

        expect($('.aaxis-flow-editor__debugger .aaxis-json-tree__preview').first().text()).toContain('300 items');
    });
});

describe('OntologyFlowEditorComponent — debug breakpoints', () => {
    useFlowEditorEnv(FlowEditorComponent);

    function flow(id: number | null = 7, extra: any[] = []): any {
        return flowRecord(id, 'Main', [
            st('t1', 'cron', 'nightly', 0, 0, CRON),
            st('d1', 'dwl_transform', 'shape', 200, 0, {code: 'payload', destination: 'payload'}),
            st('l1', 'logger', 'log', 400, 0, {message: 'x'}),
            ...extra
        ], [{from: 't1', fromPort: 0, to: 'd1'}, {from: 'd1', fromPort: 0, to: 'l1'}]);
    }

    const stored = (): any => JSON.parse(window.localStorage.getItem(STORE) || '{}');

    it('toggles a breakpoint from the tile menu without touching the flow', async () => {
        const editor = createEditor({flow: flow()});
        await flush();

        contextMenu(tile('shape'));
        expect(menuLabels()).toContain(KEY('menu_breakpoint'));
        clickMenu(KEY('menu_breakpoint'));

        expect(tile('shape').classList.contains('has-breakpoint')).toBe(true);
        expect(stored()).toEqual({7: ['d1']});
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(true);
        contextMenu(tile('shape'));
        expect($('body > .aaxis-flow-editor__menu > .aaxis-flow-editor__menu-item--active').text()).toBe(KEY('menu_breakpoint'));
        clickMenu(KEY('menu_breakpoint'));
        expect(tile('shape').classList.contains('has-breakpoint')).toBe(false);
        expect(stored()).toEqual({});
    });

    it('is not offered on triggers or inner-flow boxes', async () => {
        createEditor({flow: flow(7, [st('b1', 'inner_flow', 'box', 0, 300, null)])});
        await flush();

        contextMenu(tile('nightly'));
        expect(menuLabels()).not.toContain(KEY('menu_breakpoint'));
        contextMenu(tile('box'));
        expect(menuLabels()).not.toContain(KEY('menu_breakpoint'));
    });

    it('restores the stored breakpoints, drops stale ones and forgets a removed step\'s', async () => {
        window.localStorage.setItem(STORE, JSON.stringify({7: ['d1', 'gone', 't1'], 9: ['x']}));
        createEditor({flow: flow()});
        await flush();

        expect(tile('shape').classList.contains('has-breakpoint')).toBe(true);
        expect(tile('nightly').classList.contains('has-breakpoint')).toBe(false);
        contextMenu(tile('shape'));
        clickMenu(KEY('menu_remove'));
        expect(stored()).toEqual({9: ['x']});
    });

    it('keeps an unsaved flow\'s breakpoints in memory and stores them on its first save', async () => {
        mockFetch({aaxis_ontology_flow_api_create: {body: {success: true, flow: {...flow(12), invalidSteps: []}}}});
        const editor = createEditor({flow: null});
        await flush();
        // Build the same canvas unsaved: restore the design onto the empty editor.
        editor.restoreFromDesign(flow().design);
        contextMenu(tile('log'));
        clickMenu(KEY('menu_breakpoint'));
        expect(stored()).toEqual({});

        root(editor).find('[data-role="save"]').prop('disabled', false).trigger('click');
        await flush();

        expect(stored()).toEqual({12: ['l1']});
    });

    it('keeps the breakpoints of a tab across tab switches', async () => {
        const editor = createEditor({flow: null});
        await flush();
        editor.restoreFromDesign(flow().design);
        contextMenu(tile('log'));
        clickMenu(KEY('menu_breakpoint'));

        root(editor).find('[data-role="tab-add"]').trigger('click');
        $('[data-role="tab"]').first().trigger('click');

        expect(tile('log').classList.contains('has-breakpoint')).toBe(true);
    });

    it('runs a new debug session straight to the first breakpoint and says so', async () => {
        window.localStorage.setItem(STORE, JSON.stringify({7: ['l1']}));
        const spy = mockFetch({aaxis_ontology_flow_debug_step: tick({
            index: 1, step: {id: 'd1', name: 'shape'}, executedIds: ['t1', 'd1'],
            next: {id: 'l1', name: 'log', type: 'logger'}, breakpoint: {id: 'l1', name: 'log', type: 'logger'}
        })});
        const editor = createEditor({flow: flow()});
        await flush();

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();

        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')[0]).toMatchObject({runAll: true, breakpoints: {root: ['l1']}});
        expect(status()).toContain(KEY('debug_paused_at'));
        expect(tile('log').classList.contains('is-debug-next')).toBe(true);
        expect(tile('nightly').classList.contains('is-debug-executed')).toBe(true);
        expect(tile('shape').classList.contains('is-debug-executed')).toBe(true);
    });

    it('starts paused after the trigger without breakpoints on the way', async () => {
        window.localStorage.setItem(STORE, JSON.stringify({99: ['zz']}));
        const spy = mockFetch({aaxis_ontology_flow_debug_step: tick()});
        const editor = createEditor({flow: flow()});
        await flush();

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();

        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')[0]).toMatchObject({runAll: false, breakpoints: {}});
    });

    it('sends the breakpoints of the subflows the flow may reach, transitively', async () => {
        window.localStorage.setItem(STORE, JSON.stringify({20: ['a'], 21: ['b'], 30: ['c']}));
        const spy = mockFetch({
            aaxis_ontology_flow_list: {body: {records: [
                {id: 20, name: 'Child', type: 'subflow', steps: [{type: 'sub_flow', name: 'deeper', config: {subflow: 21}}]},
                {id: 21, name: 'Grandchild', type: 'subflow', steps: []},
                {id: 30, name: 'Unrelated', type: 'subflow', steps: []}
            ]}},
            aaxis_ontology_flow_debug_step: queue(tick(), tick({index: 1}))
        });
        const editor = createEditor({flow: flow(7, [st('c1', 'sub_flow', 'call', 600, 0, {subflow: '20'})])});
        await flush();

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        $(`.aaxis-flow-editor__debug-btn[aria-label="${KEY('debug_run_all')}"]`).trigger('click');
        await flush();

        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')[0]).toMatchObject({runAll: true, breakpoints: {20: ['a'], 21: ['b']}});
        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')[1].breakpoints).toEqual({20: ['a'], 21: ['b']});
    });

    it('remembers what ran at home when a Continue pauses inside a subflow', async () => {
        window.localStorage.setItem(STORE, JSON.stringify({20: ['w']}));
        mockFetch({
            aaxis_ontology_flow_list: {body: {records: [
                {id: 20, name: 'Child', type: 'subflow', steps: [], design: {version: 2, steps: [
                    st('s', 'subflow', 'child', 0, 0, {enabled: true}), st('w', 'logger', 'work', 200, 0, {message: 'x'})
                ], links: [{from: 's', fromPort: 0, to: 'w'}]}}
            ]}},
            aaxis_ontology_flow_debug_step: tick({
                step: {id: 's', name: 'child'}, executedIds: ['s'], transition: 'entered',
                frame: {flowId: 20, flowName: 'Child', depth: 1, canvasDepth: 1, innerId: null},
                next: {id: 'w', name: 'work', type: 'logger'}, breakpoint: {id: 'w', name: 'work', type: 'logger'},
                rootExecutedIds: ['t1', 'd1'], subflowTrails: [{flowId: 20, flowName: 'Child', executedIds: ['s']}]
            })
        });
        const editor = createEditor({flow: flow(7, [st('c1', 'sub_flow', 'call', 600, 0, {subflow: '20'})])});
        await flush();

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();

        // On the subflow's tab, paused before "work"; home keeps nightly + shape for the way back.
        expect($('[data-role="tab"]')).toHaveLength(2);
        expect(tile('work').classList.contains('is-debug-next')).toBe(true);
        expect(editor.debugSession.marksByFlow.root).toEqual(['t1', 'd1']);
    });
});

describe('OntologyFlowEditorComponent — a stopped debug session closes its run on the server', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const stop = (): JQuery => $('.aaxis-flow-editor__debug-btn--stop');
    const stopCalls = (spy: any): Array<{key: any; keepalive: boolean}> => spy.mock.calls
        .filter((c: any[]) => String(c[0]) === '/aaxis_ontology_flow_debug_stop')
        .map((c: any[]) => ({key: JSON.parse(c[1].body).contextKey, keepalive: c[1].keepalive === true}));

    async function stepOnce(reply: any = tick()): Promise<{editor: any; spy: any}> {
        const spy = mockFetch({aaxis_ontology_flow_debug_step: reply, aaxis_ontology_flow_debug_stop: {body: {success: true, stopped: true}}});
        const editor = createEditor({flow: simpleFlow()});
        await flush();
        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        return {editor, spy};
    }

    it('sends the session key when the stop button closes an open walk', async () => {
        const {spy} = await stepOnce();

        stop().trigger('click');
        await flush();

        expect(stopCalls(spy)).toEqual([{key: 'k1', keepalive: false}]);
        expect($('[data-role="debugger"]').prop('hidden')).toBe(true);
    });

    it('stops nothing once the walk finished, failed, or for Run Now', async () => {
        const done = await stepOnce(tick({done: true, next: null}));
        stop().trigger('click');
        await flush();
        expect(stopCalls(done.spy)).toEqual([]);

        const failed = await stepOnce({status: 422, body: {success: false, message: 'boom'}});
        stop().trigger('click');
        await flush();
        expect(stopCalls(failed.spy)).toEqual([]);

        const spy = mockFetch({aaxis_ontology_flow_debug: {body: {success: true, output: {}, executedIds: [], contextKey: 'k9'}}});
        const editor = createEditor({flow: simpleFlow()});
        await flush();
        root(editor).find('[data-role="debug"]').trigger('click');
        await flush();
        stop().trigger('click');
        await flush();
        expect(stopCalls(spy)).toEqual([]);
    });

    it('lets a tick still running at close send the stop with the key it brings back', async () => {
        let release!: (reply: any) => void;
        const spy = mockFetch({
            aaxis_ontology_flow_debug_step: () => new Promise(resolve => {
                release = resolve;
            }),
            aaxis_ontology_flow_debug_stop: {body: {success: true, stopped: true}}
        });
        const editor = createEditor({flow: simpleFlow()});
        await flush();
        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();

        stop().trigger('click');
        await flush();
        expect(stopCalls(spy)).toEqual([]);

        release(tick({contextKey: 'k2'}));
        await flush();
        expect(stopCalls(spy)).toEqual([{key: 'k2', keepalive: false}]);
    });

    it('stops the open walk with a keepalive request when the page goes away or the editor is disposed', async () => {
        const {editor, spy} = await stepOnce();

        window.dispatchEvent(new Event('pagehide'));
        expect(stopCalls(spy)).toEqual([{key: 'k1', keepalive: true}]);

        editor.dispose();
        expect(stopCalls(spy)).toHaveLength(2);
        expect(stopCalls(spy)[1]).toEqual({key: 'k1', keepalive: true});
    });
});

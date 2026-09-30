import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    createEditor, deferred, field, FlowDialog, flowRecord, flush, mockFetch, root, sentTo, simpleFlow, st, tabTitles, tile,
    useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

/** Serves each call of a route from a queue of replies (the last one repeats). */
function queue(...replies: any[]): () => any {
    let i = 0;
    return () => replies[Math.min(i++, replies.length - 1)];
}

function status(): string {
    return $('.aaxis-flow-editor__debugger-status').text();
}

/** The debugger's action buttons: [aria-label, disabled]. */
function buttons(): Array<[string, boolean]> {
    return $('.aaxis-flow-editor__debug-btn').map((_i: number, el: HTMLButtonElement) =>
        [[el.getAttribute('aria-label'), el.disabled]]).get();
}

function clickButton(labelKey: string): void {
    $(`.aaxis-flow-editor__debug-btn[aria-label="${KEY(labelKey)}"]`).trigger('click');
}

function amber(): string[] {
    return $('[data-role="step"].is-debug-active .aaxis-flow-editor__step-name').map((_i: number, el: HTMLElement) => el.textContent).get();
}

const tick = (extra: Record<string, any> = {}): any => ({body: {
    success: true, context: {payload: {a: 1}}, contextKey: 'k1', index: 0, total: 2, done: false,
    step: {id: 't1', name: 'nightly'}, executedIds: ['t1'], next: {id: 'd1', name: 'shape', type: 'dwl_transform'},
    frame: {flowId: 7, flowName: 'Nightly', depth: 0}, transition: null, ...extra
}});

describe('OntologyFlowEditorComponent — Run Now', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('runs the whole flow in one request and shows the sidebar with the final context', async () => {
        vi.useFakeTimers();
        const reply = deferred<any>();
        const spy = mockFetch({aaxis_ontology_flow_debug: reply.promise});
        const editor = createEditor({flow: simpleFlow()});
        const $run = root(editor).find('[data-role="debug"]');

        $run.trigger('click');
        expect($run.find('.fa-spinner')).toHaveLength(1);
        expect($run.prop('disabled')).toBe(true);
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(true);
        expect(sentTo(spy, 'aaxis_ontology_flow_debug')).toEqual([{
            flowId: 7,
            steps: [
                {id: 't1', type: 'cron', name: 'nightly', config: {enabled: true, mode: 'interval', value: 1, unit: 'day'}},
                {id: 'd1', type: 'dwl_transform', name: 'shape', config: {code: 'payload', destination: 'payload'}}
            ],
            links: [{from: 't1', fromPort: 0, to: 'd1'}],
            input: {}
        }]);

        reply.resolve({body: {success: true, output: {flowUuid: 'u', payload: [1, 2]}, contextKey: 'k9', executedIds: ['t1', 'd1']}});
        await flush();

        expect($run.find('.fa-spinner')).toHaveLength(0);
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(false);
        expect($('.aaxis-flow-editor__debugger-mode').text()).toBe(KEY('run_now_title'));
        expect(status()).toBe(` — ${KEY('debug_finished')}`);
        expect(amber()).toEqual(['nightly', 'shape']);
        // Variables: one line per entry, structures collapsed with a count preview.
        const $vars = $('.aaxis-flow-editor__debugger-vars > .aaxis-json-tree > .aaxis-json-tree__node');
        expect($vars).toHaveLength(2);
        expect($vars.eq(0).text()).toBe('"flowUuid": "u"');
        expect($vars.eq(1).hasClass('is-collapsed')).toBe(true);
        expect($vars.eq(1).find('.aaxis-json-tree__preview').first().text()).toBe(' … 2 items … ]');
        $vars.eq(1).find('.aaxis-json-tree__line').first().trigger('click');
        expect($vars.eq(1).hasClass('is-collapsed')).toBe(false);
        // Finished cleanly: a single (disabled) stop button over the auto-close countdown.
        expect(buttons()).toEqual([[KEY('close'), true]]);
        expect($('[data-role="debug-autoclose-bar"]')[0].style.width).toBe('100%');

        vi.advanceTimersByTime(7500);
        expect($('[data-role="debug-autoclose-bar"]')[0].style.width).toBe('50%');
        vi.advanceTimersByTime(7600);
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(true);
        expect(amber()).toEqual([]);
        expect($run.prop('disabled')).toBe(false);
    });

    it('keeps a failed run open with the failing step red', async () => {
        mockFetch({aaxis_ontology_flow_debug: {status: 422, body: {success: false, message: 'boom', executedIds: ['t1'], failedStepId: 'd1'}}});
        const editor = createEditor({flow: simpleFlow()});

        root(editor).find('[data-role="debug"]').trigger('click');
        await flush();

        expect(status()).toBe(` — ${KEY('debug_failed')}: boom`);
        expect($('.aaxis-flow-editor__debugger-status').hasClass('is-error')).toBe(true);
        expect(amber()).toEqual(['nightly']);
        expect(tile('shape').classList.contains('is-debug-failed')).toBe(true);
        expect(buttons()).toEqual([[KEY('close'), false]]);
        expect($('[data-role="debug-autoclose-bar"]')).toHaveLength(0);

        clickButton('close');
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(true);
        expect(tile('shape').classList.contains('is-debug-failed')).toBe(false);
    });

    it('falls back to the generic error text', async () => {
        mockFetch({aaxis_ontology_flow_debug: {status: 500, body: null}});
        const editor = createEditor({flow: simpleFlow()});

        root(editor).find('[data-role="debug"]').trigger('click');
        await flush();

        expect(status()).toBe(` — ${KEY('debug_failed')}: ${KEY('debug_error')}`);
        expect(amber()).toEqual([]);
    });

    it('never auto-closes when configured with 0 seconds', async () => {
        vi.useFakeTimers();
        mockFetch({aaxis_ontology_flow_debug: {body: {success: true, output: null}}});
        const editor = createEditor({flow: simpleFlow(null), debugAutoCloseSeconds: 0});

        root(editor).find('[data-role="debug"]').trigger('click');
        await flush();
        vi.advanceTimersByTime(60000);

        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(false);
        expect(buttons()).toEqual([[KEY('close'), false]]);
        // An empty context still renders an (empty) object.
        expect($('.aaxis-flow-editor__debugger-vars').text()).toBe('{}');
    });

    it('dismisses the countdown on a click outside the debugger pane', async () => {
        mockFetch({aaxis_ontology_flow_debug: {body: {success: true, output: {}}}});
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="debug"]').trigger('click');
        await flush();

        $('.aaxis-flow-editor__debugger-vars').trigger('click');
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(false);
        root(editor).find('[data-role="canvas"]').trigger('click');
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(true);
    });

    it('ignores a response that arrives after the session was closed', async () => {
        const reply = deferred<any>();
        mockFetch({aaxis_ontology_flow_debug: reply.promise});
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="debug"]').trigger('click');

        // Switching tabs closes the session.
        root(editor).find('[data-role="tab-add"]').trigger('click');
        reply.resolve({body: {success: true, output: {a: 1}, executedIds: ['t1']}});
        await flush();

        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(true);
        expect(root(editor).find('[data-role="debug"] .fa-spinner')).toHaveLength(0);
    });

    it('ignores a failure that arrives after the session was closed', async () => {
        const reply = deferred<any>();
        mockFetch({aaxis_ontology_flow_debug: reply.promise});
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="debug"]').trigger('click');
        root(editor).find('[data-role="tab-add"]').trigger('click');

        reply.resolve({status: 500, body: {success: false, message: 'late'}});
        await flush();

        expect($('.aaxis-flow-editor__debugger-status')).toHaveLength(0);
    });

    it('does nothing without a trigger', () => {
        const spy = mockFetch();
        const editor = createEditor({flow: flowRecord(1, 'F', [st('l', 'logger', 'log', 0, 0)])});

        root(editor).find('[data-role="debug"]').trigger('click');

        expect(sentTo(spy, 'aaxis_ontology_flow_debug')).toEqual([]);
    });
});

describe('OntologyFlowEditorComponent — entity-change debug input', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const changeFlow = (): any => flowRecord(5, 'C', [st('t', 'entity_change', 'changed', 0, 0, {enabled: true, system: 'crm', entity: 'order'})]);

    it('asks for the event (prefilled from the trigger) and runs with it', async () => {
        const spy = mockFetch({aaxis_ontology_flow_debug: {body: {success: true, output: {}}}});
        const editor = createEditor({flow: changeFlow()});
        await flush();

        root(editor).find('[data-role="debug"]').trigger('click');
        await flush();
        const dialog = FlowDialog.last();
        expect(dialog.options.title).toBe(KEY('debug_input_title'));
        expect(field('config_system_label').val()).toBe('crm');
        expect(field('config_entity_label').val()).toBe('order');

        dialog.$content.find('textarea').val('{bad');
        dialog.$content.find('.btn-primary').trigger('click');
        expect(dialog.$content.find('.aaxis-flow-editor__settings-error').text()).toBe(KEY('debug_payload_invalid'));
        dialog.$content.find('textarea').val('{"id": 3}');
        dialog.$content.find('.btn-primary').trigger('click');
        await flush();

        expect(dialog.closed).toBe(true);
        expect(sentTo(spy, 'aaxis_ontology_flow_debug')[0].input).toEqual({payload: {id: 3}, system: 'crm', entity: 'order'});
    });

    it('requires a system and entity, sends a null payload when left empty, and can be cancelled', async () => {
        const spy = mockFetch({aaxis_ontology_flow_debug_step: tick()});
        const flow = changeFlow();
        flow.design.steps[0].config = {enabled: true};
        const editor = createEditor({flow});
        await flush();

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        let dialog = FlowDialog.last();
        dialog.$content.find('.btn-primary').trigger('click');
        expect(dialog.$content.find('.aaxis-flow-editor__settings-error').text()).toBe(KEY('config_system_entity_required'));
        dialog.$content.find('.btn').not('.btn-primary').trigger('click');
        expect(dialog.closed).toBe(true);
        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')).toEqual([]);

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        dialog = FlowDialog.last();
        field('config_system_label').val('erp').trigger('change');
        field('config_entity_label').val('item');
        dialog.$content.find('.btn-primary').trigger('click');
        await flush();
        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')[0].input).toEqual({payload: null, system: 'erp', entity: 'item'});
    });
});

describe('OntologyFlowEditorComponent — step-by-step Debug', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('walks one step per request, round-tripping only the context handle', async () => {
        vi.useFakeTimers();
        const spy = mockFetch({aaxis_ontology_flow_debug_step: queue(
            tick(),
            tick({index: 1, done: true, step: {id: 'd1', name: 'shape'}, executedIds: ['d1'], next: null, contextKey: 'k2',
                context: {payload: 'done', n: null, ok: true, count: 3}})
        )});
        const editor = createEditor({flow: simpleFlow(), debugAutoCloseSeconds: 2});

        root(editor).find('[data-role="debug-step"]').trigger('click');
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(false);
        expect($('.aaxis-flow-editor__debugger-mode').text()).toBe(KEY('debug_step_title'));
        expect(status()).toBe(` — ${KEY('debug_running')}`);
        expect($('.aaxis-flow-editor__debugger-empty').text()).toBe(KEY('debug_not_started'));
        expect($('.aaxis-flow-editor__debug-btn .fa-spinner')).toHaveLength(1);
        expect(root(editor).find('[data-role="debug-step"]').prop('disabled')).toBe(true);
        await flush();

        expect(status()).toBe(' — nightly (1/2)');
        expect(amber()).toEqual(['nightly']);
        expect(buttons()).toEqual([
            [KEY('debug_run_all'), false], [KEY('debug_next_step'), false], [KEY('debug_step_into'), true],
            [KEY('debug_step_out'), true], [KEY('cancel'), false]
        ]);
        expect($('.aaxis-flow-editor__debug-btn svg')).toHaveLength(5);
        expect($('.aaxis-flow-editor__debug-btn--stop')).toHaveLength(1);

        clickButton('debug_next_step');
        await flush();
        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')).toEqual([
            expect.objectContaining({flowId: 7, contextKey: null, runAll: false, stepInto: false, stepOut: false, input: {}}),
            expect.objectContaining({contextKey: 'k1', runAll: false, stepInto: false, stepOut: false})
        ]);
        expect(status()).toBe(` — ${KEY('debug_finished')}`);
        expect(amber()).toEqual(['nightly', 'shape']);
        expect(buttons().every(([, disabled]) => disabled)).toBe(true);
        const primitives = $('.aaxis-flow-editor__debugger-vars .aaxis-json-tree__node > .aaxis-json-tree__line > span:last-child')
            .map((_i: number, el: HTMLElement) => el.className).get();
        expect(primitives).toEqual(['aaxis-json-str', 'aaxis-json-null', 'aaxis-json-bool', 'aaxis-json-num']);

        vi.advanceTimersByTime(2100);
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(true);
    });

    it('runs the rest in one request from Run all, with the spinner on that button', async () => {
        const second = deferred<any>();
        const spy = mockFetch({aaxis_ontology_flow_debug_step: queue(tick(), second.promise)});
        const editor = createEditor({flow: simpleFlow(), debugAutoCloseSeconds: 0});
        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();

        clickButton('debug_run_all');
        expect($(`.aaxis-flow-editor__debug-btn[aria-label="${KEY('debug_run_all')}"] .fa-spinner`)).toHaveLength(1);
        second.resolve(tick({done: true, index: 1}));
        await flush();

        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')[1]).toMatchObject({runAll: true});
        expect(buttons().at(-1)).toEqual([KEY('close'), false]);
        clickButton('close');
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(true);
    });

    it('steps into a subflow invoker and follows the frame to the subflow\'s tab', async () => {
        const child = flowRecord(20, 'Child', [st('e', 'subflow', 'entry', 0, 0, {enabled: true}), st('w', 'logger', 'work', 200, 0)],
            [{from: 'e', fromPort: 0, to: 'w'}], {type: 'subflow'});
        const main = flowRecord(7, 'Main', [
            st('t1', 'cron', 'go', 0, 0, {enabled: true}), st('s1', 'sub_flow', 'call', 200, 0, {subflow: 20})
        ], [{from: 't1', fromPort: 0, to: 's1'}]);
        const into = deferred<any>();
        const spy = mockFetch({
            aaxis_ontology_flow_list: {body: {records: [child]}},
            aaxis_ontology_flow_debug_step: queue(
                tick({step: {id: 't1', name: 'go'}, next: {id: 's1', name: 'call', type: 'sub_flow'}}),
                into.promise,
                tick({index: 1, step: {id: 'w', name: 'work'}, executedIds: ['w'], frame: {flowId: 20, flowName: 'Child', depth: 1},
                    iteration: 0, next: null}),
                tick({index: 1, step: {id: 's1', name: 'call'}, executedIds: ['s1'], transition: 'returned', frame: {flowId: 7, flowName: 'Main', depth: 0},
                    subflowTrails: [{flowId: 20, flowName: 'Child', executedIds: ['e', 'w']}], next: null})
            )
        });
        const editor = createEditor({flow: main, debugAutoCloseSeconds: 0});
        await flush();

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        expect(buttons()[2]).toEqual([KEY('debug_step_into'), false]);
        clickButton('debug_step_into');
        expect($(`.aaxis-flow-editor__debug-btn[aria-label="${KEY('debug_step_into')}"] .fa-spinner`)).toHaveLength(1);
        into.resolve(tick({step: {id: 'e', name: 'entry'}, executedIds: ['e'], transition: 'entered',
            frame: {flowId: 20, flowName: 'Child', depth: 1}, next: {id: 'w', name: 'work', type: 'logger'}}));
        await flush();

        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')[1]).toMatchObject({stepInto: true});
        expect(tabTitles()).toEqual(['go', 'entry']);
        expect($('[data-role="tab"].is-active .aaxis-flow-editor__tab-title').text()).toBe('entry');
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(false);
        expect(status()).toBe(' — Child: entry (1/2)');
        expect(amber()).toEqual(['entry']);
        expect(buttons()[3]).toEqual([KEY('debug_step_out'), false]);

        clickButton('debug_next_step');
        await flush();
        expect(status()).toBe(` — Child: work (2/2) [${KEY('debug_iteration')} 1]`);
        expect(amber()).toEqual(['entry', 'work']);

        clickButton('debug_step_out');
        await flush();
        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')[3]).toMatchObject({stepOut: true});
        expect($('[data-role="tab"].is-active .aaxis-flow-editor__tab-title').text()).toBe('go');
        expect(amber()).toEqual(['go', 'call']);

        // The subflow's marks were remembered silently: revisiting its tab shows them.
        $('[data-role="tab"]').eq(1).trigger('click');
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(true);
    });

    it('stays on the current canvas when the frame\'s flow cannot be opened', async () => {
        mockFetch({aaxis_ontology_flow_debug_step: tick({step: {id: 'x', name: 'ghost'}, executedIds: ['x'],
            frame: {flowId: 404, flowName: 'Gone', depth: 1}})});
        const editor = createEditor({flow: simpleFlow(), debugAutoCloseSeconds: 0});

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();

        expect(tabTitles()).toEqual(['nightly']);
        expect(status()).toBe(' — Gone: ghost (1/2)');
        expect(amber()).toEqual([]);
    });

    it('wipes the subflow canvas for each new foreach iteration', async () => {
        const child = flowRecord(20, 'Child', [st('e', 'subflow', 'entry', 0, 0, {enabled: true}), st('w', 'logger', 'work', 200, 0)],
            [{from: 'e', fromPort: 0, to: 'w'}], {type: 'subflow'});
        const main = flowRecord(7, 'Main', [st('t1', 'cron', 'go', 0, 0, {enabled: true}), st('f1', 'foreach', 'loop', 200, 0, {subflow: 20})]);
        mockFetch({
            aaxis_ontology_flow_list: {body: {records: [child]}},
            aaxis_ontology_flow_debug_step: queue(
                tick({step: {id: 'w', name: 'work'}, executedIds: ['e', 'w'], frame: {flowId: 20, flowName: 'Child', depth: 1}}),
                tick({step: {id: 'e', name: 'entry'}, executedIds: ['e'], transition: 'reentered', iteration: 1,
                    frame: {flowId: 20, flowName: 'Child', depth: 1}})
            )
        });
        const editor = createEditor({flow: main, debugAutoCloseSeconds: 0});
        await flush();
        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        expect(amber()).toEqual(['entry', 'work']);

        clickButton('debug_next_step');
        await flush();

        expect(amber()).toEqual(['entry']);
        expect(status()).toBe(` — Child: entry (1/2) [${KEY('debug_iteration')} 2]`);
    });

    it('visits the subflows a stepped-over invoker ran, then comes home and repaints', async () => {
        vi.useFakeTimers();
        const child = flowRecord(20, 'Child', [st('e', 'subflow', 'entry', 0, 0, {enabled: true})], [], {type: 'subflow'});
        const main = flowRecord(7, 'Main', [
            st('t1', 'cron', 'go', 0, 0, {enabled: true}), st('s1', 'sub_flow', 'call', 200, 0, {subflow: 20})
        ], [{from: 't1', fromPort: 0, to: 's1'}]);
        mockFetch({
            aaxis_ontology_flow_list: {body: {records: [child]}},
            aaxis_ontology_flow_debug_step: tick({step: {id: 's1', name: 'call'}, executedIds: ['t1', 's1'],
                subflowTrails: [{flowId: 404, executedIds: []}, {flowId: 20, flowName: 'Child', executedIds: ['e']}]})
        });
        const editor = createEditor({flow: main, debugAutoCloseSeconds: 0});
        await flush();

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        expect($('[data-role="tab"].is-active .aaxis-flow-editor__tab-title').text()).toBe('entry');
        expect(amber()).toEqual(['entry']);
        expect(buttons().slice(0, 4).every(([, disabled]) => disabled)).toBe(true);
        // The visit owns the busy state; its spinner sits on Next step.
        expect($(`.aaxis-flow-editor__debug-btn[aria-label="${KEY('debug_next_step')}"] .fa-spinner`)).toHaveLength(1);
        expect(status()).toBe(` — ${KEY('debug_running')}`);

        await vi.advanceTimersByTimeAsync(1700);
        expect($('[data-role="tab"].is-active .aaxis-flow-editor__tab-title').text()).toBe('go');
        expect(amber()).toEqual(['go', 'call']);
        expect(buttons()[1]).toEqual([KEY('debug_next_step'), false]);
    });

    it('paints a failing tick and visits the failure\'s subflow trails too', async () => {
        vi.useFakeTimers();
        const child = flowRecord(20, 'Child', [st('e', 'subflow', 'entry', 0, 0, {enabled: true})], [], {type: 'subflow'});
        const main = flowRecord(7, 'Main', [
            st('t1', 'cron', 'go', 0, 0, {enabled: true}), st('s1', 'sub_flow', 'call', 200, 0, {subflow: 20})
        ], [{from: 't1', fromPort: 0, to: 's1'}]);
        mockFetch({
            aaxis_ontology_flow_list: {body: {records: [child]}},
            aaxis_ontology_flow_debug_step: {status: 422, body: {success: false, message: 'inner failure', executedIds: ['t1'],
                failedStepId: 's1', subflowTrails: [{flowId: 20, executedIds: ['e']}]}}
        });
        const editor = createEditor({flow: main, debugAutoCloseSeconds: 0});
        await flush();

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        await vi.advanceTimersByTimeAsync(1700);

        expect($('[data-role="tab"].is-active .aaxis-flow-editor__tab-title').text()).toBe('go');
        // (The failing tick's executed ids are not repainted after the visit — reported, not pinned.)
        expect(tile('call').classList.contains('is-debug-failed')).toBe(true);
        expect(status()).toBe(` — ${KEY('debug_failed')}: inner failure`);
        expect(buttons().at(-1)).toEqual([KEY('close'), false]);
    });

    it('stops visiting when the session is closed mid-visit', async () => {
        vi.useFakeTimers();
        const child = flowRecord(20, 'Child', [st('e', 'subflow', 'entry', 0, 0, {enabled: true})], [], {type: 'subflow'});
        const main = flowRecord(7, 'Main', [st('t1', 'cron', 'go', 0, 0, {enabled: true})]);
        mockFetch({
            aaxis_ontology_flow_list: {body: {records: [child]}},
            aaxis_ontology_flow_debug_step: tick({subflowTrails: [{flowId: 20, executedIds: ['e']}]})
        });
        const editor = createEditor({flow: main, debugAutoCloseSeconds: 0});
        await flush();
        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();

        clickButton('cancel');
        await vi.advanceTimersByTimeAsync(1700);

        expect($('[data-role="tab"].is-active .aaxis-flow-editor__tab-title').text()).toBe('go');
        expect(amber()).toEqual([]);
    });

    it('shows a step request failure in the sidebar', async () => {
        mockFetch({aaxis_ontology_flow_debug_step: new Error('offline')});
        const editor = createEditor({flow: simpleFlow()});

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();

        expect(status()).toBe(` — ${KEY('debug_failed')}: offline`);
        expect(buttons().at(-1)).toEqual([KEY('close'), false]);
        expect(root(editor).find('[data-role="debug"]').prop('disabled')).toBe(true);
    });

    it('ignores a step response for a closed session', async () => {
        const reply = deferred<any>();
        mockFetch({aaxis_ontology_flow_debug_step: reply.promise});
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="debug-step"]').trigger('click');
        clickButton('cancel');

        reply.resolve(tick());
        await flush();

        expect(amber()).toEqual([]);
        expect(root(editor).find('[data-role="debugger"]').prop('hidden')).toBe(true);
    });
});

describe('OntologyFlowEditorComponent — the debugger\'s DWL evaluator', () => {
    useFlowEditorEnv(FlowEditorComponent);

    async function started(evalReply: any): Promise<any> {
        const spy = mockFetch({aaxis_ontology_flow_debug_step: tick(), aaxis_ontology_flow_debug_eval: evalReply});
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        return spy;
    }

    function evaluate(expression: string): void {
        $('.aaxis-flow-editor__debugger-eval-editor').val(expression);
        $('.aaxis-flow-editor__debugger-eval-run').trigger('click');
    }

    it('is disabled until a context exists', async () => {
        const reply = deferred<any>();
        mockFetch({aaxis_ontology_flow_debug_step: reply.promise});
        const editor = createEditor({flow: simpleFlow()});

        root(editor).find('[data-role="debug-step"]').trigger('click');
        expect($('.aaxis-flow-editor__debugger-eval-run').prop('disabled')).toBe(true);
        reply.resolve(tick());
        await flush();
        expect($('.aaxis-flow-editor__debugger-eval-run').prop('disabled')).toBe(false);
    });

    it('evaluates against the session context and shows the result as a JSON tree', async () => {
        const spy = await started({body: {success: true, output: {name: 'x', tags: ['a'], empty: [], none: {}, n: 1.5}}});

        evaluate('  payload  ');
        expect($('.aaxis-flow-editor__debugger-eval-run').prop('disabled')).toBe(true);
        await flush();

        expect(sentTo(spy, 'aaxis_ontology_flow_debug_eval')).toEqual([{expression: 'payload', contextKey: 'k1'}]);
        expect(FlowDialog.last().options.title).toBe(KEY('debug_eval_label'));
        const $tree = FlowDialog.last().$content.find('.aaxis-json-tree');
        expect($tree.text().replaceAll(/\s+/g, ' ')).toBe(
            '{ … 5 keys … }"name": "x","tags": [ … 1 item … ],"a"],"empty": [],"none": {},"n": 1.5}'
        );
        const $root = $tree.children('.aaxis-json-tree__node');
        expect($root.hasClass('is-collapsed')).toBe(false);
        $root.children('.aaxis-json-tree__line').trigger('click');
        expect($root.hasClass('is-collapsed')).toBe(true);
        expect($('.aaxis-flow-editor__debugger-eval-run').prop('disabled')).toBe(false);
    });

    it('renders a scalar or null result', async () => {
        await started({body: {success: true, output: null}});

        evaluate('null');
        await flush();

        expect(FlowDialog.last().$content.find('.aaxis-json-null').text()).toBe('null');
    });

    it('shows the engine error in the dialog', async () => {
        await started({status: 422, body: {success: false, message: 'Unexpected token'}});

        evaluate('payload.');
        await flush();

        expect(FlowDialog.last().$content.find('.aaxis-debug-eval-error').text()).toBe('Unexpected token');
    });

    it('falls back to the generic message without one', async () => {
        await started({status: 500, body: null});

        evaluate('x');
        await flush();

        expect(FlowDialog.last().$content.find('.aaxis-debug-eval-error').text()).toBe(KEY('debug_error'));
    });

    it('flashes a request failure and skips an empty expression', async () => {
        const spy = await started(new Error('offline'));

        evaluate('   ');
        expect(sentTo(spy, 'aaxis_ontology_flow_debug_eval')).toEqual([]);
        evaluate('x');
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('debug_error'));
        expect(FlowDialog.instances).toHaveLength(0);
    });
});

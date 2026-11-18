import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import mediator from 'oroui/js/mediator';
import pageStateChecker from 'oronavigation/js/app/services/page-state-checker';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    createEditor, deferred, designOf, dropFromToolbox, FlowDialog, flowRecord, flush, mockFetch, pointer, root, sentTo,
    simpleFlow, st, tabTitles, tile, tileNames, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

function saved(flow: any): any {
    return {body: {success: true, flow}};
}

describe('OntologyFlowEditorComponent — saving', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('refuses to save a flow without a trigger', () => {
        const spy = mockFetch();
        const editor = createEditor();
        dropFromToolbox('logger', 140, 140);

        root(editor).find('[data-role="save"]').trigger('click');

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('trigger_required'));
        expect(sentTo(spy, 'aaxis_ontology_flow_api_create')).toEqual([]);
    });

    it('creates a new flow, adopts its id into the URL and applies the server\'s red steps', async () => {
        const reply = deferred<any>();
        const spy = mockFetch({aaxis_ontology_flow_api_create: reply.promise});
        const editor = createEditor();
        dropFromToolbox('cron', 140, 140);
        dropFromToolbox('logger', 540, 140);
        const $save = root(editor).find('[data-role="save"]');

        $save.trigger('click');
        expect($save.prop('disabled')).toBe(true);
        expect($save.find('.fa-spinner')).toHaveLength(1);
        const [body] = sentTo(spy, 'aaxis_ontology_flow_api_create');
        expect(body.steps).toEqual([
            {type: 'cron', name: 'schedule-1', x: 100, y: 100, config: {enabled: true}},
            {type: 'logger', name: 'logger-1', x: 500, y: 100, config: null}
        ]);
        expect(body.design).toEqual(designOf(editor));
        expect(spy.mock.calls.at(-1)[1]).toMatchObject({method: 'POST', credentials: 'same-origin'});

        reply.resolve(saved({id: 12, name: 'schedule-1', type: 'flow', enabled: true, invalidSteps: ['logger-1']}));
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', KEY('saved'));
        expect($save.find('.fa-spinner')).toHaveLength(0);
        expect($save.prop('disabled')).toBe(true);
        expect(tile('logger-1').classList.contains('is-config-invalid')).toBe(true);
        expect(window.location.pathname + window.location.search).toBe('/aaxis_ontology_flow_editor?id=12');
        expect(root(editor).find('[data-role="flow-history"]').prop('disabled')).toBe(false);
        expect($('[data-role="tab"] .aaxis-flow-editor__tab-dirty')).toHaveLength(0);
    });

    it('updates an existing flow in place', async () => {
        const spy = mockFetch({aaxis_ontology_flow_api_update: saved(simpleFlow())});
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);

        root(editor).find('[data-role="save"]').trigger('click');
        await flush();

        expect(spy.mock.calls.at(-1)[0]).toBe('/aaxis_ontology_flow_api_update?id=7');
        expect(spy.mock.calls.at(-1)[1].method).toBe('PUT');
        expect(window.location.search).toBe('');
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(true);
    });

    it.each([
        ['the server message', {status: 422, body: {success: false, message: 'name taken'}}, 'name taken'],
        ['a generic message', {status: 500, body: null}, KEY('save_error')],
        ['the transport error', new Error('offline'), 'offline']
    ])('flashes %s when saving fails and keeps the changes pending', async (_label, reply, message) => {
        mockFetch({aaxis_ontology_flow_api_update: reply});
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);

        root(editor).find('[data-role="save"]').trigger('click');
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', message);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
        expect(root(editor).find('[data-role="save"] .fa-spinner')).toHaveLength(0);
    });

    it('saves every dirty tab sequentially with Save all, reporting per-tab failures', async () => {
        const update = deferred<any>();
        const spy = mockFetch({
            aaxis_ontology_flow_api_update: update.promise,
            aaxis_ontology_flow_api_create: saved({id: 30, name: 'schedule-1', type: 'flow', enabled: true, invalidSteps: []})
        });
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);
        root(editor).find('[data-role="tab-add"]').trigger('click');
        dropFromToolbox('logger', 140, 140); // no trigger: cannot be saved
        root(editor).find('[data-role="tab-add"]').trigger('click');
        dropFromToolbox('cron', 140, 140);
        const $saveAll = root(editor).find('[data-role="save-all"]');

        $saveAll.trigger('click');
        expect($saveAll.find('.fa-spinner')).toHaveLength(1);
        expect($saveAll.prop('disabled')).toBe(true);
        $saveAll.trigger('click'); // ignored while saving
        expect(sentTo(spy, 'aaxis_ontology_flow_api_update')).toHaveLength(1);
        expect(sentTo(spy, 'aaxis_ontology_flow_api_update')[0].steps.map((s: any) => s.name)).toEqual(['nightly', 'shape', 'logger-1']);
        update.resolve(saved({...simpleFlow(), invalidSteps: ['shape']}));
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', `${KEY('name_unset')}: ${KEY('trigger_required')}`);
        expect(sentTo(spy, 'aaxis_ontology_flow_api_create')).toHaveLength(1);
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', KEY('saved_all'));
        expect($saveAll.find('.fa-spinner')).toHaveLength(0);
        expect($('[data-role="tab"]').map((i: number, el: HTMLElement) => $(el).find('.aaxis-flow-editor__tab-dirty').length).get())
            .toEqual([0, 1, 0]);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(true);
        expect(window.location.search).toBe('?id=30?tabs=7,30');

        $('[data-role="tab"]').eq(0).trigger('click');
        expect(tile('shape').classList.contains('is-config-invalid')).toBe(true);
    });

    it('flashes each failed tab of Save all with its title, and nothing when no tab is dirty', async () => {
        const spy = mockFetch({aaxis_ontology_flow_api_update: {status: 422, body: {success: false}}});
        const editor = createEditor({flow: simpleFlow()});

        root(editor).find('[data-role="save-all"]').prop('disabled', false).trigger('click');
        expect(sentTo(spy, 'aaxis_ontology_flow_api_update')).toEqual([]);

        dropFromToolbox('logger', 540, 440);
        root(editor).find('[data-role="tab-add"]').trigger('click'); // Save all is for flows out of focus
        root(editor).find('[data-role="save-all"]').trigger('click');
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', `nightly: ${KEY('save_error')}`);
        expect(messenger.notificationFlashMessage).not.toHaveBeenCalledWith('success', expect.anything());
        $('[data-role="tab"]').eq(0).trigger('click');
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
    });

    it('reports a transport failure of Save all', async () => {
        mockFetch({aaxis_ontology_flow_api_update: new Error('offline')});
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);
        root(editor).find('[data-role="tab-add"]').trigger('click');

        root(editor).find('[data-role="save-all"]').trigger('click');
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'nightly: offline');
    });
});

describe('OntologyFlowEditorComponent — unsaved-changes guards', () => {
    useFlowEditorEnv(FlowEditorComponent);

    function handler(event: string): (...args: any[]) => void {
        return (mediator.on as any).mock.calls.find((c: any[]) => c[0] === event)[1];
    }

    it('lets the browser prompt before unloading a dirty editor only', () => {
        createEditor({flow: simpleFlow()});
        const clean = new Event('beforeunload', {cancelable: true});
        window.dispatchEvent(clean);
        expect(clean.defaultPrevented).toBe(false);

        dropFromToolbox('logger', 540, 440);
        const dirty = new Event('beforeunload', {cancelable: true});
        window.dispatchEvent(dirty);
        expect(dirty.defaultPrevented).toBe(true);
        // The message doubles as the handler's return value (older browsers read that).
        expect($(window).triggerHandler('beforeunload')).toBe('oro.ui.leave_page_with_unsaved_data_confirm');
    });

    it('asks before in-app navigation away from unsaved work', () => {
        createEditor({flow: simpleFlow()});
        const onOpenLink = handler('openLink:before');
        const clean = {prevented: false};
        onOpenLink(clean);
        expect(clean.prevented).toBe(false);
        expect(window.confirm).not.toHaveBeenCalled();

        dropFromToolbox('logger', 540, 440);
        (window.confirm as any).mockReturnValueOnce(false);
        const refused = {prevented: false};
        onOpenLink(refused);
        expect(refused.prevented).toBe(true);
        const accepted = {prevented: false};
        onOpenLink(accepted);
        expect(accepted.prevented).toBe(false);
        const already = {prevented: true};
        onOpenLink(already);
        expect(window.confirm).toHaveBeenCalledTimes(2);
    });

    it('defers a page refresh on the user\'s answer', () => {
        createEditor({flow: simpleFlow()});
        const onRefresh = handler('page:beforeRefresh');
        const queue: any[] = [];
        onRefresh(queue);
        expect(queue).toEqual([]);

        dropFromToolbox('logger', 540, 440);
        onRefresh(queue);
        (window.confirm as any).mockReturnValueOnce(false);
        onRefresh(queue);

        expect(queue.map(d => d.state())).toEqual(['resolved', 'rejected']);
    });

    it('reports a stashed dirty tab to the page-state checker too', () => {
        const editor = createEditor({flow: simpleFlow()});
        const checker = (pageStateChecker.registerChecker as any).mock.calls[0][0];
        dropFromToolbox('logger', 540, 440);
        root(editor).find('[data-role="tab-add"]').trigger('click');

        expect(checker()).toBe(true);
    });
});

describe('OntologyFlowEditorComponent — View source and Flow history', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('shows the current canvas (unsaved edits included) as the flow document', () => {
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);

        root(editor).find('[data-role="view-source"]').trigger('click');

        const dialog = FlowDialog.last();
        expect(dialog.options).toMatchObject({title: `${KEY('source_title')} — nightly`, bodyClass: 'aaxis-flow-json-host'});
        const doc = JSON.parse(dialog.$content.find('pre').text());
        expect(doc).toMatchObject({id: 7, name: 'nightly', type: 'flow', enabled: true});
        expect(doc.steps.map((s: any) => s.name)).toEqual(['nightly', 'shape', 'logger-1']);
        expect(doc.design).toEqual(designOf(editor));
        expect(dialog.$content.find('pre .aaxis-json-key').length).toBeGreaterThan(0);
    });

    it('describes an unsaved, trigger-less canvas with nulls', () => {
        const editor = createEditor();

        root(editor).find('[data-role="view-source"]').trigger('click');

        expect(FlowDialog.last().options.title).toBe(`${KEY('source_title')} — `);
        expect(JSON.parse(FlowDialog.last().$content.find('pre').text())).toMatchObject({id: null, name: null, type: null, enabled: false});
    });

    it('copies the JSON through the clipboard API', async () => {
        vi.useFakeTimers();
        const writeText = vi.fn(() => Promise.resolve());
        vi.stubGlobal('navigator', {...navigator, clipboard: {writeText}});
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="view-source"]').trigger('click');
        const $copy = FlowDialog.last().$content.find('.aaxis-flow-json-bar .btn');

        $copy.trigger('click');
        await flush();

        expect(writeText).toHaveBeenCalledWith(FlowDialog.last().$content.find('pre').text());
        expect($copy.text()).toBe(KEY('copied'));
        vi.advanceTimersByTime(1300);
        expect($copy.text()).toBe(KEY('copy'));
    });

    it('falls back to execCommand without the clipboard API', () => {
        const execCommand = vi.fn(() => true);
        (document as any).execCommand = execCommand;
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="view-source"]').trigger('click');

        FlowDialog.last().$content.find('.aaxis-flow-json-bar .btn').trigger('click');

        expect(execCommand).toHaveBeenCalledWith('copy');
        expect($('body > textarea')).toHaveLength(0);
        expect(FlowDialog.last().$content.find('.aaxis-flow-json-bar .btn').text()).toBe(KEY('copied'));
        delete (document as any).execCommand;
    });

    it('diffs the canvas against a picked archived revision, in full or diff-only', async () => {
        const v2 = {version: 2, archivedAt: '2026-01-02T10:00:00Z', name: 'nightly', steps: simpleFlow().steps, design: simpleFlow().design};
        const v1 = {version: 1, archivedAt: 'not a date', name: 'old', steps: [], design: null};
        const spy = mockFetch({aaxis_ontology_flow_history_list: {body: {versions: [v2, v1]}}});
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);

        root(editor).find('[data-role="flow-history"]').trigger('click');
        const dialog = FlowDialog.last();
        expect(dialog.options.title).toBe(`${KEY('history_title')} — Nightly`);
        expect(dialog.$content.text()).toBe('aaxis.ontology.event_view.loading');
        await flush();

        expect(spy.mock.calls.at(-1)[0]).toBe('/aaxis_ontology_flow_history_list?id=7');
        const $select = dialog.$content.find('select');
        expect($select.find('option').map((_i: number, o: HTMLOptionElement) => o.text).get())
            .toEqual([`v2 — ${new Date(v2.archivedAt).toLocaleString()}`, 'v1 — not a date']);
        const $pre = dialog.$content.find('pre');
        expect($pre.find('.aaxis-json-changed').length).toBeGreaterThan(0);
        expect($pre.text()).toContain('"logger-1"');

        dialog.$content.find('input[type="checkbox"]').prop('checked', true).trigger('change');
        expect($pre.text()).not.toContain('"shape"');
        expect($pre.text()).toContain('"logger-1"');

        $select.val('1').trigger('change');
        expect($pre.text()).toContain('"nightly"');
    });

    it('notes an unchanged revision in diff-only mode, and an empty history', async () => {
        const source = simpleFlow();
        const same = {version: 1, archivedAt: null, name: 'nightly', steps: [
            {type: 'cron', name: 'nightly', x: 0, y: 0, config: source.design.steps[0].config},
            {type: 'dwl_transform', name: 'shape', x: 200, y: 0, config: source.design.steps[1].config}
        ], design: source.design};
        mockFetch({aaxis_ontology_flow_history_list: {body: {versions: [same]}}});
        const editor = createEditor({flow: source});
        root(editor).find('[data-role="flow-history"]').trigger('click');
        await flush();

        FlowDialog.last().$content.find('input[type="checkbox"]').prop('checked', true).trigger('change');
        expect(FlowDialog.last().$content.find('pre').text()).toBe(KEY('history_no_changes'));

        mockFetch({aaxis_ontology_flow_history_list: {body: {}}});
        root(editor).find('[data-role="flow-history"]').trigger('click');
        await flush();
        expect(FlowDialog.last().$content.text()).toBe(KEY('history_empty'));
    });

    it('reports a history that cannot be loaded', async () => {
        mockFetch({aaxis_ontology_flow_history_list: new Error('down')});
        const editor = createEditor({flow: simpleFlow()});

        root(editor).find('[data-role="flow-history"]').trigger('click');
        await flush();

        expect(FlowDialog.last().$content.text()).toBe(KEY('history_error'));
    });
});

describe('OntologyFlowEditorComponent — dispose', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('unbinds every handler, drops in-flight drag artefacts and is idempotent', () => {
        const editor = createEditor({flow: simpleFlow()});
        const item = document.querySelector('[data-step-type="logger"]') as HTMLElement;
        pointer(item, 'pointerdown', 950, 100);
        pointer(window, 'pointermove', 300, 300);
        expect(document.querySelector('.is-ghost')).not.toBeNull();
        const port = tile('nightly').querySelector('[data-role="port"]')!;
        pointer(port, 'pointerdown', 200, 100, {pointerId: 2});
        pointer(document.querySelector('[data-role="canvas"]')!, 'pointerdown', 700, 500, {pointerId: 3});
        $(document.body).trigger($.Event('contextmenu'));
        tile('shape').dispatchEvent(new MouseEvent('contextmenu', {bubbles: true}));

        editor.dispose();
        editor.dispose();

        expect(editor.disposed).toBe(true);
        expect(document.querySelector('.is-ghost')).toBeNull();
        expect(document.querySelector('[data-role="wire-temp"]')).toBeNull();
        expect(document.querySelector('.aaxis-flow-editor__marquee')).toBeNull();
        expect(document.querySelector('body > .aaxis-flow-editor__menu')).toBeNull();
        root(editor).find('[data-role="view-source"]').trigger('click');
        expect(FlowDialog.instances).toHaveLength(0);
        pointer(window, 'pointerup', 300, 300);
        expect(tileNames()).toEqual(['nightly', 'shape']);
        const unload = new Event('beforeunload', {cancelable: true});
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(false);
        expect(tabTitles()).toEqual(['nightly']);
    });

    it('drops an armed-but-unmoved toolbox drag on dispose', () => {
        const editor = createEditor();
        pointer(document.querySelector('[data-step-type="logger"]')!, 'pointerdown', 950, 100);

        expect(() => editor.dispose()).not.toThrow();
    });

    it('keeps the flow name from the record while the canvas has no trigger', () => {
        createEditor({flow: flowRecord(3, 'Stored name', [st('l', 'logger', 'log', 0, 0)])});

        expect(tabTitles()).toEqual(['Stored name']);
    });
});

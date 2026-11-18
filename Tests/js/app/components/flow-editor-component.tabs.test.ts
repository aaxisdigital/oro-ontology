import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    createEditor, dropFromToolbox, FlowDialog, flowRecord, flush, geometry, mockFetch, root, simpleFlow, st, tabTitles,
    tileNames, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

function tab(index: number): any {
    return $('[data-role="tab"]').eq(index);
}

function activeTitle(): string {
    return $('[data-role="tab"].is-active .aaxis-flow-editor__tab-title').text();
}

function dirtyTabs(): number[] {
    return $('[data-role="tab"]').map((i: number, el: HTMLElement) => ($(el).find('.aaxis-flow-editor__tab-dirty').length ? i : -1)).get()
        .filter((i: number) => i >= 0);
}

function url(): string {
    return window.location.pathname + window.location.search;
}

/** Fires a native drag event (jsdom has no DragEvent) carrying a dataTransfer stub. */
function drag(el: Element, type: string, clientX = 0): any {
    const ev: any = new Event(type, {bubbles: true, cancelable: true});
    ev.clientX = clientX;
    ev.dataTransfer = {setData: vi.fn(), effectAllowed: '', dropEffect: ''};
    el.dispatchEvent(ev);
    return ev;
}

const OTHER = flowRecord(8, 'Other', [st('o', 'endpoint', 'hook', 0, 0, {enabled: true})]);
const SUB = flowRecord(9, 'Sub', [st('s', 'subflow', 'entry', 0, 0, {enabled: true})], [], {type: 'subflow', enabled: false});

describe('OntologyFlowEditorComponent — tabs', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('opens a new empty tab with +, keeping the other tab\'s work, and mirrors the set in the URL', () => {
        const editor = createEditor({flow: simpleFlow()});

        root(editor).find('[data-role="tab-add"]').trigger('click');

        expect(tabTitles()).toEqual(['nightly', KEY('name_unset')]);
        expect(activeTitle()).toBe(KEY('name_unset'));
        expect(tileNames()).toEqual([]);
        expect(url()).toBe('/aaxis_ontology_flow_editor?tabs=7');

        tab(0).trigger('click');
        expect(tileNames()).toEqual(['nightly', 'shape']);
        expect(url()).toBe('/aaxis_ontology_flow_editor?id=7');
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(true);
    });

    it('keeps a stashed tab\'s unsaved changes and marks it dirty', () => {
        const editor = createEditor({flow: simpleFlow()});
        expect(tab(0).attr('title')).toBeUndefined();
        dropFromToolbox('logger', 540, 440);
        expect(dirtyTabs()).toEqual([0]);
        // A drawn dot (no glyph text), the tab saying it in words.
        expect(tab(0).find('.aaxis-flow-editor__tab-dirty').text()).toBe('');
        expect(tab(0).attr('title')).toBe(KEY('tab_unsaved'));

        root(editor).find('[data-role="tab-add"]').trigger('click');
        expect(dirtyTabs()).toEqual([0]);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(true);
        expect(root(editor).find('[data-role="save-all"]').prop('disabled')).toBe(false);

        tab(0).trigger('click');
        expect(tileNames()).toEqual(['nightly', 'shape', 'logger-1']);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
        tab(0).trigger('click'); // the active tab: nothing happens
        expect(tileNames()).toEqual(['nightly', 'shape', 'logger-1']);
    });

    it('closes a clean tab directly and a dirty one only after confirming', () => {
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="tab-add"]').trigger('click');
        dropFromToolbox('cron', 140, 140);

        tab(1).find('[data-role="tab-close"]').trigger('click');
        expect(FlowDialog.last().options.title).toBe(KEY('tab_close_title'));
        // Cancel reads "Cancel" — not the settings popup's "Discard", which read as the action.
        expect(FlowDialog.last().$content.find('.btn').not('.btn-primary').text()).toBe(KEY('dialog_cancel'));
        FlowDialog.last().$content.find('.btn').not('.btn-primary').trigger('click');
        expect(tabTitles()).toEqual(['nightly', 'schedule-1']);

        tab(1).find('[data-role="tab-close"]').trigger('click');
        FlowDialog.last().$content.find('.btn-primary').trigger('click');
        expect(tabTitles()).toEqual(['nightly']);
        expect(tileNames()).toEqual(['nightly', 'shape']);
        expect(url()).toBe('/aaxis_ontology_flow_editor?id=7');
    });

    it('closes a tab before the active one and keeps the active flow on screen', () => {
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="tab-add"]').trigger('click');

        tab(0).find('[data-role="tab-close"]').trigger('click');

        expect(tabTitles()).toEqual([KEY('name_unset')]);
        expect(activeTitle()).toBe(KEY('name_unset'));
        expect(url()).toBe('/aaxis_ontology_flow_editor');
    });

    it('closes an inactive tab after the active one without reloading the canvas', () => {
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="tab-add"]').trigger('click');
        tab(0).trigger('click');

        tab(1).find('[data-role="tab-close"]').trigger('click');

        expect(tabTitles()).toEqual(['nightly']);
        expect(tileNames()).toEqual(['nightly', 'shape']);
    });

    it('always keeps one tab: closing the last opens a fresh one', () => {
        createEditor({flow: simpleFlow()});

        tab(0).find('[data-role="tab-close"]').trigger('click');

        expect(tabTitles()).toEqual([KEY('name_unset')]);
        expect(tileNames()).toEqual([]);
    });

    it('reorders tabs by dragging; the active tab travels by identity', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: {records: [OTHER, SUB]}}});
        window.history.replaceState(null, '', '/x?tabs=7,8,9');
        createEditor({flow: simpleFlow()});
        await flush();
        expect(tabTitles()).toEqual(['nightly', 'hook', 'entry']);

        const start = drag(tab(0)[0], 'dragstart');
        expect(start.dataTransfer.setData).toHaveBeenCalledWith('text/plain', '0');
        expect(start.dataTransfer.effectAllowed).toBe('move');
        const over = drag(tab(2)[0], 'dragover', 2 * geometry.tabWidth + 80);
        expect(over.defaultPrevented).toBe(true);
        expect(tab(2).hasClass('is-drop-after')).toBe(true);
        drag(tab(2)[0], 'dragover', 2 * geometry.tabWidth + 10);
        expect(tab(2).hasClass('is-drop-before')).toBe(true);
        drag(tab(2)[0], 'dragleave');
        expect(tab(2).hasClass('is-drop-before')).toBe(false);
        drag(tab(2)[0], 'drop', 2 * geometry.tabWidth + 80);

        expect(tabTitles()).toEqual(['hook', 'entry', 'nightly']);
        expect(activeTitle()).toBe('nightly');
        // (The stub router renders {id} as a query; the real route carries it in the path.)
        expect(url()).toBe('/aaxis_ontology_flow_editor?id=7?tabs=8,9,7');

        drag(tab(2)[0], 'dragstart');
        drag(tab(0)[0], 'drop', 10);
        expect(tabTitles()).toEqual(['nightly', 'hook', 'entry']);
        drag(tab(0)[0], 'dragend');
    });

    it('ignores drag-overs and drops without a tab drag, and drops onto itself', () => {
        createEditor({flow: simpleFlow()});
        $('[data-role="tab-add"]').trigger('click');

        const over = drag(tab(0)[0], 'dragover', 10);
        expect(over.defaultPrevented).toBe(false);
        drag(tab(0)[0], 'drop', 10);
        drag(tab(1)[0], 'dragstart');
        drag(tab(1)[0], 'drop', 150);
        drag(tab(1)[0], 'dragend');

        expect(tabTitles()).toEqual(['nightly', KEY('name_unset')]);
    });
});

describe('OntologyFlowEditorComponent — the "open flow" picker', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const FLOWS = [
        OTHER,
        {...flowRecord(10, 'Beta', []), enabled: false},
        SUB,
        {id: 11, name: null, type: 'subflow', enabled: true, steps: [], design: null},
        {id: 99, name: 'Native', type: 'native', enabled: true}
    ];

    function pickerTabs(): string[] {
        return $('.aaxis-flow-picker__tab').map((_i: number, el: HTMLElement) => el.textContent).get();
    }

    function rows(): string[] {
        return $('.aaxis-flow-picker__row').map((_i: number, el: HTMLElement) => el.textContent).get();
    }

    it('lists the non-native flows by kind, filters them and opens the picked one in a new tab', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: {records: FLOWS}}});
        const editor = createEditor({flow: simpleFlow()});

        root(editor).find('[data-role="tab-open"]').trigger('click');
        const dialog = FlowDialog.last();
        expect(dialog.options.title).toBe(KEY('tab_open'));
        expect(dialog.$content.find('.aaxis-json-note').text()).toBe(KEY('loading'));
        await flush();

        expect(pickerTabs()).toEqual([`${KEY('tab_open_flows')} (2)`, `${KEY('tab_open_subflows')} (2)`]);
        expect(rows()).toEqual(['Other', `Beta${KEY('tab_open_disabled')}`]);
        expect(document.activeElement).toBe($('.aaxis-flow-picker__filter')[0]);
        $('.aaxis-flow-picker__filter').val('  bET ').trigger('input');
        expect(pickerTabs()).toEqual([`${KEY('tab_open_flows')} (1)`, `${KEY('tab_open_subflows')} (0)`]);
        expect(rows()).toEqual([`Beta${KEY('tab_open_disabled')}`]);
        $('.aaxis-flow-picker__tab').eq(1).trigger('click');
        expect($('.aaxis-flow-picker__tab').eq(1).hasClass('is-active')).toBe(true);
        expect($('.aaxis-flow-picker__list .aaxis-json-note').text()).toBe(KEY('tab_open_no_match'));
        $('.aaxis-flow-picker__filter').val('').trigger('input');
        expect(rows()).toEqual([`Sub${KEY('tab_open_disabled')}`, '']);
        $('.aaxis-flow-picker__row').eq(0).trigger('click');

        expect(dialog.closed).toBe(true);
        expect(tabTitles()).toEqual(['nightly', 'entry']);
        expect(activeTitle()).toBe('entry');
    });

    it('focuses an already-open flow instead of opening it twice', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: {records: [simpleFlow(), OTHER]}}});
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="tab-add"]').trigger('click');

        root(editor).find('[data-role="tab-open"]').trigger('click');
        await flush();
        $('.aaxis-flow-picker__row').eq(0).trigger('click');

        expect(tabTitles()).toEqual(['nightly', KEY('name_unset')]);
        expect(activeTitle()).toBe('nightly');
    });

    it('says so when there is nothing to open, and flashes a failed load', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: {}}});
        const editor = createEditor();
        root(editor).find('[data-role="tab-open"]').trigger('click');
        await flush();
        expect(FlowDialog.last().$content.text()).toBe(KEY('tab_open_empty'));

        mockFetch({aaxis_ontology_flow_list: new Error('down')});
        root(editor).find('[data-role="tab-open"]').trigger('click');
        await flush();
        expect(FlowDialog.last().$content.text()).toBe('');
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('catalog_load_error'));
    });
});

import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    createEditor, deferred, dropFromToolbox, FlowDialog, flush, mockFetch, pointer, root, sentTo, simpleFlow, tabTitles,
    tileNames, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

function saved(flow: any): any {
    return {body: {success: true, flow}};
}

function dialogButton(label: string): any {
    return FlowDialog.last().$content.find('button').filter((_i: number, el: HTMLElement) => el.textContent === KEY(label));
}

function sectionsCollapsed(): boolean[] {
    return $('.aaxis-flow-editor__toolbox-section').map((_i: number, el: HTMLElement) => el.classList.contains('is-collapsed')).get();
}

describe('OntologyFlowEditorComponent — tab bar actions', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('renders only the flow tabs, leaving the twig\'s action groups in place', () => {
        const editor = createEditor({flow: simpleFlow()});
        const $tabs = root(editor).find('[data-role="tabs"]');

        expect($tabs.children().map((_i: number, el: HTMLElement) => el.dataset.role).get()).toEqual(['tab-list', 'tab-actions']);
        expect($tabs.find('[data-role="tab-list"] [data-role="tab"]')).toHaveLength(1);
        expect($tabs.find('[data-role="tab-actions"] [data-role="tab"]')).toHaveLength(0);
        expect($tabs.find('[data-role="document-actions"]').children().map((_i: number, el: HTMLElement) => el.dataset.role).get())
            .toEqual(['tab-add', 'tab-open', 'save', 'save-all', 'view-source', 'flow-history', 'tab-close-all']);
    });

    it('re-creates the tab list when the markup lacks it', () => {
        $('[data-role="tab-list"]').remove();
        const editor = createEditor({flow: simpleFlow()});

        expect(root(editor).find('[data-role="tabs"] > [data-role="tab-list"]:first-child [data-role="tab"]')).toHaveLength(1);
    });

    it('keeps the same action buttons across re-renders, their state following the canvas', () => {
        const editor = createEditor({flow: simpleFlow()});
        const save = root(editor).find('[data-role="save"]')[0];
        expect(save.disabled).toBe(true);

        dropFromToolbox('logger', 540, 440);
        root(editor).find('[data-role="tab-add"]').trigger('click');

        expect(root(editor).find('[data-role="save"]')[0]).toBe(save);
        expect(save.disabled).toBe(true); // the new tab is clean
        expect(root(editor).find('[data-role="save-all"]').prop('disabled')).toBe(false); // the first is not
    });

    it('offers Save all only while a tab out of focus holds changes', () => {
        const editor = createEditor({flow: simpleFlow()});
        const $saveAll = root(editor).find('[data-role="save-all"]');

        dropFromToolbox('logger', 540, 440);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
        expect($saveAll.prop('disabled')).toBe(true); // only the focused flow changed: Save is the action

        root(editor).find('[data-role="tab-add"]').trigger('click');
        expect($saveAll.prop('disabled')).toBe(false); // the changed flow is now out of focus

        dropFromToolbox('cron', 140, 140);
        expect($saveAll.prop('disabled')).toBe(false); // two changed flows

        $('[data-role="tab"]').eq(0).trigger('click');
        expect($saveAll.prop('disabled')).toBe(false); // still another changed one behind the focus
    });

    it('keeps Save disabled while its request is in flight, even when the canvas re-syncs', async () => {
        const update = deferred<any>();
        mockFetch({aaxis_ontology_flow_api_update: update.promise});
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);
        const $save = root(editor).find('[data-role="save"]');

        $save.trigger('click');
        dropFromToolbox('logger', 700, 440); // a change while saving re-syncs the buttons
        expect($save.prop('disabled')).toBe(true);
        expect($save.find('.fa-spinner')).toHaveLength(1);

        update.resolve(saved(simpleFlow()));
        await flush();
        expect($save.find('.fa-spinner')).toHaveLength(0);
    });

    it('shows and hides the toolbox from the tab bar, marking the toggle while it is open', () => {
        const editor = createEditor({flow: simpleFlow()});
        const $toggle = root(editor).find('[data-role="toolbox-toggle"]');
        const toolbox = root(editor).find('[data-role="toolbox"]')[0];
        expect(toolbox.hidden).toBe(false);
        expect($toggle.hasClass('is-active')).toBe(true);

        $toggle.trigger('click');
        expect(toolbox.hidden).toBe(true);
        expect($toggle.hasClass('is-active')).toBe(false);

        $toggle.trigger('click');
        expect(toolbox.hidden).toBe(false);
        expect($toggle.hasClass('is-active')).toBe(true);
    });

    it('closes the editor straight away when nothing is unsaved', () => {
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="tab-add"]').trigger('click');

        root(editor).find('[data-role="tab-close-all"]').trigger('click');

        expect(FlowDialog.instances).toHaveLength(0);
        expect(window.location.hash).toBe('#flows');
    });

    it('asks before closing unsaved work: Cancel keeps everything, Don\'t save leaves without the guards', () => {
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);
        const $closeAll = root(editor).find('[data-role="tab-close-all"]');

        $closeAll.trigger('click');
        expect(FlowDialog.last().$content.text()).toContain(KEY('tab_close_all_question'));
        expect(FlowDialog.last().$content.find('button').map((_i: number, el: HTMLElement) => el.textContent).get())
            .toEqual([KEY('dialog_cancel'), KEY('dont_save'), KEY('save')]);
        dialogButton('dialog_cancel').trigger('click');
        expect(FlowDialog.last().closed).toBe(true);
        expect(window.location.hash).not.toBe('#flows');
        expect(tileNames()).toEqual(['nightly', 'shape', 'logger-1']);

        $closeAll.trigger('click');
        dialogButton('dont_save').trigger('click');
        expect(window.location.hash).toBe('#flows');
        // Leaving explicitly silences the unsaved-changes guards.
        expect(editor.dirtyChecker()).toBe(false);
    });

    it('saves everything, then leaves the editor', async () => {
        const spy = mockFetch({aaxis_ontology_flow_api_update: saved(simpleFlow())});
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);

        root(editor).find('[data-role="tab-close-all"]').trigger('click');
        dialogButton('save').trigger('click');
        await flush();

        expect(sentTo(spy, 'aaxis_ontology_flow_api_update')).toHaveLength(1);
        expect(window.location.hash).toBe('#flows');
    });

    it('stays when a flow could not be saved, keeping open only those flows', async () => {
        const spy = mockFetch({aaxis_ontology_flow_api_update: saved(simpleFlow())});
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);
        root(editor).find('[data-role="tab-add"]').trigger('click');
        dropFromToolbox('logger', 140, 140); // no trigger: cannot be saved

        root(editor).find('[data-role="tab-close-all"]').trigger('click');
        dialogButton('save').trigger('click');
        await flush();

        expect(sentTo(spy, 'aaxis_ontology_flow_api_update')).toHaveLength(1);
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', `${KEY('name_unset')}: ${KEY('trigger_required')}`);
        expect(window.location.hash).not.toBe('#flows');
        expect(tabTitles()).toEqual([KEY('name_unset')]);
        expect(tileNames()).toEqual(['logger-1']);
    });
});

describe('OntologyFlowEditorComponent — toolbox expand / collapse all', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('collapses and expands every section, their own toggles following', () => {
        const editor = createEditor();
        $('[data-role="toolbox-section-toggle"]').first().trigger('click');
        expect(sectionsCollapsed()[0]).toBe(true);

        root(editor).find('[data-role="toolbox-collapse-all"]').trigger('click');
        expect(sectionsCollapsed().every(Boolean)).toBe(true);
        expect($('[data-role="toolbox-section-toggle"] .fa-plus')).toHaveLength(sectionsCollapsed().length);

        root(editor).find('[data-role="toolbox-expand-all"]').trigger('click');
        expect(sectionsCollapsed().some(Boolean)).toBe(false);
        expect($('[data-role="toolbox-section-toggle"] .fa-minus')).toHaveLength(sectionsCollapsed().length);
    });

    it('does not start a toolbox drag from the header buttons', () => {
        const editor = createEditor();
        const button = root(editor).find('[data-role="toolbox-collapse-all"]')[0];

        pointer(button, 'pointerdown', 950, 70);

        expect(editor.panelDrag).toBeNull();
    });
});

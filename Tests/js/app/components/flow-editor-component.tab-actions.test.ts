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

    it('groups open, new, save, save all, close all and the toolbox toggle at the right of the tab bar', () => {
        const editor = createEditor({flow: simpleFlow()});
        const $actions = root(editor).find('[data-role="tabs"] [data-role="tab-actions"]');

        expect($actions.children().map((_i: number, el: HTMLElement) => el.dataset.role).get())
            .toEqual(['tab-open', 'tab-add', 'save', 'save-all', 'tab-close-all', 'toolbox-toggle']);
        expect($actions.find('[data-role="save"]').attr('title')).toBe(KEY('tab_save'));
        expect(root(editor).find('.aaxis-flow-editor__controls [data-role="save"], .aaxis-flow-editor__controls [data-role="toolbox-toggle"]')).toHaveLength(0);
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

    it('closes every clean tab at once, leaving one fresh tab', () => {
        const editor = createEditor({flow: simpleFlow()});
        root(editor).find('[data-role="tab-add"]').trigger('click');
        expect(tabTitles()).toHaveLength(2);

        root(editor).find('[data-role="tab-close-all"]').trigger('click');

        expect(FlowDialog.instances).toHaveLength(0);
        expect(tabTitles()).toEqual([KEY('name_unset')]);
        expect(tileNames()).toEqual([]);
    });

    it('asks before closing unsaved work: Cancel keeps everything, Don\'t save discards it', () => {
        const editor = createEditor({flow: simpleFlow()});
        dropFromToolbox('logger', 540, 440);
        const $closeAll = root(editor).find('[data-role="tab-close-all"]');

        $closeAll.trigger('click');
        expect(FlowDialog.last().$content.text()).toContain(KEY('tab_close_all_question'));
        expect(FlowDialog.last().$content.find('button').map((_i: number, el: HTMLElement) => el.textContent).get())
            .toEqual([KEY('dialog_cancel'), KEY('dont_save'), KEY('save')]);
        dialogButton('dialog_cancel').trigger('click');
        expect(FlowDialog.last().closed).toBe(true);
        expect(tileNames()).toEqual(['nightly', 'shape', 'logger-1']);

        $closeAll.trigger('click');
        dialogButton('dont_save').trigger('click');
        expect(tabTitles()).toEqual([KEY('name_unset')]);
        expect(tileNames()).toEqual([]);
    });

    it('saves everything before closing, keeping open only the flows that could not be saved', async () => {
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

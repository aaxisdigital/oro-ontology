import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    confirmSettings, createEditor, dropFromToolbox, dwlTextarea, field, flowRecord, flush, mockFetch, openSettings, root,
    st, tile, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;
const INVALID = 'aaxis-flow-editor__field--invalid';
const CRON = {enabled: true, mode: 'interval', value: 1, unit: 'day'};

/** Opens the settings of `step` on a flow holding a trigger + that step (extra = flow record fields). */
async function open(step: any, extra: Record<string, any> = {}): Promise<any> {
    const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, CRON), step], [], extra)});
    await flush();
    openSettings(step.name);
    await flush();
    return editor;
}

/** The [config key, tooltip] of every field painted red in the open popup. */
function redFields(): Array<[string, string | null]> {
    return $(`.aaxis-flow-editor__settings .${INVALID}`).map((_i: number, el: HTMLElement) =>
        [[el.dataset.fieldProblem as string, el.getAttribute('title')]]).get();
}

/** The config keys painted red (once each — a key may paint two controls, e.g. pick + DWL twin). */
const redKeys = (): string[] => Array.from(new Set(redFields().map(([key]) => key)));

describe('OntologyFlowEditorComponent — wrong fields of a red step', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('paints the missing fields of a red step and their problem as the tooltip', async () => {
        await open(st('d', 'dwl_transform', 'shape', 200, 0, {code: '', destination: 'flowUuid'}), {invalidSteps: ['shape']});

        // (in page order: the destination sits on the first row, above the code)
        expect(redFields()).toEqual([
            ['destination', KEY('destination_reserved')],
            ['code', KEY('dwl_code_required')]
        ]);
        expect(dwlTextarea('dwl_code_label').hasClass(INVALID)).toBe(true);
    });

    it('leaves a runnable step alone, whatever its fields hold', async () => {
        await open(st('d', 'dwl_transform', 'shape', 200, 0, {code: '', destination: ''}));

        expect(tile('shape').classList.contains('is-config-invalid')).toBe(false);
        expect(redFields()).toEqual([]);
    });

    it('keeps the marks current while the user edits', async () => {
        await open(st('d', 'dwl_transform', 'shape', 200, 0, {code: '', destination: 'out'}), {invalidSteps: ['shape']});
        expect(redKeys()).toEqual(['code']);

        dwlTextarea('dwl_code_label').val('payload').trigger('input');
        expect(redKeys()).toEqual([]);
        expect(dwlTextarea('dwl_code_label').attr('title')).toBeUndefined();

        field('destination_label').val('flowUuid').trigger('input');
        expect(redFields()).toEqual([['destination', KEY('destination_reserved')]]);
    });

    it('marks a fresh drop that needs configuration (it starts red)', async () => {
        createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, CRON)])});
        await flush();
        dropFromToolbox('logger', 340, 140);

        openSettings('logger-1');
        await flush();

        expect(redFields()).toEqual([['message', KEY('logger_message_required')]]);
    });

    it('paints selects and pickers once their catalogs have loaded', async () => {
        await open(st('r', 'entity_read', 'read', 200, 0, {destination: 'payload', mode: 'by_attribute'}), {invalidSteps: ['read']});

        expect(redKeys()).toEqual(['system', 'entity', 'attribute', 'attr_value']);
        expect(field('config_system_label').find(`.aaxis-flow-editor__combo input.${INVALID}`)).toHaveLength(1);
    });

    it('paints the connector picker\'s visible filter', async () => {
        await open(st('h', 'invoke', 'call', 200, 0, {path: 'x', destination: 'out'}), {invalidSteps: ['call']});

        expect(redKeys()).toEqual(['connector']);
        expect($(`[data-role="connector-filter"].${INVALID}`)).toHaveLength(1);
    });

    it('paints the cron expression or the interval value, whichever mode is shown', async () => {
        await open(st('c', 'cron', 'nightly', 0, 200, {enabled: true, mode: 'cron', expression: 'nope'}), {invalidSteps: ['nightly']});
        expect(redFields()).toEqual([['expression', KEY('cron_expression_invalid')]]);

        $('.aaxis-flow-editor__settings select').first().val('interval').trigger('change');
        field('schedule_value_label').val('0').trigger('input');
        expect(redKeys()).toEqual(['value']);
    });

    it('paints the Call Subflow picker until a target is picked', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: {records: [{id: 20, name: 'Zeta', type: 'subflow'}]}}});
        await open(st('s', 'sub_flow', 'call', 200, 0, null), {invalidSteps: ['call']});
        expect(redKeys()).toEqual(['subflow']);

        const $filter = $('.aaxis-flow-editor__combo input');
        $filter.trigger('focus');
        const option = $('.aaxis-flow-editor__combo-option').filter((_i: number, el: HTMLElement) => el.textContent === 'Zeta')[0];
        option.dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));

        expect(redKeys()).toEqual([]);
    });
});

describe('OntologyFlowEditorComponent — problems only the server can judge', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const BAD = 'Step "shape" has invalid DWL: Expected RIGHT_BRACE';

    it('paints the field the server judged until its value changes', async () => {
        await open(st('d', 'dwl_transform', 'shape', 200, 0, {code: '{', destination: 'out'}), {
            invalidSteps: ['shape'], invalidFields: {shape: {code: BAD}}
        });
        expect(redFields()).toEqual([['code', BAD]]);

        dwlTextarea('dwl_code_label').val('{}').trigger('input');
        expect(redFields()).toEqual([]);
        dwlTextarea('dwl_code_label').val('{').trigger('input');
        expect(redFields()).toEqual([['code', BAD]]);
    });

    it('keeps the tile red on a confirm that left the judged field as it was', async () => {
        const editor = await open(st('d', 'dwl_transform', 'shape', 200, 0, {code: '{', destination: 'out'}), {
            invalidSteps: ['shape'], invalidFields: {shape: {code: BAD}}
        });

        field('destination_label').val('other').trigger('input');
        confirmSettings();
        expect(tile('shape').classList.contains('is-config-invalid')).toBe(true);
        openSettings('shape');
        await flush();
        expect(redFields()).toEqual([['code', BAD]]);
        $('.aaxis-flow-editor__settings .btn').not('.btn-primary').trigger('click');

        // Fixing the code clears both the field and (nothing else wrong) the tile.
        openSettings('shape');
        await flush();
        dwlTextarea('dwl_code_label').val('payload').trigger('input');
        confirmSettings();
        expect(tile('shape').classList.contains('is-config-invalid')).toBe(false);
        expect(editor.steps.find((s: any) => s.name === 'shape').serverProblems).toBeUndefined();
    });

    it('restores the tooltip a field had once its problem is gone', async () => {
        await open(st('f', 'file_read', 'read', 200, 0, {
            connector: 3, path: 'a.csv', destination: 'rows', content_type: 'application/csv', reader_options: '{'
        }), {invalidSteps: ['read'], invalidFields: {read: {reader_options: 'bad options'}}});
        const $options = $('[data-role="file-reader-options"]');
        expect($options.attr('title')).toBe('bad options');

        $options.val('{header: false}').trigger('input');

        expect($options.hasClass(INVALID)).toBe(false);
        expect($options.attr('title')).toBe(KEY('file_reader_options_title'));
    });

    it('keeps the server-judged fields across tab switches', async () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 0, 0, CRON),
            st('d', 'dwl_transform', 'shape', 200, 0, {code: '{', destination: 'out'})
        ], [], {invalidSteps: ['shape'], invalidFields: {shape: {code: BAD}}})});
        await flush();

        root(editor).find('[data-role="tab-add"]').trigger('click');
        $('[data-role="tab"]').first().trigger('click');
        openSettings('shape');
        await flush();

        expect(redFields()).toEqual([['code', BAD]]);
    });

    it('takes the judged fields from a save response', async () => {
        const saved = flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 0, 0, CRON),
            st('d', 'dwl_transform', 'shape', 200, 0, {code: '{', destination: 'out'})
        ], [], {invalidSteps: ['shape'], invalidFields: {shape: {code: BAD}}});
        mockFetch({aaxis_ontology_flow_api_update: {body: {success: true, flow: saved}}});
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 0, 0, CRON),
            st('d', 'dwl_transform', 'shape', 200, 0, {code: '{', destination: 'out'})
        ])});
        await flush();
        expect(tile('shape').classList.contains('is-config-invalid')).toBe(false);

        editor.steps[1].x = 220; // something to save
        root(editor).find('[data-role="save"]').prop('disabled', false).trigger('click');
        await flush();
        openSettings('shape');
        await flush();

        expect(tile('shape').classList.contains('is-config-invalid')).toBe(true);
        expect(redFields()).toEqual([['code', BAD]]);
    });

    it('reads an empty list from the server as no field problems', async () => {
        await open(st('d', 'dwl_transform', 'shape', 200, 0, {code: '', destination: 'out'}), {
            invalidSteps: ['shape'], invalidFields: []
        });

        expect(redKeys()).toEqual(['code']);
    });
});

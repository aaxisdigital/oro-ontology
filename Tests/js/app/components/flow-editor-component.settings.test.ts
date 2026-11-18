import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    configOf, confirmSettings, createEditor, dwlTextarea, field, flowRecord, flush, mockFetch, openSettings, root, st,
    tabTitles, tile, tileNames, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

function nameInput(): any {
    return $('.aaxis-flow-editor__settings input[maxlength="64"]');
}

function errorText(): string {
    return $('.aaxis-flow-editor__settings-error').text();
}

function keydown(target: any, key: string): void {
    $(target).trigger($.Event('keydown', {key}));
}

/** A one-step flow (plus a cron trigger so names can collide) and its editor. */
function editorWith(step: any, extra: any[] = []): any {
    return createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {enabled: true}), step, ...extra])});
}

describe('OntologyFlowEditorComponent — the step settings popup', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('opens a modal panel next to the tile, titled "<label> - <name>", with the name focused', () => {
        editorWith(st('e', 'email', 'notify', 300, 200));

        const $panel = openSettings('notify');

        expect($panel.find('.aaxis-flow-editor__settings-title').text()).toBe('Email - notify');
        expect($('.aaxis-flow-editor__settings-backdrop')).toHaveLength(1);
        expect(nameInput().val()).toBe('notify');
        expect(document.activeElement).toBe(nameInput()[0]);
        // Right of the tile (canvas 100 + 300 + 80 + 12), top aligned with it.
        expect([$panel[0].style.left, $panel[0].style.top]).toEqual(['492px', '250px']);
        expect($panel.find('.aaxis-flow-editor__settings-top').prop('hidden')).toBe(true);
    });

    it('flips to the tile\'s left when the right side would overflow the window', () => {
        editorWith(st('e', 'email', 'notify', 900, 200));

        const $panel = openSettings('notify');

        expect($panel[0].style.left).toBe(`${100 + 900 - 12}px`);
    });

    it('renames the step on confirm: tile label, tooltip, dirty state', () => {
        const editor = editorWith(st('e', 'email', 'notify', 300, 200));
        openSettings('notify');

        nameInput().val('  alert  ');
        confirmSettings();

        expect($('.aaxis-flow-editor__settings')).toHaveLength(0);
        expect($('.aaxis-flow-editor__settings-backdrop')).toHaveLength(0);
        expect(tileNames()).toEqual(['tick', 'alert']);
        expect(tile('alert').title).toBe('alert (Email)');
        expect(configOf(editor, 'alert')).toBeNull();
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
    });

    it('renaming the trigger renames the tab', () => {
        editorWith(st('e', 'email', 'notify', 300, 200));
        openSettings('tick');

        nameInput().val('hourly');
        keydown(nameInput(), 'Enter');

        expect(tabTitles()).toEqual(['hourly']);
    });

    it('blocks an empty or duplicate name (case-insensitive) with an inline error', () => {
        editorWith(st('e', 'email', 'notify', 300, 200));
        openSettings('notify');

        nameInput().val('   ');
        confirmSettings();
        expect(errorText()).toBe(KEY('step_name_required'));
        nameInput().val('TICK');
        confirmSettings();
        expect(errorText()).toBe(KEY('step_name_exists'));
        expect($('.aaxis-flow-editor__settings')).toHaveLength(1);

        nameInput().val('notify');
        confirmSettings();
        expect(tileNames()).toEqual(['tick', 'notify']);
    });

    it('discards the edits on Cancel and on Escape', () => {
        editorWith(st('e', 'email', 'notify', 300, 200));

        openSettings('notify');
        nameInput().val('changed');
        $('.aaxis-flow-editor__settings .btn').not('.btn-primary').trigger('click');
        expect($('.aaxis-flow-editor__settings')).toHaveLength(0);

        openSettings('notify');
        nameInput().val('changed');
        keydown(document, 'Tab');
        expect($('.aaxis-flow-editor__settings')).toHaveLength(1);
        keydown(document, 'Escape');
        expect($('.aaxis-flow-editor__settings')).toHaveLength(0);
        expect(tileNames()).toEqual(['tick', 'notify']);
    });

    it('absorbs clicks on the backdrop', () => {
        editorWith(st('e', 'email', 'notify', 300, 200));
        openSettings('notify');

        const click = $.Event('click');
        $('.aaxis-flow-editor__settings-backdrop').trigger(click);

        expect(click.isDefaultPrevented()).toBe(true);
        expect(click.isPropagationStopped()).toBe(true);
        expect($('.aaxis-flow-editor__settings')).toHaveLength(1);
    });

    it('can be dragged by its title and then stays where the user put it', () => {
        editorWith(st('e', 'email', 'notify', 300, 200));
        const $panel = openSettings('notify');
        const $title = $panel.find('.aaxis-flow-editor__settings-title');

        $title.trigger($.Event('mousedown', {clientX: 10, clientY: 10}));
        $(document).trigger($.Event('mousemove', {clientX: 210, clientY: 110}));
        expect([$panel[0].style.left, $panel[0].style.top]).toEqual(['200px', '100px']);
        $(document).trigger($.Event('mousemove', {clientX: 99999, clientY: -500}));
        expect([$panel[0].style.left, $panel[0].style.top]).toEqual([`${window.innerWidth - 40}px`, '0px']);
        $(document).trigger($.Event('mouseup'));
        $(document).trigger($.Event('mousemove', {clientX: 300, clientY: 300}));
        expect($panel[0].style.left).toBe(`${window.innerWidth - 40}px`);
        expect($panel[0].dataset.userMoved).toBe('1');
    });

    it('stores an incomplete configuration but paints the tile red, and clears it once complete', () => {
        const editor = editorWith(st('c', 'choice', 'pick', 300, 200));

        openSettings('pick');
        confirmSettings();
        expect(configOf(editor, 'pick')).toEqual({expression: ''});
        expect(tile('pick').classList.contains('is-config-invalid')).toBe(true);
        expect(root(editor).find('[data-role="debug"]').prop('disabled')).toBe(true);

        openSettings('pick');
        expect($('.aaxis-flow-editor__settings').hasClass('is-wide')).toBe(true);
        dwlTextarea('choice_expression_label').val('payload.total > 10');
        confirmSettings();
        expect(configOf(editor, 'pick')).toEqual({expression: 'payload.total > 10'});
        expect(tile('pick').classList.contains('is-config-invalid')).toBe(false);
        expect(root(editor).find('[data-role="debug"]').prop('disabled')).toBe(false);
    });
});

describe('OntologyFlowEditorComponent — trigger settings', () => {
    useFlowEditorEnv(FlowEditorComponent);

    function cronInput(): any {
        return $('.aaxis-flow-editor__cron-input');
    }

    function typeCron(value: string, caret?: number): void {
        cronInput().val(value);
        if (caret !== undefined) {
            (cronInput()[0] as HTMLInputElement).setSelectionRange(caret, caret);
        }
        cronInput().trigger('input');
    }

    it('configures an interval schedule (the default mode of a fresh trigger)', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {enabled: true})])});
        openSettings('tick');

        expect(field('schedule_mode_label').val()).toBe('interval');
        expect(field('schedule_value_label').val()).toBe('1');
        expect($('.aaxis-flow-editor__cron-input').closest('div').css('display')).toBe('none');
        field('schedule_value_label').val('3');
        field('schedule_unit_label').val('hour');
        confirmSettings();

        expect(configOf(editor, 'tick')).toEqual({enabled: true, mode: 'interval', value: 3, unit: 'hour'});
        expect(tile('tick').classList.contains('is-config-invalid')).toBe(false);
    });

    it.each(['0', '1.5', ''])('rejects the interval value %j (tile red)', value => {
        createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {enabled: true, mode: 'interval', value: 2, unit: 'day'})])});
        openSettings('tick');
        expect(field('schedule_value_label').val()).toBe('2');
        expect(field('schedule_unit_label').val()).toBe('day');

        field('schedule_value_label').val(value);
        confirmSettings();

        expect(tile('tick').classList.contains('is-config-invalid')).toBe(true);
    });

    it('switches to cron mode and stores the trimmed expression; the Enabled switch disarms the flow', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {enabled: true})])});
        openSettings('tick');

        field('schedule_mode_label').val('cron').trigger('change');
        expect(field('schedule_value_label').closest('.aaxis-flow-editor__settings-row').css('display')).toBe('none');
        typeCron(' 0 3 * * mon-fri ');
        $('.aaxis-flow-editor__settings input[type="checkbox"]').first().prop('checked', false);
        confirmSettings();

        expect(configOf(editor, 'tick')).toEqual({enabled: false, mode: 'cron', expression: '0 3 * * mon-fri'});
        // Switched off, the flow still runs manually (the switch gates the automatic runs only).
        expect(root(editor).find('[data-role="debug"]').prop('disabled')).toBe(false);
    });

    it('opens a legacy {expression} config in cron mode', () => {
        createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {expression: '*/5 * * * *'})])});
        openSettings('tick');

        expect(field('schedule_mode_label').val()).toBe('cron');
        expect(cronInput().val()).toBe('*/5 * * * *');
        expect(cronInput().hasClass('aaxis-flow-editor__cron-input--invalid')).toBe(false);
        // A legacy config without the flag reads as enabled.
        expect($('.aaxis-flow-editor__settings input[type="checkbox"]').first().prop('checked')).toBe(true);
    });

    it('shows the symbols of the cron field the caret is in', () => {
        createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {mode: 'cron', expression: ''})])});
        openSettings('tick');
        const hint = (): string => $('.aaxis-flow-editor__cron-hint').text();

        expect(hint()).toBe(`${KEY('schedule_cron_field_0')} — *  */15  0,30  10-20`);
        typeCron('0 3 ', 4);
        expect(hint()).toBe(`${KEY('schedule_cron_field_2')} — *  1  1,15  */2`);
        typeCron('0 3 1', 5);
        expect(hint()).toBe(`${KEY('schedule_cron_field_2')} — *  1  1,15  */2`);
        typeCron('0 3 1 1 1 extra ', 16);
        expect(hint()).toBe(`${KEY('schedule_cron_field_4')} — *  0-6  MON-FRI  SUN`);
        typeCron('  @daily');
        expect(hint()).toBe(KEY('schedule_cron_macros'));
    });

    it.each([
        ['*/15 0,12 10-20 * *', true],
        ['0 0 1 jan,JUL mon-fri', true],
        ['0 0 * * 7', true],
        ['5 4 * * sun', true],
        ['5 4 1-31/2 1-12 0-6', true],
        ['0 12 * * 1/2', true],
        ['@hourly', true],
        ['@annually', true],
        ['@never', false],
        ['* * * *', false],
        ['* * * * * *', false],
        ['60 * * * *', false],
        ['* 24 * * *', false],
        ['* * 0 * *', false],
        ['* * * 13 *', false],
        ['* * * * 8', false],
        ['*/0 * * * *', false],
        ['*/a * * * *', false],
        ['1/2/3 * * * *', false],
        ['/5 * * * *', false],
        ['5/ * * * *', false],
        ['5- * * * *', false],
        ['1-2-3 * * * *', false],
        ['10-5 * * * *', false],
        ['1, * * * *', false],
        ['x * * * *', false],
        ['* * * foo *', false],
        ['* * * * dec', false]
    ])('validates the cron expression %j as %s', (expression, valid) => {
        createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {mode: 'cron', expression: 'x'})])});
        openSettings('tick');

        typeCron(expression);
        expect(cronInput().hasClass('aaxis-flow-editor__cron-input--invalid')).toBe(!valid);
        confirmSettings();
        expect(tile('tick').classList.contains('is-config-invalid')).toBe(!valid);
    });

    it('accepts an endpoint path with leading and trailing slashes', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'endpoint', 'hook', 0, 0, {enabled: true})])});
        openSettings('hook');

        field('endpoint_path_label').val('//orders/{id}/v1.json//');
        confirmSettings();

        expect(configOf(editor, 'hook')).toMatchObject({path: '//orders/{id}/v1.json//', method: 'GET', public: false, response: ''});
        expect(tile('hook').classList.contains('is-config-invalid')).toBe(false);
    });

    it('configures an endpoint: method, trimmed path, public switch and the response binding', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'endpoint', 'hook', 0, 0, {enabled: true, response: 'x'})])});
        openSettings('hook');

        expect(field('endpoint_method_label').val()).toBe('GET');
        expect($('.aaxis-flow-editor__settings input[type="checkbox"]').eq(1).prop('checked')).toBe(false);
        field('endpoint_method_label').val('POST');
        field('endpoint_path_label').val(' /orders/{orderId}/lines ');
        $('.aaxis-flow-editor__settings input[type="checkbox"]').eq(1).prop('checked', true);
        dwlTextarea('endpoint_response_label').val('  {statusCode: 201}  ');
        confirmSettings();

        expect(configOf(editor, 'hook')).toEqual({
            enabled: true, method: 'POST', path: '/orders/{orderId}/lines', public: true, response: '{statusCode: 201}'
        });
        expect(tile('hook').classList.contains('is-config-invalid')).toBe(false);
    });

    it.each([
        ['', 'endpoint_path_required'],
        ['///', 'endpoint_path_required'],
        ['orders/{Body}', 'endpoint_path_reserved'],
        ['orders/{flowUuid}', 'endpoint_path_reserved'],
        ['orders/{9x}', 'endpoint_path_invalid'],
        ['orders/a b', 'endpoint_path_invalid'],
        ['orders//x', 'endpoint_path_invalid'],
        ['orders/{id}/{Payload}/', 'endpoint_path_reserved']
    ])('rejects the endpoint path %j', (path, _error) => {
        createEditor({flow: flowRecord(1, 'F', [st('t', 'endpoint', 'hook', 0, 0, {enabled: true, method: 'DELETE', path: 'a', public: true})])});
        openSettings('hook');
        expect(field('endpoint_method_label').val()).toBe('DELETE');

        field('endpoint_path_label').val(path);
        confirmSettings();

        expect(tile('hook').classList.contains('is-config-invalid')).toBe(true);
    });

    it('configures the entity-change trigger\'s system and entity (entities follow the system)', async () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'entity_change', 'changed', 0, 0, {enabled: true})])});
        await flush();
        openSettings('changed');
        await flush();

        const $system = field('config_system_label');
        const $entity = field('config_entity_label');
        expect($system.find('option').map((_i: number, o: HTMLOptionElement) => o.value).get()).toEqual(['', 'crm', 'erp']);
        expect($entity.find('option').map((_i: number, o: HTMLOptionElement) => o.value).get()).toEqual(['']);
        $system.val('crm').trigger('change');
        expect($entity.find('option').map((_i: number, o: HTMLOptionElement) => o.text).get())
            .toEqual([KEY('choose_placeholder'), 'Customer', 'order']);
        $entity.val('order');
        confirmSettings();

        expect(configOf(editor, 'changed')).toEqual({enabled: true, system: 'crm', entity: 'order'});
        expect(tile('changed').classList.contains('is-config-invalid')).toBe(false);
    });

    it('marks the entity-change trigger red without a system/entity', async () => {
        createEditor({flow: flowRecord(1, 'F', [st('t', 'entity_change', 'changed', 0, 0, {enabled: true, system: 'crm', entity: 'customer'})])});
        openSettings('changed');
        await flush();
        expect(field('config_entity_label').val()).toBe('customer');

        field('config_system_label').val('erp').trigger('change');
        confirmSettings();

        expect(tile('changed').classList.contains('is-config-invalid')).toBe(true);
    });

    it('configures a subflow entry: just Enabled and the name', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'subflow', 'entry', 0, 0, {enabled: false})], [], {type: 'subflow'})});
        openSettings('entry');

        expect($('.aaxis-flow-editor__settings input[type="checkbox"]').prop('checked')).toBe(false);
        $('.aaxis-flow-editor__settings input[type="checkbox"]').prop('checked', true);
        confirmSettings();

        expect(configOf(editor, 'entry')).toEqual({enabled: true});
    });
});

describe('OntologyFlowEditorComponent — simple step settings', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('configures a DWL transform: code and destination', () => {
        const editor = editorWith(st('d', 'dwl_transform', 'shape', 300, 0));
        openSettings('shape');

        expect(field('destination_label').val()).toBe('payload');
        dwlTextarea('dwl_code_label').val('payload map $.id');
        field('destination_label').val(' ids ');
        confirmSettings();

        expect(configOf(editor, 'shape')).toEqual({code: 'payload map $.id', destination: 'ids'});
        expect(tile('shape').classList.contains('is-config-invalid')).toBe(false);
    });

    it.each([
        ['code missing', '', 'payload'],
        ['destination missing', 'x', '  '],
        ['reserved destination flowUuid', 'x', 'FlowUuid'],
        ['reserved destination choiceResults', 'x', 'choiceresults'],
        ['legacy reserved flow-uuid', 'x', 'flow-uuid']
    ])('marks a DWL transform red when %s', (_label, code, destination) => {
        editorWith(st('d', 'dwl_transform', 'shape', 300, 0, {code: 'old', destination: 'out'}));
        openSettings('shape');
        expect(dwlTextarea('dwl_code_label').val()).toBe('old');
        expect(field('destination_label').val()).toBe('out');

        dwlTextarea('dwl_code_label').val(code);
        field('destination_label').val(destination);
        confirmSettings();

        expect(tile('shape').classList.contains('is-config-invalid')).toBe(true);
    });

    it('configures a logger message as text or as DWL', () => {
        const editor = editorWith(st('l', 'logger', 'log', 300, 0));
        openSettings('log');

        dwlTextarea('logger_message_label').val('hello');
        confirmSettings();
        expect(configOf(editor, 'log')).toEqual({message: 'hello', message_dwl: false});

        openSettings('log');
        $('.aaxis-flow-editor__settings .aaxis-dwl-field input[type="checkbox"]').prop('checked', true).trigger('change');
        confirmSettings();
        expect(configOf(editor, 'log')).toEqual({message: 'hello', message_dwl: true});

        openSettings('log');
        dwlTextarea('logger_message_label').val('   ');
        confirmSettings();
        expect(tile('log').classList.contains('is-config-invalid')).toBe(true);
    });

    it('configures an event value, red while empty', () => {
        const editor = editorWith(st('e', 'event', 'ev', 300, 0, {value: 'x', value_dwl: true}));
        openSettings('ev');
        expect($('.aaxis-flow-editor__settings .aaxis-dwl-field input[type="checkbox"]').prop('checked')).toBe(true);

        dwlTextarea('event_value_label').val('done');
        confirmSettings();
        expect(configOf(editor, 'ev')).toEqual({value: 'done', value_dwl: true});

        openSettings('ev');
        dwlTextarea('event_value_label').val('');
        confirmSettings();
        expect(tile('ev').classList.contains('is-config-invalid')).toBe(true);
    });

    it('configures an MS Teams message variable and an https webhook', () => {
        const editor = editorWith(st('m', 'ms_teams', 'teams', 300, 0));
        openSettings('teams');

        field('msteams_message_label').val(' summary ');
        $('.aaxis-flow-editor__settings textarea').val(' https://x.powerplatform.com/hook ');
        confirmSettings();

        expect(configOf(editor, 'teams')).toEqual({message: 'summary', webhook: 'https://x.powerplatform.com/hook'});
        expect(tile('teams').classList.contains('is-config-invalid')).toBe(false);
    });

    it.each([
        ['no message variable', '', 'https://example.com'],
        ['no webhook', 'm', ''],
        ['a plain-http webhook', 'm', 'http://example.com'],
        ['a malformed webhook', 'm', 'not a url']
    ])('marks MS Teams red with %s', (_label, message, webhook) => {
        editorWith(st('m', 'ms_teams', 'teams', 300, 0, {message: 'x', webhook: 'https://a.b'}));
        openSettings('teams');
        expect(field('msteams_message_label').val()).toBe('x');

        field('msteams_message_label').val(message);
        $('.aaxis-flow-editor__settings textarea').val(webhook);
        confirmSettings();

        expect(tile('teams').classList.contains('is-config-invalid')).toBe(true);
    });

    it('blocks the confirm while the section catalogs load, then unlocks and focuses the name', async () => {
        let release!: () => void;
        const gate = new Promise<void>(resolve => {
            release = resolve;
        });
        mockFetch({aaxis_ontology_entity_list: gate.then(() => ({body: {systems: [], entities: []}}))});
        const editor = editorWith(st('r', 'entity_read', 'read', 300, 0));
        openSettings('read');

        expect($('.aaxis-flow-editor__settings-loading')).toHaveLength(1);
        nameInput().val('renamed');
        confirmSettings();
        expect(tileNames()).toContain('read');

        release();
        await flush();
        expect($('.aaxis-flow-editor__settings-loading')).toHaveLength(0);
        expect(document.activeElement).toBe(nameInput()[0]);
        confirmSettings();
        expect(tileNames()).toContain('renamed');
        expect(configOf(editor, 'renamed')).toMatchObject({destination: 'payload', system: '', entity: ''});
    });
});

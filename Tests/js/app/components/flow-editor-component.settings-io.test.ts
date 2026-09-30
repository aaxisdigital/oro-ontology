import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    configOf, confirmSettings, createEditor, dwlTextarea, field, flowRecord, flush, mockFetch, openSettings, st, tile,
    useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

function options($select: any, attr: 'value' | 'text' = 'value'): string[] {
    return $select.find('option').map((_i: number, o: HTMLOptionElement) => (attr === 'value' ? o.value : o.text)).get();
}

function visible($el: any): boolean {
    return $el.closest('.aaxis-flow-editor__settings-col').css('display') !== 'none';
}

/** One step behind a cron trigger; the settings popup opened with its catalogs loaded. */
async function open(step: any, flowExtra: Record<string, any> = {}): Promise<any> {
    const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {enabled: true}), step], [], flowExtra)});
    await flush();
    openSettings(step.name);
    await flush();
    return editor;
}

const FLOWS = {records: [
    {id: 1, name: 'F', type: 'subflow'},
    {id: 20, name: 'Zeta', type: 'subflow'},
    {id: 21, name: null, type: 'subflow'},
    {id: 22, name: 'alpha', type: 'subflow'},
    {id: 30, name: 'Main', type: 'flow'}
]};

describe('OntologyFlowEditorComponent — Entity Read / Entity Write settings', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('reads all records with optional ordering and limit from the entity\'s reader attributes', async () => {
        const editor = await open(st('r', 'entity_read', 'read', 300, 0));
        const $system = field('config_system_label');
        const $entity = field('config_entity_label');
        const $mode = field('reader_mode_label');
        const $orderBy = field('reader_order_by_label');

        expect($mode.val()).toBe('all');
        expect(visible($orderBy)).toBe(true);
        expect(visible(field('reader_order_dir_label'))).toBe(false);
        expect(visible(field('reader_record_id_label'))).toBe(false);
        $system.val('crm').trigger('change');
        $entity.val('customer').trigger('change');
        expect(options($orderBy)).toEqual(['', 'id', 'email', 'name']);
        $orderBy.val('email').trigger('change');
        expect(visible(field('reader_order_dir_label'))).toBe(true);
        field('reader_order_dir_label').val('desc');
        field('reader_limit_label').val('100');
        field('destination_label').val('customers');
        confirmSettings();

        expect(configOf(editor, 'read')).toEqual({
            destination: 'customers', system: 'crm', entity: 'customer', mode: 'all',
            order_by: 'email', order_dir: 'desc', limit: 100
        });
        expect(tile('read').classList.contains('is-config-invalid')).toBe(false);
    });

    it('falls back to the configured attributes and restores the saved order/limit', async () => {
        const editor = await open(st('r', 'entity_read', 'read', 300, 0, {
            system: 'crm', entity: 'order', mode: 'all', order_by: 'total', order_dir: 'desc', limit: 10, destination: 'rows'
        }));

        expect(options(field('reader_order_by_label'))).toEqual(['', 'total']);
        expect(field('reader_order_by_label').val()).toBe('total');
        expect(field('reader_order_dir_label').val()).toBe('desc');
        expect(field('reader_limit_label').val()).toBe('10');
        field('reader_order_by_label').val('').trigger('change');
        field('reader_limit_label').val('');
        confirmSettings();

        // Cleared order/limit are dropped from the config, not kept from the saved one.
        expect(configOf(editor, 'read')).toEqual({destination: 'rows', system: 'crm', entity: 'order', mode: 'all'});
    });

    it('reads one record by id', async () => {
        const editor = await open(st('r', 'entity_read', 'read', 300, 0, {system: 'erp', entity: 'item', mode: 'by_id', record_id: '42'}));

        expect(visible(field('reader_record_id_label'))).toBe(true);
        expect(visible(field('reader_order_by_label'))).toBe(false);
        expect(options(field('reader_order_by_label'))).toEqual(['']);
        field('reader_record_id_label').val(' 43 ');
        confirmSettings();

        expect(configOf(editor, 'read')).toEqual({destination: 'payload', system: 'erp', entity: 'item', mode: 'by_id', record_id: '43'});
    });

    it('reads the records matching an attribute value', async () => {
        const editor = await open(st('r', 'entity_read', 'read', 300, 0, {
            system: 'crm', entity: 'customer', mode: 'by_attribute', attribute: 'email', attr_value: 'a@b.c'
        }));

        expect(visible(field('reader_attribute_label'))).toBe(true);
        expect(field('reader_attribute_label').val()).toBe('email');
        expect(field('reader_attr_value_label').val()).toBe('a@b.c');
        confirmSettings();

        expect(configOf(editor, 'read')).toEqual({
            destination: 'payload', system: 'crm', entity: 'customer', mode: 'by_attribute', attribute: 'email', attr_value: 'a@b.c'
        });
    });

    it.each([
        ['no entity', {mode: 'all'}, () => field('config_entity_label').val('')],
        ['by id without an id', {mode: 'by_id', record_id: 'x'}, () => field('reader_record_id_label').val(' ')],
        ['by attribute without the attribute', {mode: 'by_attribute', attribute: 'email', attr_value: 'x'}, () => field('reader_attribute_label').val('')],
        ['by attribute without a value', {mode: 'by_attribute', attribute: 'email', attr_value: 'x'}, () => field('reader_attr_value_label').val('')],
        ['a reserved destination', {mode: 'all'}, () => field('destination_label').val('flowUuid')]
    ])('marks an Entity Read red with %s', async (_label, config, edit) => {
        await open(st('r', 'entity_read', 'read', 300, 0, {system: 'crm', entity: 'customer', ...config}));

        edit();
        confirmSettings();

        expect(tile('read').classList.contains('is-config-invalid')).toBe(true);
    });

    it('writes the content of a context key or a DWL expression', async () => {
        const editor = await open(st('w', 'entity_write', 'write', 300, 0, {system: 'crm', entity: 'order'}));

        expect(dwlTextarea('writer_content_label').val()).toBe('payload');
        expect(field('reader_mode_label')).toHaveLength(0);
        $('.aaxis-flow-editor__settings .aaxis-dwl-field input[type="checkbox"]').prop('checked', true).trigger('change');
        dwlTextarea('writer_content_label').val(' orders ');
        confirmSettings();

        expect(configOf(editor, 'write')).toEqual({destination: 'payload', system: 'crm', entity: 'order', content: 'orders', content_dwl: true});

        openSettings('write');
        await flush();
        dwlTextarea('writer_content_label').val('');
        confirmSettings();
        expect(tile('write').classList.contains('is-config-invalid')).toBe(true);
    });

    it('reports a failed entity catalog and retries on the next open', async () => {
        const spy = mockFetch({aaxis_ontology_entity_list: new Error('down')});
        await open(st('r', 'entity_read', 'read', 300, 0));

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('catalog_load_error'));
        expect(field('config_system_label').prop('disabled')).toBe(true);
        const before = spy.mock.calls.length;
        $('.aaxis-flow-editor__settings .btn').not('.btn-primary').trigger('click');
        openSettings('read');
        await flush();
        expect(spy.mock.calls).toHaveLength(before + 1);
    });

    it('stays where the user dragged it when a mode switch re-lays the panel out', async () => {
        await open(st('r', 'entity_read', 'read', 300, 0));
        const $panel = $('.aaxis-flow-editor__settings');
        $panel.find('.aaxis-flow-editor__settings-title').trigger($.Event('mousedown', {clientX: 0, clientY: 0}));
        $(document).trigger($.Event('mousemove', {clientX: 60, clientY: 70}));
        $(document).trigger($.Event('mouseup'));

        field('reader_mode_label').val('by_id').trigger('change');

        expect([$panel[0].style.left, $panel[0].style.top]).toEqual(['60px', '70px']);
    });

    it('fetches the entity catalog once per editor session', async () => {
        const spy = mockFetch();
        await open(st('r', 'entity_read', 'read', 300, 0));
        $('.aaxis-flow-editor__settings .btn').not('.btn-primary').trigger('click');
        openSettings('read');
        await flush();

        expect(spy.mock.calls.filter((c: any[]) => c[0] === '/aaxis_ontology_entity_list')).toHaveLength(1);
    });
});

describe('OntologyFlowEditorComponent — connector-backed settings', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('configures an HTTP request against a rest_api connector; body content only when a body is sent', async () => {
        const editor = await open(st('h', 'invoke', 'call', 300, 0, {operation: 'post', body: 'empty', connector: '1'}));
        const $connector = field('connector_label');

        expect(options($connector, 'text')).toEqual([KEY('choose_placeholder'), 'api (rest_api · crm)']);
        expect($connector.val()).toBe('1');
        expect(field('operation_label').val()).toBe('post');
        expect(dwlTextarea('body_content_label').prop('disabled')).toBe(true);
        expect($('.aaxis-dwl-field').hasClass('aaxis-dwl-field--disabled')).toBe(true);
        field('path_label').val(' /v1/orders ');
        confirmSettings();
        expect(configOf(editor, 'call')).toEqual({connector: '1', operation: 'post', path: '/v1/orders', body: 'empty', destination: 'payload'});

        openSettings('call');
        await flush();
        field('body_label').val('json').trigger('change');
        expect(dwlTextarea('body_content_label').prop('disabled')).toBe(false);
        dwlTextarea('body_content_label').val('{"a": 1}');
        confirmSettings();
        expect(configOf(editor, 'call')).toMatchObject({body: 'json', body_content: '{"a": 1}', body_dwl: false});
        expect(tile('call').classList.contains('is-config-invalid')).toBe(false);
    });

    it.each([
        ['no connector', () => field('connector_label').val('')],
        ['no path', () => field('path_label').val(' ')],
        ['no destination', () => field('destination_label').val('')]
    ])('marks an HTTP request red with %s', async (_label, edit) => {
        await open(st('h', 'invoke', 'call', 300, 0, {connector: '1', path: '/x', operation: 'get', body: 'empty'}));

        edit();
        confirmSettings();

        expect(tile('call').classList.contains('is-config-invalid')).toBe(true);
    });

    it('configures a SQL query against a database connector with optional bindings', async () => {
        const editor = await open(st('q', 'sql_query', 'query', 300, 0, {connector: '2', sql: 'select 1'}));

        expect(options(field('connector_label'), 'text')).toEqual([KEY('choose_placeholder'), 'pg (database)']);
        confirmSettings();
        expect(configOf(editor, 'query')).toEqual({connector: '2', sql: 'select 1', sql_dwl: false, destination: 'payload'});

        openSettings('query');
        await flush();
        dwlTextarea('sql_bindings_label').val(' params ');
        confirmSettings();
        expect(configOf(editor, 'query')).toEqual({
            connector: '2', sql: 'select 1', sql_dwl: false, destination: 'payload', binding: 'params', binding_dwl: false
        });
    });

    it.each([
        ['no connector', () => field('connector_label').val('')],
        ['no SQL', () => dwlTextarea('sql_label').val('')],
        ['a reserved destination', () => field('destination_label').val('choiceResults')]
    ])('marks a SQL query red with %s', async (_label, edit) => {
        await open(st('q', 'sql_query', 'query', 300, 0, {connector: '2', sql: 'select 1', binding: 'b', binding_dwl: true}));
        expect($('.aaxis-flow-editor__settings .aaxis-dwl-field input[type="checkbox"]').eq(1).prop('checked')).toBe(true);

        edit();
        confirmSettings();

        expect(tile('query').classList.contains('is-config-invalid')).toBe(true);
    });

    it('offers only file-based connectors to the file operations', async () => {
        const editor = await open(st('f', 'file_read', 'read file', 300, 0, {connector: '3', path: 'in/a.csv', path_dwl: true}));

        expect(options(field('connector_label'), 'text')).toEqual([KEY('choose_placeholder'), 'files (file_system · erp)', 'box (sftp)']);
        confirmSettings();

        expect(configOf(editor, 'read file')).toEqual({connector: '3', path: 'in/a.csv', path_dwl: true, destination: 'payload'});
        expect(tile('read file').classList.contains('is-config-invalid')).toBe(false);
    });

    it('adds the content to Write File and the new name to Rename', async () => {
        const editor = await open(st('w', 'file_write', 'write file', 300, 0, {connector: '4', path: 'out.txt'}),);
        expect(dwlTextarea('writer_content_label').val()).toBe('payload');
        confirmSettings();
        expect(configOf(editor, 'write file')).toEqual({
            connector: '4', path: 'out.txt', path_dwl: false, destination: 'payload', content: 'payload', content_dwl: false
        });

        openSettings('write file');
        await flush();
        dwlTextarea('writer_content_label').val('');
        confirmSettings();
        expect(tile('write file').classList.contains('is-config-invalid')).toBe(true);
    });

    it('requires Rename\'s new name', async () => {
        const editor = await open(st('r', 'file_rename', 'mv', 300, 0, {connector: '3', path: 'a.txt', new_name: 'b.txt', new_name_dwl: false}));
        confirmSettings();
        expect(configOf(editor, 'mv')).toEqual({
            connector: '3', path: 'a.txt', path_dwl: false, destination: 'payload', new_name: 'b.txt', new_name_dwl: false
        });

        openSettings('mv');
        await flush();
        dwlTextarea('file_new_name_label').val(' ');
        confirmSettings();
        expect(tile('mv').classList.contains('is-config-invalid')).toBe(true);
    });

    it.each([
        ['no connector', () => field('connector_label').val('')],
        ['no path', () => dwlTextarea('file_path_label').val('')],
        ['no destination', () => field('destination_label').val('')]
    ])('marks a file operation red with %s', async (_label, edit) => {
        await open(st('f', 'file_delete', 'rm', 300, 0, {connector: '3', path: 'a'}));

        edit();
        confirmSettings();

        expect(tile('rm').classList.contains('is-config-invalid')).toBe(true);
    });

    it.each(['invoke', 'sql_query', 'file_list'])('reports a failed connector catalog (%s)', async type => {
        mockFetch({aaxis_ontology_connector_list: new Error('down')});
        await open(st('x', type, 'step', 300, 0));

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('catalog_load_error'));
    });
});

describe('OntologyFlowEditorComponent — subflow pickers', () => {
    useFlowEditorEnv(FlowEditorComponent);

    function $filter(): any {
        return $('.aaxis-flow-editor__combo input');
    }

    function optionTexts(): string[] {
        return $('.aaxis-flow-editor__combo-option').map((_i: number, el: HTMLElement) => el.textContent).get();
    }

    function pick(text: string): void {
        const option = $('.aaxis-flow-editor__combo-option').filter((_i: number, el: HTMLElement) => el.textContent === text)[0];
        option.dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));
    }

    it('picks the subflow to call from a name-ordered, filterable list (never the flow itself)', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        const editor = await open(st('s', 'sub_flow', 'call', 300, 0));

        expect($filter().prop('disabled')).toBe(false);
        $filter().trigger('focus');
        expect($('.aaxis-flow-editor__combo-list').prop('hidden')).toBe(false);
        expect(optionTexts()).toEqual(['21', 'alpha', 'Zeta']);
        $filter().val('ZE').trigger('input');
        expect(optionTexts()).toEqual(['Zeta']);
        $filter().val('nothing').trigger('input');
        expect($('.aaxis-flow-editor__combo-empty').text()).toBe(KEY('subflow_none'));
        $filter().val('').trigger('input');
        pick('alpha');
        expect($filter().val()).toBe('alpha');
        expect($('.aaxis-flow-editor__combo-list').prop('hidden')).toBe(true);
        confirmSettings();

        expect(configOf(editor, 'call')).toEqual({subflow: '22'});
        expect(tile('call').classList.contains('is-config-invalid')).toBe(false);
    });

    it('shows the saved subflow, clears the choice while typing and restores it on blur', async () => {
        vi.useFakeTimers();
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        const editor = await open(st('s', 'sub_flow', 'call', 300, 0, {subflow: 20}));

        expect($filter().val()).toBe('Zeta');
        $filter().trigger('focus');
        expect($('.aaxis-flow-editor__combo-option.is-selected').text()).toBe('Zeta');
        $filter().val('Zet').trigger('input');
        $filter().trigger('blur');
        vi.advanceTimersByTime(150);
        expect($filter().val()).toBe('');
        expect($('.aaxis-flow-editor__combo-list').prop('hidden')).toBe(true);
        confirmSettings();

        expect(configOf(editor, 'call')).toEqual({subflow: ''});
        expect(tile('call').classList.contains('is-config-invalid')).toBe(true);
    });

    it('forgets a saved subflow that no longer exists', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        const editor = await open(st('s', 'sub_flow', 'call', 300, 0, {subflow: 404}));

        expect($filter().val()).toBe('');
        confirmSettings();
        expect(configOf(editor, 'call')).toEqual({subflow: ''});
    });

    it('reports a failed flow catalog', async () => {
        mockFetch({aaxis_ontology_flow_list: new Error('down')});
        await open(st('s', 'sub_flow', 'call', 300, 0));

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('catalog_load_error'));
        expect($filter().prop('disabled')).toBe(true);
    });

    it('configures a foreach loop: subflow, array variable and item name', async () => {
        vi.useFakeTimers();
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        const editor = await open(st('f', 'foreach', 'loop', 300, 0, {subflow: '21', array: 'orders', item: 'order'}));

        expect($filter().val()).toBe('21');
        expect(field('foreach_array_label').val()).toBe('orders');
        $filter().trigger('focus').val('a').trigger('input');
        expect(optionTexts()).toEqual(['alpha', 'Zeta']);
        pick('Zeta');
        $filter().trigger('blur');
        vi.advanceTimersByTime(150);
        expect($filter().val()).toBe('Zeta');
        field('foreach_item_label').val(' line ');
        confirmSettings();

        expect(configOf(editor, 'loop')).toEqual({subflow: '20', array: 'orders', item: 'line'});
        expect(tile('loop').classList.contains('is-config-invalid')).toBe(false);
    });

    it.each([
        ['no subflow', {array: 'a', item: 'i'}],
        ['no array variable', {subflow: '20', item: 'i'}],
        ['no item name', {subflow: '20', array: 'a'}],
        ['a reserved item name', {subflow: '20', array: 'a', item: 'Index'}]
    ])('marks a foreach red with %s', async (_label, config) => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        await open(st('f', 'foreach', 'loop', 300, 0, config));

        confirmSettings();

        expect(tile('loop').classList.contains('is-config-invalid')).toBe(true);
    });

    it('treats typing in the foreach picker as reopening the choice', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        const editor = await open(st('f', 'foreach', 'loop', 300, 0, {subflow: '20', array: 'a', item: 'i'}));

        $filter().trigger('focus').val('Zet').trigger('input');
        expect($('.aaxis-flow-editor__combo-option.is-selected')).toHaveLength(0);
        $filter().val('none at all').trigger('input');
        expect($('.aaxis-flow-editor__combo-empty').text()).toBe(KEY('combo_no_match'));
        confirmSettings();

        expect(configOf(editor, 'loop')).toEqual({subflow: '', array: 'a', item: 'i'});
    });
});

describe('OntologyFlowEditorComponent — Invoke PHP settings', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const METHODS: Record<string, any[]> = {
        'App\\Mailer': [
            {name: 'send', params: [
                {name: 'to', type: 'string', required: true}, {name: 'count', type: '?int', required: true},
                {name: 'ratio', type: 'float', required: true}, {name: 'force', type: 'bool', required: true},
                {name: 'tags', type: 'array', required: true}, {name: 'items', type: 'iterable', required: true},
                {name: 'mixed', type: 'mixed', required: true}, {name: 'cc', type: 'string', required: false}
            ]},
            {name: 'ping', params: []}
        ],
        'App\\Clock': [{name: 'now', params: []}]
    };

    function phpRoutes(): any {
        return mockFetch({
            aaxis_ontology_flow_php_classes: {body: {classes: ['App\\Mailer', 'App\\Clock']}},
            aaxis_ontology_flow_php_methods: (url: string) => ({body: {methods: METHODS[new URL(url, 'http://x').searchParams.get('class')!]}})
        });
    }

    function combo(index: number): any {
        return $('.aaxis-flow-editor__combo input').eq(index);
    }

    /** Focuses a combobox, clears its text (listing every option) and picks one. */
    function pick(index: number, text: string): void {
        combo(index).trigger('focus').val('').trigger('input');
        const option = $('.aaxis-flow-editor__combo-option').filter((_i: number, el: HTMLElement) => el.textContent === text)[0];
        option.dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));
    }

    it('picks a class, then a method whose required parameters pre-fill the DWL object', async () => {
        const spy = phpRoutes();
        const editor = await open(st('p', 'invoke_php', 'php', 300, 0));

        expect(combo(1).prop('disabled')).toBe(true);
        pick(0, 'App\\Mailer');
        await flush();
        expect(combo(1).prop('disabled')).toBe(false);
        pick(1, 'send');
        expect(dwlTextarea('invoke_php_params_label').val()).toBe(
            "{\n    to: '',\n    count: 0,\n    ratio: 0.0,\n    force: false,\n    tags: [],\n    items: [],\n    mixed: null\n}"
        );
        pick(1, 'ping');
        expect(dwlTextarea('invoke_php_params_label').val()).toBe('');
        field('destination_label').val('result');
        confirmSettings();

        expect(configOf(editor, 'php')).toEqual({destination: 'result', class: 'App\\Mailer', method: 'ping', params: ''});
        expect(tile('php').classList.contains('is-config-invalid')).toBe(false);
        expect(spy.mock.calls.map((c: any[]) => c[0])).toContain('/aaxis_ontology_flow_php_methods?class=App%5CMailer');
    });

    it('reopens a saved step untouched, and a new class clears the method and parameters', async () => {
        phpRoutes();
        const editor = await open(st('p', 'invoke_php', 'php', 300, 0, {class: 'App\\Mailer', method: 'send', params: '{to: "x"}', destination: 'r'}));

        expect(combo(0).val()).toBe('App\\Mailer');
        expect(combo(1).val()).toBe('send');
        expect(dwlTextarea('invoke_php_params_label').val()).toBe('{to: "x"}');
        pick(0, 'App\\Clock');
        await flush();
        expect(combo(1).val()).toBe('');
        expect(dwlTextarea('invoke_php_params_label').val()).toBe('');
        confirmSettings();

        expect(configOf(editor, 'php')).toEqual({destination: 'r', class: 'App\\Clock', method: '', params: ''});
        expect(tile('php').classList.contains('is-config-invalid')).toBe(true);
    });

    it.each([
        ['no destination', {class: 'App\\Clock', method: 'now', destination: ' '}],
        ['no class', {destination: 'r'}]
    ])('marks Invoke PHP red with %s', async (_label, config) => {
        phpRoutes();
        await open(st('p', 'invoke_php', 'php', 300, 0, config));

        confirmSettings();

        expect(tile('php').classList.contains('is-config-invalid')).toBe(true);
    });

    it('retries a class\'s methods after a failed load', async () => {
        let fail = true;
        const spy = mockFetch({
            aaxis_ontology_flow_php_classes: {body: {classes: ['App\\Clock']}},
            aaxis_ontology_flow_php_methods: () => (fail ? new Error('down') : {body: {methods: METHODS['App\\Clock']}})
        });
        await open(st('p', 'invoke_php', 'php', 300, 0, {class: 'App\\Clock', method: 'now', destination: 'r'}));
        expect(combo(1).prop('disabled')).toBe(true);
        $('.aaxis-flow-editor__settings .btn').not('.btn-primary').trigger('click');

        fail = false;
        openSettings('php');
        await flush();

        expect(combo(1).val()).toBe('now');
        expect(spy.mock.calls.filter((c: any[]) => String(c[0]).startsWith('/aaxis_ontology_flow_php_methods'))).toHaveLength(2);
    });

    it('caches the class list and each class\'s methods, retrying failures', async () => {
        let fail = true;
        const spy = mockFetch({
            aaxis_ontology_flow_php_classes: () => (fail ? new Error('down') : {body: {}}),
            aaxis_ontology_flow_php_methods: () => (fail ? new Error('down') : {body: {}})
        });
        await open(st('p', 'invoke_php', 'php', 300, 0, {class: 'App\\Clock', method: 'now', destination: 'r'}));
        expect($('.aaxis-flow-editor__settings-loading')).toHaveLength(0);
        expect(combo(0).prop('disabled')).toBe(true);
        $('.aaxis-flow-editor__settings .btn').not('.btn-primary').trigger('click');

        fail = false;
        openSettings('php');
        await flush();
        expect(combo(0).val()).toBe('App\\Clock');
        expect(combo(1).prop('disabled')).toBe(false);
        $('.aaxis-flow-editor__settings .btn').not('.btn-primary').trigger('click');
        openSettings('php');
        await flush();

        const calls = spy.mock.calls.map((c: any[]) => String(c[0]).split('?')[0]);
        expect(calls.filter((u: string) => u === '/aaxis_ontology_flow_php_classes')).toHaveLength(2);
        expect(calls.filter((u: string) => u === '/aaxis_ontology_flow_php_methods')).toHaveLength(1);
    });
});

import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    CONNECTOR_CATALOG, configOf, confirmSettings, createEditor, dwlTextarea, field, flowRecord, flush, mockFetch,
    openSettings, routes, st, tile, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

function options($select: any, attr: 'value' | 'text' = 'value'): string[] {
    return $select.find('option').map((_i: number, o: HTMLOptionElement) => (attr === 'value' ? o.value : o.text)).get();
}

/** The HTTP Request path: the text input of its single-line DWL field. */
function pathInput(): any {
    return field('path_label').find('.aaxis-dwl-input__input');
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

/** The visible dropdown input of an Entity Read / Write name picker (System / Entity). */
function namePick(labelKey: string): any {
    return field(labelKey).find('.aaxis-flow-editor__combo input');
}

/** The option texts the name picker lists right now (open it first). */
function nameOptions(labelKey: string): string[] {
    return field(labelKey).find('.aaxis-flow-editor__combo-option').map((_i: number, el: HTMLElement) => el.textContent).get();
}

/** Picks `name` in a name picker's dropdown. */
function pickName(labelKey: string, name: string): void {
    namePick(labelKey).trigger('focus');
    const option = field(labelKey).find('.aaxis-flow-editor__combo-option')
        .filter((_i: number, el: HTMLElement) => el.textContent === name)[0];
    option.dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));
}

/** Flips a name picker's DWL switch. */
function nameDwl(labelKey: string, on: boolean): void {
    field(labelKey).find('.aaxis-dwl-input__switch input').prop('checked', on).trigger('change');
}

describe('OntologyFlowEditorComponent — Entity Read / Entity Write settings', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('gives the reader and the writer the same medium panel, so their fields show their values', async () => {
        await open(st('r', 'entity_read', 'read', 300, 0));
        expect($('.aaxis-flow-editor__settings').hasClass('is-medium')).toBe(true);
        $('.aaxis-flow-editor__settings').remove();

        await open(st('w', 'entity_write', 'write', 300, 0, {system: 'crm', entity: 'order'}));
        expect($('.aaxis-flow-editor__settings').hasClass('is-medium')).toBe(true);
    });

    it('reads all records with optional ordering and limit from the entity\'s reader attributes', async () => {
        const editor = await open(st('r', 'entity_read', 'read', 300, 0));
        const $mode = field('reader_mode_label');
        const $orderBy = field('reader_order_by_label');

        expect($mode.val()).toBe('all');
        expect(visible($orderBy)).toBe(true);
        expect(visible(field('reader_order_dir_label'))).toBe(false);
        expect(visible(field('reader_record_id_label'))).toBe(false);
        pickName('config_system_label', 'crm');
        pickName('config_entity_label', 'customer');
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
        ['no entity', {mode: 'all'}, () => namePick('config_entity_label').trigger('focus').val('').trigger('input')],
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

    it('picks the entity among the chosen system\'s, and drops a pick another system lacks', async () => {
        const editor = await open(st('r', 'entity_read', 'read', 300, 0, {system: 'crm', entity: 'customer'}));

        expect(namePick('config_system_label').val()).toBe('crm');
        // A field showing its pick lists everything when focused.
        namePick('config_entity_label').trigger('focus');
        expect(nameOptions('config_entity_label')).toEqual(['customer', 'order']);
        pickName('config_system_label', 'erp');
        expect(namePick('config_entity_label').val()).toBe('');
        pickName('config_entity_label', 'item');
        confirmSettings();

        expect(configOf(editor, 'read')).toEqual({destination: 'payload', system: 'erp', entity: 'item', mode: 'all'});
    });

    it('takes the system and entity names from DWL expressions, with free-text attributes', async () => {
        const editor = await open(st('r', 'entity_read', 'read', 300, 0, {
            system: 'crm', entity: 'customer', mode: 'by_attribute', attribute: 'email', attr_value: 'a@b.c'
        }));
        expect(field('config_system_label').find('.aaxis-dwl-input__input').not('.aaxis-flow-editor__combo input').is(':visible')).toBe(false);

        nameDwl('config_system_label', true);
        field('config_system_label').find('[aria-label="aaxis.ontology.flow_editor.config_system_label"]').val(' payload.system ').trigger('input');
        // A computed system: the entity dropdown offers every known entity name.
        namePick('config_entity_label').trigger('focus').val('').trigger('input');
        expect(nameOptions('config_entity_label')).toEqual(['customer', 'item', 'order']);
        pickName('config_entity_label', 'order');
        // Its attributes are unknown until it runs: the attribute is typed.
        expect(visible($('[data-role="attribute-text"]'))).toBe(true);
        expect($('[data-role="attribute-text"]').val()).toBe('email');
        $('[data-role="attribute-text"]').val(' total ');
        confirmSettings();

        expect(configOf(editor, 'read')).toEqual({
            destination: 'payload', system: 'payload.system', system_dwl: true, entity: 'order',
            mode: 'by_attribute', attribute: 'total', attr_value: 'a@b.c'
        });
        expect(tile('read').classList.contains('is-config-invalid')).toBe(false);
    });

    it('reopens a computed entity name with its switch on, and back to a pick when switched off', async () => {
        const editor = await open(st('w', 'entity_write', 'write', 300, 0, {
            system: 'crm', entity: 'payload.kind', entity_dwl: true, content: 'payload'
        }));
        const $expression = field('config_entity_label').find('[aria-label="aaxis.ontology.flow_editor.config_entity_label"]');

        expect(field('config_entity_label').find('.aaxis-dwl-input__switch input').prop('checked')).toBe(true);
        expect($expression.val()).toBe('payload.kind');
        expect(namePick('config_entity_label').is(':visible')).toBe(false);
        nameDwl('config_entity_label', false);
        expect(namePick('config_entity_label').val()).toBe('');
        pickName('config_entity_label', 'customer');
        confirmSettings();

        expect(configOf(editor, 'write')).toEqual({destination: 'payload', system: 'crm', entity: 'customer', content: 'payload', content_dwl: false});
    });

    it('marks an empty name expression red', async () => {
        await open(st('r', 'entity_read', 'read', 300, 0, {system: 'crm', entity: 'customer'}));

        nameDwl('config_system_label', true);
        confirmSettings();

        expect(tile('read').classList.contains('is-config-invalid')).toBe(true);
    });

    it('reports a failed entity catalog and retries on the next open', async () => {
        const spy = mockFetch({aaxis_ontology_entity_list: new Error('down')});
        await open(st('r', 'entity_read', 'read', 300, 0));

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('catalog_load_error'));
        expect(namePick('config_system_label').prop('disabled')).toBe(true);
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
        pathInput().val(' /v1/orders ');
        confirmSettings();
        expect(configOf(editor, 'call')).toEqual({connector: '1', operation: 'post', path: '/v1/orders', path_dwl: false, body: 'empty', destination: 'payload'});

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
        ['no path', () => pathInput().val(' ')],
        ['no destination', () => field('destination_label').val('')]
    ])('marks an HTTP request red with %s', async (_label, edit) => {
        await open(st('h', 'invoke', 'call', 300, 0, {connector: '1', path: '/x', operation: 'get', body: 'empty'}));

        edit();
        confirmSettings();

        expect(tile('call').classList.contains('is-config-invalid')).toBe(true);
    });

    it('makes an HTTP request async: the destination empties, goes inert and leaves the config', async () => {
        const editor = await open(st('h', 'invoke', 'call', 300, 0, {connector: '1', path: '/x', operation: 'post', body: 'empty', destination: 'resp'}));
        const $async = field('http_async_label').find('input');
        const $destination = field('destination_label');

        expect($async.prop('checked')).toBe(false);
        expect($destination.prop('disabled')).toBe(false);
        $async.prop('checked', true).trigger('change');
        expect($destination.val()).toBe('');
        expect($destination.prop('disabled')).toBe(true);
        expect($destination.attr('placeholder')).toBe(KEY('http_async_destination_placeholder'));
        confirmSettings();

        expect(configOf(editor, 'call')).toEqual({connector: '1', operation: 'post', path: '/x', path_dwl: false, body: 'empty', async: true});
        expect(tile('call').classList.contains('is-config-invalid')).toBe(false);

        // Reopened: still async and empty; switching it off brings a destination back (the
        // `payload` default — an async step keeps none in its config).
        openSettings('call');
        await flush();
        expect(field('http_async_label').find('input').prop('checked')).toBe(true);
        expect(field('destination_label').val()).toBe('');
        expect(field('destination_label').prop('disabled')).toBe(true);
        field('http_async_label').find('input').prop('checked', false).trigger('change');
        expect(field('destination_label').prop('disabled')).toBe(false);
        expect(field('destination_label').attr('placeholder')).toBe('');
        confirmSettings();
        expect(configOf(editor, 'call')).toEqual({connector: '1', operation: 'post', path: '/x', path_dwl: false, body: 'empty', destination: 'payload'});
    });

    it('takes the path as a single-line DWL expression when its switch is on', async () => {
        const editor = await open(st('h', 'invoke', 'call', 300, 0, {connector: '1', path: '/x', operation: 'get', body: 'empty'}));
        const $toggle = field('path_label').find('input[type="checkbox"]');

        expect($toggle.prop('checked')).toBe(false);
        expect(pathInput().hasClass('aaxis-dwl-input__input--code')).toBe(false);
        pathInput().val(' "orders/" ++ payload.id ');
        $toggle.prop('checked', true).trigger('change');
        expect(pathInput().hasClass('aaxis-dwl-input__input--code')).toBe(true);
        confirmSettings();
        expect(configOf(editor, 'call')).toMatchObject({path: '"orders/" ++ payload.id', path_dwl: true});

        openSettings('call');
        await flush();
        expect(field('path_label').find('input[type="checkbox"]').prop('checked')).toBe(true);
        expect(pathInput().val()).toBe('"orders/" ++ payload.id');
        expect(pathInput().hasClass('aaxis-dwl-input__input--code')).toBe(true);
    });

    it('gives back the destination typed before Async was switched on', async () => {
        await open(st('h', 'invoke', 'call', 300, 0, {connector: '1', path: '/x', operation: 'get', body: 'empty', destination: 'resp'}));
        const $async = field('http_async_label').find('input');

        field('destination_label').val('orders');
        $async.prop('checked', true).trigger('change');
        $async.prop('checked', false).trigger('change');

        expect(field('destination_label').val()).toBe('orders');
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

    it('gives Read File a Content type | Reader options row that stores nothing while None', async () => {
        const editor = await open(st('f', 'file_read', 'read file', 300, 0, {connector: '3', path: 'in/a.csv'}));
        const $type = $('[data-role="file-content-type"]');
        const $options = $('[data-role="file-reader-options"]');

        // The row sits right under Name | Connector | Destination.
        const $rows = $('.aaxis-flow-editor__settings .aaxis-flow-editor__settings-row');
        expect($rows.eq(1).find('[data-role="file-content-type"]')).toHaveLength(1);
        expect(options($type, 'text')).toEqual([
            KEY('file_content_type_none'), 'application/json', 'application/xml', 'application/csv', 'application/dw'
        ]);
        expect($type.val()).toBe('');
        expect($options.prop('disabled')).toBe(true);
        // Always DWL: code style, no text/DWL switch.
        expect($options.hasClass('aaxis-dwl-input__input--code')).toBe(true);
        expect($options.closest('.aaxis-dwl-input').find('input[type="checkbox"]')).toHaveLength(0);

        $type.val('application/csv').trigger('change');
        expect($options.prop('disabled')).toBe(false);
        $options.val(' {header: false, separator: ";"} ');
        confirmSettings();
        expect(configOf(editor, 'read file')).toEqual({
            connector: '3', path: 'in/a.csv', path_dwl: false, destination: 'payload',
            content_type: 'application/csv', reader_options: '{header: false, separator: ";"}'
        });

        openSettings('read file');
        await flush();
        expect($('[data-role="file-content-type"]').val()).toBe('application/csv');
        expect($('[data-role="file-reader-options"]').val()).toBe('{header: false, separator: ";"}');
        $('[data-role="file-reader-options"]').val('');
        confirmSettings();
        expect(configOf(editor, 'read file').content_type).toBe('application/csv');
        expect(configOf(editor, 'read file')).not.toHaveProperty('reader_options');

        openSettings('read file');
        await flush();
        $('[data-role="file-reader-options"]').val('{header: false}');
        $('[data-role="file-content-type"]').val('').trigger('change');
        confirmSettings();
        expect(configOf(editor, 'read file')).toEqual({connector: '3', path: 'in/a.csv', path_dwl: false, destination: 'payload'});
    });

    it('keeps a Read File content type the list does not offer', async () => {
        const editor = await open(st('f', 'file_read', 'read file', 300, 0, {connector: '3', path: 'a', content_type: 'text/csv'}));

        expect(options($('[data-role="file-content-type"]'), 'text').slice(-1)).toEqual(['text/csv']);
        expect($('[data-role="file-content-type"]').val()).toBe('text/csv');
        confirmSettings();
        expect(configOf(editor, 'read file').content_type).toBe('text/csv');
    });

    it.each(['file_list', 'file_write', 'file_delete', 'file_rename'])('has no content type row on %s', async type => {
        await open(st('f', type, 'op', 300, 0, {connector: '3', path: 'a'}));

        expect($('[data-role="file-content-type"]')).toHaveLength(0);
        expect($('[data-role="file-reader-options"]')).toHaveLength(0);
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

    function $refresh(): any {
        return $('[data-role="connector-refresh"]');
    }

    function connectorFetches(spy: any): number {
        return spy.mock.calls.filter((c: any[]) => String(c[0]).startsWith('/aaxis_ontology_connector_list')).length;
    }

    it('re-fetches the connector catalog on refresh, keeping the current selection', async () => {
        const spy = mockFetch();
        await open(st('h', 'invoke', 'call', 300, 0, {connector: '1', path: '/x', operation: 'get', body: 'empty'}));
        expect(connectorFetches(spy)).toBe(1);
        expect($refresh().prop('disabled')).toBe(false);

        routes.aaxis_ontology_connector_list = {body: {records: [
            ...CONNECTOR_CATALOG.records,
            {id: 9, name: 'fresh', type: 'rest_api'}
        ]}};
        $refresh().trigger('click');
        expect($refresh().prop('disabled')).toBe(true);
        expect($refresh().find('i').hasClass('fa-spin')).toBe(true);
        expect(field('connector_label').prop('disabled')).toBe(true);
        await flush();

        expect(connectorFetches(spy)).toBe(2);
        expect(options(field('connector_label'), 'text')).toEqual([KEY('choose_placeholder'), 'api (rest_api · crm)', 'fresh (rest_api)']);
        expect(field('connector_label').val()).toBe('1');
        expect(field('connector_label').prop('disabled')).toBe(false);
        expect($refresh().prop('disabled')).toBe(false);
        expect($refresh().find('i').hasClass('fa-spin')).toBe(false);
    });

    it('keeps a choice made before refreshing, and the refreshed catalog serves later panels', async () => {
        const spy = mockFetch();
        const editor = await open(st('q', 'sql_query', 'query', 300, 0, {sql: 'select 1'}));
        routes.aaxis_ontology_connector_list = {body: {records: [
            {id: 2, name: 'pg', type: 'database'},
            {id: 7, name: 'pg2', type: 'database'}
        ]}};
        field('connector_label').val('2');
        $refresh().trigger('click');
        await flush();

        expect(field('connector_label').val()).toBe('2');
        field('connector_label').val('7');
        confirmSettings();
        await flush();
        expect(configOf(editor, 'query').connector).toBe('7');

        openSettings('query');
        await flush();
        expect(connectorFetches(spy)).toBe(2);
        expect(options(field('connector_label'), 'text')).toEqual([KEY('choose_placeholder'), 'pg (database)', 'pg2 (database)']);
    });

    it('drops a connector that no longer exists and keeps the old options when a refresh fails', async () => {
        mockFetch();
        await open(st('f', 'file_read', 'read file', 300, 0, {connector: '3', path: 'a'}));

        routes.aaxis_ontology_connector_list = new Error('down');
        $refresh().trigger('click');
        await flush();
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('catalog_load_error'));
        expect(options(field('connector_label'))).toEqual(['', '3', '4']);
        expect(field('connector_label').val()).toBe('3');
        expect(field('connector_label').prop('disabled')).toBe(false);

        routes.aaxis_ontology_connector_list = {body: {records: [{id: 4, name: 'box', type: 'sftp'}]}};
        $refresh().trigger('click');
        await flush();
        expect(options(field('connector_label'))).toEqual(['', '4']);
        expect(field('connector_label').val()).toBe('');
    });

    it('retries with the stored connector when the first load failed', async () => {
        mockFetch({aaxis_ontology_connector_list: new Error('down')});
        await open(st('h', 'invoke', 'call', 300, 0, {connector: '1', path: '/x', operation: 'get', body: 'empty'}));
        expect(field('connector_label').prop('disabled')).toBe(true);
        expect($refresh().prop('disabled')).toBe(false);

        routes.aaxis_ontology_connector_list = {body: CONNECTOR_CATALOG};
        $refresh().trigger('click');
        await flush();
        expect(field('connector_label').val()).toBe('1');
        expect(field('connector_label').prop('disabled')).toBe(false);
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

describe('OntologyFlowEditorComponent — Log event switch', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const logSwitch = (): any => $('.aaxis-flow-editor__settings-actions [data-role="log-event"]');

    it('sits left of the buttons on every non-trigger step and stores log_event only when off', async () => {
        const editor = await open(st('l', 'logger', 'log', 300, 0, {message: 'hi'}));
        const $actions = $('.aaxis-flow-editor__settings-actions');

        expect($actions.children().first().is('[data-role="log-event"]')).toBe(true);
        expect($actions.children().last().hasClass('aaxis-flow-editor__settings-buttons')).toBe(true);
        expect(logSwitch().text()).toBe(KEY('step_log_event_label'));
        expect(logSwitch().find('input').prop('checked')).toBe(true);
        logSwitch().find('input').prop('checked', false);
        confirmSettings();
        expect(configOf(editor, 'log')).toEqual({message: 'hi', message_dwl: false, log_event: false});
        expect(tile('log').classList.contains('is-config-invalid')).toBe(false);

        openSettings('log');
        await flush();
        expect(logSwitch().find('input').prop('checked')).toBe(false);
        logSwitch().find('input').prop('checked', true);
        confirmSettings();
        expect(configOf(editor, 'log')).toEqual({message: 'hi', message_dwl: false});
    });

    it('gives a name-only step a config only while its events are off', async () => {
        const editor = await open(st('e', 'email', 'mail', 300, 0));

        logSwitch().find('input').prop('checked', false);
        confirmSettings();
        expect(configOf(editor, 'mail')).toEqual({log_event: false});

        openSettings('mail');
        await flush();
        logSwitch().find('input').prop('checked', true);
        confirmSettings();
        expect(configOf(editor, 'mail')).toBeNull();
    });

    it('is not offered on triggers, whose buttons stay on the right', async () => {
        createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {enabled: true})])});
        await flush();
        openSettings('tick');
        await flush();

        expect(logSwitch()).toHaveLength(0);
        expect($('.aaxis-flow-editor__settings-actions').children()).toHaveLength(1);
        expect($('.aaxis-flow-editor__settings-buttons .btn-primary')).toHaveLength(1);
    });
});

describe('OntologyFlowEditorComponent — destination pre-filled from the name', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const typeName = (name: string): void => {
        field('step_name_label').val(name).trigger('input');
    };

    it('mirrors the name into a destination the step never stored, spaces and dashes as underscores', async () => {
        const editor = await open(st('d', 'dwl_transform', 'shape', 300, 0));
        expect(field('destination_label').val()).toBe('payload');

        typeName('Load orders');
        expect(field('destination_label').val()).toBe('Load_orders');
        typeName(' Load - all  orders ');
        expect(field('destination_label').val()).toBe('Load_all_orders');
        typeName('');
        expect(field('destination_label').val()).toBe('Load_all_orders');

        typeName('rows');
        dwlTextarea('dwl_code_label').val('payload');
        confirmSettings();
        expect(configOf(editor, 'rows')).toMatchObject({destination: 'rows'});
    });

    it('leaves a stored destination alone', async () => {
        await open(st('r', 'entity_read', 'read', 300, 0, {destination: 'records', system: 'erp', entity: 'Order', mode: 'all'}));

        typeName('Read orders');

        expect(field('destination_label').val()).toBe('records');
    });

    it('stops once the destination is typed, and resumes when it is cleared', async () => {
        await open(st('d', 'dwl_transform', 'shape', 300, 0));

        typeName('first');
        field('destination_label').val('mine');
        typeName('second');
        expect(field('destination_label').val()).toBe('mine');

        field('destination_label').val('');
        typeName('third-try');
        expect(field('destination_label').val()).toBe('third_try');
    });

    it('does not fill the destination of an async HTTP request', async () => {
        await open(st('h', 'invoke', 'call', 300, 0, {connector: '1', path: '/x', operation: 'post', body: 'empty', async: true}));

        typeName('Push orders');

        expect(field('destination_label').val()).toBe('');
        field('http_async_label').find('input').prop('checked', false).trigger('change');
        expect(field('destination_label').val()).toBe('payload');
        typeName('Push orders now');
        expect(field('destination_label').val()).toBe('Push_orders_now');
    });

    it('does nothing on steps without a destination', async () => {
        await open(st('l', 'logger', 'log', 300, 0, {message: 'hi'}));

        typeName('Log it');

        expect($('[data-role="destination"]')).toHaveLength(0);
    });
});

describe('OntologyFlowEditorComponent — HTTP Request layout and the connector combobox', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const rowLabels = (): string[][] => $('.aaxis-flow-editor__settings .aaxis-flow-editor__settings-row').map((_i: number, row: HTMLElement) =>
        [$(row).children('.aaxis-flow-editor__settings-col').map((_j: number, col: HTMLElement) => $(col).children('label').first().text()).get()]
    ).get();

    async function openWith(step: any, options: Record<string, any> = {}): Promise<any> {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 0, 0, {enabled: true}), step]), ...options});
        await flush();
        openSettings(step.name);
        await flush();
        return editor;
    }

    const $filter = (): any => $('[data-role="connector-filter"]');
    const listed = (): string[] => $('[data-role="connector-list"] .aaxis-flow-editor__combo-option').map((_i: number, el: HTMLElement) => el.textContent).get();

    it('lays the HTTP Request out as Name | Connector, Operation | Body | Destination | Async, then Path', async () => {
        mockFetch();
        await openWith(st('h', 'invoke', 'call', 300, 0, {connector: '1'}));

        expect(rowLabels().slice(0, 3)).toEqual([
            [KEY('step_name_label'), KEY('connector_label')],
            [KEY('operation_label'), KEY('body_label'), KEY('destination_label'), KEY('http_async_label')],
            [KEY('path_label')]
        ]);
        const grows = $('.aaxis-flow-editor__settings-row').eq(1).children().map((_i: number, el: HTMLElement) => el.style.flex).get();
        expect(grows.map((g: string) => Number.parseFloat(g))).toEqual([2.5, 2.5, 4, 1]);
    });

    it('shows the picked connector, filters by what is typed and picks with a click', async () => {
        mockFetch();
        const editor = await openWith(st('f', 'file_list', 'files', 300, 0, {connector: '3', path: '/'}));
        expect($filter().val()).toBe('files (file_system · erp)');
        expect($filter().attr('placeholder')).toBe(KEY('connector_filter_placeholder'));

        $filter().trigger('focus');
        expect(listed()).toEqual(['files (file_system · erp)', 'box (sftp)']); // the shown pick is no filter
        $filter().val('BO').trigger('input');
        expect(listed()).toEqual(['box (sftp)']);
        $('[data-role="connector-list"] [data-value="4"]')[0].dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));

        expect(field('connector_label').val()).toBe('4');
        expect($filter().val()).toBe('box (sftp)');
        confirmSettings();
        expect(configOf(editor, 'files').connector).toBe('4');
    });

    it('takes the first match on Enter, says when nothing matches, and restores the pick on blur', async () => {
        vi.useFakeTimers();
        mockFetch();
        await openWith(st('f', 'file_list', 'files', 300, 0, {connector: '3', path: '/'}));

        $filter().trigger('focus').val('zzz').trigger('input');
        expect($('[data-role="connector-list"] .aaxis-flow-editor__combo-empty').text()).toBe(KEY('combo_no_match'));
        $filter().trigger('blur');
        vi.advanceTimersByTime(200);
        expect($filter().val()).toBe('files (file_system · erp)');

        $filter().trigger('focus').val('box').trigger('input');
        $filter().trigger($.Event('keydown', {key: 'Enter'}));
        expect(field('connector_label').val()).toBe('4');
        vi.useRealTimers();
    });

    it('offers "Add new connector…" only when the user may create one', async () => {
        mockFetch();
        await openWith(st('h', 'invoke', 'call', 300, 0, {connector: '1'}));
        $filter().trigger('focus');

        expect($('[data-role="connector-add"]')).toHaveLength(0);
    });

    it('opens the create page in a new tab and, back here, picks the one new connector', async () => {
        const spy = mockFetch();
        const open = vi.spyOn(window, 'open').mockReturnValue(null);
        await openWith(st('h', 'invoke', 'call', 300, 0, {connector: '1'}), {connectorCreateUrl: '/connectors/create'});
        $filter().trigger('focus');
        expect($('[data-role="connector-add"]').text()).toBe(KEY('connector_add'));

        $('[data-role="connector-add"]')[0].dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));
        expect(open).toHaveBeenCalledWith('/connectors/create', '_blank');
        expect(field('connector_label').val()).toBe('1'); // nothing picked by the action itself

        routes.aaxis_ontology_connector_list = {body: {records: [...CONNECTOR_CATALOG.records, {id: 9, name: 'brand-new', type: 'rest_api'}]}};
        window.dispatchEvent(new Event('focus'));
        await flush();

        expect(spy.mock.calls.filter((c: any[]) => String(c[0]).startsWith('/aaxis_ontology_connector_list'))).toHaveLength(2);
        expect(field('connector_label').val()).toBe('9');
        expect($filter().val()).toBe('brand-new (rest_api)');
    });

    it('keeps the current pick when coming back finds no single new connector', async () => {
        mockFetch();
        vi.spyOn(window, 'open').mockReturnValue(null);
        await openWith(st('h', 'invoke', 'call', 300, 0, {connector: '1'}), {connectorCreateUrl: '/connectors/create'});
        $filter().trigger('focus');
        $('[data-role="connector-add"]')[0].dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));

        window.dispatchEvent(new Event('focus'));
        await flush();

        expect(field('connector_label').val()).toBe('1');
    });
});

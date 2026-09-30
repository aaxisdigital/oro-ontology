import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import {ENTITIES, LIST_URL, mountDataView, record} from '../../helpers/data-view';
import {FakeDialog, FakeSelect2, flush, jsonResponse, resetFakes} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/data-grid', async () => (await import('../../helpers/page-mocks')).dataGridModule());
vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());
vi.mock('oroform/js/app/views/select2-view', async () => (await import('../../helpers/page-mocks')).select2Module());
// Show translation parameters, which the shared stub drops when the key has no placeholders.
vi.mock('orotranslation/js/translator', () => ({
    default: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key
}));

const CREATE_URL = '/aaxis_ontology_data_create';

describe('OntologyDataViewComponent — Add / Update Data form', () => {
    beforeEach(() => {
        resetFakes();
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    async function openAdd(routes: Record<string, any> = {}, row?: any) {
        const ctx = mountDataView({}, routes);
        await flush();
        if (row) {
            ctx.grid.options.onAction('update', row);
        } else {
            ctx.$el.find('[data-role="add"]').trigger('click');
        }
        const dialog = FakeDialog.instances[0];
        const $c = dialog.$content;

        return {
            ...ctx,
            dialog,
            $system: $c.find('[data-role="system"]'),
            $entity: $c.find('[data-role="entity"]'),
            $format: $c.find('[data-role="format"]'),
            $payload: $c.find('[data-role="payload"]'),
            $payloadError: $c.find('[data-role="payload-error"]'),
            $wand: $c.find('[data-role="format-payload"]'),
            $submit: $c.find('.aaxis-data-form__footer .btn-primary'),
            $cancel: $c.find('[data-role="close"]'),
            $error: $c.find('.aaxis-rfm__alert')
        };
    }

    const type = ($payload: any, text: string) => $payload.val(text).trigger('input');
    const optionValues = ($select: any) => $select.find('option').map((_i: number, o: any) => o.value).get();

    describe('add mode', () => {
        it('opens a wide dialog titled Add, with no subtitle', async () => {
            const {dialog} = await openAdd();

            expect(dialog.options).toMatchObject({title: 'aaxis.ontology.data_view.add_title', width: '860px', subtitle: undefined});
        });

        it('offers every system and every entity, plus a JSON / CSV format choice', async () => {
            const {$system, $entity, $format} = await openAdd();

            expect(optionValues($system)).toEqual(['', '1', '2']);
            expect($system.find('option').first().text()).toBe('aaxis.ontology.data_view.choose_system');
            expect(optionValues($entity)).toEqual(['', '10', '11', '20', '30']);
            expect($entity.find('option').first().text()).toBe('aaxis.ontology.data_view.choose_entity');
            expect(optionValues($format)).toEqual(['json', 'csv']);
            expect($system.prop('disabled')).toBe(false);
        });

        it('marks the entity label as required and hides the alert until needed', async () => {
            const {dialog, $error} = await openAdd();

            expect(dialog.$content.find('.aaxis-rfm__req')).toHaveLength(1);
            expect($error.attr('hidden')).toBe('hidden');
        });

        it('enhances the three selects and keeps Submit disabled until the form is valid', async () => {
            const {$submit} = await openAdd();

            expect(FakeSelect2.instances.map(s => s.options.select2Config)).toEqual([
                {width: '100%', allowClear: true},
                {width: '100%', minimumResultsForSearch: Infinity},
                {width: '100%', allowClear: true}
            ]);
            expect($submit.prop('disabled')).toBe(true);
            expect($submit.text()).toBe('aaxis.common.grid.submit');
        });

        it('leaves the native controls in place when Select2 cannot initialise', async () => {
            FakeSelect2.failNext = true;
            const {$entity} = await openAdd();

            expect($entity).toHaveLength(1);
            expect(FakeSelect2.instances).toHaveLength(0);
        });

        it('closes on Cancel', async () => {
            const {dialog, $cancel} = await openAdd();

            $cancel.trigger('click');

            expect(dialog.close).toHaveBeenCalled();
        });
    });

    describe('system / entity wiring', () => {
        it('filters the entity list to the chosen system', async () => {
            const {$system, $entity} = await openAdd();

            $system.val('2').trigger('change');

            expect(optionValues($entity)).toEqual(['', '20']);
        });

        it('shows every entity again when the system is cleared', async () => {
            const {$system, $entity} = await openAdd();
            $system.val('2').trigger('change');

            $system.val('').trigger('change');

            expect(optionValues($entity)).toEqual(['', '10', '11', '20', '30']);
        });

        it('keeps the selected entity when it belongs to the newly chosen system, else clears it', async () => {
            const {$system, $entity} = await openAdd();
            $entity.val('10');

            $system.val('1').trigger('change');
            expect($entity.val()).toBe('10');

            $system.val('2').trigger('change');
            expect($entity.val()).toBe('');
        });

        it('re-creates the entity Select2 on every rebuild, disposing the previous one', async () => {
            const {$system} = await openAdd();
            const before = FakeSelect2.instances.length;
            const entitySelect = FakeSelect2.instances[before - 1];

            $system.val('1').trigger('change');

            expect(entitySelect.dispose).toHaveBeenCalledTimes(1);
            expect(FakeSelect2.instances).toHaveLength(before + 1);
        });

        it('picking an entity selects its system without rebuilding the entity list', async () => {
            const {$system, $entity} = await openAdd();

            $entity.val('20').trigger('change');

            expect($system.val()).toBe('2');
            expect(optionValues($entity)).toEqual(['', '10', '11', '20', '30']);
        });

        it('leaves the system alone for an entity with none', async () => {
            const {$system, $entity} = await openAdd();
            $system.val('1');

            $entity.val('30').trigger('change');

            expect($system.val()).toBe('1');
        });

        it('clearing the entity leaves the system alone', async () => {
            const {$system, $entity} = await openAdd();
            $entity.val('10').trigger('change');

            $entity.val('').trigger('change');

            expect($system.val()).toBe('1');
        });
    });

    describe('payload validation', () => {
        async function ready(entityId: string) {
            const ctx = await openAdd();
            ctx.$entity.val(entityId).trigger('change');

            return ctx;
        }

        it('needs an entity: a valid payload alone does not enable Submit', async () => {
            const {$payload, $submit} = await openAdd();

            type($payload, '{"id":"A"}');

            expect($submit.prop('disabled')).toBe(true);
        });

        it('enables Submit for a valid JSON record that carries the entity unique attribute', async () => {
            const {$payload, $submit, $payloadError} = await ready('10');

            type($payload, '{"id":"A-1","total":1}');

            expect($submit.prop('disabled')).toBe(false);
            expect($payloadError.text()).toBe('');
        });

        it('stays quiet (but disabled) for an empty JSON payload', async () => {
            const {$payload, $submit, $payloadError} = await ready('10');

            type($payload, '   ');

            expect($submit.prop('disabled')).toBe(true);
            expect($payloadError.text()).toBe('');
        });

        it('asks for a CSV header when the CSV payload is empty', async () => {
            const {$format, $payload, $payloadError} = await ready('10');

            $format.val('csv').trigger('change');
            type($payload, '');

            expect($payloadError.text()).toBe('aaxis.ontology.data_view.csv_header_required');
        });

        it('reports invalid JSON', async () => {
            const {$payload, $submit, $payloadError} = await ready('10');

            type($payload, '{"id":');

            expect($payloadError.text()).toBe('aaxis.ontology.data_view.payload_invalid');
            expect($submit.prop('disabled')).toBe(true);
        });

        it.each([['"text"'], ['5'], ['null'], ['[]']])('reports %s as not a record set when a unique attribute applies', async raw => {
            const {$payload, $payloadError} = await ready('10');

            type($payload, raw);

            expect($payloadError.text()).toBe('aaxis.ontology.data_view.payload_invalid');
        });

        it.each([
            ['a record without the unique attribute', '{"total":1}', 1],
            ['a blank unique attribute', '{"id":"  "}', 1],
            ['a null unique attribute', '{"id":null}', 1],
            ['the second record of a batch', '[{"id":"A"},{"x":1}]', 2],
            ['a non-object batch element', '[{"id":"A"}, 7]', 2],
            ['a nested-array element', '[[1]]', 1]
        ])('names the row when %s is missing its id', async (_name, raw, row) => {
            const {$payload, $submit, $payloadError} = await ready('10');

            type($payload, raw);

            expect($payloadError.text())
                .toBe(`aaxis.ontology.data_view.unique_attribute_missing:{"attribute":"id","row":${row}}`);
            expect($submit.prop('disabled')).toBe(true);
        });

        it('accepts a batch where every record has the id', async () => {
            const {$payload, $submit} = await ready('10');

            type($payload, '[{"id":"A"},{"id":2}]');

            expect($submit.prop('disabled')).toBe(false);
        });

        it('skips the unique attribute check when the entity has none configured', async () => {
            const {$payload, $submit} = await ready('11');

            type($payload, '{"anything":1}');

            expect($submit.prop('disabled')).toBe(false);
        });

        it('validates CSV rows against the unique attribute', async () => {
            const {$format, $payload, $submit, $payloadError} = await ready('20');
            $format.val('csv').trigger('change');

            type($payload, 'email,name\na@x.com,Ann\n,Bob');
            expect($payloadError.text())
                .toBe('aaxis.ontology.data_view.unique_attribute_missing:{"attribute":"email","row":2}');
            expect($submit.prop('disabled')).toBe(true);

            type($payload, 'email,name\na@x.com,Ann\nb@x.com,Bob');
            expect($payloadError.text()).toBe('');
            expect($submit.prop('disabled')).toBe(false);
        });

        it('treats a CSV with only a header row as invalid when a unique attribute applies', async () => {
            const {$format, $payload, $payloadError} = await ready('20');
            $format.val('csv').trigger('change');

            type($payload, 'email,name');

            expect($payloadError.text()).toBe('aaxis.ontology.data_view.payload_invalid');
        });

        it('re-validates when the format changes', async () => {
            const {$format, $payload, $submit} = await ready('11');
            type($payload, 'a,b\n1,2');
            expect($submit.prop('disabled')).toBe(true); // not JSON

            $format.val('csv').trigger('change');

            expect($submit.prop('disabled')).toBe(false);
        });
    });

    describe('Format button', () => {
        it('re-indents JSON textually, never rounding big integers', async () => {
            const {$payload, $wand} = await openAdd();
            type($payload, '{"id":12345678901234567890,"a":[1,2],"s":"x,y"}');

            $wand.trigger('click');

            expect($payload.val()).toBe('{\n  "id": 12345678901234567890,\n  "a": [\n    1,\n    2\n  ],\n  "s": "x,y"\n}');
        });

        it('does nothing on an empty payload', async () => {
            const {$payload, $wand, $payloadError} = await openAdd();

            $wand.trigger('click');

            expect($payload.val()).toBe('');
            expect($payloadError.text()).toBe('');
        });

        it('reports an unparseable payload instead of touching it', async () => {
            const {$payload, $wand, $payloadError} = await openAdd();
            type($payload, '{oops');

            $wand.trigger('click');

            expect($payload.val()).toBe('{oops');
            expect($payloadError.text()).toBe('aaxis.ontology.data_view.payload_invalid');
        });

        it('drops blank lines from CSV', async () => {
            const {$format, $payload, $wand} = await openAdd();
            $format.val('csv').trigger('change');
            type($payload, 'a,b\r\n\r\n1,2\n   \n3,4');

            $wand.trigger('click');

            expect($payload.val()).toBe('a,b\n1,2\n3,4');
        });

        it('pretty-prints XML when that format is selected', async () => {
            const {$format, $payload, $wand} = await openAdd();
            $format.append('<option value="xml">XML</option>').val('xml').trigger('change');
            type($payload, '<root><item>1</item><empty/></root>');

            $wand.trigger('click');

            expect($payload.val()).toBe('<root>\n  <item>1</item>\n  <empty/>\n</root>');
        });

        it('reports invalid XML', async () => {
            const {$format, $payload, $wand, $payloadError} = await openAdd();
            $format.append('<option value="xml">XML</option>').val('xml').trigger('change');
            type($payload, '<root><unclosed></root>');

            $wand.trigger('click');

            expect($payloadError.text()).toBe('aaxis.ontology.data_view.payload_invalid');
        });

        it('a payload formatted before the id attribute exists still formats', async () => {
            const {$entity, $payload, $wand} = await openAdd();
            $entity.val('10').trigger('change');
            type($payload, '{"x":1}');

            $wand.trigger('click');

            expect($payload.val()).toBe('{\n  "x": 1\n}');
        });
    });

    describe('submit', () => {
        async function ready(routes: Record<string, any> = {}) {
            const ctx = await openAdd(routes);
            ctx.$entity.val('10').trigger('change');
            type(ctx.$payload, '{"id":"A-1"}');

            return ctx;
        }

        it('posts entity id, format and payload with the CSRF header, shows a spinner, then flashes, closes and reloads', async () => {
            const {$submit, dialog, fetchSpy} = await ready({[CREATE_URL]: jsonResponse({success: true})});

            $submit.trigger('click');
            expect($submit.prop('disabled')).toBe(true);
            expect($submit.find('.fa-spinner.fa-spin')).toHaveLength(1);
            expect($submit.text().trim()).toBe('aaxis.ontology.data_view.sending');
            await flush();

            const [url, init] = fetchSpy.mock.calls.find(c => c[0] === CREATE_URL)!;
            expect(url).toBe(CREATE_URL);
            expect(init.method).toBe('POST');
            expect(init.headers['X-CSRF-Header']).toBe('tok');
            expect(JSON.parse(init.body)).toEqual({entityId: 10, format: 'json', payload: '{"id":"A-1"}'});
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', 'aaxis.ontology.data_view.saved');
            expect(dialog.close).toHaveBeenCalled();
            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
        });

        it('sends CSV payloads as CSV', async () => {
            const ctx = await openAdd({[CREATE_URL]: jsonResponse({success: true})});
            ctx.$entity.val('20').trigger('change');
            ctx.$format.val('csv').trigger('change');
            type(ctx.$payload, 'email\na@x.com');

            ctx.$submit.trigger('click');

            expect(JSON.parse(ctx.fetchSpy.mock.calls.find(c => c[0] === CREATE_URL)![1].body))
                .toEqual({entityId: 20, format: 'csv', payload: 'email\na@x.com'});
            await flush();
        });

        it.each([
            ['the server message', jsonResponse({success: false, message: 'Bad record'}, false), 'Bad record'],
            ['a generic message when none is given', jsonResponse({success: false}), 'aaxis.ontology.data_view.save_error'],
            ['a generic message for an empty body', jsonResponse(null), 'aaxis.ontology.data_view.save_error']
        ])('shows %s inline and lets the user retry', async (_name, response, message) => {
            const {$submit, $error, dialog} = await ready({[CREATE_URL]: response});

            $submit.trigger('click');
            await flush();

            expect($error.text()).toBe(message);
            expect($error.attr('hidden')).toBeUndefined();
            expect($submit.prop('disabled')).toBe(false);
            expect($submit.text()).toBe('aaxis.common.grid.submit');
            expect(dialog.close).not.toHaveBeenCalled();
        });

        it('shows the generic error when the request fails', async () => {
            const {$submit, $error} = await ready({[CREATE_URL]: Promise.reject(new Error('net'))});

            $submit.trigger('click');
            await flush();

            expect($error.text()).toBe('aaxis.ontology.data_view.save_error');
            expect($submit.prop('disabled')).toBe(false);
        });

        it('hides a previous error again on the next attempt', async () => {
            const {$submit, $error} = await ready({[CREATE_URL]: jsonResponse({success: false, message: 'x'})});
            $submit.trigger('click');
            await flush();
            expect($error.attr('hidden')).toBeUndefined();

            $submit.prop('disabled', false).trigger('click');

            expect($error.attr('hidden')).toBe('hidden');
            expect($error.text()).toBe('');
            await flush();
        });

        it('does not post an invalid payload even if Submit is clicked', async () => {
            const {$submit, $payload, fetchSpy} = await ready();
            fetchSpy.mockClear();
            type($payload, '{bad');

            $submit.prop('disabled', false).trigger('click');

            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it('does not post without an entity', async () => {
            const {$submit, $entity, fetchSpy} = await ready();
            fetchSpy.mockClear();
            $entity.val('');

            $submit.prop('disabled', false).trigger('click');

            expect(fetchSpy).not.toHaveBeenCalled();
        });
    });

    describe('update mode', () => {
        const ROW = record({payload: '{"id":"A-1","total":10}'});

        it('is titled Update, subtitled entity — unique id', async () => {
            const {dialog} = await openAdd({}, ROW);

            expect(dialog.options).toMatchObject({title: 'aaxis.ontology.data_view.update_title', subtitle: 'Order — A-1'});
        });

        it.each([
            [{entity: 'Order', uniqueId: null}, 'Order'],
            [{entity: null, uniqueId: null}, undefined]
        ])('builds the update subtitle from %j as %s', async (overrides, subtitle) => {
            const {dialog} = await openAdd({}, record(overrides));

            expect(dialog.options.subtitle).toBe(subtitle);
        });

        it('locks system and entity to the record and loads the payload pretty-printed', async () => {
            const {$system, $entity, $format, $payload} = await openAdd({}, ROW);

            expect($system.val()).toBe('1');
            expect($system.prop('disabled')).toBe(true);
            expect(optionValues($entity)).toEqual(['10']);
            expect($entity.find('option').text()).toBe('Order');
            expect($entity.val()).toBe('10');
            expect($entity.prop('disabled')).toBe(true);
            expect($format.val()).toBe('json');
            expect($payload.val()).toBe('{\n  "id": "A-1",\n  "total": 10\n}');
        });

        it('enhances the entity select as-is instead of rebuilding its options', async () => {
            await openAdd({}, ROW);

            expect(FakeSelect2.instances.map(s => s.options.select2Config)).toEqual([
                {width: '100%', allowClear: true},
                {width: '100%', minimumResultsForSearch: Infinity},
                {width: '100%'}
            ]);
        });

        it('keeps Submit disabled until the payload actually differs from what was loaded', async () => {
            const {$payload, $submit} = await openAdd({}, ROW);
            const loaded = String($payload.val());
            expect($submit.prop('disabled')).toBe(true);

            type($payload, '{"id":"A-1","total":11}');
            expect($submit.prop('disabled')).toBe(false);

            type($payload, loaded);
            expect($submit.prop('disabled')).toBe(true);
        });

        it('requires the payload to keep the entity unique attribute', async () => {
            const {$payload, $submit, $payloadError} = await openAdd({}, ROW);

            type($payload, '{"total":11}');

            expect($payloadError.text())
                .toBe('aaxis.ontology.data_view.unique_attribute_missing:{"attribute":"id","row":1}');
            expect($submit.prop('disabled')).toBe(true);
        });

        it('submits the locked entity id', async () => {
            const {$payload, $submit, fetchSpy} = await openAdd({[CREATE_URL]: jsonResponse({success: true})}, ROW);
            type($payload, '{"id":"A-1","total":11}');

            $submit.trigger('click');
            await flush();

            expect(JSON.parse(fetchSpy.mock.calls.find(c => c[0] === CREATE_URL)![1].body).entityId).toBe(10);
        });

        it('loads an unparseable stored payload as it came, and a missing one as empty', async () => {
            const bad = await openAdd({}, record({payload: '{not json'}));
            expect(bad.$payload.val()).toBe('{not json');

            resetFakes();
            $(document.body).empty();
            const empty = await openAdd({}, record({payload: ''}));
            expect(empty.$payload.val()).toBe('');
        });

        it('copes with a record whose system/entity ids are missing', async () => {
            const {$system, $entity} = await openAdd({}, record({systemId: null, entityId: null, entity: null}));

            expect($system.val()).toBe('');
            expect($entity.val()).toBe('');
        });

        it('has no unique attribute check for an entity the list does not know', async () => {
            const {$payload, $payloadError} = await openAdd({}, record({entityId: 999}));

            type($payload, '{"whatever":1}');

            expect($payloadError.text()).toBe('');
        });
    });

    describe('closing', () => {
        it('disposes every Select2 and forgets the update baseline, so the next Add is ungated', async () => {
            const {dialog, component} = await openAdd({}, record());
            const selects = [...FakeSelect2.instances];

            dialog.close();

            for (const select of selects) {
                expect(select.dispose).toHaveBeenCalled();
            }
            expect(component.addDialog).toBeNull();
            expect(component.addBaseline).toBeNull();
        });

        it('tolerates selects that never initialised', async () => {
            FakeSelect2.failNext = true;
            const {dialog} = await openAdd();

            expect(() => dialog.close()).not.toThrow();
        });
    });

    describe('pure helpers', () => {
        let component: any;

        beforeEach(async () => {
            component = (await openAdd()).component;
        });

        it.each([
            ['{"a":1}', '{\n  "a": 1\n}'],
            ['[]', '[]'],
            ['{}', '{}'],
            ['[ \n ]', '[]'],
            ['{ "a" : [ ] , "b" : { } }', '{\n  "a": [],\n  "b": {}\n}'],
            ['{"a":"{[,:]}"}', '{\n  "a": "{[,:]}"\n}'],
            ['{"a":"q\\"x\\\\"}', '{\n  "a": "q\\"x\\\\"\n}'],
            ['[1,[2,[3]]]', '[\n  1,\n  [\n    2,\n    [\n      3\n    ]\n  ]\n]'],
            ['{"n":-1.5e+10,"t":true,"z":null}', '{\n  "n": -1.5e+10,\n  "t": true,\n  "z": null\n}'],
            ['{"big":123456789012345678901234567890}', '{\n  "big": 123456789012345678901234567890\n}'],
            ['\t{\r\n"a"\t:\t1\r\n}', '{\n  "a": 1\n}']
        ])('reindentJson(%j)', (raw, expected) => {
            expect(component.reindentJson(raw)).toBe(expected);
        });

        it('reindentJson never lets the depth go negative on stray closers', () => {
            expect(component.reindentJson('}}[')).toBe('\n}\n}[\n  ');
        });

        it.each([
            ['', ''],
            ['   ', ''],
            [null, ''],
            ['not json', 'not json'],
            ['  {"a":1}  ', '{\n  "a": 1\n}'],
            ['{"id":12345678901234567890}', '{\n  "id": 12345678901234567890\n}']
        ])('prettyJsonOrRaw(%j)', (raw, expected) => {
            expect(component.prettyJsonOrRaw(raw)).toBe(expected);
        });

        it('prettyJsonOrRaw hands back the original when the re-indenter output does not round-trip', () => {
            vi.spyOn(component, 'reindentJson').mockReturnValue('{"different":true}');

            expect(component.prettyJsonOrRaw('{"a":1}')).toBe('{"a":1}');
        });

        it('prettyJsonOrRaw hands back the original when the re-indenter output is not JSON', () => {
            vi.spyOn(component, 'reindentJson').mockReturnValue('{broken');

            expect(component.prettyJsonOrRaw('{"a":1}')).toBe('{"a":1}');
        });

        it.each([
            ['<root><item>1</item><empty/></root>', '<root>\n  <item>1</item>\n  <empty/>\n</root>'],
            ['<?xml version="1.0"?><root><!-- c --><i2>1</i2></root>', '<?xml version="1.0"?>\n<root>\n  <!-- c -->\n  <i2>1</i2>\n</root>']
        ])('prettyXml(%j)', (xml, expected) => {
            expect(component.prettyXml(xml)).toBe(expected);
        });

        it.each([
            ['json', '{"a":1}', true],
            ['json', '{"a":', false],
            ['csv', 'a,b\n1,2', true],
            ['csv', '\n\n', false],
            ['csv', ' \n', false],
            ['xml', '<a/>', true],
            ['xml', '<a><b></a>', false],
            ['yaml', 'a: 1', false]
        ])('isFormatValid(%s, %j) is %s', (format, raw, expected) => {
            expect(component.isFormatValid(format, raw)).toBe(expected);
        });

        it.each([
            ['json', '', []],
            ['json', '   ', []],
            ['json', '[{"a":1},{"a":2}]', [{a: 1}, {a: 2}]],
            ['json', '{"a":1}', [{a: 1}]],
            ['json', '5', null],
            ['json', 'null', null],
            ['json', '{bad', null],
            ['csv', 'a,b\n1,2\n3', [{a: '1', b: '2'}, {a: '3', b: ''}]],
            ['csv', 'a,b', null],
            ['csv', '"a", "b"\n"1","2"', [{a: '1', b: '2'}]],
            ['xml', '<a/>', null]
        ])('parseAllRecords(%s, %j)', (format, raw, expected) => {
            expect(component.parseAllRecords(format, raw)).toEqual(expected);
        });

        it.each([
            ['a,b,c', ['a', 'b', 'c']],
            [' "a" , b ', ['a', 'b']],
            ['"x,y"', ['"x', 'y"']],
            ['', ['']]
        ])('splitCsv(%j)', (line, expected) => {
            expect(component.splitCsv(line)).toEqual(expected);
        });
    });
});

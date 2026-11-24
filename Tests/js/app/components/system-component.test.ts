import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import SystemComponent from '../../../../Resources/js-src/app/components/system-component';
import {
    FakeDialog, FakeGrid, FakeRecordForm, flush, jsonResponse, navigateTo, pageElement, resetFakes, stubFetch
} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/data-grid', async () => (await import('../../helpers/page-mocks')).dataGridModule());
vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());
vi.mock('aaxiscommon/js/app/widgets/record-form-modal', async () => (await import('../../helpers/page-mocks')).recordFormModule());

const LIST_URL = '/aaxis_ontology_system_list';

const SYSTEMS = [
    {id: 1, name: 'ERP', enabled: true, external: true, entityCount: 3, recordCount: 40, flowCount: 0},
    {id: 2, name: 'OroCommerce', enabled: true, external: false, entityCount: 1, recordCount: 9, flowCount: 0}
];

describe('OntologySystemComponent', () => {
    let $el: any;

    beforeEach(() => {
        resetFakes();
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    function mount(options: Record<string, unknown> = {}, routes: Record<string, any> = {}) {
        $el = pageElement();
        const fetchSpy = stubFetch({[LIST_URL]: jsonResponse({systems: SYSTEMS}), ...routes});
        const component: any = new (SystemComponent as any)({
            _sourceElement: $el, canCreate: true, canUpdate: true, canDelete: true, ...options
        });

        return {component, fetchSpy, grid: FakeGrid.instances[0]};
    }

    describe('grid', () => {
        it('mounts a grid on the list slot with the six system columns', () => {
            const {grid} = mount();

            expect(grid.mount).toHaveBeenCalledWith($el.find('[data-role="list"]'));
            expect(grid.options.columns.map((c: any) => c.key)).toEqual(
                ['name', 'entityCount', 'recordCount', 'flowCount', 'external', 'enabled']
            );
            expect(grid.options.gridKey).toBe('ontology-system');
            expect(grid.options.preferencesUrl).toBe('/aaxis_common_grid_preference_get?gridKey=ontology-system');
            expect(grid.options.emptyText).toBe('aaxis.ontology.system_manager.empty');
        });

        it.each([
            [{canUpdate: true, canDelete: true}, ['entities', 'edit', 'delete']],
            [{canUpdate: true, canDelete: false}, ['entities', 'edit']],
            [{canUpdate: false, canDelete: true}, ['entities', 'delete']],
            [{canUpdate: false, canDelete: false}, ['entities']]
        ])('offers only the permitted row actions for %j', (perms, expected) => {
            const {grid} = mount(perms);

            expect(grid.options.actions.map((a: any) => a.key)).toEqual(expected);
        });

        it('disables Delete for internal systems only', () => {
            const {grid} = mount();
            const del = grid.action('delete');

            expect(del.variant).toBe('danger');
            expect(del.disabled({external: false})).toBe(true);
            expect(del.disabled({external: true})).toBe(false);
            expect(del.disabled({})).toBe(false);
            expect(del.disabledTitle).toBe('aaxis.ontology.system_manager.delete_internal_forbidden');
        });
    });

    describe('loading', () => {
        it('loads the systems into the grid and flips the refresh button busy while it does', async () => {
            const {grid, fetchSpy} = mount();

            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(true);
            await flush();

            expect(fetchSpy).toHaveBeenCalledWith(LIST_URL, {credentials: 'same-origin'});
            expect(grid.setRows).toHaveBeenCalledWith(SYSTEMS);
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('treats a missing list as empty', async () => {
            const {grid} = mount({}, {[LIST_URL]: jsonResponse({})});
            await flush();

            expect(grid.setRows).toHaveBeenCalledWith([]);
        });

        it('flashes an error when the list cannot be loaded and releases the busy state', async () => {
            mount({}, {[LIST_URL]: Promise.reject(new Error('down'))});
            await flush();

            expect(messenger.notificationFlashMessage)
                .toHaveBeenCalledWith('error', 'aaxis.ontology.system_manager.load_error');
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('reloads on the refresh button and opens column settings on the columns button', async () => {
            const {grid, fetchSpy} = mount();
            await flush();

            $el.find('[data-role="refresh"]').trigger('click');
            await flush();
            $el.find('[data-role="columns-settings"]').trigger('click');

            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
            expect(grid.toggleColumnSettings).toHaveBeenCalledWith($el.find('[data-role="columns-settings"]')[0]);
        });
    });

    describe('row actions', () => {
        it('opens the Entities page filtered on the system name, forwarding the click event', () => {
            const {grid} = mount();
            const event = {metaKey: true} as MouseEvent;

            grid.options.onAction('entities', {name: 'ERP & Co'}, event);

            expect(navigateTo).toHaveBeenCalledWith('/aaxis_ontology_entities?system=ERP%20%26%20Co', event);
        });

        it('encodes a missing name as empty', () => {
            const {grid} = mount();

            grid.options.onAction('entities', {name: null});

            expect(navigateTo).toHaveBeenCalledWith('/aaxis_ontology_entities?system=', undefined);
        });

        it('ignores an unknown action', () => {
            const {grid} = mount();

            grid.options.onAction('bogus', SYSTEMS[0]);

            expect(FakeRecordForm.instances).toHaveLength(0);
            expect(FakeDialog.instances).toHaveLength(0);
            expect(navigateTo).not.toHaveBeenCalled();
        });
    });

    describe('create / edit form', () => {
        it('opens an empty create form, enabled by default, on the Add button', () => {
            mount();

            $el.find('[data-role="add"]').trigger('click');

            const form = FakeRecordForm.instances[0];
            expect(form.open).toHaveBeenCalled();
            expect(form.options.title).toBe('aaxis.ontology.system_manager.create_title');
            expect(form.options.subtitle).toBe('aaxis.ontology.system_manager.create_subtitle');
            expect(form.options.values).toEqual({enabled: true});
            expect(form.options.fields.map((f: any) => [f.key, f.type, !!f.required])).toEqual([
                ['name', 'text', true],
                ['enabled', 'boolean', false]
            ]);
        });

        it('opens the create form at once for ?add=1 and drops the parameter', () => {
            window.history.replaceState({}, '', '/systems?add=1&x=2');
            mount();

            expect(FakeRecordForm.instances).toHaveLength(1);
            expect(FakeRecordForm.instances[0].options.title).toBe('aaxis.ontology.system_manager.create_title');
            expect(window.location.search).toBe('?x=2');
            window.history.replaceState({}, '', '/');
        });

        it('ignores ?add=1 for a user who may not create systems', () => {
            window.history.replaceState({}, '', '/systems?add=1');
            mount({canCreate: false});

            expect(FakeRecordForm.instances).toHaveLength(0);
            window.history.replaceState({}, '', '/');
        });

        it('opens the edit form preloaded with the row', () => {
            const {grid} = mount();

            grid.options.onAction('edit', SYSTEMS[0]);

            const form = FakeRecordForm.instances[0];
            expect(form.options.title).toBe('aaxis.ontology.system_manager.edit_title');
            expect(form.options.subtitle).toBe('aaxis.ontology.system_manager.edit_subtitle');
            expect(form.options.values).toEqual({name: 'ERP', enabled: true});
        });

        it('creates with POST, flashes success and reloads', async () => {
            const create = vi.fn(() => jsonResponse({success: true}));
            const {fetchSpy} = mount({}, {'/aaxis_ontology_system_api_create': create});
            await flush();
            $el.find('[data-role="add"]').trigger('click');

            await FakeRecordForm.instances[0].options.onSubmit({name: 'New', enabled: 0});

            const [url, init] = fetchSpy.mock.calls.find(c => c[0] === '/aaxis_ontology_system_api_create')!;
            expect(url).toBe('/aaxis_ontology_system_api_create');
            expect(init.method).toBe('POST');
            expect(init.headers['X-CSRF-Header']).toBe('tok');
            expect(JSON.parse(init.body)).toEqual({name: 'New', enabled: false});
            expect(messenger.notificationFlashMessage)
                .toHaveBeenCalledWith('success', 'aaxis.ontology.system_manager.saved');
            await flush();
            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
        });

        it('updates with PUT on the system id', async () => {
            const {grid, fetchSpy} = mount({}, {'/aaxis_ontology_system_api_update': jsonResponse({success: true})});
            await flush();
            grid.options.onAction('edit', SYSTEMS[0]);

            await FakeRecordForm.instances[0].options.onSubmit({name: 'ERP2', enabled: true});

            const [url, init] = fetchSpy.mock.calls.find(c => c[0].startsWith('/aaxis_ontology_system_api_update'))!;
            expect(url).toBe('/aaxis_ontology_system_api_update?id=1');
            expect(init.method).toBe('PUT');
            expect(JSON.parse(init.body)).toEqual({name: 'ERP2', enabled: true});
        });

        it.each([
            ['the server rejects it with a message', jsonResponse({success: false, message: 'Name taken'}, false), 'Name taken'],
            ['the server rejects it without a message', jsonResponse({success: false}, false), 'aaxis.ontology.system_manager.save_error'],
            ['the body reports failure on a 200', jsonResponse({success: false, message: 'Nope'}), 'Nope'],
            ['the body is empty', jsonResponse(null), 'aaxis.ontology.system_manager.save_error']
        ])('rejects the submit when %s', async (_name, response, message) => {
            mount({}, {'/aaxis_ontology_system_api_create': response});
            await flush();
            $el.find('[data-role="add"]').trigger('click');

            await expect(FakeRecordForm.instances[0].options.onSubmit({name: 'X'})).rejects.toThrow(message);
            expect(messenger.notificationFlashMessage).not.toHaveBeenCalledWith('success', expect.anything());
        });
    });

    describe('delete', () => {
        function openDelete(routes: Record<string, any> = {}) {
            const ctx = mount({}, routes);
            ctx.grid.options.onAction('delete', {...SYSTEMS[0], entityCount: 3, recordCount: 40});
            const dialog = FakeDialog.instances[0];

            return {...ctx, dialog};
        }

        it('opens a confirmation dialog naming the system and what will be lost', () => {
            const {dialog} = openDelete();

            expect(dialog.options).toMatchObject({title: 'aaxis.ontology.system_manager.delete_title', width: '520px'});
            expect(dialog.$content.find('.aaxis-ontology-confirm__q').text())
                .toBe('aaxis.ontology.system_manager.confirm_delete');
            expect(dialog.$content.find('.aaxis-ontology-confirm__danger')).toHaveLength(2);
            expect(dialog.$content.find('.aaxis-ontology-confirm__delete').text()).toBe('aaxis.common.grid.delete');
        });

        it('closes without calling the server on Cancel', () => {
            const {dialog, fetchSpy} = openDelete();
            fetchSpy.mockClear();

            dialog.$content.find('button.btn').first().trigger('click');

            expect(dialog.close).toHaveBeenCalled();
            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it('deletes, flashes success, reloads and closes on Confirm', async () => {
            const {dialog, fetchSpy} = openDelete({'/aaxis_ontology_system_delete': jsonResponse({successful: true})});
            await flush();

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');
            expect(dialog.$content.find('.aaxis-ontology-confirm__delete').prop('disabled')).toBe(true);
            await flush();

            const [url, init] = fetchSpy.mock.calls.find(c => c[0].startsWith('/aaxis_ontology_system_delete'))!;
            expect(url).toBe('/aaxis_ontology_system_delete?id=1');
            expect(init.method).toBe('DELETE');
            expect(messenger.notificationFlashMessage)
                .toHaveBeenCalledWith('success', 'aaxis.ontology.system_manager.deleted');
            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
            expect(dialog.close).toHaveBeenCalled();
        });

        it.each([
            ['with the server message', jsonResponse({successful: false, message: 'In use'}), 'In use'],
            ['with the generic message when the server gives none', jsonResponse({}, false), 'aaxis.ontology.system_manager.delete_error'],
            ['when the body is empty', jsonResponse(null), 'aaxis.ontology.system_manager.delete_error']
        ])('flashes an error %s and still closes', async (_name, response, message) => {
            const {dialog} = openDelete({'/aaxis_ontology_system_delete': response});
            await flush();

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', message);
            expect(dialog.close).toHaveBeenCalled();
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('flashes the generic error when the request fails', async () => {
            const {dialog} = openDelete({'/aaxis_ontology_system_delete': Promise.reject(new Error('net'))});
            await flush();

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');
            await flush();

            expect(messenger.notificationFlashMessage)
                .toHaveBeenCalledWith('error', 'aaxis.ontology.system_manager.delete_error');
            expect(dialog.close).toHaveBeenCalled();
        });
    });

    describe('dispose', () => {
        it('unbinds the page, disposes the grid and is idempotent', async () => {
            const {component, grid, fetchSpy} = mount();
            await flush();

            component.dispose();
            component.dispose();
            $el.find('[data-role="refresh"]').trigger('click');
            $el.find('[data-role="add"]').trigger('click');

            expect(component.disposed).toBe(true);
            expect(grid.dispose).toHaveBeenCalledTimes(1);
            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(1);
            expect(FakeRecordForm.instances).toHaveLength(0);
            expect($.isEmptyObject($._data($el[0], 'events') ?? {})).toBe(true);
        });
    });
});

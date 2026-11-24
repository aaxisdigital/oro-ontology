import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import EntityComponent from '../../../../Resources/js-src/app/components/entity-component';
import {
    FakeDialog, FakeGrid, FakeRecordForm, flush, jsonResponse, navigateTo, pageElement, resetFakes, stubFetch
} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/data-grid', async () => (await import('../../helpers/page-mocks')).dataGridModule());
vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());
vi.mock('aaxiscommon/js/app/widgets/record-form-modal', async () => (await import('../../helpers/page-mocks')).recordFormModule());

const playgrounds: Array<{options: any; open: ReturnType<typeof vi.fn>}> = [];
vi.mock('../../../../Resources/js-src/app/widgets/dwl-playground', () => ({
    default: class {
        open = vi.fn();

        constructor(public options: any) {
            playgrounds.push(this);
        }
    }
}));

const erasers: Array<{options: any; open: ReturnType<typeof vi.fn>}> = [];
vi.mock('../../../../Resources/js-src/app/widgets/erase-records', () => ({
    default: class {
        open = vi.fn();

        constructor(public options: any) {
            erasers.push(this);
        }
    }
}));

const LIST_URL = '/aaxis_ontology_entity_list';
const ORO_ENTITIES_URL = '/aaxis_ontology_entity_oro_entities';
const ORO_FIELDS_URL = '/aaxis_ontology_entity_oro_fields';

const SYSTEMS = [
    {id: 1, name: 'ERP', external: true},
    {id: 2, name: 'OroCommerce', external: false}
];
const DATATYPES = [{value: 'text', label: 'Text'}, {value: 'number', label: 'Number'}];

function entity(overrides: Record<string, unknown> = {}): any {
    return {
        id: 10, name: 'Order', displayName: 'Orders', uniqueAttribute: 'id', enabled: true, forceDbStorage: false,
        systemId: 1, systemName: 'ERP', attributeCount: 2,
        attributes: [{name: 'id', datatype: 'number', required: true}, {name: 'total', datatype: 'number', required: false}],
        recordCount: 7, flowCount: 0, ...overrides
    };
}

describe('OntologyEntityComponent', () => {
    let $el: any;

    beforeEach(() => {
        resetFakes();
        playgrounds.length = 0;
        erasers.length = 0;
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        window.history.replaceState({}, '', '/');
    });

    function mount(options: Record<string, unknown> = {}, routes: Record<string, any> = {}, entities = [entity()]) {
        $el = pageElement();
        const fetchSpy = stubFetch({
            [LIST_URL]: jsonResponse({entities, systems: SYSTEMS, datatypes: DATATYPES}),
            ...routes
        });
        const component: any = new (EntityComponent as any)({
            _sourceElement: $el, canCreate: true, canUpdate: true, canDelete: true, ...options
        });

        return {component, fetchSpy, grid: FakeGrid.instances[0]};
    }

    /** Mounts, waits for the initial load, and opens the edit/create form; returns the modal. */
    async function openForm(row: any | null, routes: Record<string, any> = {}, options: Record<string, unknown> = {}) {
        const ctx = mount(options, routes);
        await flush();
        if (row === null) {
            $el.find('[data-role="add"]').trigger('click');
        } else {
            ctx.grid.options.onAction('edit', row);
        }
        await flush();

        return {...ctx, modal: FakeRecordForm.instances[0]};
    }

    describe('grid', () => {
        it('builds the entity grid columns with the expected keys and preferences', () => {
            const {grid} = mount();

            expect(grid.options.columns.map((c: any) => c.key)).toEqual([
                'name', 'systemName', 'uniqueAttribute', 'attributeCount', 'recordCount', 'flowCount', 'enabled'
            ]);
            expect(grid.options.gridKey).toBe('ontology-entity');
            expect(grid.options.preferencesUrl).toBe('/aaxis_common_grid_preference_get?gridKey=ontology-entity');
            expect(grid.mount).toHaveBeenCalledWith($el.find('[data-role="list"]'));
        });

        it('shows the display name when there is one, else the raw name', () => {
            const {grid} = mount();

            expect(grid.column('name').render(entity())).toBe('Orders');
            expect(grid.column('name').render(entity({displayName: ''}))).toBe('Order');
        });

        it('renders the attribute count cell, 0 when absent', () => {
            const {grid} = mount();

            expect(grid.column('attributeCount').render(entity()).text()).toBe('2');
            expect(grid.column('attributeCount').render(entity({attributeCount: undefined})).text()).toBe('0');
            expect(grid.column('attributeCount').render(entity()).hasClass('aaxis-attr-cell')).toBe(true);
        });

        it.each([
            [{canUpdate: true, canDelete: true}, ['dwl', 'data', 'edit', 'purge', 'delete']],
            [{canUpdate: true, canDelete: false}, ['dwl', 'data', 'edit']],
            [{canUpdate: false, canDelete: true}, ['dwl', 'data', 'purge', 'delete']],
            [{canUpdate: false, canDelete: false}, ['dwl', 'data']]
        ])('offers only the permitted actions for %j', (perms, expected) => {
            const {grid} = mount(perms);

            expect(grid.options.actions.map((a: any) => a.key)).toEqual(expected);
        });

        it('shows DWL as a badge and disables Purge when there is nothing to erase', () => {
            const {grid} = mount();

            expect(grid.action('dwl').badge).toBe('dwl');
            expect(grid.action('purge').variant).toBe('danger');
            expect(grid.action('purge').disabled(entity({recordCount: 0}))).toBe(true);
            expect(grid.action('purge').disabled(entity({recordCount: 3}))).toBe(false);
            expect(grid.action('purge').label).toBe('aaxis.ontology.entity_manager.purge_title');
            expect(grid.action('purge').disabledTitle).toBe('aaxis.ontology.entity_manager.purge_empty');
            expect(grid.action('delete').variant).toBe('danger');
        });
    });

    describe('loading', () => {
        it('loads the entities into the grid and clears the busy state', async () => {
            const {grid, fetchSpy} = mount();

            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(true);
            await flush();

            expect(fetchSpy).toHaveBeenCalledWith(LIST_URL, {credentials: 'same-origin'});
            expect(grid.setRows).toHaveBeenCalledWith([entity()]);
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('tolerates a payload with no lists at all', async () => {
            const {grid} = mount({}, {[LIST_URL]: jsonResponse({})});
            await flush();

            expect(grid.setRows).toHaveBeenCalledWith([]);
        });

        it('flashes an error when loading fails', async () => {
            mount({}, {[LIST_URL]: Promise.reject(new Error('down'))});
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.entity_manager.load_error');
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('wires the toolbar: refresh reloads, columns opens the settings', async () => {
            const {grid, fetchSpy} = mount();
            await flush();

            $el.find('[data-role="refresh"]').trigger('click');
            $el.find('[data-role="columns-settings"]').trigger('click');

            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
            expect(grid.toggleColumnSettings).toHaveBeenCalledTimes(1);
        });
    });

    describe('?system= deep link', () => {
        it('filters the grid on the system name, once', async () => {
            window.history.replaceState({}, '', '/?system=%20ERP%20');
            const {grid} = mount();
            await flush();

            expect(grid.setFilter).toHaveBeenCalledWith('systemName', {operator: 'equals', value: 'ERP'});

            $el.find('[data-role="refresh"]').trigger('click');
            await flush();
            expect(grid.setFilter).toHaveBeenCalledTimes(1);
        });

        it.each(['/', '/?system=', '/?system=%20'])('does nothing for %s', async url => {
            window.history.replaceState({}, '', url);
            const {grid} = mount();
            await flush();

            expect(grid.setFilter).not.toHaveBeenCalled();
        });
    });

    describe('navigation and playground actions', () => {
        it('opens the Data View filtered on the entity NAME, forwarding the event', () => {
            const {grid} = mount();
            const event = {metaKey: true} as MouseEvent;

            grid.options.onAction('data', entity({name: 'Sales Order'}), event);

            expect(navigateTo).toHaveBeenCalledWith('/aaxis_ontology_data_view?entity=Sales%20Order', event);
        });

        it('encodes a missing name as empty', () => {
            const {grid} = mount();

            grid.options.onAction('data', entity({name: null}));

            expect(navigateTo).toHaveBeenCalledWith('/aaxis_ontology_data_view?entity=', undefined);
        });

        it('opens the DWL playground over the entity, labelled with its display name', () => {
            const {grid} = mount();

            grid.options.onAction('dwl', entity());
            grid.options.onAction('dwl', entity({id: 11, displayName: '', name: 'Plain'}));

            expect(playgrounds.map(p => p.options)).toEqual([
                {entityId: 10, entityLabel: 'Orders'},
                {entityId: 11, entityLabel: 'Plain'}
            ]);
            expect(playgrounds[0].open).toHaveBeenCalledTimes(1);
        });

        it('ignores an unknown action', () => {
            const {grid} = mount();

            grid.options.onAction('bogus', entity());

            expect(FakeDialog.instances).toHaveLength(0);
            expect(FakeRecordForm.instances).toHaveLength(0);
            expect(navigateTo).not.toHaveBeenCalled();
        });
    });

    describe('erase', () => {
        it('is disabled for an OroCommerce entity, whose data is its own table', async () => {
            const {grid} = mount();
            await flush(); // the systems (external or not) arrive with the list

            expect(grid.action('purge').disabled(entity({systemId: 2, recordCount: 900}))).toBe(true);
            expect(grid.action('purge').disabled(entity({systemId: 1, recordCount: 900}))).toBe(false);
        });

        it('opens the Erase popup with the unique attribute first, then the others alphabetically', async () => {
            const {grid} = mount();
            await flush();

            grid.options.onAction('purge', entity({
                uniqueAttribute: 'sku',
                attributes: [{name: 'weight'}, {name: 'sku'}, {name: 'address.city'}, {name: ''}]
            }));

            expect(erasers).toHaveLength(1);
            expect(erasers[0].open).toHaveBeenCalled();
            expect(erasers[0].options).toMatchObject({entityId: 10, entityLabel: 'Orders', attributes: ['sku', 'address.city', 'weight']});
        });

        it('offers just the configured attributes when there is no unique attribute, labelled by name', async () => {
            const {grid} = mount();
            await flush();

            grid.options.onAction('purge', entity({uniqueAttribute: null, displayName: '', attributes: undefined}));

            expect(erasers[0].options).toMatchObject({entityLabel: 'Order', attributes: []});
        });

        it('reloads the grid once records were erased', async () => {
            const {grid, fetchSpy} = mount();
            await flush();
            grid.options.onAction('purge', entity());
            fetchSpy.mockClear();

            erasers[0].options.onErased(3);
            await flush();

            expect(fetchSpy.mock.calls.map(c => c[0])).toEqual([LIST_URL]);
        });
    });

    describe('delete', () => {
        function openDelete(routes: Record<string, any> = {}, row: any = entity()) {
            const ctx = mount({}, routes);
            return flush().then(() => {
                ctx.grid.options.onAction('delete', row);

                return {...ctx, dialog: FakeDialog.instances[0]};
            });
        }

        it('warns about the data loss for an external-system entity', async () => {
            const {dialog} = await openDelete();

            expect(dialog.options).toMatchObject({title: 'aaxis.ontology.entity_manager.delete_title', width: '520px'});
            expect(dialog.$content.find('.aaxis-ontology-confirm__danger')).toHaveLength(2);
            expect(dialog.$content.find('.aaxis-ontology-confirm__danger').first().text())
                .toBe('aaxis.ontology.entity_manager.delete_external_data_warning');
            expect(dialog.$content.text()).not.toContain('delete_internal_data_note');
        });

        it('reassures that OroCommerce data is untouched for an internal-system entity', async () => {
            const {dialog} = await openDelete({}, entity({systemId: 2}));

            expect(dialog.$content.text()).toContain('aaxis.ontology.entity_manager.delete_internal_data_note');
            expect(dialog.$content.find('.aaxis-ontology-confirm__danger')).toHaveLength(1);
            expect(dialog.$content.find('.aaxis-ontology-confirm__danger').text())
                .toBe('aaxis.ontology.entity_manager.delete_flows_warning');
        });

        it('treats an entity with no system as external', async () => {
            const {dialog} = await openDelete({}, entity({systemId: null}));

            expect(dialog.$content.find('.aaxis-ontology-confirm__danger')).toHaveLength(2);
        });

        it('cancels without a request', async () => {
            const {dialog, fetchSpy} = await openDelete();
            fetchSpy.mockClear();

            dialog.$content.find('button.btn').first().trigger('click');

            expect(dialog.close).toHaveBeenCalled();
            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it('deletes, flashes success, reloads and closes on Confirm', async () => {
            const {dialog, fetchSpy} = await openDelete({'/aaxis_ontology_entity_delete': jsonResponse({successful: true})});

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');
            await flush();

            const [url, init] = fetchSpy.mock.calls.find(c => c[0].startsWith('/aaxis_ontology_entity_delete'))!;
            expect(url).toBe('/aaxis_ontology_entity_delete?id=10');
            expect(init.method).toBe('DELETE');
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', 'aaxis.ontology.entity_manager.deleted');
            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
            expect(dialog.close).toHaveBeenCalled();
        });

        it.each([
            ['with the server message', jsonResponse({successful: false, message: 'In use'}), 'In use'],
            ['generic on HTTP failure', jsonResponse({}, false), 'aaxis.ontology.entity_manager.delete_error'],
            ['generic on an empty body', jsonResponse(null), 'aaxis.ontology.entity_manager.delete_error']
        ])('flashes an error %s', async (_name, response, message) => {
            const {dialog} = await openDelete({'/aaxis_ontology_entity_delete': response});

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', message);
            expect(dialog.close).toHaveBeenCalled();
        });

        it('flashes the generic error when the request fails', async () => {
            const {dialog} = await openDelete({'/aaxis_ontology_entity_delete': Promise.reject(new Error('net'))});

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.entity_manager.delete_error');
        });
    });

    describe('create form', () => {
        it('opens an empty create form with the system list, datatypes and free-text names', async () => {
            const {modal} = await openForm(null);

            expect(modal.open).toHaveBeenCalled();
            expect(modal.options).toMatchObject({
                title: 'aaxis.ontology.entity_manager.create_title',
                subtitle: 'aaxis.ontology.entity_manager.create_subtitle',
                width: '840px',
                values: {enabled: true, forceDbStorage: false, attributes: []}
            });
            expect(modal.field('systemId').options).toEqual([{value: '1', label: 'ERP'}, {value: '2', label: 'OroCommerce'}]);
            expect(modal.field('name').type).toBe('text');
            expect(modal.field('uniqueAttribute').required).toBe(true);
            const attributes = modal.field('attributes');
            expect(attributes.type).toBe('collection');
            expect(attributes.fields.map((f: any) => [f.key, f.type])).toEqual([
                ['name', 'text'], ['datatype', 'select'], ['required', 'boolean']
            ]);
            expect(attributes.fields[1].options).toEqual(DATATYPES);
        });

        it('picks the system with a type-to-filter picker and a refresh, without "Add new" by default', async () => {
            const {modal} = await openForm(null);
            const picker = modal.field('systemId').picker;

            expect(picker.placeholder).toBe('aaxis.ontology.entity_manager.system_filter_placeholder');
            expect(typeof picker.refresh).toBe('function');
            expect(picker.addNew).toBeUndefined();
        });

        it('reloads the systems through the entity list and keeps them for later rebuilds', async () => {
            const {modal, fetchSpy} = await openForm(null);
            fetchSpy.mockImplementation(() => Promise.resolve(jsonResponse({entities: [], datatypes: DATATYPES, systems: [...SYSTEMS, {id: 3, name: 'CRM', external: true}]})));

            const options = await modal.field('systemId').picker.refresh();

            expect(options).toEqual([{value: '1', label: 'ERP'}, {value: '2', label: 'OroCommerce'}, {value: '3', label: 'CRM'}]);
            // A later rebuild offers the fresh list too.
            const rebuilt: any[] = [];
            modal.options.onFieldChange({key: 'systemId', value: '3', api: {
                values: () => ({systemId: '3', name: '', attributes: []}),
                rebuildFields: (fields: any[]) => rebuilt.push(fields),
                setRowValue: vi.fn()
            }});
            expect(rebuilt[0].find((f: any) => f.key === 'systemId').options).toHaveLength(3);
        });

        it('reports a failed system reload', async () => {
            const {modal, fetchSpy} = await openForm(null);
            fetchSpy.mockImplementation(() => Promise.reject(new Error('down')));

            await expect(modal.field('systemId').picker.refresh()).rejects.toThrow('down');
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.entity_manager.load_error');
        });

        it('adds a new system in a new tab and picks it on coming back', async () => {
            const {modal} = await openForm(null, {}, {systemCreateUrl: '/systems?add=1'});
            const open = vi.spyOn(window, 'open').mockReturnValue(null);
            let values = ['1', '2'];
            const api = {
                values: () => values,
                refresh: vi.fn(() => {
                    values = ['1', '2', '7'];
                    return Promise.resolve();
                }),
                pick: vi.fn()
            };

            const addNew = modal.field('systemId').picker.addNew;
            expect(addNew.label).toBe('aaxis.ontology.entity_manager.system_add');
            addNew.onAdd(api);
            expect(open).toHaveBeenCalledWith('/systems?add=1', '_blank');
            expect(api.refresh).not.toHaveBeenCalled();

            window.dispatchEvent(new Event('focus'));
            await flush();
            expect(api.refresh).toHaveBeenCalledTimes(1);
            expect(api.pick).toHaveBeenCalledWith('7');

            // Only the NEXT focus counts.
            window.dispatchEvent(new Event('focus'));
            await flush();
            expect(api.refresh).toHaveBeenCalledTimes(1);
        });

        it('picks nothing when no single new system appeared', async () => {
            const {modal} = await openForm(null, {}, {systemCreateUrl: '/systems?add=1'});
            vi.spyOn(window, 'open').mockReturnValue(null);
            const api = {values: () => ['1', '2'], refresh: vi.fn(() => Promise.resolve()), pick: vi.fn()};

            modal.field('systemId').picker.addNew.onAdd(api);
            window.dispatchEvent(new Event('focus'));
            await flush();

            expect(api.pick).not.toHaveBeenCalled();
        });

        it('lays the rows out as system + name (50/50), then unique attribute + enabled + storage (50/15/35)', async () => {
            const {modal} = await openForm(null);

            expect(modal.options.fields.map((f: any) => [f.key, f.row ?? null, f.width ?? null])).toEqual([
                ['systemId', 'identity', '50%'], ['name', 'identity', '50%'],
                ['uniqueAttribute', 'settings', '50%'], ['enabled', 'settings', '15%'], ['forceDbStorage', 'settings', '35%'],
                ['attributes', null, null]
            ]);
            expect(modal.field('forceDbStorage').hint).toBe('aaxis.ontology.entity.force_db_storage.hint');
            // Only the attribute rows scroll; the fields, the title and the add button stay in view.
            expect(modal.field('attributes').fill).toBe(true);
        });

    });

    describe('edit form', () => {
        it('preloads the entity values for an external system without any Oro lookups', async () => {
            const {modal, fetchSpy} = await openForm(entity({forceDbStorage: 1}));

            expect(modal.options.title).toBe('aaxis.ontology.entity_manager.edit_title');
            expect(modal.options.values).toEqual({
                name: 'Order', systemId: '1', uniqueAttribute: 'id', enabled: true, forceDbStorage: true,
                attributes: entity().attributes
            });
            expect(modal.field('name').type).toBe('text');
            expect(fetchSpy.mock.calls.some(c => c[0].startsWith(ORO_ENTITIES_URL))).toBe(false);
        });

        it('tolerates an entity with no system or unique attribute', async () => {
            const {modal} = await openForm(entity({systemId: null, uniqueAttribute: null}));

            expect(modal.options.values).toMatchObject({systemId: '', uniqueAttribute: ''});
        });

        it('preloads the Oro entity and field lists for an internal system, then shows comboboxes', async () => {
            const {modal, fetchSpy} = await openForm(entity({systemId: 2, name: 'Oro\\Order'}), {
                [ORO_ENTITIES_URL]: jsonResponse({entities: [{value: 'Oro\\Order', label: 'Order'}, {value: 'Oro\\User', label: 'User'}]}),
                [ORO_FIELDS_URL]: jsonResponse({fields: [
                    {value: 'id', label: 'Id', datatype: 'number'}, {value: 'total', label: 'Total', datatype: 'number'}
                ]})
            });

            expect(fetchSpy).toHaveBeenCalledWith(ORO_ENTITIES_URL, {credentials: 'same-origin'});
            expect(fetchSpy).toHaveBeenCalledWith(ORO_FIELDS_URL + '?entity=Oro%5COrder', {credentials: 'same-origin'});
            const name = modal.field('name');
            expect(name.type).toBe('select');
            expect(name.options.map((o: any) => o.value)).toEqual(['', 'Oro\\Order', 'Oro\\User']);
            const attrName = modal.field('attributes').fields[0];
            expect(attrName.type).toBe('select');
            // the entity's fields first, then any existing attribute names the model no longer has
            expect(attrName.options.map((o: any) => o.value)).toEqual(['', 'id', 'total']);
        });

        it('keeps an existing attribute the Oro model no longer has, and an unknown current entity name', async () => {
            const {modal} = await openForm(
                entity({
                    systemId: 2, name: 'Legacy\\Thing',
                    attributes: [{name: 'gone', datatype: 'text', required: false}, {name: '', datatype: 'text', required: false}]
                }),
                {[ORO_ENTITIES_URL]: jsonResponse({entities: [{value: 'Oro\\User', label: 'User'}]})}
            );

            expect(modal.field('name').options.map((o: any) => o.value)).toEqual(['', 'Oro\\User', 'Legacy\\Thing']);
            // Legacy\Thing is not in the Oro entity list, so its fields are never requested
            expect(modal.field('attributes').fields[0].options.map((o: any) => o.value)).toEqual(['', 'gone']);
        });

        it('falls back to empty lists when the Oro lookups fail, and does not retry the entity list', async () => {
            const {modal, fetchSpy, grid} = await openForm(entity({systemId: 2, name: 'Oro\\Order'}), {
                [ORO_ENTITIES_URL]: Promise.reject(new Error('down'))
            });

            expect(modal.field('name').options.map((o: any) => o.value)).toEqual(['', 'Oro\\Order']);

            grid.options.onAction('edit', entity({systemId: 2, name: 'Oro\\Order'}));
            await flush();
            expect(fetchSpy.mock.calls.filter(c => c[0] === ORO_ENTITIES_URL)).toHaveLength(1);
        });

        it('caches an entity\'s fields, and falls back to none when the fields request fails', async () => {
            const fields = vi.fn(() => jsonResponse({fields: [{value: 'id', label: 'Id', datatype: 'number'}]}));
            const {fetchSpy, grid} = await openForm(entity({systemId: 2, name: 'Oro\\Order'}), {
                [ORO_ENTITIES_URL]: jsonResponse({entities: [{value: 'Oro\\Order', label: 'Order'}, {value: 'Oro\\User', label: 'User'}]}),
                [ORO_FIELDS_URL]: fields
            });

            grid.options.onAction('edit', entity({systemId: 2, name: 'Oro\\Order'}));
            await flush();
            expect(fields).toHaveBeenCalledTimes(1);

            fields.mockImplementation((() => Promise.reject(new Error('x'))) as any);
            grid.options.onAction('edit', entity({systemId: 2, name: 'Oro\\User'}));
            await flush();
            const modal = FakeRecordForm.instances.at(-1)!;
            expect(modal.field('attributes').fields[0].options.map((o: any) => o.value)).toEqual(['', 'id', 'total']);
            expect(fetchSpy.mock.calls.filter(c => c[0].startsWith(ORO_FIELDS_URL))).toHaveLength(2);
        });

        it('treats a missing entities / fields payload as empty', async () => {
            const {modal} = await openForm(entity({systemId: 2, name: 'Oro\\Order'}), {
                [ORO_ENTITIES_URL]: jsonResponse({}),
                [ORO_FIELDS_URL]: jsonResponse({})
            });

            expect(modal.field('name').options.map((o: any) => o.value)).toEqual(['', 'Oro\\Order']);
        });
    });

    describe('form reactions', () => {
        function ctxFor(key: string, value: any, api: any, collectionKey?: string): any {
            return {key, value, api, collectionKey};
        }

        function makeApi(values: Record<string, any>) {
            return {values: vi.fn(() => values), rebuildFields: vi.fn(), setRowValue: vi.fn()};
        }

        it('pre-fills a collection row\'s datatype when an Oro field is picked as the attribute name', async () => {
            const {modal} = await openForm(entity({systemId: 2, name: 'Oro\\Order'}), {
                [ORO_ENTITIES_URL]: jsonResponse({entities: [{value: 'Oro\\Order', label: 'Order'}]}),
                [ORO_FIELDS_URL]: jsonResponse({fields: [{value: 'total', label: 'Total', datatype: 'decimal'}]})
            });
            const api = makeApi({name: 'Oro\\Order'});

            modal.options.onFieldChange(ctxFor('name', 'total', api, 'attributes'));

            expect(api.setRowValue).toHaveBeenCalledWith('datatype', 'decimal');
        });

        it('leaves the datatype alone for a field the entity does not have', async () => {
            const {modal} = await openForm(null);
            const api = makeApi({name: 'Oro\\Order'});

            modal.options.onFieldChange(ctxFor('name', 'unknown', api, 'attributes'));

            expect(api.setRowValue).not.toHaveBeenCalled();
        });

        it('ignores every other change inside a collection', async () => {
            const {modal} = await openForm(null);
            const api = makeApi({});

            modal.options.onFieldChange(ctxFor('datatype', 'text', api, 'attributes'));
            modal.options.onFieldChange(ctxFor('name', 'x', api, 'something_else'));

            expect(api.setRowValue).not.toHaveBeenCalled();
            expect(api.rebuildFields).not.toHaveBeenCalled();
        });

        it('rebuilds the form immediately when switching to an external system', async () => {
            const {modal} = await openForm(null);
            const api = makeApi({systemId: '1', name: 'X'});

            modal.options.onFieldChange(ctxFor('systemId', '1', api));

            expect(api.rebuildFields).toHaveBeenCalledTimes(1);
            const fields = api.rebuildFields.mock.calls[0][0];
            expect(fields.find((f: any) => f.key === 'name').type).toBe('text');
        });

        it('rebuilds the form immediately when the system is cleared', async () => {
            const {modal} = await openForm(null);
            const api = makeApi({systemId: '', name: ''});

            modal.options.onFieldChange(ctxFor('systemId', '', api));

            expect(api.rebuildFields.mock.calls[0][0].find((f: any) => f.key === 'name').type).toBe('text');
        });

        it('loads the Oro entities before rebuilding into comboboxes for an internal system', async () => {
            const {modal} = await openForm(null, {
                [ORO_ENTITIES_URL]: jsonResponse({entities: [{value: 'Oro\\Order', label: 'Order'}]})
            });
            const api = makeApi({systemId: '2', name: 'Oro\\Order'});

            modal.options.onFieldChange(ctxFor('systemId', '2', api));
            expect(api.rebuildFields).not.toHaveBeenCalled(); // waits for the lookup
            await flush();

            expect(api.rebuildFields).toHaveBeenCalledTimes(1);
            const internalName = api.rebuildFields.mock.calls[0][0].find((f: any) => f.key === 'name');
            expect([internalName.type, internalName.row, internalName.width]).toEqual(['select', 'identity', '50%']);
        });

        it('reloads the fields and rebuilds when the entity changes within an internal system', async () => {
            const {modal, fetchSpy} = await openForm(null, {
                [ORO_ENTITIES_URL]: jsonResponse({entities: [{value: 'Oro\\Order', label: 'Order'}]}),
                [ORO_FIELDS_URL]: jsonResponse({fields: [{value: 'total', label: 'Total', datatype: 'decimal'}]})
            });
            // make system 2 (internal) the current one
            const system = makeApi({systemId: '2', name: ''});
            modal.options.onFieldChange(ctxFor('systemId', '2', system));
            await flush();

            const api = makeApi({systemId: '2', name: 'Oro\\Order'});
            modal.options.onFieldChange(ctxFor('name', 'Oro\\Order', api));
            await flush();

            expect(fetchSpy).toHaveBeenCalledWith(ORO_FIELDS_URL + '?entity=Oro%5COrder', {credentials: 'same-origin'});
            const fields = api.rebuildFields.mock.calls[0][0];
            expect(fields.find((f: any) => f.key === 'attributes').fields[0].options.map((o: any) => o.value))
                .toEqual(['', 'total']);
        });

        it('does nothing when the entity name changes on an external system, or an unrelated field changes', async () => {
            const {modal} = await openForm(null);
            const api = makeApi({systemId: '1', name: 'X'});

            modal.options.onFieldChange(ctxFor('name', 'X', api));
            modal.options.onFieldChange(ctxFor('uniqueAttribute', 'id', api));

            expect(api.rebuildFields).not.toHaveBeenCalled();
        });
    });

    describe('save', () => {
        it('rejects a dotted unique attribute up front, without a request', async () => {
            const {modal, fetchSpy} = await openForm(null);
            fetchSpy.mockClear();

            await expect(modal.options.onSubmit({name: 'X', uniqueAttribute: 'a.b'}))
                .rejects.toThrow('aaxis.ontology.entity_manager.unique_attribute_no_dots');
            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it('creates with POST: system id numeric or null, attribute datatype defaulting to "undefined"', async () => {
            const {modal, fetchSpy} = await openForm(null, {'/aaxis_ontology_entity_api_create': jsonResponse({success: true})});

            await modal.options.onSubmit({
                name: 'X', systemId: '2', uniqueAttribute: 'id', enabled: 1, forceDbStorage: 0,
                attributes: [{name: 'a', datatype: '', required: 1}, {name: 'b', datatype: 'text'}]
            });

            const [url, init] = fetchSpy.mock.calls.find(c => c[0] === '/aaxis_ontology_entity_api_create')!;
            expect(url).toBe('/aaxis_ontology_entity_api_create');
            expect(init.method).toBe('POST');
            expect(init.headers['X-CSRF-Header']).toBe('tok');
            expect(JSON.parse(init.body)).toEqual({
                name: 'X', systemId: 2, uniqueAttribute: 'id', enabled: true, forceDbStorage: false,
                attributes: [
                    {name: 'a', datatype: 'undefined', required: true},
                    {name: 'b', datatype: 'text', required: false}
                ]
            });
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', 'aaxis.ontology.entity_manager.saved');
            await flush();
            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
        });

        it('sends a null system id when none was chosen and tolerates missing attributes', async () => {
            const {modal, fetchSpy} = await openForm(null, {'/aaxis_ontology_entity_api_create': jsonResponse({success: true})});

            await modal.options.onSubmit({name: 'X', systemId: '', uniqueAttribute: 'id'});

            const body = JSON.parse(fetchSpy.mock.calls.find(c => c[0] === '/aaxis_ontology_entity_api_create')![1].body);
            expect(body.systemId).toBeNull();
            expect(body.attributes).toEqual([]);
        });

        it('updates with PUT on the entity id', async () => {
            const {modal, fetchSpy} = await openForm(entity(), {'/aaxis_ontology_entity_api_update': jsonResponse({success: true})});

            await modal.options.onSubmit({name: 'X', systemId: '1', uniqueAttribute: 'id', attributes: []});

            const [url, init] = fetchSpy.mock.calls.find(c => c[0].startsWith('/aaxis_ontology_entity_api_update'))!;
            expect(url).toBe('/aaxis_ontology_entity_api_update?id=10');
            expect(init.method).toBe('PUT');
        });

        it.each([
            ['with the server message', jsonResponse({success: false, message: 'Name taken'}, false), 'Name taken'],
            ['without a message', jsonResponse({success: false}), 'aaxis.ontology.entity_manager.save_error'],
            ['with an empty body', jsonResponse(null), 'aaxis.ontology.entity_manager.save_error']
        ])('rejects the submit when the server refuses %s', async (_name, response, message) => {
            const {modal} = await openForm(null, {'/aaxis_ontology_entity_api_create': response});

            await expect(modal.options.onSubmit({name: 'X', uniqueAttribute: 'id'})).rejects.toThrow(message);
            expect(messenger.notificationFlashMessage).not.toHaveBeenCalledWith('success', expect.anything());
        });
    });

    describe('dispose', () => {
        it('unbinds the page and disposes the grid; a second dispose is a no-op', async () => {
            const {component, grid} = mount();
            await flush();

            component.dispose();
            component.dispose();
            $el.find('[data-role="add"]').trigger('click');

            expect(component.disposed).toBe(true);
            expect(grid.dispose).toHaveBeenCalledTimes(1);
            expect(FakeRecordForm.instances).toHaveLength(0);
        });
    });
});

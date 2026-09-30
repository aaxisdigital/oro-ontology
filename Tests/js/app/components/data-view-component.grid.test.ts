import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import {ENTITIES, LIST_URL, mountDataView, record, SYSTEMS} from '../../helpers/data-view';
import {FakeDialog, FakeRecordForm, flush, jsonResponse, resetFakes} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/data-grid', async () => (await import('../../helpers/page-mocks')).dataGridModule());
vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());
vi.mock('oroform/js/app/views/select2-view', async () => (await import('../../helpers/page-mocks')).select2Module());

describe('OntologyDataViewComponent — grid, loading and version lookup', () => {
    beforeEach(() => {
        resetFakes();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        window.history.replaceState({}, '', '/');
    });

    describe('grid', () => {
        it('mounts a grid on the list slot with the data columns and a dedicated preferences key', () => {
            const {grid, $el} = mountDataView();

            expect(grid.mount).toHaveBeenCalledWith($el.find('[data-role="list"]'));
            expect(grid.options.columns.map((c: any) => c.key)).toEqual(
                ['system', 'entity', 'uniqueId', 'uuid', 'version', 'updatedAt', 'payload']
            );
            expect(grid.options.gridKey).toBe('ontology-data-view');
            expect(grid.options.preferencesUrl).toBe('/aaxis_common_grid_preference_get?gridKey=ontology-data-view');
            expect(grid.options.emptyText).toBe('aaxis.ontology.data_view.empty');
        });

        it('hides uuid and version by default', () => {
            const {grid} = mountDataView();

            expect(grid.column('uuid').hidden).toBe(true);
            expect(grid.column('version').hidden).toBe(true);
            expect(grid.column('system').hidden).toBeUndefined();
        });

        it('offers Update only to users who may create, always Versions', () => {
            expect(mountDataView({canCreate: true}).grid.options.actions.map((a: any) => a.key)).toEqual(['update', 'versions']);

            resetFakes();
            expect(mountDataView({canCreate: false}).grid.options.actions.map((a: any) => a.key)).toEqual(['versions']);
        });

        it('formats the updated-at stamp', () => {
            const {grid} = mountDataView();

            expect(grid.column('updatedAt').render(record())).toBe(new Date('2026-05-12T10:00:00Z').toLocaleString());
            expect(grid.column('updatedAt').render(record({updatedAt: null}))).toBe('');
        });

        it('previews a long payload truncated to 60 characters and copies the whole thing', () => {
            const {grid} = mountDataView();
            const long = 'x'.repeat(80);

            const $short = grid.column('payload').render(record({payload: '{"a":1}'}));
            const $long = grid.column('payload').render(record({payload: long}));

            expect($short.text()).toBe('{"a":1}');
            expect($short.hasClass('aaxis-data-payload__text')).toBe(true);
            expect($long.text()).toBe('x'.repeat(60) + '…');
            expect(grid.column('payload').copyValue(record({payload: long}))).toBe(long);
            expect(grid.column('payload').copyValue(record({payload: null}))).toBe('');
            expect(grid.column('payload').sortable).toBe(false);
        });

        it('renders an exactly 60-character payload in full', () => {
            const {grid} = mountDataView();

            expect(grid.column('payload').render(record({payload: 'y'.repeat(60)})).text()).toBe('y'.repeat(60));
        });
    });

    describe('loading', () => {
        it('loads records, systems and entities and clears the busy state', async () => {
            const {grid, component, $el, fetchSpy} = mountDataView();

            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(true);
            await flush();

            expect(fetchSpy).toHaveBeenCalledWith(LIST_URL, {credentials: 'same-origin'});
            expect(grid.setRows).toHaveBeenCalledWith([record()]);
            expect(component.systems).toEqual(SYSTEMS);
            expect(component.entities).toEqual(ENTITIES);
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('tolerates a payload with no lists', async () => {
            const {grid, component} = mountDataView({}, {[LIST_URL]: jsonResponse({})});
            await flush();

            expect(grid.setRows).toHaveBeenCalledWith([]);
            expect(component.systems).toEqual([]);
            expect(component.entities).toEqual([]);
        });

        it('flashes an error when loading fails', async () => {
            const {$el} = mountDataView({}, {[LIST_URL]: Promise.reject(new Error('down'))});
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.data_view.load_error');
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('wires the toolbar: refresh reloads, columns opens the settings', async () => {
            const {grid, $el, fetchSpy} = mountDataView();
            await flush();

            $el.find('[data-role="refresh"]').trigger('click');
            $el.find('[data-role="columns-settings"]').trigger('click');

            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
            expect(grid.toggleColumnSettings).toHaveBeenCalledTimes(1);
        });
    });

    describe('?entity= deep link', () => {
        it('filters the grid on the entity name once, after the first load', async () => {
            window.history.replaceState({}, '', '/?entity=%20Order%20');
            const {grid, $el} = mountDataView();
            await flush();

            expect(grid.setFilter).toHaveBeenCalledWith('entity', {operator: 'equals', value: 'Order'});

            $el.find('[data-role="refresh"]').trigger('click');
            await flush();
            expect(grid.setFilter).toHaveBeenCalledTimes(1);
        });

        it.each(['/', '/?entity=', '/?entity=%20'])('does not filter for %s', async url => {
            window.history.replaceState({}, '', url);
            const {grid} = mountDataView();
            await flush();

            expect(grid.setFilter).not.toHaveBeenCalled();
        });
    });

    describe('row actions', () => {
        it('opens the Add Data dialog on the Add button', async () => {
            const {$el} = mountDataView();
            await flush();
            const event = new MouseEvent('click', {cancelable: true, bubbles: true});

            $el.find('[data-role="add"]')[0].dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
            expect(FakeDialog.instances).toHaveLength(1);
            expect(FakeDialog.instances[0].options.title).toBe('aaxis.ontology.data_view.add_title');
        });

        it('opens the Update dialog for the row on the update action', async () => {
            const {grid} = mountDataView();
            await flush();

            grid.options.onAction('update', record());

            expect(FakeDialog.instances[0].options.title).toBe('aaxis.ontology.data_view.update_title');
        });

        it('ignores an unknown action', async () => {
            const {grid} = mountDataView();
            await flush();

            grid.options.onAction('bogus', record());

            expect(FakeDialog.instances).toHaveLength(0);
            expect(FakeRecordForm.instances).toHaveLength(0);
        });
    });

    describe('versions lookup', () => {
        const VERSIONS_URL = '/aaxis_ontology_data_versions';

        async function open(row: any, routes: Record<string, any> = {}) {
            const ctx = mountDataView({}, routes);
            await flush();
            ctx.grid.options.onAction('versions', row);

            return {...ctx, dialog: FakeDialog.instances[0]};
        }

        it('opens a 720px dialog titled and subtitled after the record, with a loading note', async () => {
            const {dialog} = await open(record(), {[VERSIONS_URL]: new Promise(() => undefined)});

            expect(dialog.options).toMatchObject({
                title: 'aaxis.ontology.data_view.versions_title', subtitle: 'Order — A-1', width: '720px'
            });
            expect(dialog.$content.find('.text-muted').text()).toBe('aaxis.ontology.data_view.versions_loading');
        });

        it.each([
            [{entity: 'Order', uniqueId: null}, 'Order'],
            [{entity: null, uniqueId: 'A-1'}, 'A-1'],
            [{entity: null, uniqueId: ''}, undefined]
        ])('builds the subtitle from %j as %s', async (overrides, subtitle) => {
            const {dialog} = await open(record(overrides), {[VERSIONS_URL]: new Promise(() => undefined)});

            expect(dialog.options.subtitle).toBe(subtitle);
        });

        it('requests the versions of a DB row by its numeric id', async () => {
            const {fetchSpy} = await open(record({id: 77}), {[VERSIONS_URL]: new Promise(() => undefined)});

            expect(fetchSpy).toHaveBeenCalledWith('/aaxis_ontology_data_versions?id=77', {credentials: 'same-origin'});
        });

        it('addresses a bucket row by entity id and unique id, url-encoded', async () => {
            const {fetchSpy} = await open(
                record({bucket: true, entityId: 10, uniqueId: 'A/1 x'}),
                {'/aaxis_ontology_data_versions_by_key': new Promise(() => undefined)}
            );

            expect(fetchSpy).toHaveBeenCalledWith(
                '/aaxis_ontology_data_versions_by_key?entityId=10&uniqueId=A%2F1%20x', {credentials: 'same-origin'}
            );
        });

        it('sends empty key parts for a bucket row missing them', async () => {
            const {fetchSpy} = await open(
                record({bucket: true, entityId: null, uniqueId: null}),
                {'/aaxis_ontology_data_versions_by_key': new Promise(() => undefined)}
            );

            expect(fetchSpy).toHaveBeenCalledWith(
                '/aaxis_ontology_data_versions_by_key?entityId=&uniqueId=', {credentials: 'same-origin'}
            );
        });

        it('replaces the loading note with the version viewer once loaded', async () => {
            const {dialog} = await open(record(), {
                [VERSIONS_URL]: jsonResponse({versions: [
                    {version: 2, uuid: 'u2', updatedAt: null, current: true, payload: {a: 2}},
                    {version: 1, uuid: 'u1', updatedAt: null, current: false, payload: {a: 1}}
                ]})
            });
            await flush();

            expect(dialog.$content.find('.text-muted')).toHaveLength(0);
            expect(dialog.$content.hasClass('aaxis-json-dialog')).toBe(true);
            expect(dialog.$content.find('.aaxis-json-view')).toHaveLength(1);
        });

        it('says the record has no versions when the list is empty or absent', async () => {
            const {dialog} = await open(record(), {[VERSIONS_URL]: jsonResponse({versions: []})});
            await flush();
            expect(dialog.$content.find('.text-muted').text()).toBe('aaxis.ontology.data_view.empty');
            expect(dialog.$content.hasClass('aaxis-json-dialog')).toBe(false);
        });

        it('treats a missing versions key like an empty list', async () => {
            const {dialog} = await open(record(), {[VERSIONS_URL]: jsonResponse({})});
            await flush();

            expect(dialog.$content.find('.text-muted').text()).toBe('aaxis.ontology.data_view.empty');
        });

        it('turns the loading note into an error when the request fails', async () => {
            const {dialog} = await open(record(), {[VERSIONS_URL]: Promise.reject(new Error('x'))});
            await flush();

            expect(dialog.$content.find('.text-muted').text()).toBe('aaxis.ontology.data_view.versions_load_error');
        });
    });

    describe('dispose', () => {
        it('unbinds the page and disposes the grid; a second dispose is a no-op', async () => {
            const {component, grid, $el} = mountDataView();
            await flush();

            component.dispose();
            component.dispose();
            $el.find('[data-role="add"]').trigger('click');

            expect(component.disposed).toBe(true);
            expect(grid.dispose).toHaveBeenCalledTimes(1);
            expect(FakeDialog.instances).toHaveLength(0);
        });
    });
});

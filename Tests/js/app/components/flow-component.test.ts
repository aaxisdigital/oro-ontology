import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import FlowComponent from '../../../../Resources/js-src/app/components/flow-component';
import {
    FakeDialog, FakeGrid, flush, jsonResponse, navigateTo, pageElement, resetFakes, stubFetch
} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/data-grid', async () => (await import('../../helpers/page-mocks')).dataGridModule());
vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());

const LIST_URL = '/aaxis_ontology_flow_list';

function flow(overrides: Record<string, unknown> = {}): any {
    return {
        id: 3, name: 'Nightly', enabled: true, type: 'flow', lastExecuted: '2026-05-12T10:00:00Z',
        lastFinished: '2026-05-12T10:00:05Z', running: false, lastModified: '2026-05-01T08:00:00Z', ...overrides
    };
}

/** jsdom's File has no text(); give it one so the component's `file.text()` path can be driven. */
function textFile(content: string, name = 'flow.json'): File {
    const file = new File([content], name, {type: 'application/json'});
    (file as any).text = () => Promise.resolve(content);

    return file;
}

describe('OntologyFlowComponent', () => {
    let $el: any;

    beforeEach(() => {
        resetFakes();
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    function mount(routes: Record<string, any> = {}, records: any[] = [flow()]) {
        $el = pageElement();
        const fetchSpy = stubFetch({[LIST_URL]: jsonResponse({records}), ...routes});
        const component: any = new (FlowComponent as any)({_sourceElement: $el});

        return {component, fetchSpy, grid: FakeGrid.instances[0]};
    }

    describe('open several flows together', () => {
        const LIST = [flow({id: 3, name: 'Nightly'}), flow({id: 5, name: 'Hourly'}), flow({id: 1, name: 'Manual', type: 'native'})];

        /** Renders each row's Name cell into the list slot, as the grid would. */
        function renderNames(grid: any, records: any[]): void {
            const $list = $el.find('[data-role="list"]').empty();
            records.forEach(row => $list.append($('<div/>').append(grid.column('name').render(row))));
        }
        const $open = () => $el.find('[data-role="open-selected"]');
        const pick = (id: number, on = true) => $el.find(`[data-role="flow-pick"][data-flow-id="${id}"]`).prop('checked', on).trigger('change');

        function mountPicking(records = LIST) {
            const extra = '<button data-role="open-selected" disabled><span data-role="open-selected-label">Open</span></button>';
            $el = pageElement(extra);
            const fetchSpy = stubFetch({[LIST_URL]: jsonResponse({records})});
            const component: any = new (FlowComponent as any)({_sourceElement: $el});

            return {component, fetchSpy, grid: FakeGrid.instances[0]};
        }

        it('puts a checkbox before the name of every editable flow, none on the built-ins', async () => {
            const {grid} = mountPicking();
            await flush();
            renderNames(grid, LIST);

            expect($el.find('[data-role="flow-pick"]').map((_i: number, el: HTMLElement) => el.dataset.flowId).get()).toEqual(['3', '5']);
            expect($el.find('.aaxis-flow-pick__name').map((_i: number, el: HTMLElement) => el.textContent).get()).toEqual(['Nightly', 'Hourly', 'Manual']);
            expect($el.find('.aaxis-flow-pick__slot')).toHaveLength(3); // the built-in keeps the slot: names align
            expect(grid.column('name').render(flow({name: null})).find('.aaxis-flow-pick__name').text()).toBe('');
        });

        it('reads "Open" for none (disabled) or one checked flow, "Open (N)" for several', async () => {
            const {grid} = mountPicking();
            await flush();
            renderNames(grid, LIST);
            const label = () => $el.find('[data-role="open-selected-label"]').text();
            expect([$open().prop('disabled'), label()]).toEqual([true, 'aaxis.ontology.flow_view.open_button']);

            pick(5);
            expect([$open().prop('disabled'), label()]).toEqual([false, 'aaxis.ontology.flow_view.open_button']);
            pick(3);
            expect([$open().prop('disabled'), label()]).toEqual([false, 'aaxis.ontology.flow_view.open_count']);

            pick(5, false);
            pick(3, false);
            expect([$open().prop('disabled'), label()]).toEqual([true, 'aaxis.ontology.flow_view.open_button']);
        });

        it('opens the checked flows in one editor, as tabs in the order they were checked', async () => {
            const {grid} = mountPicking();
            await flush();
            renderNames(grid, LIST);
            pick(5);
            pick(3);

            const event = $.Event('click');
            $open().trigger(event);

            expect(navigateTo).toHaveBeenCalledWith('/aaxis_ontology_flow_editor?id=5&tabs=5%2C3', event);
        });

        it('opens a single checked flow without a tab list, and nothing when none is checked', async () => {
            const {component, grid} = mountPicking();
            await flush();
            component.openPicked();
            expect(navigateTo).not.toHaveBeenCalled();

            renderNames(grid, LIST);
            pick(3);
            $open().trigger('click');

            expect(navigateTo).toHaveBeenCalledWith('/aaxis_ontology_flow_editor?id=3', expect.anything());
        });

        it('keeps the checks across a re-render and drops flows that are gone after a reload', async () => {
            const {grid, fetchSpy} = mountPicking();
            await flush();
            renderNames(grid, LIST);
            pick(3);
            pick(5);

            renderNames(grid, LIST); // a sort/page change re-renders the cells
            expect($el.find('[data-role="flow-pick"]:checked').map((_i: number, el: HTMLElement) => el.dataset.flowId).get()).toEqual(['3', '5']);

            fetchSpy.mockImplementation(() => Promise.resolve(jsonResponse({records: [LIST[1], LIST[2]]})));
            $el.find('[data-role="refresh"]').trigger('click');
            await flush();

            expect($el.find('[data-role="open-selected-label"]').text()).toBe('aaxis.ontology.flow_view.open_button'); // one left
        });

        it('stops reacting to the checkboxes once disposed', async () => {
            const {component, grid} = mountPicking();
            await flush();
            renderNames(grid, LIST);
            component.dispose();

            pick(3);

            expect($open().prop('disabled')).toBe(true);
        });
    });

    describe('grid', () => {
        it('builds the flows grid with its own preferences key', () => {
            const {grid} = mount();

            expect(grid.options.columns.map((c: any) => c.key)).toEqual(
                ['name', 'type', 'enabled', 'lastExecuted', 'lastFinished', 'lastModified']
            );
            expect(grid.options.gridKey).toBe('ontology-flow-view');
            expect(grid.options.preferencesUrl).toBe('/aaxis_common_grid_preference_get?gridKey=ontology-flow-view');
            expect(grid.mount).toHaveBeenCalledWith($el.find('[data-role="list"]'));
        });

        it('disables Export and Delete for the built-in (native) flows only', () => {
            const {grid} = mount();

            expect(grid.options.actions.map((a: any) => a.key)).toEqual(['export', 'delete']);
            for (const key of ['export', 'delete']) {
                expect(grid.action(key).disabled({type: 'native'})).toBe(true);
                expect(grid.action(key).disabled({type: 'flow'})).toBe(false);
                expect(grid.action(key).disabledTitle).toContain('builtin_disabled');
            }
            expect(grid.action('delete').variant).toBe('danger');
        });

        it('labels the type column, defaulting an empty type to subflow', () => {
            const {grid} = mount();
            const render = grid.column('type').render;

            expect(render(flow({type: 'flow'}))).toBe('aaxis.ontology.flow_view.type_flow');
            expect(render(flow({type: ''}))).toBe('aaxis.ontology.flow_view.type_subflow');
        });

        it('shows "Running" instead of a finish time while a run is in flight', () => {
            const {grid} = mount();
            const render = grid.column('lastFinished').render;

            expect(render(flow({running: true}))).toBe('aaxis.ontology.flow_view.running');
            expect(render(flow())).toBe(new Date('2026-05-12T10:00:05Z').toLocaleString());
            expect(render(flow({lastFinished: null}))).toBe('');
        });

        it('formats the executed and modified stamps', () => {
            const {grid} = mount();

            expect(grid.column('lastExecuted').render(flow())).toBe(new Date('2026-05-12T10:00:00Z').toLocaleString());
            expect(grid.column('lastModified').render(flow({lastModified: null}))).toBe('');
        });

        it('opens the editor when a user flow row is clicked, and ignores native rows', () => {
            const {grid} = mount();
            const event = {metaKey: true} as MouseEvent;

            grid.options.onRowClick(flow({id: 8}), event);
            grid.options.onRowClick(flow({id: 9, type: 'native'}), event);

            expect(navigateTo).toHaveBeenCalledTimes(1);
            expect(navigateTo).toHaveBeenCalledWith('/aaxis_ontology_flow_editor?id=8', event);
        });
    });

    describe('loading and toolbar', () => {
        it('loads the flows into the grid', async () => {
            const {grid, fetchSpy} = mount();
            await flush();

            expect(fetchSpy).toHaveBeenCalledWith(LIST_URL, {credentials: 'same-origin'});
            expect(grid.setRows).toHaveBeenCalledWith([flow()]);
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('treats a missing record list as empty', async () => {
            const {grid} = mount({[LIST_URL]: jsonResponse({})});
            await flush();

            expect(grid.setRows).toHaveBeenCalledWith([]);
        });

        it('flashes an error when loading fails', async () => {
            mount({[LIST_URL]: Promise.reject(new Error('x'))});
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.flow_view.load_error');
        });

        it('refreshes and opens column settings from the toolbar', async () => {
            const {grid, fetchSpy} = mount();
            await flush();

            $el.find('[data-role="refresh"]').trigger('click');
            $el.find('[data-role="columns-settings"]').trigger('click');

            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
            expect(grid.toggleColumnSettings).toHaveBeenCalledTimes(1);
        });

        it('opens a blank editor on Add, forwarding the ORIGINAL click event for cmd/ctrl+click', () => {
            mount();
            const native = new MouseEvent('click', {metaKey: true, bubbles: true});
            const event = $.Event('click', {originalEvent: native});

            $el.find('[data-role="add"]').trigger(event);

            expect(event.isDefaultPrevented()).toBe(true);
            expect(navigateTo).toHaveBeenCalledWith('/aaxis_ontology_flow_editor', native);
        });

        it('falls back to the jQuery event when there is no original event', () => {
            mount();

            $el.find('[data-role="add"]').trigger('click');

            expect(navigateTo).toHaveBeenCalledTimes(1);
            expect(navigateTo.mock.calls[0][0]).toBe('/aaxis_ontology_flow_editor');
        });
    });

    describe('delete', () => {
        function openDelete(routes: Record<string, any> = {}, row: any = flow()) {
            const ctx = mount(routes);
            ctx.grid.options.onAction('delete', row);

            return {...ctx, dialog: FakeDialog.instances[0]};
        }

        it('asks for confirmation before deleting', () => {
            const {dialog} = openDelete();

            expect(dialog.options).toMatchObject({title: 'aaxis.ontology.flow_view.delete_title', width: '520px'});
            expect(dialog.$content.find('.aaxis-ontology-confirm__q').text()).toBe('aaxis.ontology.flow_view.confirm_delete');
            expect(dialog.$content.find('.aaxis-ontology-confirm__danger').text()).toBe('aaxis.ontology.flow_view.delete_warning');
        });

        it('never deletes or exports a native flow, even though the grid dispatches disabled actions', () => {
            const {grid, fetchSpy} = mount();
            fetchSpy.mockClear();

            grid.options.onAction('delete', flow({type: 'native'}));
            grid.options.onAction('export', flow({type: 'native'}));

            expect(FakeDialog.instances).toHaveLength(0);
            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it('ignores an unknown action', () => {
            const {grid, fetchSpy} = mount();
            fetchSpy.mockClear();

            grid.options.onAction('bogus', flow());

            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it('cancels without a request', () => {
            const {dialog, fetchSpy} = openDelete();
            fetchSpy.mockClear();

            dialog.$content.find('button.btn').first().trigger('click');

            expect(dialog.close).toHaveBeenCalled();
            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it.each([
            ['successful', {successful: true}],
            ['success', {success: true}]
        ])('deletes with the CSRF header on a "%s" answer, then reloads and closes', async (_key, body) => {
            const {dialog, fetchSpy} = openDelete({'/aaxis_ontology_flow_delete': jsonResponse(body)});
            await flush();

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');
            expect(dialog.$content.find('.aaxis-ontology-confirm__delete').prop('disabled')).toBe(true);
            await flush();

            const [url, init] = fetchSpy.mock.calls.find(c => c[0].startsWith('/aaxis_ontology_flow_delete'))!;
            expect(url).toBe('/aaxis_ontology_flow_delete?id=3');
            expect(init).toEqual({method: 'DELETE', credentials: 'same-origin', headers: {'X-CSRF-Header': 'tok'}});
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', 'aaxis.ontology.flow_view.deleted');
            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
            expect(dialog.close).toHaveBeenCalled();
        });

        it.each([
            ['the server message', jsonResponse({successful: false, message: 'Referenced'}), 'Referenced'],
            ['the generic message when the server gives none', jsonResponse({}, false), 'aaxis.ontology.flow_view.delete_error'],
            ['the generic message for an empty body', jsonResponse(null), 'aaxis.ontology.flow_view.delete_error']
        ])('flashes %s on a failed delete and still closes', async (_name, response, message) => {
            const {dialog} = openDelete({'/aaxis_ontology_flow_delete': response});
            await flush();

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', message);
            expect(dialog.close).toHaveBeenCalled();
        });

        it('flashes the generic error when the request fails', async () => {
            const {dialog} = openDelete({'/aaxis_ontology_flow_delete': Promise.reject(new Error('net'))});
            await flush();

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.flow_view.delete_error');
            expect(dialog.close).toHaveBeenCalled();
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });
    });

    describe('export', () => {
        let clicked: HTMLAnchorElement[];
        let createObjectURL: ReturnType<typeof vi.fn>;
        let revokeObjectURL: ReturnType<typeof vi.fn>;
        let blobParts: any[];

        beforeEach(() => {
            clicked = [];
            blobParts = [];
            vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
                clicked.push(this);
            });
            createObjectURL = vi.fn(() => 'blob:x');
            revokeObjectURL = vi.fn();
            (window.URL as any).createObjectURL = createObjectURL;
            (window.URL as any).revokeObjectURL = revokeObjectURL;
            vi.stubGlobal('Blob', class extends Blob {
                constructor(parts: BlobPart[] = [], options?: BlobPropertyBag) {
                    super(parts, options);
                    blobParts.push(parts, options);
                }
            });
        });

        const EXPORT_URL = '/aaxis_ontology_flow_export';

        it('downloads the exported document as pretty JSON under the server-provided filename', async () => {
            vi.useFakeTimers();
            const {grid} = mount({[EXPORT_URL]: jsonResponse({success: true, document: {format: 'x', v: 1}, filename: 'nightly.json'})});
            await flush();

            grid.options.onAction('export', flow());
            await flush();

            expect(blobParts[0]).toEqual([JSON.stringify({format: 'x', v: 1}, null, 2)]);
            expect(blobParts[1]).toEqual({type: 'application/json;charset=utf-8'});
            expect(clicked).toHaveLength(1);
            expect(clicked[0].download).toBe('nightly.json');
            expect(clicked[0].href).toBe('blob:x');
            expect(document.body.contains(clicked[0])).toBe(false);
            expect(revokeObjectURL).not.toHaveBeenCalled();
            vi.advanceTimersByTime(1);
            expect(revokeObjectURL).toHaveBeenCalledWith('blob:x');
            vi.useRealTimers();
        });

        it('falls back to flow.json when the server names no file', async () => {
            const {grid} = mount({[EXPORT_URL]: jsonResponse({success: true, document: {}})});
            await flush();

            grid.options.onAction('export', flow());
            await flush();

            expect(clicked[0].download).toBe('flow.json');
        });

        it.each([
            ['the first server error', jsonResponse({success: false, errors: ['Bad connector', 'Other']}), 'Bad connector'],
            ['the generic message when there are no errors', jsonResponse({success: false}), 'aaxis.ontology.flow_view.export_error'],
            ['the generic message on an HTTP failure', jsonResponse({success: true}, false), 'aaxis.ontology.flow_view.export_error'],
            ['the generic message for an empty body', jsonResponse(null), 'aaxis.ontology.flow_view.export_error']
        ])('flashes %s', async (_name, response, message) => {
            const {grid} = mount({[EXPORT_URL]: response});
            await flush();

            grid.options.onAction('export', flow());
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', message);
            expect(createObjectURL).not.toHaveBeenCalled();
        });

        it('flashes the generic message when the request itself fails with an empty error', async () => {
            const {grid} = mount({[EXPORT_URL]: Promise.reject(new Error(''))});
            await flush();

            grid.options.onAction('export', flow());
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.flow_view.export_error');
        });

        it('requests the export by flow id', async () => {
            const {grid, fetchSpy} = mount({[EXPORT_URL]: jsonResponse({success: true, document: {}})});
            await flush();

            grid.options.onAction('export', flow({id: 41}));

            expect(fetchSpy).toHaveBeenCalledWith('/aaxis_ontology_flow_export?id=41', {credentials: 'same-origin'});
            await flush();
        });
    });

    describe('import', () => {
        const IMPORT_URL = '/aaxis_ontology_flow_import';

        function openImport(routes: Record<string, any> = {}) {
            const ctx = mount(routes);
            $el.find('[data-role="import"]').trigger('click');
            const dialog = FakeDialog.instances[0];
            const $file = dialog.$content.find('input[type="file"]');
            const $submit = dialog.$content.find('.btn-primary');
            const $errors = dialog.$content.find('[role="alert"]');

            /** Picks a file the way the browser does: files is read-only, so define it. */
            const pick = (file: File | null) => {
                Object.defineProperty($file[0], 'files', {value: file ? [file] : [], configurable: true});
                $file.trigger('change');
            };

            return {...ctx, dialog, $file, $submit, $errors, pick};
        }

        it('opens a dialog with a JSON file picker and a disabled Import button', () => {
            const {dialog, $file, $submit, $errors} = openImport();

            expect(dialog.options).toMatchObject({
                title: 'aaxis.ontology.flow_view.import_title',
                subtitle: 'aaxis.ontology.flow_view.import_hint',
                width: '560px'
            });
            expect($file.attr('accept')).toBe('application/json,.json');
            expect($submit.prop('disabled')).toBe(true);
            expect($errors.attr('hidden')).toBe('hidden');
        });

        it('swallows the default action of the Import toolbar button', () => {
            mount();
            const event = $.Event('click');

            $el.find('[data-role="import"]').trigger(event);

            expect(event.isDefaultPrevented()).toBe(true);
        });

        it('enables Import once a file is chosen and disables it again when the choice is cleared', () => {
            const {$submit, pick} = openImport();

            pick(textFile('{}'));
            expect($submit.prop('disabled')).toBe(false);

            pick(null);
            expect($submit.prop('disabled')).toBe(true);
        });

        it('clears a previous error when another file is picked', () => {
            const {$errors, pick} = openImport();
            $errors.removeAttr('hidden').append('<ul><li>old</li></ul>');

            pick(textFile('{}'));

            expect($errors.attr('hidden')).toBe('hidden');
            expect($errors.children()).toHaveLength(0);
        });

        it('closes on Cancel', () => {
            const {dialog} = openImport();

            dialog.$content.find('button.btn').first().trigger('click');

            expect(dialog.close).toHaveBeenCalled();
        });

        it('does nothing when Import is pressed with no file', () => {
            const {$submit, fetchSpy, pick} = openImport();
            pick(null);
            fetchSpy.mockClear();

            $submit.prop('disabled', false).trigger('click');

            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it('sends the file TEXT as {document}, with the CSRF header, then flashes, closes and reloads', async () => {
            const {dialog, fetchSpy, $file, $submit, pick} = openImport({[IMPORT_URL]: jsonResponse({success: true})});
            await flush();
            pick(textFile('{"format":"aaxis-ontology-flow"}'));

            $submit.trigger('click');
            expect($submit.prop('disabled')).toBe(true);
            await flush();

            const [url, init] = fetchSpy.mock.calls.find(c => c[0] === IMPORT_URL)!;
            expect(url).toBe(IMPORT_URL);
            expect(init.method).toBe('POST');
            expect(init.credentials).toBe('same-origin');
            expect(init.headers).toEqual({'Content-Type': 'application/json', 'X-CSRF-Header': 'tok'});
            expect(JSON.parse(init.body)).toEqual({document: '{"format":"aaxis-ontology-flow"}'});
            expect($file.val()).toBe('');
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', 'aaxis.ontology.flow_view.imported');
            expect(dialog.close).toHaveBeenCalled();
            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
        });

        it('lists every problem the server reports and lets the user retry', async () => {
            const {dialog, $submit, $errors, pick} = openImport({
                [IMPORT_URL]: jsonResponse({success: false, errors: ['Name taken', 'No such connector']}, false)
            });
            await flush();
            pick(textFile('{}'));

            $submit.trigger('click');
            await flush();

            expect($errors.attr('hidden')).toBeUndefined();
            expect($errors.find('ul.aaxis-flow-import__errors li').map((_i: number, li: any) => li.textContent).get())
                .toEqual(['Name taken', 'No such connector']);
            expect(dialog.close).not.toHaveBeenCalled();
            expect($submit.prop('disabled')).toBe(false); // the failed request releases the button for a retry
        });

        it.each([
            ['an unsuccessful body without errors', jsonResponse({success: false})],
            ['an empty body', jsonResponse(null)]
        ])('shows the generic import error for %s', async (_name, response) => {
            const {$submit, $errors, pick} = openImport({[IMPORT_URL]: response});
            await flush();
            pick(textFile('{}'));

            $submit.trigger('click');
            await flush();

            expect($errors.find('li').text()).toBe('aaxis.ontology.flow_view.import_error');
        });

        it('shows the generic import error when the request fails', async () => {
            const {$submit, $errors, pick} = openImport({[IMPORT_URL]: Promise.reject(new Error('net'))});
            await flush();
            pick(textFile('{}'));

            $submit.trigger('click');
            await flush();

            expect($errors.find('li').text()).toBe('aaxis.ontology.flow_view.import_error');
            expect($submit.prop('disabled')).toBe(false);
        });

        it('reports a read error and re-enables Import when the file cannot be read', async () => {
            const {fetchSpy, $submit, $errors, pick} = openImport();
            await flush();
            fetchSpy.mockClear();
            const file = textFile('{}');
            (file as any).text = () => Promise.reject(new Error('unreadable'));
            pick(file);

            $submit.trigger('click');
            await flush();

            expect($errors.find('li').text()).toBe('aaxis.ontology.flow_view.import_read_error');
            expect($submit.prop('disabled')).toBe(false);
            expect(fetchSpy).not.toHaveBeenCalled();
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
            expect(navigateTo).not.toHaveBeenCalled();
        });
    });
});

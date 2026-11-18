import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import EventComponent from '../../../../Resources/js-src/app/components/event-component';
import {
    FakeDialog, FakeGrid, flush, jsonResponse, navigateTo, pageElement, resetFakes, stubFetch
} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/data-grid', async () => (await import('../../helpers/page-mocks')).dataGridModule());
vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());

const LIST_URL = '/aaxis_ontology_event_list';

function run(overrides: Record<string, unknown> = {}): any {
    return {
        flowUuid: 'uuid-1', flowId: 5, flowName: 'Nightly', startedAt: '2026-05-12T10:00:00Z',
        finishedAt: '2026-05-12T10:00:03Z', elapsedMs: 3000, status: 'success', events: 4, ...overrides
    };
}

describe('OntologyEventComponent', () => {
    let $el: any;

    beforeEach(() => {
        resetFakes();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        window.history.replaceState({}, '', '/');
    });

    function mount(records: any[] = [run()], routes: Record<string, any> = {}) {
        $el = pageElement();
        const fetchSpy = stubFetch({[LIST_URL]: jsonResponse({records}), ...routes});
        const component: any = new (EventComponent as any)({_sourceElement: $el});

        return {component, fetchSpy, grid: FakeGrid.instances[0]};
    }

    describe('grid', () => {
        it('addresses runs by uuid and uses a fresh preferences key', () => {
            const {grid} = mount();

            expect(grid.options.idKey).toBe('flowUuid');
            expect(grid.options.gridKey).toBe('ontology-event-runs');
            expect(grid.options.preferencesUrl).toBe('/aaxis_common_grid_preference_get?gridKey=ontology-event-runs');
            expect(grid.options.columns.map((c: any) => c.key)).toEqual(
                ['flowName', 'flowUuid', 'startedAt', 'finishedAt', 'elapsedMs', 'status', 'events']
            );
            expect(grid.mount).toHaveBeenCalledWith($el.find('[data-role="list"]'));
        });

        it('offers view-events and view-flow, the latter disabled once the flow is gone', () => {
            const {grid} = mount();

            expect(grid.options.actions.map((a: any) => a.key)).toEqual(['events', 'flow']);
            expect(grid.action('flow').disabled({flowId: null})).toBe(true);
            expect(grid.action('flow').disabled({flowId: 4})).toBe(false);
            expect(grid.action('flow').disabledTitle).toBe('aaxis.ontology.event_view.view_flow_gone');
        });

        it('renders the uuid as a titled span and copies the bare uuid', () => {
            const {grid} = mount();
            const col = grid.column('flowUuid');

            const $cell = col.render(run());
            expect($cell.is('.aaxis-event-uuid__text')).toBe(true);
            expect($cell.text()).toBe('uuid-1');
            expect($cell.attr('title')).toBe('uuid-1');
            expect(col.render(run({flowUuid: null})).text()).toBe('');
            expect(col.copyValue(run())).toBe('uuid-1');
            expect(col.copyValue(run({flowUuid: null}))).toBe('');
        });

        it('formats the start and finish times with the locale formatter', () => {
            const {grid} = mount();

            expect(grid.column('startedAt').render(run())).toBe(new Date('2026-05-12T10:00:00Z').toLocaleString());
            expect(grid.column('finishedAt').render(run({finishedAt: null}))).toBe('');
        });

        it('shows a running run as "Running" and copies an empty elapsed value for it', () => {
            const {grid} = mount();
            const col = grid.column('elapsedMs');

            const $running = col.render(run({elapsedMs: null}));
            expect($running.is('.aaxis-event-running')).toBe(true);
            expect($running.text()).toBe('aaxis.ontology.event_view.running');
            expect(col.copyValue(run({elapsedMs: null}))).toBe('');
            expect(col.copyValue(run({elapsedMs: 250}))).toBe('250');
        });

        it.each([
            [0, '0ms'],
            [999, '999ms'],
            [1000, '1.0s'],
            [1499, '1.5s'],
            [59_999, '60.0s'],
            [60_000, '1m 00s'],
            [125_400, '2m 05s'],
            [3_599_000, '59m 59s'],
            [3_600_000, '1h 00m'],
            [3_900_000, '1h 05m'],
            [90_000_000, '25h 00m']
        ])('humanizes %s ms as %s', (ms, text) => {
            const {grid} = mount();

            expect(grid.column('elapsedMs').render(run({elapsedMs: ms})).text()).toBe(text);
        });

        it('renders the status badge with a status-specific class and label', () => {
            const {grid} = mount();
            const col = grid.column('status');

            const $badge = col.render(run({status: 'exception'}));
            expect($badge.attr('class')).toBe('aaxis-event-status aaxis-event-status--exception');
            expect($badge.text()).toBe('aaxis.ontology.event_view.status_exception');
            expect(col.copyValue(run({status: 'running'}))).toBe('running');
        });
    });

    describe('loading', () => {
        it('loads the runs, releasing the busy state afterwards', async () => {
            const {grid, fetchSpy} = mount([run(), run({flowUuid: 'u2'})]);

            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(true);
            await flush();

            expect(fetchSpy).toHaveBeenCalledWith(LIST_URL, {credentials: 'same-origin'});
            expect(grid.setRows.mock.calls[0][0]).toHaveLength(2);
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('treats a missing record list as empty', async () => {
            const {grid} = mount(undefined, {[LIST_URL]: jsonResponse({})});
            await flush();

            expect(grid.setRows).toHaveBeenCalledWith([]);
        });

        it('flashes an error when loading fails', async () => {
            mount(undefined, {[LIST_URL]: Promise.reject(new Error('x'))});
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.event_view.load_error');
            expect($el.find('[data-role="refresh"]').prop('disabled')).toBe(false);
        });

        it('refreshes incrementally: only the runs changed since the cursor, merged into the rows shown', async () => {
            const answers = [
                {records: [run({flowUuid: 'a', startedAt: '2026-05-12T10:00:00+00:00'}), run({flowUuid: 'b', startedAt: '2026-05-12T09:00:00+00:00', status: 'running'})], cursor: 'C1', incremental: false},
                {records: [run({flowUuid: 'b', startedAt: '2026-05-12T09:00:00+00:00', status: 'success'}), run({flowUuid: 'c', startedAt: '2026-05-12T11:00:00+00:00'})], cursor: 'C2', incremental: true},
                {records: [], cursor: 'C3', incremental: true}
            ];
            const urls: string[] = [];
            const {grid} = mount(undefined, {[LIST_URL]: (url: string) => {
                urls.push(url);
                return jsonResponse(answers[urls.length - 1]);
            }});
            await flush();

            $el.find('[data-role="refresh"]').trigger('click');
            await flush();
            const merged = grid.setRows.mock.calls.at(-1)[0];
            expect(merged.map((r: any) => r.flowUuid)).toEqual(['c', 'a', 'b']);
            expect(merged[2].status).toBe('success');

            $el.find('[data-role="refresh"]').trigger('click');
            await flush();
            expect(urls).toEqual([LIST_URL, `${LIST_URL}?since=C1`, `${LIST_URL}?since=C2`]);
            expect(grid.setRows.mock.calls.at(-1)[0].map((r: any) => r.flowUuid)).toEqual(['c', 'a', 'b']);
        });

        it('replaces every row on a full (non-incremental) answer', async () => {
            const answers = [
                {records: [run({flowUuid: 'a'}), run({flowUuid: 'b'})], cursor: 'C1'},
                {records: [run({flowUuid: 'z'})], cursor: 'C2', incremental: false}
            ];
            let call = 0;
            const {grid} = mount(undefined, {[LIST_URL]: () => jsonResponse(answers[call++])});
            await flush();

            $el.find('[data-role="refresh"]').trigger('click');
            await flush();

            expect(grid.setRows.mock.calls.at(-1)[0].map((r: any) => r.flowUuid)).toEqual(['z']);
        });

        it('wires the toolbar: refresh reloads, columns opens the settings', async () => {
            const {grid, fetchSpy} = mount();
            await flush();

            $el.find('[data-role="refresh"]').trigger('click');
            $el.find('[data-role="columns-settings"]').trigger('click');
            await flush();

            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(2);
            expect(grid.toggleColumnSettings).toHaveBeenCalledTimes(1);
        });
    });

    describe('?uuid= deep link', () => {
        it('pre-filters the grid to the requested run on the first load only', async () => {
            window.history.replaceState({}, '', '/?uuid=%20abc-123%20');
            const {grid} = mount();
            await flush();

            expect(grid.setFilter).toHaveBeenCalledWith('flowUuid', {operator: 'equals', value: 'abc-123'});

            $el.find('[data-role="refresh"]').trigger('click');
            await flush();
            expect(grid.setFilter).toHaveBeenCalledTimes(1);
        });

        it.each(['/', '/?uuid=', '/?uuid=%20%20'])('does not filter for %s', async url => {
            window.history.replaceState({}, '', url);
            const {grid} = mount();
            await flush();

            expect(grid.setFilter).not.toHaveBeenCalled();
        });
    });

    describe('row actions', () => {
        it('opens the flow editor for a run whose flow still exists, forwarding the click event', () => {
            const {grid} = mount();
            const event = {ctrlKey: true} as MouseEvent;

            grid.options.onAction('flow', run({flowId: 9}), event);

            expect(navigateTo).toHaveBeenCalledWith('/aaxis_ontology_flow_editor?id=9', event);
        });

        it('does nothing for a run whose flow is gone, or for an unknown action', () => {
            const {grid} = mount();

            grid.options.onAction('flow', run({flowId: null}));
            grid.options.onAction('events', run({flowUuid: null}));
            grid.options.onAction('other', run());

            expect(navigateTo).not.toHaveBeenCalled();
            expect(FakeDialog.instances).toHaveLength(0);
        });

        it('opens the events popup for a run that has a uuid', () => {
            const {grid} = mount(undefined, {'/aaxis_ontology_event_run': new Promise(() => undefined)});

            grid.options.onAction('events', run());

            expect(FakeDialog.instances).toHaveLength(1);
        });
    });

    describe('run events popup', () => {
        const RUN_URL = '/aaxis_ontology_event_run';

        function openPopup(records: any[] | undefined, row: any = run(), routes: Record<string, any> = {}) {
            const ctx = mount(undefined, {
                [RUN_URL]: records === undefined ? jsonResponse({}) : jsonResponse({records}),
                ...routes
            });
            ctx.grid.options.onAction('events', row);

            return {...ctx, dialog: FakeDialog.instances[0]};
        }

        const rows = (dialog: FakeDialog) => dialog.$content.find('.aaxis-event-run__row');

        it('builds a wide flex-body dialog titled with the flow name and shows a loading note first', () => {
            const {dialog} = openPopup([]);

            expect(dialog.options).toMatchObject({
                title: 'aaxis.ontology.event_view.run_events_title — Nightly',
                width: '760px',
                bodyClass: 'aaxis-event-run-host'
            });
            expect(dialog.$content.find('.aaxis-json-note').text()).toBe('aaxis.ontology.event_view.loading');
        });

        it('falls back to the uuid in the title when the flow has no name', () => {
            const {dialog} = openPopup([], run({flowName: null}));

            expect(dialog.options.title).toBe('aaxis.ontology.event_view.run_events_title — uuid-1');
        });

        it('requests the run by uuid, url-encoded', () => {
            const {fetchSpy} = openPopup([], run({flowUuid: 'a b/c'}));

            expect(fetchSpy).toHaveBeenCalledWith('/aaxis_ontology_event_run?uuid=a%20b%2Fc', {credentials: 'same-origin'});
        });

        it('addresses a bucket-backed run by flow id and the raw start/finish stamps', () => {
            const {fetchSpy} = openPopup([], run({
                bucket: true, flowId: 5, startedAtRaw: '2026-05-12 10:00:00.1', finishedAtRaw: '2026-05-12 10:00:03.9'
            }));

            expect(fetchSpy).toHaveBeenCalledWith(
                '/aaxis_ontology_event_run?uuid=uuid-1&bucket=1&flowId=5'
                + '&startedAt=2026-05-12%2010%3A00%3A00.1&finishedAt=2026-05-12%2010%3A00%3A03.9',
                {credentials: 'same-origin'}
            );
        });

        it('sends empty bucket parameters for a run with no flow id or stamps', () => {
            const {fetchSpy} = openPopup([], run({bucket: true, flowId: null}));

            expect(fetchSpy).toHaveBeenCalledWith(
                '/aaxis_ontology_event_run?uuid=uuid-1&bucket=1&flowId=&startedAt=&finishedAt=',
                {credentials: 'same-origin'}
            );
        });

        it('shows the full time on boundary events and "+delta" since the previous event on the rest', async () => {
            const {dialog} = openPopup([
                {id: 1, event: 'flow-start', datetime: '2026-05-12T10:00:00Z', ms: 1000, payload: {trigger: 'debug'}},
                {id: 2, event: 'step', datetime: '2026-05-12T10:00:00Z', ms: 1250, payload: {name: 'read', type: 'entity_read'}},
                {id: 3, event: 'log-message', datetime: '2026-05-12T10:00:02Z', ms: 3500, payload: {message: 'hi'}},
                {id: 4, event: 'flow-finish', datetime: '2026-05-12T10:00:03Z', ms: 4000, payload: {}}
            ]);
            await flush();

            const $rows = rows(dialog);
            expect($rows).toHaveLength(4);
            const time = (i: number) => $rows.eq(i).find('.aaxis-event-run__time').text();
            expect(time(0)).toBe(new Date('2026-05-12T10:00:00Z').toLocaleString());
            expect(time(1)).toBe('+250ms');
            expect(time(2)).toBe('+2.3s'); // 2250 ms since the step
            expect(time(3)).toBe(new Date('2026-05-12T10:00:03Z').toLocaleString());
            expect(dialog.$content.find('.aaxis-json-note')).toHaveLength(0);
        });

        it('leaves the time blank for a non-boundary event with no previous timestamp or no ms of its own', async () => {
            const {dialog} = openPopup([
                {id: 1, event: 'step', datetime: null, ms: 100, payload: {name: 'a', type: 't'}},
                {id: 2, event: 'step', datetime: null, ms: null, payload: {name: 'b', type: 't'}},
                {id: 3, event: 'step', datetime: null, ms: 130, payload: {name: 'c', type: 't'}}
            ]);
            await flush();

            const time = (i: number) => rows(dialog).eq(i).find('.aaxis-event-run__time').text();
            expect(time(0)).toBe('');
            expect(time(1)).toBe('');
            expect(time(2)).toBe('+30ms'); // relative to the last event that HAD an ms
        });

        it('never renders a negative delta', async () => {
            const {dialog} = openPopup([
                {id: 1, event: 'step', datetime: null, ms: 500, payload: {name: 'a', type: 't'}},
                {id: 2, event: 'step', datetime: null, ms: 400, payload: {name: 'b', type: 't'}}
            ]);
            await flush();

            expect(rows(dialog).eq(1).find('.aaxis-event-run__time').text()).toBe('+0ms');
        });

        it('tags each row with its event kind', async () => {
            const {dialog} = openPopup([
                {id: 1, event: 'flow-exception', datetime: null, ms: null, payload: {message: 'boom'}}
            ]);
            await flush();

            const $kind = rows(dialog).find('.aaxis-event-kind');
            expect($kind.attr('class')).toBe('aaxis-event-kind aaxis-event-kind--flow-exception');
            expect($kind.text()).toBe('flow-exception');
        });

        it.each([
            ['flow-start', {trigger: 'schedule', user: {name: 'Ann'}}, 'schedule — Ann'],
            ['subflow-start', {trigger: 'subflow'}, 'subflow'],
            ['flow-start', {user: {name: 'Bob'}}, ' — Bob'],
            ['flow-start', {user: {}}, ''],
            ['data-upsert', {entity: 'Order', uniqueIds: [1, 2, 3], changedIds: [2]}, 'Order — 3 ids, 1 changed'],
            ['data-upsert', {uniqueIds: 'x'}, ' — 0 ids, 0 changed'],
            ['step', {name: 'read', type: 'entity_read'}, 'read (entity_read)'],
            ['step', {}, ' ()'],
            ['flow-exception', {message: 'boom'}, 'boom'],
            ['log-message', {message: 42}, '42'],
            ['log-message', {}, '']
        ])('summarizes %s %j as "%s"', async (kind, payload, expected) => {
            const {dialog} = openPopup([{id: 1, event: kind, datetime: null, ms: null, payload}]);
            await flush();

            const $summary = rows(dialog).find('.aaxis-event-run__summary');
            if (expected === '') {
                expect($summary).toHaveLength(0);
            } else {
                expect($summary.text()).toBe(expected);
                expect($summary.attr('title')).toBe(expected);
            }
        });

        it('shows nothing extra for finish events and tolerates a missing payload', async () => {
            const {dialog} = openPopup([
                {id: 1, event: 'flow-finish', datetime: null, ms: null, payload: {message: 'ignored'}},
                {id: 2, event: 'subflow-finish', datetime: null, ms: null, payload: null},
                {id: 3, event: 'step', datetime: null, ms: null, payload: null}
            ]);
            await flush();

            expect(rows(dialog).eq(0).find('.aaxis-event-run__summary')).toHaveLength(0);
            expect(rows(dialog).eq(1).find('.aaxis-event-run__summary')).toHaveLength(0);
            expect(rows(dialog).eq(2).find('.aaxis-event-run__summary').text()).toBe(' ()');
        });

        it('truncates only the tooltip of an over-long summary', async () => {
            const long = 'x'.repeat(2500);
            const {dialog} = openPopup([{id: 1, event: 'log-message', datetime: null, ms: null, payload: {message: long}}]);
            await flush();

            const $summary = rows(dialog).find('.aaxis-event-run__summary');
            expect($summary.text()).toBe(long);
            expect($summary.attr('title')).toBe('x'.repeat(2000) + '…');
        });

        describe('summaries that do not fit their line', () => {
            const LONG = 'Step "Push": the connector responded HTTP 500 — a very long upstream explanation follows';

            /** jsdom has no layout: a summary over 40 characters "overflows" its 300px line. */
            function fakeLayout(): void {
                vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockImplementation(function (this: HTMLElement) {
                    return (this.textContent || '').length > 40 ? 900 : 100;
                });
                vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(300);
            }

            async function openWith(messages: string[]): Promise<FakeDialog> {
                const {dialog} = openPopup(messages.map((message, i) => (
                    {id: i, event: 'flow-exception', datetime: null, ms: null, payload: {message}}
                )));
                await flush();
                return dialog;
            }

            afterEach(() => {
                vi.restoreAllMocks();
            });

            it('offers expand and copy only where the text is cut or holds line breaks', async () => {
                fakeLayout();
                const dialog = await openWith(['short', LONG, 'two\nlines']);

                expect(rows(dialog).map((_i: number, el: HTMLElement) => el.classList.contains('has-overflow')).get())
                    .toEqual([false, true, true]);
                const $tools = rows(dialog).eq(1).find('.aaxis-event-run__tool');
                expect($tools.map((_i: number, el: HTMLElement) => el.dataset.role).get()).toEqual(['summary-expand', 'summary-copy']);
                expect($tools.eq(0).attr('title')).toBe('aaxis.ontology.event_view.summary_expand');
                expect($tools.eq(1).attr('aria-label')).toBe('aaxis.ontology.event_view.summary_copy');
            });

            it('expands the whole text over several lines and back', async () => {
                fakeLayout();
                const dialog = await openWith([LONG]);
                const $row = rows(dialog).eq(0);
                const $expand = $row.find('[data-role="summary-expand"]');

                $expand.trigger('click');
                expect($row.hasClass('is-expanded')).toBe(true);
                expect($expand.find('.fa').hasClass('fa-compress')).toBe(true);
                expect($expand.attr('title')).toBe('aaxis.ontology.event_view.summary_collapse');

                $expand.trigger('click');
                expect($row.hasClass('is-expanded')).toBe(false);
                expect($expand.find('.fa').hasClass('fa-expand')).toBe(true);
                expect($expand.attr('title')).toBe('aaxis.ontology.event_view.summary_expand');
            });

            it('copies the full message, confirming with a check mark for a moment', async () => {
                const writeText = vi.fn(() => Promise.resolve());
                vi.stubGlobal('navigator', {clipboard: {writeText}});
                const timeout = vi.spyOn(window, 'setTimeout');
                const dialog = await openWith([LONG]);
                const $copy = rows(dialog).find('[data-role="summary-copy"]');

                $copy.trigger('click');
                await flush();

                expect(writeText).toHaveBeenCalledWith(LONG);
                expect($copy.find('.fa').hasClass('fa-check')).toBe(true);
                (timeout.mock.calls.at(-1)![0] as () => void)();
                expect($copy.find('.fa').hasClass('fa-files-o')).toBe(true);
            });

            it('flashes an error when the browser refuses the copy', async () => {
                vi.stubGlobal('navigator', {clipboard: {writeText: () => Promise.reject(new Error('denied'))}});
                const dialog = await openWith([LONG]);

                rows(dialog).find('[data-role="summary-copy"]').trigger('click');
                await flush();

                expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.event_view.summary_copy_failed');
            });

            it('re-checks on every resize of the list, keeps expanded rows, and stops watching on close', async () => {
                let onResize: () => void = () => undefined;
                const disconnect = vi.fn();
                vi.stubGlobal('ResizeObserver', class {
                    constructor(callback: () => void) {
                        onResize = callback;
                    }

                    observe(): void {
                        // the callback above is what matters
                    }

                    disconnect = disconnect;
                });
                const dialog = await openWith([LONG, 'short']);
                expect(rows(dialog).eq(0).hasClass('has-overflow')).toBe(false); // no layout yet

                fakeLayout();
                onResize();
                expect(rows(dialog).eq(0).hasClass('has-overflow')).toBe(true);
                rows(dialog).eq(0).find('[data-role="summary-expand"]').trigger('click');
                onResize();
                expect(rows(dialog).eq(0).hasClass('is-expanded')).toBe(true);
                expect(rows(dialog).eq(1).hasClass('has-overflow')).toBe(false);

                dialog.close();
                expect(disconnect).toHaveBeenCalled();
            });
        });

        it('says so when the run has no events', async () => {
            const {dialog} = openPopup([]);
            await flush();

            expect(rows(dialog)).toHaveLength(0);
            expect(dialog.$content.find('.aaxis-json-note').text()).toBe('aaxis.ontology.event_view.empty');
        });

        it('treats a missing record list like an empty one', async () => {
            const {dialog} = openPopup(undefined);
            await flush();

            expect(dialog.$content.find('.aaxis-json-note').text()).toBe('aaxis.ontology.event_view.empty');
        });

        it('clears the loading note and flashes an error when the request fails', async () => {
            const {dialog} = openPopup([], run(), {[RUN_URL]: Promise.reject(new Error('down'))});
            await flush();

            expect(dialog.$content.find('.aaxis-json-note')).toHaveLength(0);
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.event_view.load_error');
        });
    });

    describe('dispose', () => {
        it('unbinds the toolbar and disposes the grid; a second dispose is a no-op', async () => {
            const {component, grid, fetchSpy} = mount();
            await flush();

            component.dispose();
            component.dispose();
            $el.find('[data-role="refresh"]').trigger('click');

            expect(component.disposed).toBe(true);
            expect(grid.dispose).toHaveBeenCalledTimes(1);
            expect(fetchSpy.mock.calls.filter(c => c[0] === LIST_URL)).toHaveLength(1);
        });
    });
});

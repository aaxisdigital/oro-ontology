import $ from 'jquery';
import {beforeEach, describe, expect, it, vi} from 'vitest';
import FlowPickerDialog, {PICKER_SORTS, PickableFlow, sortFlows} from '../../../../Resources/js-src/app/widgets/flow-picker';
import {FakeDialog, flush, resetFakes} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());

const T = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

const flow = (id: number, name: string | null, extra: Partial<PickableFlow> = {}): PickableFlow =>
    ({id, name, type: 'flow', enabled: true, lastModified: null, ...extra});

const FLOWS: PickableFlow[] = [
    flow(1, 'orders', {lastModified: '2026-11-10T10:00:00+00:00'}),
    flow(2, 'Billing', {enabled: false, lastModified: '2026-11-18T09:30:00+00:00'}),
    flow(3, 'customers', {lastModified: '2026-11-01T08:00:00+00:00'}),
    flow(4, 'lookup', {type: 'subflow', lastModified: '2026-11-15T12:00:00+00:00'}),
    flow(5, 'notify', {type: 'subflow', enabled: false}),
    flow(6, 'Native', {type: 'native'})
];

describe('sortFlows', () => {
    const names = (sorted: PickableFlow[]): Array<string | null> => sorted.map(f => f.name);
    const rows = [
        flow(1, 'beta', {lastModified: '2026-11-02T00:00:00+00:00'}),
        flow(2, 'Alpha', {enabled: false, lastModified: '2026-11-03T00:00:00+00:00'}),
        flow(3, 'flow10', {lastModified: null}),
        flow(4, 'flow9', {enabled: false, lastModified: 'not a date'}),
        flow(5, 'gamma', {lastModified: '2026-11-02T00:00:00+00:00'})
    ];

    it.each([
        ['name_asc', ['Alpha', 'beta', 'flow9', 'flow10', 'gamma']],
        ['name_desc', ['gamma', 'flow10', 'flow9', 'beta', 'Alpha']],
        // Same instant → by name; no (or an unreadable) date counts as the oldest.
        ['modified_desc', ['Alpha', 'beta', 'gamma', 'flow9', 'flow10']],
        ['modified_asc', ['flow9', 'flow10', 'beta', 'gamma', 'Alpha']],
        ['status_enabled', ['beta', 'flow10', 'gamma', 'Alpha', 'flow9']],
        ['status_disabled', ['Alpha', 'flow9', 'beta', 'flow10', 'gamma']]
    ] as const)('orders by %s, ties by name', (sort, expected) => {
        expect(names(sortFlows(rows, sort))).toEqual(expected);
    });

    it('returns a copy and sorts unnamed flows as an empty name', () => {
        const input = [flow(8, 'b'), flow(7, null), flow(9, 'a')];
        expect(sortFlows(input, 'name_asc').map(f => f.id)).toEqual([7, 9, 8]);
        expect(input.map(f => f.id)).toEqual([8, 7, 9]);
    });

    it('offers every order', () => {
        expect([...PICKER_SORTS]).toEqual(['name_asc', 'name_desc', 'modified_desc', 'modified_asc', 'status_enabled', 'status_disabled']);
    });
});

describe('FlowPickerDialog', () => {
    let onOpen: ReturnType<typeof vi.fn>;
    let onLoadError: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        resetFakes();
        onOpen = vi.fn();
        onLoadError = vi.fn();
    });

    async function open(load: () => Promise<PickableFlow[]> = () => Promise.resolve(FLOWS)): Promise<{dialog: any; q: (role: string) => any}> {
        new FlowPickerDialog({load, onOpen, onLoadError}).open();
        const dialog = FakeDialog.instances.at(-1)!;
        await flush();

        return {dialog, q: (role: string) => dialog.$content.find(`[data-role="${role}"]`)};
    }

    const rowNames = (): string[] => $('.aaxis-flow-picker__row .aaxis-flow-picker__name').map((_i: number, el: HTMLElement) => el.textContent).get();
    const kindLabels = (): string[] => $('.aaxis-flow-picker__kind').map((_i: number, el: HTMLElement) => el.textContent).get();
    const row = (name: string): any => $('.aaxis-flow-picker__row').filter((_i: number, el: HTMLElement) => $(el).find('.aaxis-flow-picker__name').text() === name);
    const kind = (k: string): any => $(`.aaxis-flow-picker__kind[data-kind="${k}"]`);
    const header = (key: string): any => $(`[data-role="picker-sort"][data-sort="${key}"]`);
    /** Per sortable header: [column, aria-sort, caret class ('' when inactive)]. */
    const headState = (): Array<[string, string, string]> => $('[data-role="picker-sort"]').map((_i: number, el: HTMLElement) => [[
        el.dataset.sort, el.getAttribute('aria-sort'),
        Array.from(el.querySelector('.fa')?.classList || []).find(c => c.startsWith('fa-caret')) || ''
    ]]).get();

    it('shows a loading note, then the bar, the column head, the flows sorted by name and the actions', async () => {
        let resolve!: (flows: PickableFlow[]) => void;
        new FlowPickerDialog({load: () => new Promise(r => {
            resolve = r;
        }), onOpen, onLoadError}).open();
        const dialog = FakeDialog.instances.at(-1)!;
        expect(dialog.options).toMatchObject({title: T('tab_open'), width: '760px'});
        expect(dialog.$content.find('.aaxis-json-note').text()).toBe(T('loading'));

        resolve(FLOWS);
        await flush();
        const q = (role: string): any => dialog.$content.find(`[data-role="${role}"]`);

        // The filter and the kinds share the top bar; the sort lives in the column headers.
        expect($('.aaxis-flow-picker__bar').children().map((_i: number, el: HTMLElement) => el.dataset.role).get())
            .toEqual(['picker-filter', 'picker-kinds']);
        expect(document.activeElement).toBe(q('picker-filter')[0]);
        expect($('.aaxis-flow-picker__head').text()).toBe(T('tab_open_col_name') + T('tab_open_col_status') + T('tab_open_col_modified'));
        expect(headState()).toEqual([['name', 'ascending', 'fa-caret-up'], ['status', 'none', ''], ['modified', 'none', '']]);
        expect(q('picker-sort').first().attr('title')).toBe(T('tab_open_sort_by'));

        // Flows only at first; natives never.
        expect(kindLabels()).toEqual([`${T('tab_open_flows')} (3)`, `${T('tab_open_subflows')} (2)`]);
        expect(kind('flow').hasClass('is-active')).toBe(true);
        expect(kind('flow').attr('aria-pressed')).toBe('true');
        expect(kind('subflow').attr('aria-pressed')).toBe('false');
        expect(rowNames()).toEqual(['Billing', 'customers', 'orders']);

        // Every row: status (always) and the last modification.
        expect(row('Billing').find('.aaxis-flow-picker__badge').text()).toBe(T('tab_open_disabled'));
        expect(row('Billing').find('.aaxis-flow-picker__badge').hasClass('aaxis-flow-picker__badge--off')).toBe(true);
        expect(row('orders').find('.aaxis-flow-picker__badge').text()).toBe(T('tab_open_enabled'));
        expect(row('orders').find('.aaxis-flow-picker__badge').hasClass('aaxis-flow-picker__badge--on')).toBe(true);
        expect(row('orders').find('.aaxis-flow-picker__modified').text()).toBe(new Date('2026-11-10T10:00:00+00:00').toLocaleString());
        expect(row('orders').find('[data-role="flow-open"]').attr('title')).toBe(T('tab_open_row'));

        expect(q('picker-cancel').text()).toBe(T('dialog_cancel'));
        expect(q('picker-open').text()).toBe(T('tab_open_selected'));
        expect(q('picker-open').prop('disabled')).toBe(true);
    });

    it('combines the kinds, never turning off the last one, and counts what the filter matches', async () => {
        const {q} = await open();

        kind('subflow').trigger('click');
        expect(kind('flow').hasClass('is-active')).toBe(true);
        expect(kind('subflow').hasClass('is-active')).toBe(true);
        expect(rowNames()).toEqual(['Billing', 'customers', 'lookup', 'notify', 'orders']);

        kind('flow').trigger('click');
        expect(kind('flow').hasClass('is-active')).toBe(false);
        expect(rowNames()).toEqual(['lookup', 'notify']);

        // The only kind left on: no effect.
        kind('subflow').trigger('click');
        expect(kind('subflow').hasClass('is-active')).toBe(true);
        expect(rowNames()).toEqual(['lookup', 'notify']);

        q('picker-filter').val('  O ').trigger('input');
        expect(kindLabels()).toEqual([`${T('tab_open_flows')} (2)`, `${T('tab_open_subflows')} (2)`]);
        q('picker-filter').val('zzz').trigger('input');
        expect(kindLabels()).toEqual([`${T('tab_open_flows')} (0)`, `${T('tab_open_subflows')} (0)`]);
        expect($('.aaxis-flow-picker__list .aaxis-json-note').text()).toBe(T('tab_open_no_match'));
    });

    it('sorts from the column headers: another column starts at its first order, the active one reverses', async () => {
        await open();
        kind('subflow').trigger('click');

        header('modified').trigger('click');
        expect(rowNames()).toEqual(['Billing', 'lookup', 'orders', 'customers', 'notify']);
        expect(headState()).toEqual([['name', 'none', ''], ['status', 'none', ''], ['modified', 'descending', 'fa-caret-down']]);
        header('modified').trigger('click');
        expect(rowNames()).toEqual(['notify', 'customers', 'orders', 'lookup', 'Billing']);
        expect(headState()[2]).toEqual(['modified', 'ascending', 'fa-caret-up']);

        header('status').trigger('click');
        expect(rowNames()).toEqual(['customers', 'lookup', 'orders', 'Billing', 'notify']);
        expect(headState()[1]).toEqual(['status', 'descending', 'fa-caret-down']);
        header('status').trigger('click');
        expect(rowNames()).toEqual(['Billing', 'notify', 'customers', 'lookup', 'orders']);

        header('name').trigger('click');
        expect(rowNames()).toEqual(['Billing', 'customers', 'lookup', 'notify', 'orders']);
        header('name').trigger('click');
        expect(rowNames()).toEqual(['orders', 'notify', 'lookup', 'customers', 'Billing']);
        expect(headState()[0]).toEqual(['name', 'descending', 'fa-caret-down']);
    });

    it('marks rows on click (the checkbox too) and opens the marked flows in marking order', async () => {
        const {dialog, q} = await open();

        row('orders').find('.aaxis-flow-picker__name').trigger('click');
        expect(row('orders').hasClass('is-selected')).toBe(true);
        expect(row('orders').attr('aria-selected')).toBe('true');
        expect(row('orders').find('[data-role="flow-pick"]').prop('checked')).toBe(true);
        expect(q('picker-open').prop('disabled')).toBe(false);
        expect(q('picker-open').text()).toBe(T('tab_open_selected'));

        // The checkbox toggles once (its click does not reach the row).
        row('Billing').find('[data-role="flow-pick"]').trigger('click');
        expect(row('Billing').hasClass('is-selected')).toBe(true);
        expect(q('picker-open').text()).toBe(T('tab_open_selected_count').replace('{{ count }}', '2'));

        // Marks survive the filter, the sort and the kinds; a hidden mark still counts.
        q('picker-filter').val('bill').trigger('input');
        header('name').trigger('click');
        kind('subflow').trigger('click');
        expect(rowNames()).toEqual(['Billing']);
        expect(row('Billing').hasClass('is-selected')).toBe(true);
        q('picker-filter').val('').trigger('input');
        row('lookup').trigger('click');
        expect(row('orders').hasClass('is-selected')).toBe(true);
        expect(q('picker-open').text()).toBe(T('tab_open_selected_count').replace('{{ count }}', '3'));

        // Clicking a marked row unmarks it.
        row('Billing').trigger('click');
        expect(row('Billing').hasClass('is-selected')).toBe(false);
        expect(row('Billing').find('[data-role="flow-pick"]').prop('checked')).toBe(false);

        expect(onOpen).not.toHaveBeenCalled();
        q('picker-open').trigger('click');
        expect(dialog.close).toHaveBeenCalled();
        expect(onOpen).toHaveBeenCalledWith([FLOWS[0], FLOWS[3]]);
    });

    it('opens one flow at once from its own button, without marking it', async () => {
        const {dialog} = await open();
        row('orders').trigger('click');

        row('customers').find('[data-role="flow-open"]').trigger('click');

        expect(dialog.close).toHaveBeenCalled();
        expect(onOpen).toHaveBeenCalledWith([FLOWS[2]]);
    });

    it('closes on Cancel without opening anything, and Open selected does nothing with no marks', async () => {
        const {dialog, q} = await open();
        q('picker-open').trigger('click');
        expect(dialog.close).not.toHaveBeenCalled();
        row('orders').trigger('click');

        q('picker-cancel').trigger('click');

        expect(dialog.close).toHaveBeenCalled();
        expect(onOpen).not.toHaveBeenCalled();
    });

    it('says so when there is nothing to open, and reports a failed load', async () => {
        const empty = await open(() => Promise.resolve([flow(6, 'Native', {type: 'native'})]));
        expect(empty.dialog.$content.text()).toBe(T('tab_open_empty'));
        expect(empty.q('picker-open')).toHaveLength(0);

        const failed = await open(() => Promise.reject(new Error('down')));
        expect(failed.dialog.$content.text()).toBe('');
        expect(onLoadError).toHaveBeenCalledTimes(1);
    });
});

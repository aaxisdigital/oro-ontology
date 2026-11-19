import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import {formatDateTime} from '../components/component-support';

/** What the picker needs of a flow (the flow list endpoint's records carry more). */
export interface PickableFlow {
    id: number;
    name: string | null;
    type: string;
    enabled: boolean;
    lastModified?: string | null;
}

export type FlowKind = 'flow' | 'subflow';

/** The sort orders offered, in display order (labels: `aaxis.ontology.flow_editor.tab_open_sort.<key>`). */
export const PICKER_SORTS = ['name_asc', 'name_desc', 'modified_desc', 'modified_asc', 'status_enabled', 'status_disabled'] as const;
export type PickerSort = typeof PICKER_SORTS[number];

export interface FlowPickerOptions<T extends PickableFlow> {
    /** Every flow the editor knows; native ones are left out here. */
    load: () => Promise<T[]>;
    /** Opens the flows, in the order they were picked. The dialog has already closed. */
    onOpen: (flows: T[]) => void;
    /** The list could not be loaded (the dialog stays open, empty). */
    onLoadError: () => void;
}

const t = (key: string, params?: Record<string, unknown>): string => __('aaxis.ontology.flow_editor.' + key, params);

const KINDS: Array<[FlowKind, string]> = [['flow', 'tab_open_flows'], ['subflow', 'tab_open_subflows']];

function byName(a: PickableFlow, b: PickableFlow): number {
    return (a.name || '').localeCompare(b.name || '', undefined, {sensitivity: 'base', numeric: true}) || a.id - b.id;
}

/** Epoch millis of the last modification; flows without one count as the oldest. */
function modifiedAt(flow: PickableFlow): number {
    const time = flow.lastModified ? Date.parse(flow.lastModified) : Number.NaN;

    return Number.isNaN(time) ? 0 : time;
}

/** A sorted COPY of `flows`. Ties fall back to the name (A–Z), so the order is always stable. */
export function sortFlows<T extends PickableFlow>(flows: T[], sort: PickerSort): T[] {
    const compare: Record<PickerSort, (a: T, b: T) => number> = {
        name_asc: byName,
        name_desc: (a, b) => byName(b, a),
        modified_desc: (a, b) => modifiedAt(b) - modifiedAt(a) || byName(a, b),
        modified_asc: (a, b) => modifiedAt(a) - modifiedAt(b) || byName(a, b),
        status_enabled: (a, b) => Number(b.enabled) - Number(a.enabled) || byName(a, b),
        status_disabled: (a, b) => Number(a.enabled) - Number(b.enabled) || byName(a, b)
    };

    return [...flows].sort(compare[sort]);
}

/**
 * The flow editor's "Open flows" popup: pick several existing (non-native) flows and open each
 * in a tab.
 *
 * - The top bar holds the name filter (it takes the remaining width), the sort order and the
 *   Flows / Subflows toggles. The toggles COMBINE: either or both kinds can be listed, and
 *   clicking the only one still on does nothing, so the list never empties by that route.
 *   Their counts follow the filter.
 * - Clicking a row only MARKS it. The marks survive filtering, sorting and the toggles, so a
 *   hidden marked flow still counts. Each row also has its own Open button, which opens that one
 *   flow right away.
 * - Open selected opens the marked flows in the order they were marked. It is disabled while
 *   nothing is marked and names the count from two flows on ("Open selected (3)").
 */
export default class FlowPickerDialog<T extends PickableFlow> {
    private readonly opts: FlowPickerOptions<T>;
    private dialog: Dialog | null = null;
    private $picker: any = null;
    private flows: T[] = [];
    private readonly kinds = new Set<FlowKind>(['flow']);
    private sort: PickerSort = 'name_asc';
    /** Marked flow ids, in marking order. */
    private picked: number[] = [];

    constructor(options: FlowPickerOptions<T>) {
        this.opts = options;
    }

    open(): void {
        this.dialog = new Dialog({title: t('tab_open'), width: '760px'});
        const $content = this.dialog.open();
        this.$picker = $('<div/>', {'class': 'aaxis-flow-picker'});
        this.$picker.append($('<p/>', {'class': 'aaxis-json-note', text: t('loading')}));
        $content.append(this.$picker);

        this.opts.load()
            .then(flows => {
                this.$picker.empty();
                this.flows = flows.filter(f => f.type !== 'native');
                if (!this.flows.length) {
                    this.$picker.append($('<p/>', {'class': 'aaxis-json-note', text: t('tab_open_empty')}));
                    return;
                }
                this.build();
            })
            .catch(() => {
                this.$picker.empty();
                this.opts.onLoadError();
            });
    }

    private build(): void {
        const $filter = $('<input/>', {
            type: 'text', 'class': 'form-control aaxis-flow-picker__filter', 'data-role': 'picker-filter',
            placeholder: t('tab_open_filter_placeholder'), autocomplete: 'off', spellcheck: false
        });
        const $sort = $('<select/>', {
            'class': 'form-control no-uniform aaxis-flow-picker__sort', 'data-role': 'picker-sort',
            title: t('tab_open_sort_title'), 'aria-label': t('tab_open_sort_title')
        });
        PICKER_SORTS.forEach(sort => $sort.append($('<option/>', {value: sort, text: t('tab_open_sort.' + sort)})));
        $sort.val(this.sort);
        const $kinds = $('<div/>', {'class': 'aaxis-flow-picker__kinds', 'data-role': 'picker-kinds'});
        const $bar = $('<div/>', {'class': 'aaxis-flow-picker__bar'}).append($filter, $sort, $kinds);

        const $head = $('<div/>', {'class': 'aaxis-flow-picker__head', 'aria-hidden': 'true'}).append(
            $('<span/>'),
            $('<span/>', {text: t('tab_open_col_name')}),
            $('<span/>', {text: t('tab_open_col_status')}),
            $('<span/>', {text: t('tab_open_col_modified')}),
            $('<span/>')
        );
        const $list = $('<div/>', {'class': 'aaxis-flow-picker__list', 'data-role': 'picker-list'});

        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', 'data-role': 'picker-cancel', text: t('dialog_cancel')});
        const $open = $('<button/>', {type: 'button', 'class': 'btn btn-primary', 'data-role': 'picker-open'});
        const $actions = $('<div/>', {'class': 'aaxis-flow-picker__actions'}).append($cancel, $open);

        this.$picker.append($bar, $head, $list, $actions);

        $filter.on('input', () => this.render());
        $sort.on('change', () => {
            this.sort = String($sort.val()) as PickerSort;
            this.render();
        });
        $cancel.on('click', () => this.dialog?.close());
        $open.on('click', () => this.openFlows(this.picked));

        this.render();
        this.syncActions();
        $filter.trigger('focus');
    }

    private part(role: string): any {
        return this.$picker.find(`[data-role="${role}"]`);
    }

    /** The flows of `kind` whose name contains the filter text (any case). */
    private matching(kind: FlowKind): T[] {
        const query = String(this.part('picker-filter').val() || '').trim().toLowerCase();

        return this.flows.filter(f => f.type === kind && (query === '' || (f.name || '').toLowerCase().includes(query)));
    }

    private render(): void {
        const $kinds = this.part('picker-kinds').empty();
        KINDS.forEach(([kind, label]) => {
            const active = this.kinds.has(kind);
            const $kind = $('<button/>', {
                type: 'button', 'class': 'aaxis-flow-picker__kind' + (active ? ' is-active' : ''),
                'data-kind': kind, 'aria-pressed': String(active),
                text: `${t(label)} (${this.matching(kind).length})`
            });
            $kind.on('click', () => this.toggleKind(kind));
            $kinds.append($kind);
        });

        const $list = this.part('picker-list').empty();
        const visible = sortFlows(KINDS.filter(([kind]) => this.kinds.has(kind)).flatMap(([kind]) => this.matching(kind)), this.sort);
        if (!visible.length) {
            $list.append($('<p/>', {'class': 'aaxis-json-note', text: t('tab_open_no_match')}));
            return;
        }
        visible.forEach(flow => $list.append(this.row(flow)));
    }

    private row(flow: T): any {
        const picked = this.picked.includes(flow.id);
        const $row = $('<div/>', {
            'class': 'aaxis-flow-picker__row' + (picked ? ' is-selected' : ''),
            'data-flow-id': flow.id, 'aria-selected': String(picked)
        });
        const $check = $('<input/>', {
            type: 'checkbox', 'class': 'aaxis-flow-picker__check', 'data-role': 'flow-pick',
            'aria-label': t('tab_open_pick', {name: flow.name || ''})
        }).prop('checked', picked);
        const $open = $('<button/>', {
            type: 'button', 'class': 'aaxis-flow-picker__open', 'data-role': 'flow-open',
            title: t('tab_open_row'), 'aria-label': t('tab_open_row')
        }).append($('<span/>', {'class': 'fa fa-folder-open-o', 'aria-hidden': 'true'}));
        $row.append(
            $check,
            $('<span/>', {'class': 'aaxis-flow-picker__name', text: flow.name || '', title: flow.name || ''}),
            $('<span/>', {
                'class': 'aaxis-flow-picker__badge aaxis-flow-picker__badge--' + (flow.enabled ? 'on' : 'off'),
                text: t(flow.enabled ? 'tab_open_enabled' : 'tab_open_disabled')
            }),
            $('<span/>', {'class': 'aaxis-flow-picker__modified', text: formatDateTime(flow.lastModified ?? null)}),
            $open
        );

        // The checkbox toggles itself; anywhere else on the row (but the Open button) toggles it too.
        $check.on('click', (e: any) => e.stopPropagation());
        $check.on('change', () => this.setPicked(flow.id, $check.prop('checked'), $row));
        $row.on('click', () => this.setPicked(flow.id, !this.picked.includes(flow.id), $row));
        $open.on('click', (e: any) => {
            e.stopPropagation();
            this.openFlows([flow.id]);
        });

        return $row;
    }

    private toggleKind(kind: FlowKind): void {
        if (this.kinds.has(kind)) {
            if (this.kinds.size === 1) {
                return;
            }
            this.kinds.delete(kind);
        } else {
            this.kinds.add(kind);
        }
        this.render();
    }

    private setPicked(id: number, picked: boolean, $row: any): void {
        this.picked = this.picked.filter(p => p !== id);
        if (picked) {
            this.picked.push(id);
        }
        $row.toggleClass('is-selected', picked).attr('aria-selected', String(picked));
        $row.find('[data-role="flow-pick"]').prop('checked', picked);
        this.syncActions();
    }

    private syncActions(): void {
        const count = this.picked.length;
        this.part('picker-open')
            .prop('disabled', count === 0)
            .text(count > 1 ? t('tab_open_selected_count', {count}) : t('tab_open_selected'));
    }

    private openFlows(ids: number[]): void {
        const flows = ids.map(id => this.flows.find(f => f.id === id)).filter((f): f is T => f !== undefined);
        if (!flows.length) {
            return;
        }
        this.dialog?.close();
        this.opts.onOpen(flows);
    }
}

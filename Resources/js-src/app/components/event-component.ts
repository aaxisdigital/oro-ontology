import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid, {GridAction, navigateTo} from 'aaxiscommon/js/app/widgets/data-grid';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import {bindGridToolbar, formatDateTime, setRefreshBusy, takeQueryParam} from './component-support';
import {writeClipboard} from '../widgets/clipboard';

interface OntologyEventOptions {
    _sourceElement: any;
}

/** One ROW PER RUN (flow uuid) — aggregated server-side from aaxis_ontology_flow_events. */
interface FlowRunRecord {
    flowUuid: string | null;
    flowId: number | null;
    flowName: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    elapsedMs: number | null;
    status: 'success' | 'exception' | 'running';
    events: number;
    /** Bucket-backed run (Use Bucket for Flow Events): the popup addresses it by these. */
    bucket?: boolean;
    startedAtRaw?: string | null;
    finishedAtRaw?: string | null;
}

interface RunEventRecord {
    id: number;
    flowName: string | null;
    event: string;
    datetime: string | null;
    ms: number | null;
    payload: Record<string, any> | null;
}

/** Boundary events carry the FULL timestamp; every other row shows how long after the previous event it happened. */
const BOUNDARY_EVENTS = new Set(['flow-start', 'flow-finish', 'flow-exception']);

/**
 * Read-only Events page built on the reusable DataGrid widget: one row per flow RUN (uuid) —
 * name, uuid, started/finished (the flow-start and flow-finish/flow-exception event times),
 * elapsed, and the run's event count. Row actions: "view events" (a popup listing the run's
 * events datetime ASC with their payloads) and "view flow" (opens the flow editor).
 */
class OntologyEventComponent extends BaseComponent {
    private $el!: any;
    private grid!: DataGrid;
    private uuidFilterApplied!: boolean;
    /** Every run shown, by uuid — Refresh merges the changed ones in. */
    private runs!: Map<string, FlowRunRecord>;
    /** The server's cursor from the last answer: Refresh asks only for runs changed after it. */
    private cursor!: string | null;

    initialize(options: OntologyEventOptions): void {
        this.$el = options._sourceElement;
        this.uuidFilterApplied = false;
        this.runs = new Map();
        this.cursor = null;

        const actions: GridAction[] = [
            {
                key: 'events',
                label: __('aaxis.ontology.event_view.view_events'),
                icon: 'fa-list-ul'
            },
            {
                key: 'flow',
                label: __('aaxis.ontology.event_view.view_flow'),
                icon: 'fa-external-link',
                disabled: (row: FlowRunRecord) => row.flowId === null,
                disabledTitle: __('aaxis.ontology.event_view.view_flow_gone')
            }
        ];

        this.grid = new DataGrid({
            columns: [
                {key: 'flowName', label: __('aaxis.ontology.event_view.flow'), type: 'text'},
                {
                    key: 'flowUuid', label: __('aaxis.ontology.event_view.uuid'), type: 'text', width: '300px',
                    render: (row: FlowRunRecord) => $('<span/>', {
                        'class': 'aaxis-event-uuid__text',
                        text: row.flowUuid || '',
                        title: row.flowUuid || ''
                    }),
                    copyValue: (row: FlowRunRecord) => row.flowUuid || ''
                },
                {
                    key: 'startedAt', label: __('aaxis.ontology.event_view.started_at'), type: 'datetime',
                    width: '185px', render: (row: FlowRunRecord) => formatDateTime(row.startedAt)
                },
                {
                    key: 'finishedAt', label: __('aaxis.ontology.event_view.finished_at'), type: 'datetime',
                    width: '185px', render: (row: FlowRunRecord) => formatDateTime(row.finishedAt)
                },
                {
                    key: 'elapsedMs', label: __('aaxis.ontology.event_view.elapsed'), type: 'number',
                    width: '110px', render: (row: FlowRunRecord) => this.renderElapsed(row),
                    copyValue: (row: FlowRunRecord) => row.elapsedMs === null ? '' : String(row.elapsedMs)
                },
                {
                    key: 'status', label: __('aaxis.ontology.event_view.status'), type: 'text', width: '110px',
                    render: (row: FlowRunRecord) => $('<span/>', {
                        'class': 'aaxis-event-status aaxis-event-status--' + row.status,
                        text: __('aaxis.ontology.event_view.status_' + row.status)
                    }),
                    copyValue: (row: FlowRunRecord) => row.status
                },
                {
                    key: 'events', label: __('aaxis.ontology.event_view.events'), type: 'number', width: '90px'
                }
            ],
            actions,
            // Run records have no `id`: the uuid IS the row identity (actions resolve rows by it).
            idKey: 'flowUuid',
            // A FRESH grid key: the per-user column preferences saved for the previous events
            // grids (other column sets entirely) must not reorder/hide the run columns.
            gridKey: 'ontology-event-runs',
            urlState: true,
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', {gridKey: 'ontology-event-runs'}),
            emptyText: __('aaxis.ontology.event_view.empty'),
            onAction: (action, row, event) => this.onAction(action, row as FlowRunRecord, event)
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));

        bindGridToolbar(this.$el, 'aaxisOntologyEvent', () => this.load(), () => this.grid);

        this.load();
    }

    private onAction(action: string, row: FlowRunRecord, event?: MouseEvent): void {
        if (action === 'events' && row.flowUuid) {
            this.openRunEvents(row);
        } else if (action === 'flow' && row.flowId !== null) {
            navigateTo(routing.generate('aaxis_ontology_flow_editor', {id: row.flowId}), event);
        }
    }

    /**
     * The first load fetches every run of the history window; later ones (Refresh) send the
     * previous answer's cursor and receive only the runs created or changed since, merged into
     * the rows already shown — new runs appear, running ones update, nothing is re-sent.
     */
    private load(): void {
        this.setBusy(true);
        const params = this.cursor !== null ? {since: this.cursor} : {};
        fetch(routing.generate('aaxis_ontology_event_list', params), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {records?: FlowRunRecord[]; cursor?: string; incremental?: boolean}) => {
                if (!data.incremental) {
                    this.runs.clear();
                }
                (data.records || []).forEach(run => {
                    if (run.flowUuid) {
                        this.runs.set(run.flowUuid, run);
                    }
                });
                this.cursor = data.cursor ?? null;
                this.grid.setRows(Array.from(this.runs.values())
                    .sort((a, b) => String(b.startedAt || '').localeCompare(String(a.startedAt || ''))));
                this.applyUuidFromUrl();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.event_view.load_error')))
            .finally(() => this.setBusy(false));
    }

    /**
     * Deep links, first load only: `?uuid=` pre-filters the grid to that run, `?flow=` to the
     * runs of that flow (the Flows list's "View events" — flow names are unique). Both are
     * one-shot: dropped from the URL, so the grid's own URL state takes over.
     */
    private applyUuidFromUrl(): void {
        if (this.uuidFilterApplied) {
            return;
        }
        this.uuidFilterApplied = true;
        const uuid = takeQueryParam('uuid');
        if (uuid !== null) {
            this.grid.setFilter('flowUuid', {operator: 'equals', value: uuid});
        }
        const flow = takeQueryParam('flow');
        if (flow !== null) {
            this.grid.setFilter('flowName', {operator: 'equals', value: flow});
        }
    }

    /** start→finish difference, humanized; a run with no finish reads Running. */
    private renderElapsed(row: FlowRunRecord): any {
        if (row.elapsedMs === null) {
            return $('<span/>', {'class': 'aaxis-event-running', text: __('aaxis.ontology.event_view.running')});
        }
        return $('<span/>', {text: this.humanizeMs(row.elapsedMs)});
    }

    private humanizeMs(ms: number): string {
        if (ms < 1000) {
            return `${ms}ms`;
        }
        if (ms < 60_000) {
            return `${(ms / 1000).toFixed(1)}s`;
        }
        if (ms < 3_600_000) {
            const minutes = Math.floor(ms / 60_000);
            const seconds = Math.round((ms % 60_000) / 1000);
            return `${minutes}m ${String(seconds).padStart(2, '0')}s`;
        }
        const hours = Math.floor(ms / 3_600_000);
        const minutes = Math.round((ms % 3_600_000) / 60_000);
        return `${hours}h ${String(minutes).padStart(2, '0')}m`;
    }

    /** The "view events" popup: the run's events in execution order, each with its payload. */
    private openRunEvents(row: FlowRunRecord): void {
        // Which rows overflow depends on the dialog's width: re-checked whenever the list resizes
        // (the dialog's resize handle, the window), until the dialog closes.
        let observer: ResizeObserver | null = null;
        const dialog = new Dialog({
            title: `${__('aaxis.ontology.event_view.run_events_title')} — ${row.flowName || row.flowUuid}`,
            width: '760px',
            // Flex-column body (like the DWL playground's): the dialog's resize handle then
            // grows the event list instead of leaving dead space under it.
            bodyClass: 'aaxis-event-run-host',
            onClose: () => observer?.disconnect()
        });
        const $content = dialog.open();
        const $list = $('<div/>', {'class': 'aaxis-event-run'});
        $list.on('click', '[data-role="summary-expand"]', (e: any) => this.toggleSummary($(e.currentTarget).closest('.aaxis-event-run__row')));
        $list.on('click', '[data-role="summary-copy"]', (e: any) => this.copySummary($(e.currentTarget)));
        $content.append($list);
        $list.append($('<p/>', {'class': 'aaxis-json-note', text: __('aaxis.ontology.event_view.loading')}));

        let runUrl = routing.generate('aaxis_ontology_event_run') + '?uuid=' + encodeURIComponent(row.flowUuid || '');
        if (row.bucket) {
            runUrl += '&bucket=1&flowId=' + encodeURIComponent(row.flowId === null ? '' : String(row.flowId))
                + '&startedAt=' + encodeURIComponent(row.startedAtRaw || '')
                + '&finishedAt=' + encodeURIComponent(row.finishedAtRaw || '');
        }
        fetch(runUrl, {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {records?: RunEventRecord[]}) => {
                $list.empty();
                // Non-boundary rows show the micro-precision delta from the PREVIOUS event's `ms`.
                let previousMs: number | null = null;
                (data.records || []).forEach(event => {
                    const $row = $('<div/>', {'class': 'aaxis-event-run__row'});
                    const timeText = this.eventTimeText(event, previousMs);
                    if (event.ms !== null) {
                        previousMs = event.ms;
                    }
                    $row.append($('<span/>', {'class': 'aaxis-event-run__time', text: timeText}));
                    $row.append($('<span/>', {'class': 'aaxis-event-kind aaxis-event-kind--' + event.event, text: event.event}));
                    const summary = this.summarizeEvent(event);
                    if (summary !== '') {
                        $row.append($('<span/>', {
                            'class': 'aaxis-event-run__summary',
                            text: summary,
                            title: summary.length > 2000 ? `${summary.slice(0, 2000)}…` : summary
                        }), this.summaryTools());
                    }
                    $list.append($row);
                });
                this.syncSummaryOverflow($list);
                if (typeof ResizeObserver !== 'undefined') {
                    observer = new ResizeObserver(() => this.syncSummaryOverflow($list));
                    observer.observe($list[0]);
                }
                if (!(data.records || []).length) {
                    $list.append($('<p/>', {'class': 'aaxis-json-note', text: __('aaxis.ontology.event_view.empty')}));
                }
            })
            .catch(() => {
                $list.empty();
                messenger.notificationFlashMessage('error', __('aaxis.ontology.event_view.load_error'));
            });
    }

    /**
     * The glyph-only buttons of a summary that does not fit its line (mostly long error messages):
     * expand it to the whole text over several lines, and copy it to the clipboard. Hidden until
     * {@see syncSummaryOverflow} finds the text cut.
     */
    private summaryTools(): any {
        const tool = (role: string, icon: string, titleKey: string): any => $('<button/>', {
            type: 'button',
            'class': 'aaxis-event-run__tool',
            'data-role': role,
            title: __(`aaxis.ontology.event_view.${titleKey}`),
            'aria-label': __(`aaxis.ontology.event_view.${titleKey}`)
        }).append($('<span/>', {'class': `fa ${icon}`, 'aria-hidden': 'true'}));

        return $('<span/>', {'class': 'aaxis-event-run__tools'}).append(
            tool('summary-expand', 'fa-expand', 'summary_expand'),
            tool('summary-copy', 'fa-files-o', 'summary_copy')
        );
    }

    /**
     * Flags the rows whose summary is cut (ellipsis) or holds line breaks the single line hides —
     * those show the expand/copy buttons. An expanded row keeps them, to collapse it again.
     */
    private syncSummaryOverflow($list: any): void {
        $list.find('.aaxis-event-run__summary').each((_i: number, el: HTMLElement) => {
            const $row = $(el).closest('.aaxis-event-run__row');
            if ($row.hasClass('is-expanded')) {
                return;
            }
            $row.toggleClass('has-overflow', el.scrollWidth > el.clientWidth || (el.textContent || '').includes('\n'));
        });
    }

    /** Expand: the whole summary over several lines (line breaks kept); again = one line. */
    private toggleSummary($row: any): void {
        const expanded = !$row.hasClass('is-expanded');
        $row.toggleClass('is-expanded', expanded);
        const key = expanded ? 'summary_collapse' : 'summary_expand';
        $row.find('[data-role="summary-expand"]')
            .attr({title: __(`aaxis.ontology.event_view.${key}`), 'aria-label': __(`aaxis.ontology.event_view.${key}`)})
            .find('.fa').toggleClass('fa-expand', !expanded).toggleClass('fa-compress', expanded);
    }

    /** Copy: the row's FULL summary text (e.g. the whole error message); a check mark confirms. */
    private copySummary($button: any): void {
        const text = $button.closest('.aaxis-event-run__row').find('.aaxis-event-run__summary').text();
        void writeClipboard(text).then(copied => {
            if (!copied) {
                messenger.notificationFlashMessage('error', __('aaxis.ontology.event_view.summary_copy_failed'));
                return;
            }
            const $icon = $button.find('.fa');
            $icon.removeClass('fa-files-o').addClass('fa-check');
            window.setTimeout(() => $icon.removeClass('fa-check').addClass('fa-files-o'), 1200);
        });
    }

    /** Full time on a boundary event, else "+delta" since the previous event ('' when it cannot be told). */
    private eventTimeText(event: RunEventRecord, previousMs: number | null): string {
        if (BOUNDARY_EVENTS.has(event.event)) {
            return formatDateTime(event.datetime);
        }
        if (previousMs === null || event.ms === null) {
            return '';
        }

        return `+${this.humanizeMs(Math.max(0, Math.round(event.ms - previousMs)))}`;
    }

    /** The one line that matters per event kind — no raw JSON in the popup. */
    private summarizeEvent(event: RunEventRecord): string {
        const payload = event.payload || {};
        switch (event.event) {
            case 'flow-start':
            case 'subflow-start': {
                const user = payload.user?.name ? ` — ${payload.user.name}` : '';
                // Runs logged before inner flows had their own kinds: a subflow-start naming one.
                const inner = payload.inner ? ` — ${payload.inner}` : '';
                return `${payload.trigger || ''}${inner}${user}`;
            }
            case 'inner-flow-start':
                // The inner flow (drawn inside its flow) the run executes.
                return String(payload.inner || '');
            case 'data-upsert': {
                const uniqueIds = Array.isArray(payload.uniqueIds) ? payload.uniqueIds.length : 0;
                const changedIds = Array.isArray(payload.changedIds) ? payload.changedIds.length : 0;
                return `${payload.entity || ''} — ${uniqueIds} ids, ${changedIds} changed`;
            }
            case 'step':
                return `${payload.name || ''} (${payload.type || ''})`;
            case 'flow-exception':
            case 'log-message':
                return String(payload.message || '');
            default:
                // flow-finish / subflow-finish / inner-flow-finish say everything with their badge alone.
                return '';
        }
    }

    private setBusy(busy: boolean): void {
        setRefreshBusy(this.$el, busy);
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisOntologyEvent');
        if (this.grid) {
            this.grid.dispose();
        }
        super.dispose();
    }
}

export default OntologyEventComponent;

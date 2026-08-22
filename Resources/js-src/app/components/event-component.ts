import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid, {GridAction, navigateTo} from 'aaxiscommon/js/app/widgets/data-grid';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import {bindGridToolbar, formatDateTime, setRefreshBusy} from './component-support';

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

    initialize(options: OntologyEventOptions): void {
        this.$el = options._sourceElement;
        this.uuidFilterApplied = false;

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

    private load(): void {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_event_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {records: FlowRunRecord[]}) => {
                this.grid.setRows(data.records || []);
                this.applyUuidFromUrl();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.event_view.load_error')))
            .finally(() => this.setBusy(false));
    }

    /** A `?uuid=` in the URL pre-filters the grid to that run. First load only. */
    private applyUuidFromUrl(): void {
        if (this.uuidFilterApplied) {
            return;
        }
        this.uuidFilterApplied = true;
        const uuid = new URLSearchParams(window.location.search).get('uuid');
        if (uuid !== null && uuid.trim() !== '') {
            this.grid.setFilter('flowUuid', {operator: 'equals', value: uuid.trim()});
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
        const dialog = new Dialog({
            title: `${__('aaxis.ontology.event_view.run_events_title')} — ${row.flowName || row.flowUuid}`,
            width: '760px',
            // Flex-column body (like the DWL playground's): the dialog's resize handle then
            // grows the event list instead of leaving dead space under it.
            bodyClass: 'aaxis-event-run-host'
        });
        const $content = dialog.open();
        const $list = $('<div/>', {'class': 'aaxis-event-run'});
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
                // Boundary events carry the FULL timestamp; every other row shows how long after
                // the PREVIOUS event it happened (micro-precision deltas from `ms`).
                const boundaries = ['flow-start', 'flow-finish', 'flow-exception'];
                let previousMs: number | null = null;
                (data.records || []).forEach(event => {
                    const $row = $('<div/>', {'class': 'aaxis-event-run__row'});
                    const timeText = boundaries.includes(event.event)
                        ? formatDateTime(event.datetime)
                        : (previousMs !== null && event.ms !== null
                            ? `+${this.humanizeMs(Math.max(0, Math.round(event.ms - previousMs)))}`
                            : '');
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
                        }));
                    }
                    $list.append($row);
                });
                if (!(data.records || []).length) {
                    $list.append($('<p/>', {'class': 'aaxis-json-note', text: __('aaxis.ontology.event_view.empty')}));
                }
            })
            .catch(() => {
                $list.empty();
                messenger.notificationFlashMessage('error', __('aaxis.ontology.event_view.load_error'));
            });
    }

    /** The one line that matters per event kind — no raw JSON in the popup. */
    private summarizeEvent(event: RunEventRecord): string {
        const payload = event.payload || {};
        switch (event.event) {
            case 'flow-start':
            case 'subflow-start': {
                const user = payload.user && payload.user.name ? ` — ${payload.user.name}` : '';
                return `${payload.trigger || ''}${user}`;
            }
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
                // flow-finish / subflow-finish say everything with their badge alone.
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

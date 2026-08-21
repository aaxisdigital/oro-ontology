import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid from 'aaxiscommon/js/app/widgets/data-grid';
import {bindGridToolbar, formatDateTime, setRefreshBusy} from './component-support';

interface OntologyEventOptions {
    _sourceElement: any;
}

interface FlowEventRecord {
    id: number;
    flowId: number | null;
    flowName: string | null;
    flowUuid: string | null;
    event: string;
    datetime: string | null;
    payload: Record<string, any> | null;
}

/**
 * Read-only Events page built on the reusable DataGrid widget. Shows the FLOW-EXECUTION events
 * (aaxis_ontology_flow_events — one row per flow-start/flow-finish/flow-exception/data-upsert/
 * log-message/step event, written asynchronously by the flow-event queue processor); there is
 * intentionally no "add" action.
 */
class OntologyEventComponent extends BaseComponent {
    private $el!: any;
    private grid!: DataGrid;
    private uuidFilterApplied!: boolean;

    initialize(options: OntologyEventOptions): void {
        this.$el = options._sourceElement;
        this.uuidFilterApplied = false;

        this.grid = new DataGrid({
            columns: [
                {key: 'flowName', label: __('aaxis.ontology.event_view.flow'), type: 'text', width: '180px'},
                {
                    key: 'event', label: __('aaxis.ontology.event_view.event'), type: 'text', width: '130px',
                    render: (row: FlowEventRecord) => $('<span/>', {
                        'class': 'aaxis-event-kind aaxis-event-kind--' + row.event,
                        text: row.event
                    }),
                    copyValue: (row: FlowEventRecord) => row.event
                },
                {
                    key: 'datetime', label: __('aaxis.ontology.event_view.datetime'), type: 'datetime',
                    width: '190px', render: (row: FlowEventRecord) => formatDateTime(row.datetime)
                },
                {
                    key: 'flowUuid', label: __('aaxis.ontology.event_view.uuid'), type: 'text', width: '300px',
                    render: (row: FlowEventRecord) => this.renderUuid(row),
                    copyValue: (row: FlowEventRecord) => row.flowUuid || ''
                },
                {
                    key: 'payload', label: __('aaxis.ontology.event_view.payload'), type: 'text',
                    sortable: false,
                    render: (row: FlowEventRecord) => this.renderPayload(row),
                    copyValue: (row: FlowEventRecord) => this.payloadText(row)
                }
            ],
            gridKey: 'ontology-event-view',
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', {gridKey: 'ontology-event-view'}),
            emptyText: __('aaxis.ontology.event_view.empty')
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));

        bindGridToolbar(this.$el, 'aaxisOntologyEvent', () => this.load(), () => this.grid);

        this.load();
    }

    private load(): void {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_event_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {records: FlowEventRecord[]}) => {
                this.grid.setRows(data.records || []);
                this.applyUuidFromUrl();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.event_view.load_error')))
            .finally(() => this.setBusy(false));
    }

    /**
     * A `?uuid=` in the URL pre-filters the grid — the address a cmd/ctrl+clicked filter icon
     * opens in a new tab ({@see renderUuid}). First load only.
     */
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

    private payloadText(row: FlowEventRecord): string {
        if (row.payload === null || Object.keys(row.payload).length === 0) {
            return '';
        }
        return JSON.stringify(row.payload);
    }

    /** One-line JSON preview; the (capped) full text rides as the tooltip and the copied value. */
    private renderPayload(row: FlowEventRecord): any {
        const text = this.payloadText(row);
        if (text === '') {
            return $('<span/>');
        }
        return $('<span/>', {
            'class': 'aaxis-event-payload',
            text,
            title: text.length > 2000 ? `${text.slice(0, 2000)}…` : text
        });
    }

    /**
     * The run uuid plus a button that filters the grid down to the rows sharing it — one flow run
     * writes several events under one uuid, and this is how they are read together. `data-role`
     * (never `data-action`, which the grid delegates globally to its row actions) and
     * stopPropagation so the click filters instead of copying the cell.
     */
    private renderUuid(row: FlowEventRecord): any {
        const uuid = row.flowUuid || '';
        const $wrap = $('<span/>', {'class': 'aaxis-event-uuid'});
        $wrap.append($('<span/>', {'class': 'aaxis-event-uuid__text', text: uuid, title: uuid}));
        if (uuid === '') {
            return $wrap;
        }
        const $filter = $('<button/>', {
            type: 'button', 'class': 'aaxis-event-uuid__filter', 'data-role': 'filter-uuid',
            title: __('aaxis.ontology.event_view.filter_by_uuid'),
            'aria-label': __('aaxis.ontology.event_view.filter_by_uuid')
        }).append($('<span/>', {'class': 'fa fa-filter', 'aria-hidden': 'true'}));
        $filter.on('click', (e: any) => {
            e.preventDefault();
            e.stopPropagation();
            // cmd/ctrl+click opens the SAME filtered view in a new tab (via `?uuid=`),
            // a plain click filters this grid in place.
            const raw = (e.originalEvent || e) as MouseEvent;
            if (raw.metaKey || raw.ctrlKey) {
                window.open(
                    routing.generate('aaxis_ontology_events') + '?uuid=' + encodeURIComponent(uuid),
                    '_blank',
                    'noopener'
                );
                return;
            }
            this.grid.setFilter('flowUuid', {operator: 'equals', value: uuid});
        });
        $wrap.append($filter);
        return $wrap;
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

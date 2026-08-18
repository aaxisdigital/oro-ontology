import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid from 'aaxiscommon/js/app/widgets/data-grid';

interface OntologyEventOptions {
    _sourceElement: any;
}

interface EventRecord {
    id: number;
    flowId: number | null;
    flow: string | null;
    uuid: string | null;
    entityId: number | null;
    entity: string | null;
    uniqueIds: string;
    uniqueIdsCount: number;
    changedIds: string;
    changedIdsCount: number;
    startedAt: string | null;
    finishedAt: string | null;
}

/**
 * Read-only Events page built on the reusable DataGrid widget. Shows data from
 * aaxis_ontology_data_events; there is intentionally no "add" action.
 */
class OntologyEventComponent extends BaseComponent {
    private $el!: any;
    private grid!: DataGrid;

    initialize(options: OntologyEventOptions): void {
        this.$el = options._sourceElement;

        this.grid = new DataGrid({
            columns: [
                {key: 'flow', label: __('aaxis.ontology.event_view.flow'), type: 'text'},
                {key: 'entity', label: __('aaxis.ontology.event_view.entity'), type: 'text'},
                {
                    key: 'uuid', label: __('aaxis.ontology.event_view.uuid'), type: 'text',
                    render: (row: EventRecord) => this.renderUuid(row),
                    copyValue: (row: EventRecord) => row.uuid || ''
                },
                {
                    key: 'uniqueIds', label: __('aaxis.ontology.event_view.unique_ids'), type: 'text',
                    sortable: false,
                    render: (row: EventRecord) => this.renderIds(row.uniqueIds, row.uniqueIdsCount),
                    copyValue: (row: EventRecord) => row.uniqueIds || ''
                },
                {
                    key: 'changedIds', label: __('aaxis.ontology.event_view.changed_ids'), type: 'text',
                    sortable: false,
                    render: (row: EventRecord) => this.renderIds(row.changedIds, row.changedIdsCount),
                    copyValue: (row: EventRecord) => row.changedIds || ''
                },
                {
                    key: 'startedAt', label: __('aaxis.ontology.event_view.started_at'), type: 'datetime',
                    width: '190px', render: (row: EventRecord) => this.renderDate(row.startedAt)
                },
                {
                    key: 'finishedAt', label: __('aaxis.ontology.event_view.finished_at'), type: 'datetime',
                    width: '190px', render: (row: EventRecord) => this.renderDate(row.finishedAt)
                }
            ],
            gridKey: 'ontology-event-view',
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', {gridKey: 'ontology-event-view'}),
            emptyText: __('aaxis.ontology.event_view.empty')
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));

        this.$el.on('click.aaxisOntologyEvent', '[data-role="refresh"]', (e: any) => {
            e.preventDefault();
            this.load();
        });
        this.$el.on('click.aaxisOntologyEvent', '[data-role="columns-settings"]', (e: any) => {
            e.preventDefault();
            this.grid.toggleColumnSettings(e.currentTarget);
        });

        this.load();
    }

    private load(): void {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_event_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {records: EventRecord[]}) => {
                this.grid.setRows(data.records || []);
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.event_view.load_error')))
            .finally(() => this.setBusy(false));
    }

    /**
     * Id list prefixed with how many there are — "(3) - a, b, c" — since a single event can carry
     * thousands and the cell only ever shows its first line. The raw list stays the tooltip and
     * the copied value (the grid takes those from `copyValue`, not from this node).
     */
    private renderIds(ids: string, count: number): any {
        if (!count) {
            return $('<span/>');
        }
        // A native tooltip holding thousands of ids is unusable — cap it; the cell still carries
        // the whole list as text and copying yields it in full.
        const title = ids.length > 2000 ? `${ids.slice(0, 2000)}…` : ids;
        return $('<span/>', {'class': 'aaxis-event-ids', title}).append(
            $('<span/>', {'class': 'aaxis-event-ids__count', text: `(${count})`}),
            document.createTextNode(` - ${ids}`)
        );
    }

    /**
     * The uuid plus a button that filters the grid down to the rows sharing it — one flow run
     * writes several events under one uuid, and this is how they are read together. `data-role`
     * (never `data-action`, which the grid delegates globally to its row actions) and
     * stopPropagation so the click filters instead of copying the cell.
     */
    private renderUuid(row: EventRecord): any {
        const uuid = row.uuid || '';
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
            this.grid.setFilter('uuid', {operator: 'equals', value: uuid});
        });
        $wrap.append($filter);
        return $wrap;
    }

    private renderDate(value: string | null): string {
        if (!value) {
            return '';
        }
        const d = new Date(value);
        return isNaN(d.getTime()) ? String(value) : d.toLocaleString();
    }

    private setBusy(busy: boolean): void {
        this.$el.find('[data-role="refresh"]').prop('disabled', busy)
            .find('.fa').toggleClass('fa-spin', busy);
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

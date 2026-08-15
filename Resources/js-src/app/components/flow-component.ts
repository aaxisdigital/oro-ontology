import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid, {GridAction} from 'aaxiscommon/js/app/widgets/data-grid';

interface OntologyFlowOptions {
    _sourceElement: any;
    canCreate?: boolean;
}

interface FlowRecord {
    id: number;
    name: string | null;
    enabled: boolean;
    type: string;
    lastExecuted: string | null;
    /** When the last run ended; null while a run is still in flight (or if it never ran). */
    lastFinished: string | null;
    /** Derived server-side: a run started and has not reported finishing. */
    running: boolean;
    lastModified: string | null;
}

/**
 * Flows page built on the reusable DataGrid widget. Shows data from aaxis_ontology_flow.
 * "Add Flow" and the per-row edit action open the flow editor page; the edit action is
 * disabled for the two built-in (type = native) flows.
 */
class OntologyFlowComponent extends BaseComponent {
    private $el!: any;
    private grid!: DataGrid;

    initialize(options: OntologyFlowOptions): void {
        this.$el = options._sourceElement;

        const actions: GridAction[] = [
            {
                key: 'edit',
                label: __('aaxis.common.grid.edit'),
                icon: 'fa-pencil',
                disabled: (row: FlowRecord) => row.type === 'native',
                disabledTitle: __('aaxis.ontology.flow_view.edit_builtin_disabled')
            }
        ];

        this.grid = new DataGrid({
            columns: [
                {key: 'name', label: __('aaxis.ontology.flow_view.name'), type: 'text'},
                {
                    key: 'type', label: __('aaxis.ontology.flow_view.type'), type: 'text', width: '120px',
                    render: (row: FlowRecord) => __('aaxis.ontology.flow_view.type_' + (row.type || 'subflow'))
                },
                {key: 'enabled', label: __('aaxis.ontology.flow_view.enabled'), type: 'boolean', width: '120px'},
                {
                    key: 'lastExecuted', label: __('aaxis.ontology.flow_view.last_executed'), type: 'datetime',
                    width: '190px', render: (row: FlowRecord) => this.renderDate(row.lastExecuted)
                },
                {
                    key: 'lastFinished', label: __('aaxis.ontology.flow_view.last_finished'), type: 'datetime',
                    width: '190px',
                    // While a run is in flight there is no finish time yet — say so rather than
                    // showing an empty cell that reads like "never ran".
                    render: (row: FlowRecord) => row.running
                        ? __('aaxis.ontology.flow_view.running')
                        : this.renderDate(row.lastFinished)
                },
                {
                    key: 'lastModified', label: __('aaxis.ontology.flow_view.last_modified'), type: 'datetime',
                    width: '190px', render: (row: FlowRecord) => this.renderDate(row.lastModified)
                }
            ],
            actions,
            gridKey: 'ontology-flow-view',
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', {gridKey: 'ontology-flow-view'}),
            emptyText: __('aaxis.ontology.flow_view.empty'),
            onAction: (action, row) => this.onAction(action, row as FlowRecord)
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));

        this.$el.on('click.aaxisOntologyFlow', '[data-role="refresh"]', (e: any) => {
            e.preventDefault();
            this.load();
        });
        this.$el.on('click.aaxisOntologyFlow', '[data-role="columns-settings"]', (e: any) => {
            e.preventDefault();
            this.grid.toggleColumnSettings(e.currentTarget);
        });
        this.$el.on('click.aaxisOntologyFlow', '[data-role="add"]', (e: any) => {
            e.preventDefault();
            window.location.href = routing.generate('aaxis_ontology_flow_editor');
        });

        this.load();
    }

    private onAction(action: string, row: FlowRecord): void {
        if (action === 'edit' && row.type !== 'native') {
            window.location.href = routing.generate('aaxis_ontology_flow_editor', {id: row.id});
        }
    }

    private load(): void {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_flow_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {records: FlowRecord[]}) => {
                this.grid.setRows(data.records || []);
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_view.load_error')))
            .finally(() => this.setBusy(false));
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
        this.$el.off('.aaxisOntologyFlow');
        if (this.grid) {
            this.grid.dispose();
        }
        super.dispose();
    }
}

export default OntologyFlowComponent;

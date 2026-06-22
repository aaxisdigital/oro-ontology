import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid, {GridAction} from 'aaxiscommon/js/app/widgets/data-grid';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';

interface OntologyFlowOptions {
    _sourceElement: any;
    canCreate?: boolean;
}

interface FlowRecord {
    id: number;
    name: string | null;
    enabled: boolean;
}

/**
 * Flows page built on the reusable DataGrid widget. Shows data from aaxis_ontology_flow. The "Add Flow"
 * button is present but the editor is not implemented yet (larger design effort).
 */
class OntologyFlowComponent extends BaseComponent {
    private $el!: any;
    private grid!: DataGrid;

    initialize(options: OntologyFlowOptions): void {
        this.$el = options._sourceElement;

        const actions: GridAction[] = [
            {key: 'edit', label: __('aaxis.common.grid.edit'), icon: 'fa-pencil'}
        ];

        this.grid = new DataGrid({
            columns: [
                {key: 'name', label: __('aaxis.ontology.flow_view.name'), type: 'text'},
                {key: 'enabled', label: __('aaxis.ontology.flow_view.enabled'), type: 'boolean', width: '120px'}
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
            this.openAdd();
        });

        this.load();
    }

    private onAction(action: string, _row: FlowRecord): void {
        if (action === 'edit') {
            // The flow editor is a larger design effort; reuse the add popup for now.
            this.openAdd();
        }
    }

    private openAdd(): void {
        // The flow editor is a larger design effort; show a placeholder for now.
        const dialog = new Dialog({
            title: __('aaxis.ontology.flow_view.add_title'),
            width: '560px'
        });
        const $content = dialog.open();
        $content.append($('<p/>', {
            'class': 'text-muted',
            text: __('aaxis.ontology.flow_view.add_placeholder')
        }));
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

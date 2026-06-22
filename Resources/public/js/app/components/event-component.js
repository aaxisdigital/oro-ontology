import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid from 'aaxiscommon/js/app/widgets/data-grid';
/**
 * Read-only Events page built on the reusable DataGrid widget. Shows data from
 * aaxis_ontology_data_events; there is intentionally no "add" action.
 */
class OntologyEventComponent extends BaseComponent {
    initialize(options) {
        this.$el = options._sourceElement;
        this.grid = new DataGrid({
            columns: [
                { key: 'flow', label: __('aaxis.ontology.event_view.flow'), type: 'text' },
                { key: 'entity', label: __('aaxis.ontology.event_view.entity'), type: 'text' },
                { key: 'uuid', label: __('aaxis.ontology.event_view.uuid'), type: 'text' },
                {
                    key: 'uniqueIds', label: __('aaxis.ontology.event_view.unique_ids'), type: 'text',
                    sortable: false
                },
                {
                    key: 'changedIds', label: __('aaxis.ontology.event_view.changed_ids'), type: 'text',
                    sortable: false
                },
                {
                    key: 'startedAt', label: __('aaxis.ontology.event_view.started_at'), type: 'datetime',
                    width: '190px', render: (row) => this.renderDate(row.startedAt)
                },
                {
                    key: 'finishedAt', label: __('aaxis.ontology.event_view.finished_at'), type: 'datetime',
                    width: '190px', render: (row) => this.renderDate(row.finishedAt)
                }
            ],
            gridKey: 'ontology-event-view',
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', { gridKey: 'ontology-event-view' }),
            emptyText: __('aaxis.ontology.event_view.empty')
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));
        this.$el.on('click.aaxisOntologyEvent', '[data-role="refresh"]', (e) => {
            e.preventDefault();
            this.load();
        });
        this.$el.on('click.aaxisOntologyEvent', '[data-role="columns-settings"]', (e) => {
            e.preventDefault();
            this.grid.toggleColumnSettings(e.currentTarget);
        });
        this.load();
    }
    load() {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_event_list'), { credentials: 'same-origin' })
            .then(r => r.json())
            .then((data) => {
            this.grid.setRows(data.records || []);
        })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.event_view.load_error')))
            .finally(() => this.setBusy(false));
    }
    renderDate(value) {
        if (!value) {
            return '';
        }
        const d = new Date(value);
        return isNaN(d.getTime()) ? String(value) : d.toLocaleString();
    }
    setBusy(busy) {
        this.$el.find('[data-role="refresh"]').prop('disabled', busy)
            .find('.fa').toggleClass('fa-spin', busy);
    }
    dispose() {
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

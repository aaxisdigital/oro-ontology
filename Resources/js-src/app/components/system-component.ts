import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid, {GridAction} from 'aaxiscommon/js/app/widgets/data-grid';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import RecordFormModal from 'aaxiscommon/js/app/widgets/record-form-modal';
interface OntologySystemOptions {
    _sourceElement: any;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}

interface SystemRecord {
    id: number;
    name: string;
    enabled: boolean;
    external: boolean;
    /** Number of entities under this system. */
    entityCount: number;
    /** Total records held by all the system's entities (ontology data, or OroCommerce tables if internal). */
    recordCount: number;
    /** How many flows depend on this system's entities (not implemented yet — currently 0). */
    flowCount: number;
}

/**
 * System management page, built on the reusable DataGrid + RecordFormModal widgets.
 */
class OntologySystemComponent extends BaseComponent {
    private $el!: any;
    private opts!: OntologySystemOptions;
    private grid!: DataGrid;

    initialize(options: OntologySystemOptions): void {
        this.$el = options._sourceElement;
        this.opts = options;

        const actions: GridAction[] = [
            {key: 'entities', label: __('aaxis.ontology.system_manager.view_entities'), icon: 'fa-sitemap'}
        ];
        if (options.canUpdate) {
            actions.push({key: 'edit', label: __('aaxis.common.grid.edit'), icon: 'fa-pencil'});
        }
        if (options.canDelete) {
            actions.push({
                key: 'delete', label: __('aaxis.common.grid.delete'), icon: 'fa-trash-o', variant: 'danger',
                // Internal systems (external = false, e.g. "OroCommerce") cannot be deleted.
                disabled: (row: SystemRecord) => row.external === false,
                disabledTitle: __('aaxis.ontology.system_manager.delete_internal_forbidden')
            });
        }

        this.grid = new DataGrid({
            columns: [
                {key: 'name', label: __('aaxis.ontology.system.name.label'), type: 'text'},
                {key: 'entityCount', label: __('aaxis.ontology.system.entity_count.label'), type: 'number', width: '120px'},
                {key: 'recordCount', label: __('aaxis.ontology.system.record_count.label'), type: 'number', width: '120px'},
                {key: 'flowCount', label: __('aaxis.ontology.system.flow_count.label'), type: 'number', width: '110px'},
                {key: 'external', label: __('aaxis.ontology.system.external.label'), type: 'boolean', width: '120px'},
                {key: 'enabled', label: __('aaxis.ontology.system.enabled.label'), type: 'boolean', width: '120px'}
            ],
            actions,
            gridKey: 'ontology-system',
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', {gridKey: 'ontology-system'}),
            emptyText: __('aaxis.ontology.system_manager.empty'),
            onAction: (action, row) => this.onAction(action, row as SystemRecord)
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));

        this.$el.on('click.aaxisOntologySys', '[data-role="add"]', this.onAdd.bind(this));
        this.$el.on('click.aaxisOntologySys', '[data-role="refresh"]', (e: any) => {
            e.preventDefault();
            this.load();
        });
        this.$el.on('click.aaxisOntologySys', '[data-role="columns-settings"]', (e: any) => {
            e.preventDefault();
            this.grid.toggleColumnSettings(e.currentTarget);
        });

        this.load();
    }

    private load(): void {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_system_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {systems: SystemRecord[]}) => this.grid.setRows(data.systems || []))
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.system_manager.load_error')))
            .finally(() => this.setBusy(false));
    }

    private onAction(action: string, row: SystemRecord): void {
        if (action === 'edit') {
            this.openForm(row);
        } else if (action === 'delete') {
            this.remove(row);
        } else if (action === 'entities') {
            this.openEntities(row);
        }
    }

    /**
     * Opens the Entities page pre-filtered to this system. The name (not the id) is passed because
     * that is what the Entities grid's `systemName` column holds.
     */
    private openEntities(row: SystemRecord): void {
        const url = routing.generate('aaxis_ontology_entities')
            + '?system=' + encodeURIComponent(String(row.name || ''));
        window.location.assign(url);
    }

    private onAdd(): void {
        this.openForm(null);
    }

    private openForm(system: SystemRecord | null): void {
        const modal = new RecordFormModal({
            title: system
                ? __('aaxis.ontology.system_manager.edit_title')
                : __('aaxis.ontology.system_manager.create_title'),
            subtitle: system
                ? __('aaxis.ontology.system_manager.edit_subtitle')
                : __('aaxis.ontology.system_manager.create_subtitle'),
            fields: [
                {
                    key: 'name', label: __('aaxis.ontology.system.name.label'), type: 'text',
                    required: true, placeholder: __('aaxis.ontology.system_manager.name_placeholder')
                },
                {key: 'enabled', label: __('aaxis.ontology.system.enabled.label'), type: 'boolean'}
            ],
            values: system ? {name: system.name, enabled: system.enabled} : {enabled: true},
            onSubmit: values => this.save(system, values)
        });
        modal.open();
    }

    private save(system: SystemRecord | null, values: Record<string, any>): Promise<void> {
        const url = system === null
            ? routing.generate('aaxis_ontology_system_api_create')
            : routing.generate('aaxis_ontology_system_api_update', {id: system.id});

        return this.apiFetch(url, system === null ? 'POST' : 'PUT', {
            name: values.name,
            enabled: !!values.enabled
        }).then(res => {
            if (!res.ok || !res.data || !res.data.success) {
                throw new Error((res.data && res.data.message) || __('aaxis.ontology.system_manager.save_error'));
            }
            messenger.notificationFlashMessage('success', __('aaxis.ontology.system_manager.saved'));
            this.load();
        });
    }

    private remove(system: SystemRecord): void {
        // Only external systems reach here — the delete action is disabled for internal ones.
        const dialog = new Dialog({title: __('aaxis.ontology.system_manager.delete_title'), width: '520px'});
        const $content = dialog.open();

        const $body = $('<div/>', {'class': 'aaxis-ontology-confirm'});
        $body.append($('<p/>', {
            'class': 'aaxis-ontology-confirm__q',
            text: __('aaxis.ontology.system_manager.confirm_delete', {name: system.name})
        }));
        $body.append($('<p/>', {
            'class': 'aaxis-ontology-confirm__danger',
            text: __('aaxis.ontology.system_manager.delete_data_warning', {
                entities: String(system.entityCount || 0),
                records: String(system.recordCount || 0)
            })
        }));
        $body.append($('<p/>', {
            'class': 'aaxis-ontology-confirm__danger',
            text: __('aaxis.ontology.system_manager.delete_flows_warning')
        }));

        const $actions = $('<div/>', {'class': 'aaxis-ontology-confirm__actions'});
        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', text: __('Cancel')});
        const $confirm = $('<button/>', {type: 'button', 'class': 'btn aaxis-ontology-confirm__delete', text: __('aaxis.common.grid.delete')});
        $actions.append($cancel, $confirm);
        $body.append($actions);
        $content.append($body);

        $cancel.on('click', () => dialog.close());
        $confirm.on('click', () => {
            $confirm.prop('disabled', true);
            this.doDelete(system, () => dialog.close());
        });
    }

    private doDelete(system: SystemRecord, done: () => void): void {
        this.setBusy(true);
        this.apiFetch(routing.generate('aaxis_ontology_system_delete', {id: system.id}), 'DELETE')
            .then(res => {
                if (!res.ok || !res.data || !res.data.successful) {
                    messenger.notificationFlashMessage(
                        'error',
                        (res.data && res.data.message) || __('aaxis.ontology.system_manager.delete_error')
                    );
                    return;
                }
                messenger.notificationFlashMessage('success', __('aaxis.ontology.system_manager.deleted'));
                this.load();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.system_manager.delete_error')))
            .finally(() => {
                this.setBusy(false);
                done();
            });
    }

    private csrf(): string {
        const name = window.location.protocol === 'https:' ? 'https-_csrf' : '_csrf';
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    private apiFetch(url: string, method: string, body?: any): Promise<{ok: boolean; data: any}> {
        const opts: any = {
            method,
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': this.csrf()}
        };
        if (body !== undefined) {
            opts.body = JSON.stringify(body);
        }
        return fetch(url, opts).then(r => r.json().then(d => ({ok: r.ok, data: d})));
    }

    private setBusy(busy: boolean): void {
        this.$el.find('[data-role="refresh"]').prop('disabled', busy)
            .find('.fa').toggleClass('fa-spin', busy);
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisOntologySys');
        if (this.grid) {
            this.grid.dispose();
        }
        super.dispose();
    }
}

export default OntologySystemComponent;

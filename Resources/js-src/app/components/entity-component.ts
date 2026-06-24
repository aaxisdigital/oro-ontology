import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid, {GridAction} from 'aaxiscommon/js/app/widgets/data-grid';
import RecordFormModal, {SelectOption} from 'aaxiscommon/js/app/widgets/record-form-modal';

interface OntologyEntityOptions {
    _sourceElement: any;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}

interface AttributeRecord {
    name: string;
    datatype: string;
    required: boolean;
}

interface EntityRecord {
    id: number;
    name: string;
    uniqueAttribute: string | null;
    enabled: boolean;
    systemId: number | null;
    systemName: string | null;
    attributeCount: number;
    attributes: AttributeRecord[];
}

/**
 * Entity management page, built on the reusable DataGrid + RecordFormModal widgets. Entities own a
 * 1:N collection of attributes edited inline in the record form.
 */
class OntologyEntityComponent extends BaseComponent {
    private $el!: any;
    private opts!: OntologyEntityOptions;
    private grid!: DataGrid;
    private systems: SelectOption[] = [];
    private datatypes: SelectOption[] = [];

    initialize(options: OntologyEntityOptions): void {
        this.$el = options._sourceElement;
        this.opts = options;

        const actions: GridAction[] = [];
        if (options.canUpdate) {
            actions.push({key: 'edit', label: __('aaxis.common.grid.edit'), icon: 'fa-pencil'});
        }
        if (options.canDelete) {
            actions.push({key: 'delete', label: __('aaxis.common.grid.delete'), icon: 'fa-trash-o', variant: 'danger'});
        }

        this.grid = new DataGrid({
            columns: [
                {key: 'name', label: __('aaxis.ontology.entity.name.label'), type: 'text'},
                {key: 'systemName', label: __('aaxis.ontology.entity.system.label'), type: 'text'},
                {key: 'uniqueAttribute', label: __('aaxis.ontology.entity.unique_attribute.label'), type: 'text'},
                {
                    key: 'attributeCount',
                    label: __('aaxis.ontology.entity_attribute.entity_plural_label'),
                    type: 'number',
                    width: '160px',
                    render: (row: EntityRecord) => this.renderAttributesCell(row)
                },
                {key: 'enabled', label: __('aaxis.ontology.entity.enabled.label'), type: 'boolean', width: '140px'}
            ],
            actions,
            gridKey: 'ontology-entity',
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', {gridKey: 'ontology-entity'}),
            emptyText: __('aaxis.ontology.entity_manager.empty'),
            onAction: (action, row) => this.onAction(action, row as EntityRecord)
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));

        this.$el.on('click.aaxisOntologyEntity', '[data-role="add"]', this.onAdd.bind(this));
        this.$el.on('click.aaxisOntologyEntity', '[data-role="refresh"]', (e: any) => {
            e.preventDefault();
            this.load();
        });
        this.$el.on('click.aaxisOntologyEntity', '[data-role="columns-settings"]', (e: any) => {
            e.preventDefault();
            this.grid.toggleColumnSettings(e.currentTarget);
        });

        this.load();
    }

    private load(): void {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_entity_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {entities: EntityRecord[]; systems: {id: number; name: string}[]; datatypes: SelectOption[]}) => {
                this.systems = (data.systems || []).map(s => ({value: String(s.id), label: s.name}));
                this.datatypes = data.datatypes || [];
                this.grid.setRows(data.entities || []);
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.entity_manager.load_error')))
            .finally(() => this.setBusy(false));
    }

    private renderAttributesCell(row: EntityRecord): any {
        return $('<span/>', {'class': 'aaxis-attr-cell', text: String(row.attributeCount || 0)});
    }

    private onAction(action: string, row: EntityRecord): void {        if (action === 'edit') {
            this.openForm(row);
        } else if (action === 'delete') {
            this.remove(row);
        }
    }

    private onAdd(): void {
        this.openForm(null);
    }

    private openForm(entity: EntityRecord | null): void {
        const modal = new RecordFormModal({
            title: entity
                ? __('aaxis.ontology.entity_manager.edit_title')
                : __('aaxis.ontology.entity_manager.create_title'),
            width: '840px',
            subtitle: entity
                ? __('aaxis.ontology.entity_manager.edit_subtitle')
                : __('aaxis.ontology.entity_manager.create_subtitle'),
            fields: [
                {
                    key: 'systemId', label: __('aaxis.ontology.entity.system.label'), type: 'select',
                    required: true, options: this.systems
                },
                {
                    key: 'name', label: __('aaxis.ontology.entity.name.label'), type: 'text',
                    required: true, placeholder: __('aaxis.ontology.entity_manager.name_placeholder'),
                    row: 'main', width: '45%'
                },
                {
                    key: 'uniqueAttribute', label: __('aaxis.ontology.entity.unique_attribute.label'), type: 'text',
                    required: true, placeholder: __('aaxis.ontology.entity_manager.unique_attribute_placeholder'),
                    row: 'main', width: '45%'
                },
                {key: 'enabled', label: __('aaxis.ontology.entity.enabled.label'), type: 'boolean', row: 'main', width: '10%'},
                {
                    key: 'attributes',
                    label: __('aaxis.ontology.entity_attribute.entity_plural_label'),
                    type: 'collection',
                    addLabel: __('aaxis.ontology.entity_manager.add_attribute'),
                    fields: [
                        {key: 'name', label: __('aaxis.ontology.entity_attribute.name.label'), type: 'text', required: true},
                        {key: 'datatype', label: __('aaxis.ontology.entity_attribute.datatype.label'), type: 'select', options: this.datatypes, width: '180px'},
                        {key: 'required', label: __('aaxis.ontology.entity_attribute.required.label'), type: 'boolean', width: '110px'}
                    ]
                }
            ],
            values: entity
                ? {name: entity.name, systemId: String(entity.systemId ?? ''), uniqueAttribute: entity.uniqueAttribute ?? '', enabled: entity.enabled, attributes: entity.attributes}
                : {enabled: true, attributes: []},
            onSubmit: values => this.save(entity, values)
        });
        modal.open();
    }

    private save(entity: EntityRecord | null, values: Record<string, any>): Promise<void> {
        // The unique id is extracted by a flat top-level key lookup on upsert, so a dotted/nested
        // (or array) path can never resolve — reject it up front instead of at every upsert.
        if (String(values.uniqueAttribute ?? '').includes('.')) {
            return Promise.reject(new Error(__('aaxis.ontology.entity_manager.unique_attribute_no_dots')));
        }

        const url = entity === null
            ? routing.generate('aaxis_ontology_entity_api_create')
            : routing.generate('aaxis_ontology_entity_api_update', {id: entity.id});

        return this.apiFetch(url, entity === null ? 'POST' : 'PUT', {
            name: values.name,
            systemId: values.systemId ? Number(values.systemId) : null,
            uniqueAttribute: values.uniqueAttribute,
            enabled: !!values.enabled,
            attributes: (values.attributes || []).map((a: any) => ({
                name: a.name,
                datatype: a.datatype || 'undefined',
                required: !!a.required
            }))
        }).then(res => {
            if (!res.ok || !res.data || !res.data.success) {
                throw new Error((res.data && res.data.message) || __('aaxis.ontology.entity_manager.save_error'));
            }
            messenger.notificationFlashMessage('success', __('aaxis.ontology.entity_manager.saved'));
            this.load();
        });
    }

    private remove(entity: EntityRecord): void {
        // eslint-disable-next-line no-alert
        if (!window.confirm(__('aaxis.ontology.entity_manager.confirm_delete', {name: entity.name}))) {
            return;
        }
        this.setBusy(true);
        this.apiFetch(routing.generate('aaxis_ontology_entity_delete', {id: entity.id}), 'DELETE')
            .then(res => {
                if (!res.ok || !res.data || !res.data.successful) {
                    messenger.notificationFlashMessage('error', __('aaxis.ontology.entity_manager.delete_error'));
                    return;
                }
                messenger.notificationFlashMessage('success', __('aaxis.ontology.entity_manager.deleted'));
                this.load();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.entity_manager.delete_error')))
            .finally(() => this.setBusy(false));
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
        this.$el.off('.aaxisOntologyEntity');
        if (this.grid) {
            this.grid.dispose();
        }
        super.dispose();
    }
}

export default OntologyEntityComponent;

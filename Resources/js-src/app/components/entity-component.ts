import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid, {GridAction, navigateTo} from 'aaxiscommon/js/app/widgets/data-grid';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import RecordFormModal, {FieldChangeContext, FormField, SelectOption, SelectPickerApi} from 'aaxiscommon/js/app/widgets/record-form-modal';
import DwlPlayground from '../widgets/dwl-playground';
import EraseRecordsDialog from '../widgets/erase-records';
import {apiFetch, bindGridToolbar, setRefreshBusy, takeQueryParam} from './component-support';

interface OntologyEntityOptions {
    _sourceElement: any;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    /** The Systems page opening its create form — offered as "Add new system…" (null = not allowed). */
    systemCreateUrl?: string | null;
}

/** A system as the entity list ships it. */
interface SystemOption {
    id: number;
    name: string;
    external?: boolean;
}

interface AttributeRecord {
    name: string;
    datatype: string;
    required: boolean;
}

interface EntityRecord {
    id: number;
    name: string;
    displayName: string;
    uniqueAttribute: string | null;
    enabled: boolean;
    forceDbStorage: boolean;
    systemId: number | null;
    systemName: string | null;
    attributeCount: number;
    attributes: AttributeRecord[];
    /** Stored records (ontology data for external systems, the OroCommerce table for internal). */
    recordCount: number;
    /** How many flows reference this entity (not implemented yet — currently 0). */
    flowCount: number;
}

/** An OroCommerce entity field offered when the selected system is internal. */
interface OroField {
    value: string;
    label: string;
    datatype: string;
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
    /** systemId (as string) -> whether that system is external. Internal systems drive the combobox UI. */
    private systemExternalById: Record<string, boolean> = {};
    /** OroCommerce entity options for internal systems; null until first loaded. */
    private oroEntities: SelectOption[] | null = null;
    /** Cache of an OroCommerce entity class -> its selectable fields. */
    private oroFieldsByEntity: Record<string, OroField[]> = {};
    /**
     * `?system=` seeds the grid filter once; later refreshes must not re-impose it. Declared with
     * `!` and assigned in initialize() — a field initializer would run AFTER the base constructor
     * has already called initialize() (see CommonBundle/CLAUDE.md).
     */
    private systemFilterApplied!: boolean;
    /** Where "Add new system…" leads (the Systems page with its create form open); null = hidden. */
    private systemCreateUrl!: string | null;

    initialize(options: OntologyEntityOptions): void {
        this.$el = options._sourceElement;
        this.opts = options;
        this.systemFilterApplied = false;
        this.systemCreateUrl = options.systemCreateUrl || null;

        // The DWL playground is read-only (it evaluates, never writes), so viewing the page is
        // enough — and it leads the action list, before the edit/delete icons.
        const actions: GridAction[] = [
            {key: 'dwl', label: __('aaxis.ontology.dwl.action'), badge: 'dwl'},
            {key: 'data', label: __('aaxis.ontology.entity_manager.view_data'), icon: 'fa-table'}
        ];
        if (options.canUpdate) {
            actions.push({key: 'edit', label: __('aaxis.common.grid.edit'), icon: 'fa-pencil'});
        }
        if (options.canDelete) {
            // Erasing records keeps the entity — sits between edit and delete. Disabled when there is
            // nothing stored to erase: no records, or an OroCommerce (internal-system) entity, whose
            // data is its own table (its record count is that table's size).
            actions.push(
                {
                    key: 'purge', label: __('aaxis.ontology.entity_manager.purge_title'), icon: 'fa-eraser',
                    variant: 'danger',
                    disabled: (row: EntityRecord) => !row.recordCount || this.isInternal(String(row.systemId ?? '')),
                    disabledTitle: __('aaxis.ontology.entity_manager.purge_empty')
                },
                {key: 'delete', label: __('aaxis.common.grid.delete'), icon: 'fa-trash-o', variant: 'danger'}
            );
        }

        this.grid = new DataGrid({
            columns: [
                {
                    key: 'name', label: __('aaxis.ontology.entity.name.label'), type: 'text',
                    // Internal-system entities store the OroCommerce class as `name`; show its label.
                    render: (row: EntityRecord) => row.displayName || row.name
                },
                {key: 'systemName', label: __('aaxis.ontology.entity.system.label'), type: 'text'},
                {key: 'uniqueAttribute', label: __('aaxis.ontology.entity.unique_attribute.label'), type: 'text'},
                {
                    key: 'attributeCount',
                    label: __('aaxis.ontology.entity_attribute.entity_plural_label'),
                    type: 'number',
                    width: '120px',
                    render: (row: EntityRecord) => this.renderAttributesCell(row)
                },
                {key: 'recordCount', label: __('aaxis.ontology.entity.record_count.label'), type: 'number', width: '110px'},
                {key: 'flowCount', label: __('aaxis.ontology.entity.flow_count.label'), type: 'number', width: '110px'},
                {key: 'enabled', label: __('aaxis.ontology.entity.enabled.label'), type: 'boolean', width: '120px'}
            ],
            actions,
            gridKey: 'ontology-entity',
            urlState: true,
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', {gridKey: 'ontology-entity'}),
            emptyText: __('aaxis.ontology.entity_manager.empty'),
            onAction: (action, row, event) => this.onAction(action, row as EntityRecord, event)
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));

        this.$el.on('click.aaxisOntologyEntity', '[data-role="add"]', this.onAdd.bind(this));
        bindGridToolbar(this.$el, 'aaxisOntologyEntity', () => this.load(), () => this.grid);

        this.load();
    }

    private load(): void {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_entity_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {entities: EntityRecord[]; systems: SystemOption[]; datatypes: SelectOption[]}) => {
                this.applySystems(data.systems || []);
                this.datatypes = data.datatypes || [];
                this.grid.setRows(data.entities || []);
                this.applySystemFromUrl();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.entity_manager.load_error')))
            .finally(() => this.setBusy(false));
    }

    /** Takes a fresh systems list: the System picker's options and which ones are external. */
    private applySystems(systems: SystemOption[]): void {
        this.systems = systems.map(s => ({value: String(s.id), label: s.name}));
        this.systemExternalById = {};
        systems.forEach(s => {
            this.systemExternalById[String(s.id)] = s.external !== false;
        });
    }

    /**
     * The System picker's refresh: re-reads the systems (through the entity list this page already
     * reads — no extra permission needed), keeping the page's own copy in step for later rebuilds.
     */
    private reloadSystems(): Promise<SelectOption[]> {
        return fetch(routing.generate('aaxis_ontology_entity_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {systems?: SystemOption[]}) => {
                this.applySystems(data.systems || []);
                return this.systems;
            })
            .catch((error: unknown) => {
                messenger.notificationFlashMessage('error', __('aaxis.ontology.entity_manager.load_error'));
                throw error;
            });
    }

    /**
     * "Add new system…" (the flow editor's "Add new connector…"): the Systems page opens in a NEW
     * browser tab with its create form; on this window's next focus the list is reloaded and the new
     * system is picked when exactly one appeared.
     */
    private addSystem(api: SelectPickerApi): void {
        const known = new Set(api.values());
        window.open(String(this.systemCreateUrl), '_blank');
        $(window).off('focus.aaxisEntitySystemAdd').one('focus.aaxisEntitySystemAdd', () => {
            void api.refresh().then(() => {
                const fresh = api.values().filter(value => !known.has(value));
                if (fresh.length === 1) {
                    api.pick(fresh[0]);
                }
            });
        });
    }

    /**
     * Honours `?system=<name>` (the Systems page's "View entities" action links here) by
     * pre-applying it as the systemName column's filter. Applied once, after the first load, so a
     * Refresh doesn't re-impose a filter the user cleared — same contract as the Data View's
     * `?entity=`.
     */
    private applySystemFromUrl(): void {
        if (this.systemFilterApplied) {
            return;
        }
        this.systemFilterApplied = true;
        const system = takeQueryParam('system');
        if (system !== null) {
            this.grid.setFilter('systemName', {operator: 'equals', value: system});
        }
    }

    private renderAttributesCell(row: EntityRecord): any {
        return $('<span/>', {'class': 'aaxis-attr-cell', text: String(row.attributeCount || 0)});
    }

    private onAction(action: string, row: EntityRecord, event?: MouseEvent): void {
        if (action === 'edit') {
            this.openForm(row);
        } else if (action === 'delete') {
            this.remove(row);
        } else if (action === 'dwl') {
            this.openDwlPlayground(row);
        } else if (action === 'data') {
            this.openDataView(row, event);
        } else if (action === 'purge') {
            this.openErase(row);
        }
    }

    /**
     * "Erase": all of the entity's stored records, or those matching one attribute comparison,
     * counted and previewable before anything goes ({@see EraseRecordsDialog}). The filter offers
     * the unique attribute first, then the configured attributes alphabetically.
     */
    private openErase(entity: EntityRecord): void {
        const names = (entity.attributes || []).map(a => a.name).filter(Boolean).sort((a, b) => a.localeCompare(b));
        const unique = entity.uniqueAttribute || '';
        new EraseRecordsDialog({
            entityId: entity.id,
            entityLabel: entity.displayName || entity.name,
            attributes: unique ? [unique, ...names.filter(n => n !== unique)] : names,
            onErased: () => this.load()
        }).open();
    }

    /**
     * Opens the Data View pre-filtered to this entity. The name (not the id) is passed because that
     * is what the Data View grid's `entity` column holds — the page turns it into a column filter.
     */
    private openDataView(row: EntityRecord, event?: MouseEvent): void {
        const url = routing.generate('aaxis_ontology_data_view')
            + '?entity=' + encodeURIComponent(String(row.name || ''));
        navigateTo(url, event); // cmd/ctrl+click opens the filtered Data View in a new tab
    }

    /** Opens the DataWeave playground over this entity's stored records. */
    private openDwlPlayground(row: EntityRecord): void {
        new DwlPlayground({
            entityId: row.id,
            entityLabel: row.displayName || row.name
        }).open();
    }

    private onAdd(): void {
        this.openForm(null);
    }

    private openForm(entity: EntityRecord | null): void {
        const values: Record<string, any> = entity
            ? {
                name: entity.name, systemId: String(entity.systemId ?? ''),
                uniqueAttribute: entity.uniqueAttribute ?? '', enabled: entity.enabled,
                forceDbStorage: !!entity.forceDbStorage,
                attributes: entity.attributes
            }
            : {enabled: true, forceDbStorage: false, attributes: []};

        // Editing an internal-system entity: preload the OroCommerce entity list and this entity's
        // fields so the comboboxes render populated, then open.
        const systemId = String(values.systemId ?? '');
        if (systemId !== '' && this.isInternal(systemId)) {
            this.ensureInternalData(String(values.name ?? '')).then(() => this.openModal(entity, values));
        } else {
            this.openModal(entity, values);
        }
    }

    private openModal(entity: EntityRecord | null, values: Record<string, any>): void {
        const modal = new RecordFormModal({
            title: entity
                ? __('aaxis.ontology.entity_manager.edit_title')
                : __('aaxis.ontology.entity_manager.create_title'),
            width: '840px',
            subtitle: entity
                ? __('aaxis.ontology.entity_manager.edit_subtitle')
                : __('aaxis.ontology.entity_manager.create_subtitle'),
            fields: this.buildFields(values),
            values,
            onFieldChange: ctx => this.onFieldChange(ctx),
            onSubmit: vals => this.save(entity, vals)
        });
        modal.open();
    }

    /**
     * Builds the form schema for the current values. When the selected system is internal
     * (external = false), the entity Name and each attribute Name become comboboxes constrained to
     * the real OroCommerce entity model; otherwise they are free-text inputs (the original UI).
     */
    private buildFields(values: Record<string, any>): FormField[] {
        const systemId = String(values.systemId ?? '');
        const internal = systemId !== '' && this.isInternal(systemId);
        const entityName = String(values.name ?? '');

        const nameField: FormField = internal
            ? {
                key: 'name', label: __('aaxis.ontology.entity.name.label'), type: 'select',
                required: true, options: this.entityOptions(entityName), row: 'identity', width: '50%'
            }
            : {
                key: 'name', label: __('aaxis.ontology.entity.name.label'), type: 'text',
                required: true, placeholder: __('aaxis.ontology.entity_manager.name_placeholder'),
                row: 'identity', width: '50%'
            };

        const attrNameField: FormField = internal
            ? {
                key: 'name', label: __('aaxis.ontology.entity_attribute.name.label'), type: 'select',
                required: true, options: this.fieldOptions(entityName, values.attributes)
            }
            : {key: 'name', label: __('aaxis.ontology.entity_attribute.name.label'), type: 'text', required: true};

        // Row 1: System | Name (50/50). Row 2: Unique attribute | Enabled | Force DB storage
        // (50/15/35 — the force switch's hint reads to its right, and the switches center on the
        // input's line). Then the attributes, whose ROWS alone scroll (`fill`): everything above,
        // the "Attributes" title and the Add attribute button stay in view.
        return [
            {
                // A type-to-filter picker (like the flow editor's connector picker): "Add new
                // system…" when the user may create one, and a refresh beside it.
                key: 'systemId', label: __('aaxis.ontology.entity.system.label'), type: 'select',
                required: true, options: this.systems, row: 'identity', width: '50%',
                picker: {
                    placeholder: __('aaxis.ontology.entity_manager.system_filter_placeholder'),
                    refresh: () => this.reloadSystems(),
                    ...(this.systemCreateUrl
                        ? {addNew: {label: __('aaxis.ontology.entity_manager.system_add'), onAdd: (api: SelectPickerApi) => this.addSystem(api)}}
                        : {})
                }
            },
            nameField,
            {
                key: 'uniqueAttribute', label: __('aaxis.ontology.entity.unique_attribute.label'), type: 'text',
                required: true, placeholder: __('aaxis.ontology.entity_manager.unique_attribute_placeholder'),
                row: 'settings', width: '50%'
            },
            {key: 'enabled', label: __('aaxis.ontology.entity.enabled.label'), type: 'boolean', row: 'settings', width: '15%'},
            {
                key: 'forceDbStorage', label: __('aaxis.ontology.entity.force_db_storage.label'), type: 'boolean',
                hint: __('aaxis.ontology.entity.force_db_storage.hint'), row: 'settings', width: '35%'
            },
            {
                key: 'attributes',
                label: __('aaxis.ontology.entity_attribute.entity_plural_label'),
                type: 'collection',
                fill: true,
                addLabel: __('aaxis.ontology.entity_manager.add_attribute'),
                fields: [
                    attrNameField,
                    {key: 'datatype', label: __('aaxis.ontology.entity_attribute.datatype.label'), type: 'select', options: this.datatypes, width: '180px'},
                    {key: 'required', label: __('aaxis.ontology.entity_attribute.required.label'), type: 'boolean', width: '110px'}
                ]
            }
        ];
    }

    private onFieldChange(ctx: FieldChangeContext): void {
        // Within the attributes collection, the only change that matters is picking an Oro field as
        // the attribute Name → pre-fill that row's Data type from the field type. Nothing else in a
        // collection row re-shapes the form.
        if (ctx.collectionKey) {
            if (ctx.collectionKey === 'attributes' && ctx.key === 'name') {
                const datatype = this.datatypeForField(String(ctx.api.values().name ?? ''), String(ctx.value ?? ''));
                if (datatype) {
                    ctx.api.setRowValue('datatype', datatype);
                }
            }
            return;
        }

        const values = ctx.api.values();
        const systemId = String(values.systemId ?? '');
        const internal = systemId !== '' && this.isInternal(systemId);

        if (ctx.key === 'systemId') {
            // System changed → re-shape the form (free text ↔ comboboxes).
            if (internal) {
                this.ensureInternalData(String(values.name ?? ''))
                    .then(() => ctx.api.rebuildFields(this.buildFields(ctx.api.values())));
            } else {
                ctx.api.rebuildFields(this.buildFields(values));
            }
        } else if (ctx.key === 'name' && internal) {
            // Entity changed within an internal system → reload its fields for the attribute combobox.
            this.ensureInternalData(String(values.name ?? ''))
                .then(() => ctx.api.rebuildFields(this.buildFields(ctx.api.values())));
        }
    }

    private isInternal(systemId: string): boolean {
        return this.systemExternalById[systemId] === false;
    }

    /** Loads the data needed to render the internal-system comboboxes: the entity list and (when an
     *  entity is selected) its fields. */
    private ensureInternalData(entityName: string): Promise<void> {
        return this.ensureOroEntities().then(() => {
            if (entityName && (this.oroEntities || []).some(e => e.value === entityName)) {
                return this.ensureOroFields(entityName);
            }
            return undefined;
        });
    }

    private ensureOroEntities(): Promise<void> {
        if (this.oroEntities !== null) {
            return Promise.resolve();
        }
        return fetch(routing.generate('aaxis_ontology_entity_oro_entities'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {entities: SelectOption[]}) => {
                this.oroEntities = data.entities || [];
            })
            .catch(() => {
                this.oroEntities = [];
            });
    }

    private ensureOroFields(entityClass: string): Promise<void> {
        if (this.oroFieldsByEntity[entityClass]) {
            return Promise.resolve();
        }
        return fetch(routing.generate('aaxis_ontology_entity_oro_fields', {entity: entityClass}), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {fields: OroField[]}) => {
                this.oroFieldsByEntity[entityClass] = data.fields || [];
            })
            .catch(() => {
                this.oroFieldsByEntity[entityClass] = [];
            });
    }

    /** Options for the internal entity Name combobox (plus a placeholder; preserves an unknown current value). */
    private entityOptions(current: string): SelectOption[] {
        const options: SelectOption[] = [{value: '', label: __('aaxis.ontology.entity_manager.entity_select_placeholder')}];
        (this.oroEntities || []).forEach(e => options.push(e));
        if (current && !options.some(o => o.value === current)) {
            options.push({value: current, label: current});
        }
        return options;
    }

    /** Options for an internal attribute Name combobox: the entity's fields, plus any existing
     *  attribute names not (any longer) present in the model. */
    private fieldOptions(entityName: string, attributes: any): SelectOption[] {
        const options: SelectOption[] = [{value: '', label: __('aaxis.ontology.entity_manager.field_select_placeholder')}];
        (this.oroFieldsByEntity[entityName] || []).forEach(f => options.push({value: f.value, label: f.label}));
        (Array.isArray(attributes) ? attributes : []).forEach((a: any) => {
            const name = String(a?.name || '');
            if (name && !options.some(o => o.value === name)) {
                options.push({value: name, label: name});
            }
        });
        return options;
    }

    private datatypeForField(entityName: string, fieldName: string): string | null {
        const field = (this.oroFieldsByEntity[entityName] || []).find(f => f.value === fieldName);
        return field ? field.datatype : null;
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

        return apiFetch(url, entity === null ? 'POST' : 'PUT', {
            name: values.name,
            systemId: values.systemId ? Number(values.systemId) : null,
            uniqueAttribute: values.uniqueAttribute,
            enabled: !!values.enabled,
            forceDbStorage: !!values.forceDbStorage,
            attributes: (values.attributes || []).map((a: any) => ({
                name: a.name,
                datatype: a.datatype || 'undefined',
                required: !!a.required
            }))
        }).then(res => {
            if (!res.ok || !res.data?.success) {
                throw new Error(res.data?.message || __('aaxis.ontology.entity_manager.save_error'));
            }
            messenger.notificationFlashMessage('success', __('aaxis.ontology.entity_manager.saved'));
            this.load();
        });
    }

    private remove(entity: EntityRecord): void {
        const internal = entity.systemId != null && this.isInternal(String(entity.systemId));
        const dialog = new Dialog({title: __('aaxis.ontology.entity_manager.delete_title'), width: '520px'});
        const $content = dialog.open();

        const $body = $('<div/>', {'class': 'aaxis-ontology-confirm'});
        $body.append($('<p/>', {
            'class': 'aaxis-ontology-confirm__q',
            text: __('aaxis.ontology.entity_manager.confirm_delete', {name: entity.displayName || entity.name})
        }));

        if (internal) {
            // The OroCommerce data is untouched; only the ontology mapping (and any flow that
            // references it) is affected.
            $body.append($('<p/>', {text: __('aaxis.ontology.entity_manager.delete_internal_data_note')}));
        } else {
            $body.append($('<p/>', {
                'class': 'aaxis-ontology-confirm__danger',
                text: __('aaxis.ontology.entity_manager.delete_external_data_warning', {count: String(entity.recordCount || 0)})
            }));
        }
        $body.append($('<p/>', {
            'class': 'aaxis-ontology-confirm__danger',
            text: __('aaxis.ontology.entity_manager.delete_flows_warning')
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
            this.doDelete(entity, () => dialog.close());
        });
    }

    private doDelete(entity: EntityRecord, done: () => void): void {
        this.setBusy(true);
        apiFetch(routing.generate('aaxis_ontology_entity_delete', {id: entity.id}), 'DELETE')
            .then(res => {
                if (!res.ok || !res.data?.successful) {
                    messenger.notificationFlashMessage('error', res.data?.message || __('aaxis.ontology.entity_manager.delete_error'));
                    return;
                }
                messenger.notificationFlashMessage('success', __('aaxis.ontology.entity_manager.deleted'));
                this.load();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.entity_manager.delete_error')))
            .finally(() => {
                this.setBusy(false);
                done();
            });
    }

    private setBusy(busy: boolean): void {
        setRefreshBusy(this.$el, busy);
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

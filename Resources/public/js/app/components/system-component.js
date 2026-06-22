import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid from 'aaxiscommon/js/app/widgets/data-grid';
import RecordFormModal from 'aaxiscommon/js/app/widgets/record-form-modal';
/**
 * System management page, built on the reusable DataGrid + RecordFormModal widgets.
 */
class OntologySystemComponent extends BaseComponent {
    initialize(options) {
        this.$el = options._sourceElement;
        this.opts = options;
        const actions = [];
        if (options.canUpdate) {
            actions.push({ key: 'edit', label: __('aaxis.common.grid.edit'), icon: 'fa-pencil' });
        }
        if (options.canDelete) {
            actions.push({ key: 'delete', label: __('aaxis.common.grid.delete'), icon: 'fa-trash-o', variant: 'danger' });
        }
        this.grid = new DataGrid({
            columns: [
                { key: 'name', label: __('aaxis.ontology.system.name.label'), type: 'text' },
                { key: 'enabled', label: __('aaxis.ontology.system.enabled.label'), type: 'boolean', width: '160px' }
            ],
            actions,
            gridKey: 'ontology-system',
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', { gridKey: 'ontology-system' }),
            emptyText: __('aaxis.ontology.system_manager.empty'),
            onAction: (action, row) => this.onAction(action, row)
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));
        this.$el.on('click.aaxisOntologySys', '[data-role="add"]', this.onAdd.bind(this));
        this.$el.on('click.aaxisOntologySys', '[data-role="refresh"]', (e) => {
            e.preventDefault();
            this.load();
        });
        this.$el.on('click.aaxisOntologySys', '[data-role="columns-settings"]', (e) => {
            e.preventDefault();
            this.grid.toggleColumnSettings(e.currentTarget);
        });
        this.load();
    }
    load() {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_system_list'), { credentials: 'same-origin' })
            .then(r => r.json())
            .then((data) => this.grid.setRows(data.systems || []))
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.system_manager.load_error')))
            .finally(() => this.setBusy(false));
    }
    onAction(action, row) {
        if (action === 'edit') {
            this.openForm(row);
        }
        else if (action === 'delete') {
            this.remove(row);
        }
    }
    onAdd() {
        this.openForm(null);
    }
    openForm(system) {
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
                { key: 'enabled', label: __('aaxis.ontology.system.enabled.label'), type: 'boolean' }
            ],
            values: system ? { name: system.name, enabled: system.enabled } : { enabled: true },
            onSubmit: values => this.save(system, values)
        });
        modal.open();
    }
    save(system, values) {
        const url = system === null
            ? routing.generate('aaxis_ontology_system_api_create')
            : routing.generate('aaxis_ontology_system_api_update', { id: system.id });
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
    remove(system) {
        // eslint-disable-next-line no-alert
        if (!window.confirm(__('aaxis.ontology.system_manager.confirm_delete', { name: system.name }))) {
            return;
        }
        this.setBusy(true);
        this.apiFetch(routing.generate('aaxis_ontology_system_delete', { id: system.id }), 'DELETE')
            .then(res => {
            if (!res.ok || !res.data || !res.data.successful) {
                messenger.notificationFlashMessage('error', __('aaxis.ontology.system_manager.delete_error'));
                return;
            }
            messenger.notificationFlashMessage('success', __('aaxis.ontology.system_manager.deleted'));
            this.load();
        })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.system_manager.delete_error')))
            .finally(() => this.setBusy(false));
    }
    csrf() {
        const name = window.location.protocol === 'https:' ? 'https-_csrf' : '_csrf';
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }
    apiFetch(url, method, body) {
        const opts = {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Header': this.csrf() }
        };
        if (body !== undefined) {
            opts.body = JSON.stringify(body);
        }
        return fetch(url, opts).then(r => r.json().then(d => ({ ok: r.ok, data: d })));
    }
    setBusy(busy) {
        this.$el.find('[data-role="refresh"]').prop('disabled', busy)
            .find('.fa').toggleClass('fa-spin', busy);
    }
    dispose() {
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

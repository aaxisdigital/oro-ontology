import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid, {GridAction, navigateTo} from 'aaxiscommon/js/app/widgets/data-grid';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';

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
            },
            {
                key: 'export',
                label: __('aaxis.ontology.flow_view.export'),
                icon: 'fa-upload',
                // The two built-in flows are fixture-seeded in every environment: there is nothing
                // to carry across, and they cannot be recreated by an import either (it always
                // builds a user flow).
                disabled: (row: FlowRecord) => row.type === 'native',
                disabledTitle: __('aaxis.ontology.flow_view.export_builtin_disabled')
            },
            {
                key: 'delete',
                label: __('aaxis.common.grid.delete'),
                icon: 'fa-trash-o',
                variant: 'danger',
                // The built-ins carry the Add Data / REST API write paths — not deletable.
                disabled: (row: FlowRecord) => row.type === 'native',
                disabledTitle: __('aaxis.ontology.flow_view.delete_builtin_disabled')
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
            onAction: (action, row, event) => this.onAction(action, row as FlowRecord, event)
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
            navigateTo(routing.generate('aaxis_ontology_flow_editor'), (e.originalEvent || e) as MouseEvent);
        });
        this.$el.on('click.aaxisOntologyFlow', '[data-role="import"]', (e: any) => {
            e.preventDefault();
            this.openImport();
        });

        this.load();
    }

    private onAction(action: string, row: FlowRecord, event?: MouseEvent): void {
        if (action === 'edit' && row.type !== 'native') {
            navigateTo(routing.generate('aaxis_ontology_flow_editor', {id: row.id}), event);
        } else if (action === 'export' && row.type !== 'native') {
            // The grid dispatches disabled actions too, so re-check here (as 'edit' does).
            this.exportFlow(row);
        } else if (action === 'delete' && row.type !== 'native') {
            this.confirmDelete(row);
        }
    }

    /** Delete with confirmation. Events the flow recorded stay (they are history, not the flow). */
    private confirmDelete(row: FlowRecord): void {
        const dialog = new Dialog({title: __('aaxis.ontology.flow_view.delete_title'), width: '520px'});
        const $content = dialog.open();

        const $body = $('<div/>', {'class': 'aaxis-ontology-confirm'});
        $body.append($('<p/>', {
            'class': 'aaxis-ontology-confirm__q',
            text: __('aaxis.ontology.flow_view.confirm_delete', {name: row.name})
        }));
        $body.append($('<p/>', {
            'class': 'aaxis-ontology-confirm__danger',
            text: __('aaxis.ontology.flow_view.delete_warning')
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
            this.doDelete(row, () => dialog.close());
        });
    }

    private doDelete(row: FlowRecord, done: () => void): void {
        this.setBusy(true);
        const name = window.location.protocol === 'https:' ? 'https-_csrf' : '_csrf';
        const match = window.document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        fetch(routing.generate('aaxis_ontology_flow_delete', {id: row.id}), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {'X-CSRF-Header': match ? decodeURIComponent(match[1]) : ''}
        }).then(r => r.json().then(d => ({ok: r.ok, data: d})))
            .then(res => {
                if (!res.ok || !res.data || !(res.data.successful || res.data.success)) {
                    messenger.notificationFlashMessage(
                        'error',
                        (res.data && res.data.message) || __('aaxis.ontology.flow_view.delete_error')
                    );
                    return;
                }
                messenger.notificationFlashMessage('success', __('aaxis.ontology.flow_view.deleted'));
                this.load();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_view.delete_error')))
            .finally(() => {
                this.setBusy(false);
                done();
            });
    }

    /** Downloads the flow as a portable JSON document (connector ids become name/type refs). */
    private exportFlow(row: FlowRecord): void {
        fetch(routing.generate('aaxis_ontology_flow_export', {id: row.id}), {credentials: 'same-origin'})
            .then(r => r.json().then(d => ({ok: r.ok, data: d})))
            .then(res => {
                if (!res.ok || !res.data || !res.data.success) {
                    const errors: string[] = (res.data && res.data.errors) || [];
                    throw new Error(errors[0] || __('aaxis.ontology.flow_view.export_error'));
                }
                this.saveJsonFile(
                    JSON.stringify(res.data.document, null, 2),
                    String(res.data.filename || 'flow.json')
                );
            })
            .catch((err: Error) => messenger.notificationFlashMessage(
                'error', err.message || __('aaxis.ontology.flow_view.export_error')
            ));
    }

    /** Hands the browser a file to save (same approach as the DWL playground's export). */
    private saveJsonFile(content: string, filename: string): void {
        const blob = new Blob([content], {type: 'application/json;charset=utf-8'});
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        // Revoke on the next tick — Safari needs the URL alive for the duration of the click.
        window.setTimeout(() => window.URL.revokeObjectURL(url), 0);
    }

    /**
     * Import dialog: pick a previously exported file, send its text to the server, and show every
     * problem it reports (bad format, name already taken, connectors/entities that do not match
     * here). A successful import lands as a DISABLED flow, so nothing runs until it is reviewed.
     */
    private openImport(): void {
        const dialog = new Dialog({
            title: __('aaxis.ontology.flow_view.import_title'),
            subtitle: __('aaxis.ontology.flow_view.import_hint'),
            width: '560px'
        });
        const $content = dialog.open();

        const $errors = $('<div/>', {'class': 'aaxis-rfm__alert', role: 'alert', hidden: 'hidden'});
        const $file = $('<input/>', {type: 'file', accept: 'application/json,.json', 'class': 'form-control'});
        const $field = $('<div/>', {'class': 'aaxis-rfm__field'}).append(
            $('<label/>', {'class': 'aaxis-rfm__label', text: __('aaxis.ontology.flow_view.import_file')}),
            $file
        );

        const $actions = $('<div/>', {'class': 'aaxis-ontology-confirm__actions'});
        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', text: __('aaxis.ontology.flow_editor.cancel')});
        const $submit = $('<button/>', {
            type: 'button', 'class': 'btn btn-primary', disabled: true,
            text: __('aaxis.ontology.flow_view.import_button')
        });
        $actions.append($cancel, $submit);
        $content.append($errors, $field, $actions);

        $file.on('change', () => {
            $errors.attr('hidden', 'hidden').empty();
            $submit.prop('disabled', !($file[0] as HTMLInputElement).files?.length);
        });
        $cancel.on('click', () => dialog.close());
        $submit.on('click', () => {
            const file = ($file[0] as HTMLInputElement).files?.[0];
            if (!file) {
                return;
            }
            $submit.prop('disabled', true);
            const reader = new FileReader();
            reader.onerror = () => {
                this.showImportErrors($errors, [__('aaxis.ontology.flow_view.import_read_error')]);
                $submit.prop('disabled', false);
            };
            reader.onload = () => {
                this.sendImport(String(reader.result || ''), dialog, $errors, $submit);
                // Clear the picker: re-selecting the SAME file fires no change event otherwise,
                // which would strand the user on a dialog that stays open after an error.
                $file.val('');
                $submit.prop('disabled', true);
            };
            reader.readAsText(file);
        });
    }

    private sendImport(document: string, dialog: Dialog, $errors: any, $submit: any): void {
        const name = window.location.protocol === 'https:' ? 'https-_csrf' : '_csrf';
        const match = window.document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        fetch(routing.generate('aaxis_ontology_flow_import'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': match ? decodeURIComponent(match[1]) : ''},
            body: JSON.stringify({document})
        }).then(r => r.json().then(d => ({ok: r.ok, data: d}))).then(res => {
            if (!res.ok || !res.data || !res.data.success) {
                this.showImportErrors($errors, (res.data && res.data.errors) || [__('aaxis.ontology.flow_view.import_error')]);
                $submit.prop('disabled', false);
                return;
            }
            messenger.notificationFlashMessage('success', __('aaxis.ontology.flow_view.imported'));
            dialog.close();
            this.load();
        }).catch(() => {
            this.showImportErrors($errors, [__('aaxis.ontology.flow_view.import_error')]);
            $submit.prop('disabled', false);
        });
    }

    private showImportErrors($errors: any, messages: string[]): void {
        const $list = $('<ul/>', {'class': 'aaxis-flow-import__errors'});
        messages.forEach(message => $list.append($('<li/>', {text: String(message)})));
        $errors.empty().append($list).removeAttr('hidden');
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

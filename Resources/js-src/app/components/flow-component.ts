import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid, {GridAction, navigateTo} from 'aaxiscommon/js/app/widgets/data-grid';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import {bindGridToolbar, csrfToken, formatDateTime, setRefreshBusy} from './component-support';

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
 * "Add Flow" opens a blank editor and CLICKING ANYWHERE ON A ROW opens that flow's editor
 * (no separate edit action) — except the two built-in (type = native) flows.
 *
 * Each editable row carries a checkbox at the start of its Name cell (inside the cell rather than
 * as a column of its own: the grid appends columns unknown to a SAVED layout at the end, and the
 * box belongs first). "Open all" opens every checked flow together in one editor, a tab each, in
 * the order they were checked.
 */
class OntologyFlowComponent extends BaseComponent {
    private $el!: any;
    private grid!: DataGrid;
    /** Ids of the checked flows, in the order they were checked (= the editor's tab order). */
    private picked!: number[];

    initialize(options: OntologyFlowOptions): void {
        this.$el = options._sourceElement;
        this.picked = [];

        const actions: GridAction[] = [
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
                {
                    key: 'name', label: __('aaxis.ontology.flow_view.name'), type: 'text',
                    render: (row: FlowRecord) => this.nameCell(row)
                },
                {
                    key: 'type', label: __('aaxis.ontology.flow_view.type'), type: 'text', width: '120px',
                    render: (row: FlowRecord) => __('aaxis.ontology.flow_view.type_' + (row.type || 'subflow'))
                },
                {key: 'enabled', label: __('aaxis.ontology.flow_view.enabled'), type: 'boolean', width: '120px'},
                {
                    key: 'lastExecuted', label: __('aaxis.ontology.flow_view.last_executed'), type: 'datetime',
                    width: '190px', render: (row: FlowRecord) => formatDateTime(row.lastExecuted)
                },
                {
                    key: 'lastFinished', label: __('aaxis.ontology.flow_view.last_finished'), type: 'datetime',
                    width: '190px',
                    // While a run is in flight there is no finish time yet — say so rather than
                    // showing an empty cell that reads like "never ran".
                    render: (row: FlowRecord) => row.running
                        ? __('aaxis.ontology.flow_view.running')
                        : formatDateTime(row.lastFinished)
                },
                {
                    key: 'lastModified', label: __('aaxis.ontology.flow_view.last_modified'), type: 'datetime',
                    width: '190px', render: (row: FlowRecord) => formatDateTime(row.lastModified)
                }
            ],
            actions,
            gridKey: 'ontology-flow-view',
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', {gridKey: 'ontology-flow-view'}),
            emptyText: __('aaxis.ontology.flow_view.empty'),
            onAction: (action, row, event) => this.onAction(action, row as FlowRecord, event),
            // The whole row opens the editor (there is no separate edit action) — except the two
            // built-in flows, which are not editable.
            onRowClick: (row, event) => {
                if ((row as FlowRecord).type !== 'native') {
                    navigateTo(routing.generate('aaxis_ontology_flow_editor', {id: (row as FlowRecord).id}), event);
                }
            }
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));

        bindGridToolbar(this.$el, 'aaxisOntologyFlow', () => this.load(), () => this.grid);
        this.$el.on('click.aaxisOntologyFlow', '[data-role="add"]', (e: any) => {
            e.preventDefault();
            navigateTo(routing.generate('aaxis_ontology_flow_editor'), (e.originalEvent || e) as MouseEvent);
        });
        this.$el.on('click.aaxisOntologyFlow', '[data-role="import"]', (e: any) => {
            e.preventDefault();
            this.openImport();
        });
        this.$el.on('change.aaxisOntologyFlow', '[data-role="flow-pick"]', (e: any) => {
            const id = Number($(e.currentTarget).data('flowId'));
            this.picked = this.picked.filter(picked => picked !== id);
            if ($(e.currentTarget).is(':checked')) {
                this.picked.push(id);
            }
            this.syncOpenSelected();
        });
        this.$el.on('click.aaxisOntologyFlow', '[data-role="open-selected"]', (e: any) => {
            e.preventDefault();
            this.openPicked((e.originalEvent || e) as MouseEvent);
        });
        this.syncOpenSelected();

        this.load();
    }

    /**
     * The Name cell: the row's pick checkbox, then the name. Built-in flows cannot be edited, so
     * they get an empty slot of the same width instead (names stay aligned).
     */
    private nameCell(row: FlowRecord): any {
        const name = row.name || '';
        const $name = $('<span/>', {'class': 'aaxis-flow-pick__name', text: name, title: name});
        if (row.type === 'native') {
            return $('<span/>', {'class': 'aaxis-flow-pick'}).append(
                $('<span/>', {'class': 'aaxis-flow-pick__slot', 'aria-hidden': 'true'}),
                $name
            );
        }
        const label = __('aaxis.ontology.flow_view.pick');
        const $box = $('<input/>', {
            type: 'checkbox',
            'class': 'aaxis-flow-pick__box no-uniform',
            'data-role': 'flow-pick',
            'data-flow-id': row.id,
            title: label,
            'aria-label': label
        }).prop('checked', this.picked.includes(row.id));
        // A <label>: the row's click-to-open ignores clicks on labels and inputs.
        return $('<span/>', {'class': 'aaxis-flow-pick'}).append(
            $('<label/>', {'class': 'aaxis-flow-pick__slot'}).append($box),
            $name
        );
    }

    /** "Open all" is available while at least one flow is checked, and counts them. */
    private syncOpenSelected(): void {
        this.$el.find('[data-role="open-selected"]').prop('disabled', this.picked.length === 0);
        this.$el.find('[data-role="open-selected-count"]').text(this.picked.length ? `(${this.picked.length})` : '');
    }

    /**
     * One editor holding every checked flow: the path opens the first and `?tabs=` lists them all,
     * which the editor reopens as tabs in this order (its URL format for a restored session).
     */
    private openPicked(event?: MouseEvent): void {
        if (!this.picked.length) {
            return;
        }
        const params: Record<string, string | number> = {id: this.picked[0]};
        if (this.picked.length > 1) {
            params.tabs = this.picked.join(',');
        }
        navigateTo(routing.generate('aaxis_ontology_flow_editor', params), event);
    }

    private onAction(action: string, row: FlowRecord, event?: MouseEvent): void {
        // The grid dispatches disabled actions too, so re-check the built-in guard here.
        if (action === 'export' && row.type !== 'native') {
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
        fetch(routing.generate('aaxis_ontology_flow_delete', {id: row.id}), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {'X-CSRF-Header': csrfToken()}
        }).then(r => r.json().then(d => ({ok: r.ok, data: d})))
            .then(res => {
                if (!res.ok || !res.data || !(res.data.successful || res.data.success)) {
                    messenger.notificationFlashMessage(
                        'error',
                        res.data?.message || __('aaxis.ontology.flow_view.delete_error')
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
                if (!res.ok || !res.data?.success) {
                    const errors: string[] = res.data?.errors || [];
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
            file.text().then(text => {
                this.sendImport(text, dialog, $errors, $submit);
                // Clear the picker: re-selecting the SAME file fires no change event otherwise,
                // which would strand the user on a dialog that stays open after an error.
                $file.val('');
                $submit.prop('disabled', true);
            }, () => {
                this.showImportErrors($errors, [__('aaxis.ontology.flow_view.import_read_error')]);
                $submit.prop('disabled', false);
            });
        });
    }

    private sendImport(document: string, dialog: Dialog, $errors: any, $submit: any): void {
        fetch(routing.generate('aaxis_ontology_flow_import'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': csrfToken()},
            body: JSON.stringify({document})
        }).then(r => r.json().then(d => ({ok: r.ok, data: d}))).then(res => {
            if (!res.ok || !res.data?.success) {
                this.showImportErrors($errors, res.data?.errors || [__('aaxis.ontology.flow_view.import_error')]);
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
                const records = data.records || [];
                // A checked flow that is gone (deleted elsewhere) can no longer be opened.
                const editable = new Set(records.filter(r => r.type !== 'native').map(r => r.id));
                this.picked = this.picked.filter(id => editable.has(id));
                this.syncOpenSelected();
                this.grid.setRows(records);
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_view.load_error')))
            .finally(() => this.setBusy(false));
    }

    private setBusy(busy: boolean): void {
        setRefreshBusy(this.$el, busy);
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

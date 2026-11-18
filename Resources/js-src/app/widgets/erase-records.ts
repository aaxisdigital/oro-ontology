import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import {apiFetch, formatDateTime} from '../components/component-support';

export interface EraseRecordsOptions {
    entityId: number;
    /** Shown in the question line. */
    entityLabel: string;
    /** The payload attributes the filter can compare (dotted paths for nested ones). */
    attributes: string[];
    /** After a successful erase (the dialog has closed). */
    onErased: (deleted: number) => void;
}

/** The comparisons offered, in display order (labels: `aaxis.ontology.erase.op.<op>`). */
export const ERASE_OPERATORS = [
    'eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'contains', 'not_contains', 'matches', 'not_matches', 'empty', 'not_empty'
] as const;

/** Comparisons that take no value. */
const VALUELESS = new Set<string>(['empty', 'not_empty']);

const PAGE_SIZE = 10;
const VALUE_DEBOUNCE_MS = 350;

/** What the server receives: everything, or one attribute condition. */
interface Selection {
    mode: 'all' | 'filter';
    attribute?: string;
    operator?: string;
    value?: string;
}

interface PreviewRecord {
    uniqueId: string;
    version: number;
    updatedAt: string | null;
    payload: unknown;
}

/**
 * The Entities grid's "Erase" popup: erase an entity's stored records — ALL of them, or those
 * whose attribute matches one comparison (= ≠ < ≤ > ≥, contains, wildcard matches, empty, and
 * their negations) — together with their version history.
 *
 * The selection is COUNTED on every change (a typed value after a short pause) and the Erase
 * button names that count; a paginated PREVIEW of the selected records is available on demand.
 * The server applies the very same selection when erasing ({@see RecordFilter} on the PHP side),
 * so what is previewed is what goes. Stale answers (the selection changed meanwhile) are dropped.
 */
export default class EraseRecordsDialog {
    private readonly opts: EraseRecordsOptions;
    private dialog: Dialog | null = null;
    private $root: any = null;
    /** Bumped on every selection change; an answer for an older one is ignored. */
    private generation = 0;
    private total: number | null = null;
    private previewOpen = false;
    private page = 1;
    private erasing = false;
    private debounce: number | null = null;

    constructor(options: EraseRecordsOptions) {
        this.opts = options;
    }

    open(): void {
        this.dialog = new Dialog({
            title: __('aaxis.ontology.erase.title'),
            width: '760px',
            bodyClass: 'aaxis-erase-host',
            onClose: () => this.cancelDebounce()
        });
        const $content = this.dialog.open();
        this.$root = this.build();
        $content.append(this.$root);
        this.syncFilterControls();
        this.refresh();
    }

    // --- Markup -------------------------------------------------------------------------------

    private build(): any {
        const t = (key: string, params?: Record<string, unknown>): string => __(`aaxis.ontology.erase.${key}`, params);

        const radio = (mode: Selection['mode'], checked: boolean): any => $('<label/>', {'class': 'aaxis-erase__mode'}).append(
            $('<input/>', {type: 'radio', name: 'aaxis-erase-mode', value: mode, 'data-role': 'erase-mode'}).prop('checked', checked),
            $('<span/>', {text: t(`mode_${mode}`)})
        );

        const $attribute = $('<select/>', {'class': 'form-control no-uniform', 'data-role': 'erase-attribute', 'aria-label': t('attribute')});
        this.opts.attributes.forEach(name => $attribute.append($('<option/>', {value: name, text: name})));
        const $operator = $('<select/>', {'class': 'form-control no-uniform', 'data-role': 'erase-operator', 'aria-label': t('operator')});
        ERASE_OPERATORS.forEach(op => $operator.append($('<option/>', {value: op, text: t(`op.${op}`)})));
        const $value = $('<input/>', {
            type: 'text', 'class': 'form-control', 'data-role': 'erase-value', autocomplete: 'off', spellcheck: false,
            placeholder: t('value_placeholder'), 'aria-label': t('value_placeholder')
        });

        const $root = $('<div/>', {'class': 'aaxis-erase'}).append(
            $('<p/>', {'class': 'aaxis-erase__question', text: t('question', {name: this.opts.entityLabel})}),
            $('<div/>', {'class': 'aaxis-erase__modes'}).append(radio('all', true), radio('filter', false)),
            $('<div/>', {'class': 'aaxis-erase__filter', 'data-role': 'erase-filter'}).append($attribute, $operator, $value),
            $('<p/>', {'class': 'aaxis-erase__hint', 'data-role': 'erase-hint', text: t('matches_hint'), hidden: true}),
            $('<div/>', {'class': 'aaxis-erase__summary'}).append(
                $('<span/>', {'class': 'aaxis-erase__count', 'data-role': 'erase-count', 'aria-live': 'polite'}),
                $('<button/>', {type: 'button', 'class': 'btn btn-sm', 'data-role': 'erase-preview-toggle', text: t('preview_show'), disabled: true})
            ),
            $('<div/>', {'class': 'aaxis-erase__preview', 'data-role': 'erase-preview', hidden: true}),
            $('<p/>', {'class': 'aaxis-ontology-confirm__danger', text: t('warning')}),
            $('<div/>', {'class': 'aaxis-ontology-confirm__actions'}).append(
                $('<button/>', {type: 'button', 'class': 'btn', 'data-role': 'erase-cancel', text: __('Cancel')}),
                $('<button/>', {type: 'button', 'class': 'btn aaxis-ontology-confirm__delete', 'data-role': 'erase-confirm', text: t('confirm_none'), disabled: true})
            )
        );

        $root.on('change', '[data-role="erase-mode"], [data-role="erase-attribute"], [data-role="erase-operator"]', () => {
            this.syncFilterControls();
            this.selectionChanged(false);
        });
        $root.on('input', '[data-role="erase-value"]', () => this.selectionChanged(true));
        $root.on('click', '[data-role="erase-preview-toggle"]', () => this.togglePreview());
        $root.on('click', '[data-role="erase-page"]', (e: any) => this.loadPage(Number($(e.currentTarget).data('page'))));
        $root.on('click', '[data-role="erase-cancel"]', () => this.dialog?.close());
        $root.on('click', '[data-role="erase-confirm"]', () => this.erase());

        return $root;
    }

    /** The filter row is live only in filter mode; the value box only for comparisons that take one. */
    private syncFilterControls(): void {
        const filter = this.mode() === 'filter';
        const operator = String(this.$root.find('[data-role="erase-operator"]').val());
        this.$root.find('[data-role="erase-attribute"], [data-role="erase-operator"]').prop('disabled', !filter);
        this.$root.find('[data-role="erase-value"]').prop('disabled', !filter || VALUELESS.has(operator))
            .prop('hidden', VALUELESS.has(operator));
        this.$root.find('[data-role="erase-filter"]').toggleClass('is-disabled', !filter);
        this.$root.find('[data-role="erase-hint"]').prop('hidden', !filter || !operator.endsWith('matches'));
    }

    // --- Selection ----------------------------------------------------------------------------

    private mode(): Selection['mode'] {
        return this.$root.find('[data-role="erase-mode"]:checked').val() === 'filter' ? 'filter' : 'all';
    }

    private selection(): Selection {
        if (this.mode() === 'all') {
            return {mode: 'all'};
        }
        const operator = String(this.$root.find('[data-role="erase-operator"]').val());

        return {
            mode: 'filter',
            attribute: String(this.$root.find('[data-role="erase-attribute"]').val() || ''),
            operator,
            value: VALUELESS.has(operator) ? '' : String(this.$root.find('[data-role="erase-value"]').val() || '')
        };
    }

    /** Why the selection cannot be counted yet (client-side, before asking the server). */
    private incompleteReason(selection: Selection): string | null {
        if (selection.mode === 'all') {
            return null;
        }
        if (!selection.attribute) {
            return __('aaxis.ontology.erase.no_attributes');
        }
        if (!VALUELESS.has(String(selection.operator)) && selection.value === '') {
            return __('aaxis.ontology.erase.value_required');
        }

        return null;
    }

    /** Typing waits for a pause; a picked mode/attribute/comparison applies at once. */
    private selectionChanged(debounced: boolean): void {
        this.cancelDebounce();
        this.generation++;
        this.setTotal(null, __('aaxis.ontology.erase.counting'));
        if (debounced) {
            this.debounce = window.setTimeout(() => {
                this.debounce = null;
                this.refresh();
            }, VALUE_DEBOUNCE_MS);
        } else {
            this.refresh();
        }
    }

    private cancelDebounce(): void {
        if (this.debounce !== null) {
            window.clearTimeout(this.debounce);
            this.debounce = null;
        }
    }

    /** Re-counts the current selection — through the preview when it is open (one request). */
    private refresh(): void {
        this.page = 1;
        if (this.previewOpen) {
            this.loadPage(1);
            return;
        }
        const selection = this.selection();
        const incomplete = this.incompleteReason(selection);
        if (incomplete !== null) {
            this.setTotal(null, incomplete);
            return;
        }
        const generation = ++this.generation;
        this.setTotal(null, __('aaxis.ontology.erase.counting'));
        this.request(selection, false).then(res => {
            if (generation !== this.generation) {
                return;
            }
            this.applyAnswer(res);
        });
    }

    private request(selection: Selection, rows: boolean, page = 1): Promise<{ok: boolean; data: any}> {
        const params: Record<string, string | number> = {id: this.opts.entityId, mode: selection.mode};
        if (selection.mode === 'filter') {
            params.attribute = selection.attribute || '';
            params.operator = selection.operator || '';
            params.value = selection.value || '';
        }
        if (rows) {
            params.rows = 1;
            params.page = page;
            params.pageSize = PAGE_SIZE;
        }

        return fetch(routing.generate('aaxis_ontology_entity_erase_preview', params), {credentials: 'same-origin'})
            .then(r => r.json().then((data: any) => ({ok: r.ok, data})))
            .catch(() => ({ok: false, data: null}));
    }

    /** Takes a count/preview answer: the total, or the server's reason the selection is invalid. */
    private applyAnswer(res: {ok: boolean; data: any}): boolean {
        if (!res.ok || res.data?.success !== true) {
            this.setTotal(null, res.data?.message || __('aaxis.ontology.erase.count_error'), true);
            return false;
        }
        const total = Number(res.data.total) || 0;
        this.setTotal(total, __('aaxis.ontology.erase.selected', {count: String(total)}));

        return true;
    }

    /** The count line + the buttons that depend on it (null = not known). */
    private setTotal(total: number | null, text: string, isError = false): void {
        this.total = total;
        this.$root.find('[data-role="erase-count"]').text(text).toggleClass('is-error', isError);
        this.$root.find('[data-role="erase-confirm"]')
            .prop('disabled', this.erasing || !total)
            .text(total ? __('aaxis.ontology.erase.confirm', {count: String(total)}) : __('aaxis.ontology.erase.confirm_none'));
        this.$root.find('[data-role="erase-preview-toggle"]').prop('disabled', !this.previewOpen && !total);
    }

    // --- Preview ------------------------------------------------------------------------------

    private togglePreview(): void {
        this.previewOpen = !this.previewOpen;
        this.$root.find('[data-role="erase-preview-toggle"]')
            .text(__(this.previewOpen ? 'aaxis.ontology.erase.preview_hide' : 'aaxis.ontology.erase.preview_show'));
        const $preview = this.$root.find('[data-role="erase-preview"]');
        if (!this.previewOpen) {
            $preview.prop('hidden', true).empty();
            this.setTotal(this.total, this.$root.find('[data-role="erase-count"]').text());
            return;
        }
        $preview.prop('hidden', false);
        this.loadPage(this.page);
    }

    private loadPage(page: number): void {
        const selection = this.selection();
        const incomplete = this.incompleteReason(selection);
        const $preview = this.$root.find('[data-role="erase-preview"]');
        if (incomplete !== null) {
            this.setTotal(null, incomplete);
            $preview.empty();
            return;
        }
        const generation = ++this.generation;
        this.page = page;
        $preview.addClass('is-loading');
        this.request(selection, true, page).then(res => {
            if (generation !== this.generation || !this.previewOpen) {
                return;
            }
            $preview.removeClass('is-loading');
            if (this.applyAnswer(res)) {
                this.renderPreview(res.data.records || [], Number(res.data.page) || page);
            } else {
                $preview.empty();
            }
        });
    }

    private renderPreview(records: PreviewRecord[], page: number): void {
        const $preview = this.$root.find('[data-role="erase-preview"]').empty();
        if (!records.length) {
            $preview.append($('<p/>', {'class': 'aaxis-erase__empty', text: __('aaxis.ontology.erase.preview_empty')}));
            return;
        }
        const t = (key: string): string => __(`aaxis.ontology.erase.col_${key}`);
        const $tbody = $('<tbody/>');
        records.forEach(record => {
            const json = JSON.stringify(record.payload);
            const pretty = JSON.stringify(record.payload, null, 2) || '';
            $tbody.append($('<tr/>').append(
                $('<td/>', {text: record.uniqueId, title: record.uniqueId}),
                $('<td/>', {'class': 'aaxis-erase__num', text: String(record.version)}),
                $('<td/>', {text: formatDateTime(record.updatedAt)}),
                $('<td/>', {'class': 'aaxis-erase__payload'}).append(
                    $('<code/>', {text: json ?? 'null', title: pretty.length > 2000 ? pretty.slice(0, 2000) + '…' : pretty})
                )
            ));
        });
        $preview.append($('<div/>', {'class': 'aaxis-erase__table-wrap'}).append(
            $('<table/>', {'class': 'aaxis-erase__table'}).append(
                $('<thead/>').append($('<tr/>').append(
                    $('<th/>', {text: t('unique_id')}),
                    $('<th/>', {'class': 'aaxis-erase__num', text: t('version')}),
                    $('<th/>', {text: t('updated')}),
                    $('<th/>', {text: t('payload')})
                )),
                $tbody
            )
        ));

        const pages = Math.max(1, Math.ceil((this.total || 0) / PAGE_SIZE));
        const pageButton = (target: number, icon: string, key: string, disabled: boolean): any => $('<button/>', {
            type: 'button', 'class': 'btn btn-sm', 'data-role': 'erase-page', 'data-page': target, disabled,
            title: __(`aaxis.ontology.erase.${key}`), 'aria-label': __(`aaxis.ontology.erase.${key}`)
        }).append($('<span/>', {'class': `fa ${icon}`, 'aria-hidden': 'true'}));
        $preview.append($('<div/>', {'class': 'aaxis-erase__pager'}).append(
            pageButton(page - 1, 'fa-chevron-left', 'page_prev', page <= 1),
            $('<span/>', {'data-role': 'erase-page-info', text: __('aaxis.ontology.erase.page_of', {page: String(page), pages: String(pages)})}),
            pageButton(page + 1, 'fa-chevron-right', 'page_next', page >= pages)
        ));
    }

    // --- Erase --------------------------------------------------------------------------------

    private erase(): void {
        if (this.erasing || !this.total) {
            return;
        }
        this.erasing = true;
        const $confirm = this.$root.find('[data-role="erase-confirm"]').prop('disabled', true);
        $confirm.prepend($('<span/>', {'class': 'fa fa-spinner fa-spin aaxis-erase__spinner', 'aria-hidden': 'true'}));
        apiFetch(routing.generate('aaxis_ontology_entity_erase_records', {id: this.opts.entityId}), 'POST', this.selection())
            .catch(() => ({ok: false, data: null}))
            .then(res => {
                this.erasing = false;
                $confirm.find('.aaxis-erase__spinner').remove();
                if (!res.ok || res.data?.success !== true) {
                    const message = res.data?.message || __('aaxis.ontology.entity_manager.purge_error');
                    this.setTotal(this.total, message, true);
                    messenger.notificationFlashMessage('error', message);
                    return;
                }
                const deleted = Number(res.data.deleted) || 0;
                messenger.notificationFlashMessage('success', __('aaxis.ontology.entity_manager.purged', {count: String(deleted)}));
                this.dialog?.close();
                this.opts.onErased(deleted);
            });
    }
}

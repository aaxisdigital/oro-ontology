import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import Select2View from 'oroform/js/app/views/select2-view';
import 'jquery.select2';
import BaseComponent from 'oroui/js/app/components/base/component';
import DataGrid from 'aaxiscommon/js/app/widgets/data-grid';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';

interface OntologyDataViewOptions {
    _sourceElement: any;
    canCreate?: boolean;
}

interface DataRecord {
    id: number;
    system: string | null;
    entity: string | null;
    uniqueId: string | null;
    uuid: string | null;
    version: number | null;
    payload: string;
    updatedAt: string | null;
}

interface SystemRef {
    id: number;
    name: string;
}

interface EntityRef {
    id: number;
    name: string;
    systemId: number | null;
    uniqueAttribute: string | null;
}

interface VersionEntry {
    version: number | null;
    uuid: string | null;
    updatedAt: string | null;
    current: boolean;
    payload: any;
}

/**
 * Data View page built on the reusable DataGrid widget, with an "Add Data" form.
 */
class OntologyDataViewComponent extends BaseComponent {
    private $el!: any;
    private grid!: DataGrid;
    private systems: SystemRef[] = [];
    private entities: EntityRef[] = [];
    private addDialog: Dialog | null = null;
    private systemSelect2: any = null;
    private entitySelect2: any = null;
    private formatSelect2: any = null;
    private addSyncing = false;
    private addUniqueAttribute = '';
    private $addError: any = null;
    private $addEntity: any = null;
    private $addFormat: any = null;
    private $addPayload: any = null;
    private $addPayloadError: any = null;
    private $addSubmit: any = null;

    initialize(options: OntologyDataViewOptions): void {
        this.$el = options._sourceElement;

        this.grid = new DataGrid({
            columns: [
                {key: 'system', label: __('aaxis.ontology.data_view.system'), type: 'text'},
                {key: 'entity', label: __('aaxis.ontology.data_view.entity'), type: 'text'},
                {key: 'uniqueId', label: __('aaxis.ontology.data_view.unique_id'), type: 'text'},
                {key: 'uuid', label: __('aaxis.ontology.data_view.uuid'), type: 'text', hidden: true},
                {key: 'version', label: __('aaxis.ontology.data_view.version'), type: 'number', width: '110px', hidden: true},
                {
                    key: 'updatedAt', label: __('aaxis.ontology.data_view.updated_at'), type: 'datetime',
                    width: '190px', render: (row: DataRecord) => this.renderDate(row.updatedAt)
                },
                {
                    key: 'payload', label: __('aaxis.ontology.data_view.payload'), type: 'json',
                    sortable: false, copyValue: (row: DataRecord) => String(row.payload || ''),
                    render: (row: DataRecord) => this.renderPayload(row)
                }
            ],
            actions: [
                {key: 'versions', label: __('aaxis.ontology.data_view.versions'), icon: 'fa-clone'}
            ],
            gridKey: 'ontology-data-view',
            preferencesUrl: routing.generate('aaxis_common_grid_preference_get', {gridKey: 'ontology-data-view'}),
            emptyText: __('aaxis.ontology.data_view.empty'),
            onAction: (action, row) => this.onAction(action, row as DataRecord)
        });
        this.grid.mount(this.$el.find('[data-role="list"]'));

        this.$el.on('click.aaxisOntologyData', '[data-role="refresh"]', (e: any) => {
            e.preventDefault();
            this.load();
        });
        this.$el.on('click.aaxisOntologyData', '[data-role="columns-settings"]', (e: any) => {
            e.preventDefault();
            this.grid.toggleColumnSettings(e.currentTarget);
        });
        this.$el.on('click.aaxisOntologyData', '[data-role="add"]', (e: any) => {
            e.preventDefault();
            this.openAddData();
        });

        this.load();
    }

    private onAction(action: string, row: DataRecord): void {
        if (action === 'versions') {
            this.openVersions(row);
        }
    }

    private openVersions(row: DataRecord): void {
        const dialog = new Dialog({
            title: __('aaxis.ontology.data_view.versions_title'),
            subtitle: [row.entity, row.uniqueId].filter(part => !!part).join(' — ') || undefined,
            width: '720px'
        });
        const $content = dialog.open();

        const $loading = $('<p/>', {
            'class': 'text-muted', text: __('aaxis.ontology.data_view.versions_loading')
        });
        $content.append($loading);

        fetch(routing.generate('aaxis_ontology_data_versions', {id: row.id}), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {versions?: VersionEntry[]}) => {
                $loading.remove();
                this.renderVersions($content, data.versions || []);
            })
            .catch(() => {
                $loading.text(__('aaxis.ontology.data_view.versions_load_error'));
            });
    }

    /**
     * Builds the versions dialog body: a combobox listing every version (newest first) followed by
     * the JSON view. Selecting an older version reconstructs how the record looked back then,
     * highlighting in yellow the values that differ from the current version and striking through
     * the keys that were not present yet.
     */
    private renderVersions($content: any, versions: VersionEntry[]): void {
        if (versions.length === 0) {
            $content.append($('<p/>', {'class': 'text-muted', text: __('aaxis.ontology.data_view.empty')}));
            return;
        }

        // Fixed-height flex layout (see ontology.scss .aaxis-json-dialog) so the version picker,
        // search toolbar and copy button stay visible while only the JSON view scrolls.
        $content.addClass('aaxis-json-dialog');

        // Version combobox (decreasing order, "version - updated at - uuid").
        const $select = $('<select/>', {'class': 'form-control aaxis-json-version__select', 'data-role': 'version'});
        versions.forEach((v, index) => {
            const label = [
                'v' + v.version,
                this.renderDate(v.updatedAt),
                v.uuid || ''
            ].filter(part => part !== '').join(' — ') + (v.current ? '  (' + __('aaxis.ontology.data_view.version_current') + ')' : '');
            $select.append($('<option/>', {value: String(index), text: label}));
        });
        const $selectField = $('<div/>', {'class': 'aaxis-json-version'}).append(
            $('<label/>', {'class': 'aaxis-json-version__label', text: __('aaxis.ontology.data_view.version')}),
            $select
        );

        // Search toolbar (same behaviour as the payload preview).
        const $search = $('<input/>', {
            type: 'search', 'class': 'form-control aaxis-json-search__input',
            placeholder: __('aaxis.ontology.data_view.search_placeholder'), spellcheck: false, autocomplete: 'off'
        });
        const $count = $('<span/>', {'class': 'aaxis-json-search__count'});
        const $prev = $('<button/>', {
            type: 'button', 'class': 'aaxis-json-search__nav', title: __('aaxis.ontology.data_view.search_prev'),
            'aria-label': __('aaxis.ontology.data_view.search_prev')
        }).append($('<span/>', {'class': 'fa fa-chevron-up', 'aria-hidden': 'true'}));
        const $next = $('<button/>', {
            type: 'button', 'class': 'aaxis-json-search__nav', title: __('aaxis.ontology.data_view.search_next'),
            'aria-label': __('aaxis.ontology.data_view.search_next')
        }).append($('<span/>', {'class': 'fa fa-chevron-down', 'aria-hidden': 'true'}));
        const $searchGroup = $('<div/>', {'class': 'aaxis-json-search'}).append($search, $count, $prev, $next);
        const $toolbar = $('<div/>', {'class': 'aaxis-json-toolbar'}).append($searchGroup);

        const $pre = $('<pre/>', {'class': 'aaxis-json-view'});

        const $copy = $('<button/>', {type: 'button', 'class': 'btn btn-sm aaxis-json-copy'}).append(
            $('<span/>', {'class': 'fa fa-clipboard', 'aria-hidden': 'true'}),
            $('<span/>', {text: ' ' + __('aaxis.ontology.data_view.copy')})
        );
        const $footer = $('<div/>', {'class': 'aaxis-json-footer'}).append($copy);

        $content.append($selectField, $toolbar, $pre, $footer);

        // --- Rendering + search state ---------------------------------------
        let baseHtml = '';
        let plainText = '';
        let current_match = 0;

        const setCurrent = (index: number): void => {
            const $marks = $pre.find('.aaxis-json-mark');
            if ($marks.length === 0) {
                $count.text('');
                return;
            }
            current_match = (index + $marks.length) % $marks.length;
            $marks.removeClass('is-current');
            const $cur = $marks.eq(current_match).addClass('is-current');
            $count.text((current_match + 1) + ' / ' + $marks.length);
            const el = $cur.get(0);
            if (el && el.scrollIntoView) {
                el.scrollIntoView({block: 'nearest'});
            }
        };
        const runSearch = (): void => {
            const query = String($search.val() || '');
            const matches = this.highlightSearch($pre, baseHtml, query);
            current_match = 0;
            if (matches > 0) {
                setCurrent(0);
            } else {
                $count.text(query === '' ? '' : '0 / 0');
            }
        };

        const renderSelected = (): void => {
            const idx = Number($select.val()) || 0;
            const selected = versions[idx];
            const selectedPayload = selected.payload || {};
            plainText = JSON.stringify(selectedPayload, null, 2);
            // In the DESC-ordered list, the immediately previous (older) version is the next index.
            const previous = versions[idx + 1];
            if (!previous) {
                // Oldest version: no previous to compare against, so no markers.
                baseHtml = this.highlightJson(plainText);
            } else {
                baseHtml = this.renderVersionDiffHtml(selectedPayload, previous.payload || {});
            }
            $pre.html(baseHtml);
            runSearch();
        };

        $select.on('change', () => renderSelected());
        $search.on('input', () => runSearch());
        $search.on('keydown', (e: any) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                setCurrent(current_match + (e.shiftKey ? -1 : 1));
            }
        });
        $prev.on('click', () => setCurrent(current_match - 1));
        $next.on('click', () => setCurrent(current_match + 1));
        $copy.on('click', () => this.copyToClipboard(plainText));

        renderSelected();
    }

    /**
     * Renders the selected snapshot as pretty JSON HTML, decorated against the immediately previous
     * (older) version. Only the values that are actually new or changed are highlighted in yellow,
     * recursing into nested objects and diffing arrays element by element; keys/elements that
     * existed in the previous version but are gone are shown struck through.
     */
    private renderVersionDiffHtml(selected: any, previous: any): string {
        return this.renderValueDiff(selected, previous, 0);
    }

    /**
     * Diffs a single value (object, array or scalar) against its previous counterpart. Objects and
     * arrays recurse so only the differing leaves are highlighted; scalars (or type mismatches) are
     * highlighted as a whole when they differ.
     */
    private renderValueDiff(sel: any, prev: any, indent: number): string {
        if (this.isPlainObject(sel) && this.isPlainObject(prev)) {
            return this.renderObjectDiff(sel, prev, indent);
        }
        if (Array.isArray(sel) && Array.isArray(prev)) {
            return this.renderArrayDiff(sel, prev, indent);
        }
        const changed = JSON.stringify(sel) !== JSON.stringify(prev);
        return this.decorateValue(this.valueToHtml(sel, indent), changed);
    }

    private renderObjectDiff(sel: any, prev: any, indent: number): string {
        const pad = '  '.repeat(indent);
        const padIn = '  '.repeat(indent + 1);
        const selObj = this.isPlainObject(sel);
        const prevObj = this.isPlainObject(prev);

        // Show the selected version's keys first (this is how the record looked then), then append
        // any keys that only existed in the previous version (removed at this version).
        const keys: string[] = [];
        if (selObj) {
            Object.keys(sel).forEach(k => keys.push(k));
        }
        if (prevObj) {
            Object.keys(prev).forEach(k => {
                if (!keys.includes(k)) {
                    keys.push(k);
                }
            });
        }
        if (keys.length === 0) {
            return '{}';
        }

        const lines = keys.map((key, idx) => {
            const comma = idx < keys.length - 1 ? ',' : '';
            const keySpan = '<span class="aaxis-json-key">"' + this.escapeHtml(key) + '":</span>';
            const keyHtml = keySpan + ' ';
            const selHas = selObj && Object.prototype.hasOwnProperty.call(sel, key);
            const prevHas = prevObj && Object.prototype.hasOwnProperty.call(prev, key);

            if (selHas && prevHas) {
                // Diff the value itself, so only the changed leaves get highlighted.
                return padIn + keyHtml + this.renderValueDiff(sel[key], prev[key], indent + 1) + comma;
            }

            if (selHas && !prevHas) {
                // New key at this version: highlight both the key and the value.
                return padIn + this.decorateValue(keySpan, true) + ' '
                    + this.decorateValue(this.valueToHtml(sel[key], indent + 1), true) + comma;
            }

            // Existed in the previous version, removed at this one: show it struck through.
            const removed = keyHtml + this.valueToHtml(prev[key], indent + 1) + comma;
            return padIn + '<span class="aaxis-json-absent">' + removed + '</span>';
        });

        return '{\n' + lines.join('\n') + '\n' + pad + '}';
    }

    /**
     * Diffs two arrays positionally (element by element). Matching positions are diffed recursively,
     * extra elements in the selected version are highlighted, and elements that were dropped since
     * the previous version are shown struck through.
     */
    private renderArrayDiff(sel: any[], prev: any[], indent: number): string {
        const pad = '  '.repeat(indent);
        const padIn = '  '.repeat(indent + 1);
        const len = Math.max(sel.length, prev.length);
        if (len === 0) {
            return '[]';
        }

        const lines: string[] = [];
        for (let i = 0; i < len; i++) {
            const comma = i < len - 1 ? ',' : '';
            const selHas = i < sel.length;
            const prevHas = i < prev.length;

            if (selHas && prevHas) {
                lines.push(padIn + this.renderValueDiff(sel[i], prev[i], indent + 1) + comma);
            } else if (selHas && !prevHas) {
                // Element added at this version.
                lines.push(padIn + this.decorateValue(this.valueToHtml(sel[i], indent + 1), true) + comma);
            } else {
                // Element removed at this version.
                lines.push(padIn + '<span class="aaxis-json-absent">' + this.valueToHtml(prev[i], indent + 1) + comma + '</span>');
            }
        }

        return '[\n' + lines.join('\n') + '\n' + pad + ']';
    }

    private decorateValue(html: string, changed: boolean): string {
        return changed ? '<span class="aaxis-json-changed">' + html + '</span>' : html;
    }

    /** Pretty-prints a JS value to coloured JSON HTML, aligning continuation lines to `indent`. */
    private valueToHtml(value: any, indent: number): string {
        const json = JSON.stringify(value, null, 2);
        const colored = this.highlightJson(json);
        const pad = '  '.repeat(indent);
        return colored.split('\n').map((line, i) => (i === 0 ? line : pad + line)).join('\n');
    }

    private isPlainObject(value: any): boolean {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    private escapeHtml(text: string): string {
        return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // --- Add Data ------------------------------------------------------------

    private openAddData(): void {
        const dialog = new Dialog({
            title: __('aaxis.ontology.data_view.add_title'),
            width: '860px',
            onClose: () => this.disposeAddSelects()
        });
        const $content = dialog.open();
        this.addDialog = dialog;

        this.$addError = $('<div/>', {'class': 'aaxis-rfm__alert', role: 'alert', hidden: 'hidden'});
        $content.append(this.$addError);

        const $form = $('<div/>', {'class': 'aaxis-data-form'});

        // Row 1: System (30%) + Entity (40%) + Format (20%), all comboboxes.
        const $system = $('<select/>', {'class': 'form-control', 'data-role': 'system'});
        $system.append($('<option/>', {value: '', text: __('aaxis.ontology.data_view.choose_system')}));
        this.systems.forEach(s => $system.append($('<option/>', {value: s.id, text: s.name})));
        const $systemField = this.field(__('aaxis.ontology.data_view.system'), false, $system);

        const $entity = $('<select/>', {'class': 'form-control', 'data-role': 'entity'});
        const $entityField = this.field(__('aaxis.ontology.data_view.entity'), true, $entity);

        const $format = $('<select/>', {'class': 'form-control', 'data-role': 'format'}).append(
            $('<option/>', {value: 'json', text: 'JSON'}),
            $('<option/>', {value: 'csv', text: 'CSV'})
        );
        // Format combobox + a wand icon button that pretty-prints the payload.
        const $formatWand = $('<button/>', {
            type: 'button', 'class': 'aaxis-data-form__wand', 'data-role': 'format-payload',
            title: __('aaxis.ontology.data_view.format_action'),
            'aria-label': __('aaxis.ontology.data_view.format_action')
        }).append($('<span/>', {'class': 'fa fa-magic', 'aria-hidden': 'true'}));
        const $formatInline = $('<div/>', {'class': 'aaxis-data-form__inline'}).append($format, $formatWand);
        const $formatField = this.field(__('aaxis.ontology.data_view.format'), false, $formatInline);

        $form.append($('<div/>', {'class': 'aaxis-data-form__row aaxis-data-form__row--sem'})
            .append($systemField, $entityField, $formatField));

        // Payload (full width). A single JSON object is one record; a JSON array / CSV rows are a
        // batch. The unique id of each record is inferred from the payload via the entity's unique
        // attribute, so there is no unique-id field here.
        const $payload = $('<textarea/>', {'class': 'form-control aaxis-data-form__payload', 'data-role': 'payload', spellcheck: false});
        const $payloadError = $('<span/>', {'class': 'aaxis-rfm__field-error aaxis-data-form__payload-error', 'data-role': 'payload-error'});
        const $payloadField = $('<div/>', {'class': 'aaxis-rfm__field'});
        $payloadField.append(
            $('<label/>', {'class': 'aaxis-rfm__label', text: __('aaxis.ontology.data_view.payload')}),
            $payload, $payloadError
        );
        $form.append($payloadField);
        $content.append($form);

        // Actions: Cancel/Submit on the right, aligned with the payload textarea's right edge.
        const $actions = $('<div/>', {'class': 'aaxis-data-form__footer'});
        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', text: __('Cancel'), 'data-role': 'close'});
        const $submit = $('<button/>', {type: 'button', 'class': 'btn btn-primary', text: __('aaxis.common.grid.submit')});
        $actions.append($cancel, $submit);
        $content.append($actions);

        // Keep references used by the auto-validation / submit-state helpers.
        this.$addEntity = $entity;
        this.$addFormat = $format;
        this.$addPayload = $payload;
        this.$addPayloadError = $payloadError;
        this.$addSubmit = $submit;
        this.addUniqueAttribute = '';

        // Initialise the comboboxes (single-select with an internal clear "x"). Format is also a
        // Select2 (no clear, no search) so its height matches System/Entity.
        this.systemSelect2 = this.initSelect2($system, {width: '100%', allowClear: true});
        this.formatSelect2 = this.initSelect2($format, {width: '100%', minimumResultsForSearch: Infinity});
        this.rebuildEntityOptions($entity, null);

        // Wiring.
        $system.on('change', () => {
            if (this.addSyncing) {
                return;
            }
            this.rebuildEntityOptions($entity, this.systemId($system));
            this.updateAddState();
        });
        $entity.on('change', () => {
            const ent = this.entities.find(e => String(e.id) === String($entity.val()));
            this.addUniqueAttribute = ent ? String(ent.uniqueAttribute || '') : '';
            if (ent && ent.systemId != null) {
                this.addSyncing = true;
                $system.val(String(ent.systemId)).trigger('change');
                this.addSyncing = false;
            }
            this.updateAddState();
        });
        $format.on('change', () => this.updateAddState());
        $payload.on('input', () => this.updateAddState());
        $formatWand.on('click', () => {
            this.formatAddPayload();
            this.updateAddState();
        });
        $cancel.on('click', () => dialog.close());
        $submit.on('click', () => this.submitAddData(dialog));

        this.updateAddState();
    }

    private field(label: string, required: boolean, $control: any): any {
        const $field = $('<div/>', {'class': 'aaxis-rfm__field'});
        const $label = $('<label/>', {'class': 'aaxis-rfm__label', text: label});
        if (required) {
            $label.append($('<span/>', {'class': 'aaxis-rfm__req', text: ' *', 'aria-hidden': 'true'}));
        }
        $field.append($label, $control);
        return $field;
    }

    private systemId($system: any): number | null {
        const val = String($system.val() || '');
        return val === '' ? null : Number(val);
    }

    private rebuildEntityOptions($entity: any, systemId: number | null): void {
        const current = String($entity.val() || '');
        const list = systemId != null
            ? this.entities.filter(e => e.systemId === systemId)
            : this.entities;

        if (this.entitySelect2) {
            this.entitySelect2.dispose();
            this.entitySelect2 = null;
        }
        $entity.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.data_view.choose_entity')}));
        list.forEach(e => $entity.append($('<option/>', {value: e.id, text: e.name})));
        $entity.val(list.some(e => String(e.id) === current) ? current : '');

        this.entitySelect2 = this.initSelect2($entity, {width: '100%', allowClear: true});
    }

    private initSelect2($el: any, config: any): any {
        try {
            return new Select2View({el: $el, select2Config: config});
        } catch (e) {
            // If Select2 can't initialise, leave the native control in place.
            return null;
        }
    }

    private disposeAddSelects(): void {
        if (this.systemSelect2) {
            this.systemSelect2.dispose();
            this.systemSelect2 = null;
        }
        if (this.entitySelect2) {
            this.entitySelect2.dispose();
            this.entitySelect2 = null;
        }
        if (this.formatSelect2) {
            this.formatSelect2.dispose();
            this.formatSelect2 = null;
        }
        this.addDialog = null;
    }

    /**
     * Validates the payload: it must parse for the chosen format and (for JSON/CSV, which we can
     * parse client-side) every record must carry the entity's unique attribute. A single object is
     * one record, a JSON array / CSV rows are a batch. XML attribute-presence is left to the server.
     */
    private validateAddPayload(): boolean {
        this.$addPayloadError.text('');
        const format = String(this.$addFormat.val());
        const raw = String(this.$addPayload.val() || '').trim();

        if (raw === '') {
            if (format === 'csv') {
                this.$addPayloadError.text(__('aaxis.ontology.data_view.csv_header_required'));
            }
            // An empty payload cannot produce a record; submit stays disabled (no nag for json/xml).
            return false;
        }

        if (!this.isFormatValid(format, raw)) {
            this.$addPayloadError.text(format === 'csv'
                ? __('aaxis.ontology.data_view.csv_header_required')
                : __('aaxis.ontology.data_view.payload_invalid'));
            return false;
        }

        const attr = this.addUniqueAttribute;
        if (attr !== '' && (format === 'json' || format === 'csv')) {
            const records = this.parseAllRecords(format, raw);
            if (records === null || records.length === 0) {
                this.$addPayloadError.text(__('aaxis.ontology.data_view.payload_invalid'));
                return false;
            }
            for (let i = 0; i < records.length; i++) {
                const rec = records[i];
                const value = (rec && typeof rec === 'object' && !Array.isArray(rec)) ? rec[attr] : undefined;
                if (value === undefined || value === null || String(value).trim() === '') {
                    this.$addPayloadError.text(__('aaxis.ontology.data_view.unique_attribute_missing', {
                        attribute: attr, row: i + 1
                    }));
                    return false;
                }
            }
        }
        return true;
    }

    /** Whether the raw payload parses for the given format. */
    private isFormatValid(format: string, raw: string): boolean {
        try {
            if (format === 'json') {
                JSON.parse(raw);
                return true;
            }
            if (format === 'xml') {
                const doc = new DOMParser().parseFromString(raw, 'application/xml');
                return doc.getElementsByTagName('parsererror').length === 0;
            }
            if (format === 'csv') {
                const lines = raw.split(/\r\n|\r|\n/).filter(l => l.trim() !== '');
                return lines.length > 0 && lines[0].trim() !== '';
            }
        } catch (e) {
            return false;
        }
        return false;
    }

    /**
     * Parses the payload into a list of record objects (a single JSON object becomes one record);
     * returns null when it can't be parsed to records for the given format.
     */
    private parseAllRecords(format: string, raw: string): any[] | null {
        if (raw.trim() === '') {
            return [];
        }
        if (format === 'json') {
            let parsed: any;
            try {
                parsed = JSON.parse(raw);
            } catch (e) {
                return null;
            }
            if (Array.isArray(parsed)) {
                return parsed;
            }
            return (parsed !== null && typeof parsed === 'object') ? [parsed] : null;
        }
        if (format === 'csv') {
            const lines = raw.split(/\r\n|\r|\n/).filter(l => l.trim() !== '');
            if (lines.length < 2) {
                return null;
            }
            const headers = this.splitCsv(lines[0]);
            return lines.slice(1).map(line => {
                const values = this.splitCsv(line);
                const obj: Record<string, string> = {};
                headers.forEach((h, i) => {
                    obj[h] = values[i] ?? '';
                });
                return obj;
            });
        }
        return null;
    }

    private splitCsv(line: string): string[] {
        return line.split(',').map(s => s.trim().replace(/^"(.*)"$/, '$1'));
    }

    /** Pretty-prints the payload for the chosen format (used by the Format button). */
    private formatAddPayload(): void {
        const format = String(this.$addFormat.val());
        const raw = String(this.$addPayload.val() || '').trim();
        if (raw === '') {
            return;
        }
        // Only gate formatting on parseability (not the unique-attribute check), so the payload can
        // be tidied before the id attribute is filled in.
        if (!this.isFormatValid(format, raw)) {
            this.$addPayloadError.text(format === 'csv'
                ? __('aaxis.ontology.data_view.csv_header_required')
                : __('aaxis.ontology.data_view.payload_invalid'));
            return;
        }
        if (format === 'json') {
            this.$addPayload.val(JSON.stringify(JSON.parse(raw), null, 2));
        } else if (format === 'xml') {
            this.$addPayload.val(this.prettyXml(raw));
        } else if (format === 'csv') {
            this.$addPayload.val(raw.split(/\r\n|\r|\n/).filter(l => l.trim() !== '').join('\n'));
        }
    }

    /** Enables Submit only when an entity is selected and the payload is valid. */
    private updateAddState(): void {
        const entityOk = !!this.$addEntity.val();
        const payloadOk = this.validateAddPayload();
        this.$addSubmit.prop('disabled', !(entityOk && payloadOk));
    }

    private prettyXml(xml: string): string {
        const withBreaks = xml.replace(/>\s*</g, '>\n<');
        let pad = 0;
        return withBreaks.split('\n').map(line => {
            const node = line.trim();
            if (/^<\/.+>$/.test(node)) {
                pad = Math.max(pad - 1, 0);
            }
            const indented = '  '.repeat(pad) + node;
            if (/^<[^!?][^>]*[^/]>$/.test(node) && !/^<.*<\/.*>$/.test(node)) {
                pad += 1;
            }
            return indented;
        }).join('\n');
    }

    private submitAddData(dialog: Dialog): void {
        this.$addError.attr('hidden', 'hidden').text('');
        if (!this.validateAddPayload()) {
            return;
        }

        const entityId = Number(this.$addEntity.val());
        if (!entityId) {
            return;
        }

        const body = {
            entityId,
            format: String(this.$addFormat.val()),
            payload: String(this.$addPayload.val() || '')
        };
        this.setAddSubmitBusy(true);
        this.apiFetch(routing.generate('aaxis_ontology_data_create'), 'POST', body).then(res => {
            if (!res.ok || !res.data || !res.data.success) {
                this.setAddSubmitBusy(false);
                this.showFormError(this.$addError, (res.data && res.data.message) || __('aaxis.ontology.data_view.save_error'));
                this.updateAddState();
                return;
            }
            messenger.notificationFlashMessage('success', __('aaxis.ontology.data_view.saved'));
            dialog.close();
            this.load();
        }).catch(() => {
            this.setAddSubmitBusy(false);
            this.showFormError(this.$addError, __('aaxis.ontology.data_view.save_error'));
            this.updateAddState();
        });
    }

    /** Toggles the submit button between its idle label and an in-progress spinner. */
    private setAddSubmitBusy(busy: boolean): void {
        if (busy) {
            this.$addSubmit.prop('disabled', true).empty().append(
                $('<span/>', {'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true'}),
                $('<span/>', {text: ' ' + __('aaxis.ontology.data_view.sending')})
            );
            return;
        }
        this.$addSubmit.empty().text(__('aaxis.common.grid.submit'));
    }

    private showFormError($error: any, message: string): void {
        $error.text(message).removeAttr('hidden');
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

    private load(): void {
        this.setBusy(true);
        fetch(routing.generate('aaxis_ontology_data_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {records: DataRecord[]; systems?: SystemRef[]; entities?: EntityRef[]}) => {
                this.systems = data.systems || [];
                this.entities = data.entities || [];
                this.grid.setRows(data.records || []);
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.data_view.load_error')))
            .finally(() => this.setBusy(false));
    }

    private renderDate(value: string | null): string {
        if (!value) {
            return '';
        }
        const d = new Date(value);
        return isNaN(d.getTime()) ? String(value) : d.toLocaleString();
    }

    private renderPayload(row: DataRecord): any {
        const text = String(row.payload || '');
        const short = text.length > 60 ? text.slice(0, 60) + '…' : text;
        // No preview here — the per-record "Versions" history shows the full payload with more tools.
        return $('<span/>', {'class': 'aaxis-data-payload__text', text: short});
    }

    /**
     * Re-renders the pre from the syntax-highlighted HTML and wraps every case-insensitive match of
     * the query in a &lt;mark&gt;, preserving the JSON colouring. Returns the number of matches.
     */
    private highlightSearch($pre: any, syntaxHtml: string, query: string): number {
        $pre.html(syntaxHtml);
        const needle = query.toLowerCase();
        if (needle === '') {
            return 0;
        }

        const root = $pre.get(0);
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        const textNodes: Text[] = [];
        let node = walker.nextNode();
        while (node) {
            textNodes.push(node as Text);
            node = walker.nextNode();
        }

        let count = 0;
        textNodes.forEach(textNode => {
            const text = textNode.nodeValue || '';
            const lower = text.toLowerCase();
            if (lower.indexOf(needle) === -1) {
                return;
            }
            const fragment = document.createDocumentFragment();
            let from = 0;
            let at = lower.indexOf(needle, from);
            while (at !== -1) {
                if (at > from) {
                    fragment.appendChild(document.createTextNode(text.slice(from, at)));
                }
                const mark = document.createElement('mark');
                mark.className = 'aaxis-json-mark';
                mark.textContent = text.slice(at, at + query.length);
                fragment.appendChild(mark);
                count++;
                from = at + query.length;
                at = lower.indexOf(needle, from);
            }
            if (from < text.length) {
                fragment.appendChild(document.createTextNode(text.slice(from)));
            }
            textNode.parentNode?.replaceChild(fragment, textNode);
        });

        return count;
    }

    private copyToClipboard(text: string): void {
        const done = () => messenger.notificationFlashMessage('success', __('aaxis.ontology.data_view.copied'));
        const fail = () => messenger.notificationFlashMessage('error', __('aaxis.ontology.data_view.copy_error'));
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(fail);
            return;
        }
        // Fallback for browsers without the async clipboard API.
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
        } catch (e) {
            fail();
        }
    }

    /**
     * Produces HTML with syntax-colouring spans for a pretty-printed JSON string. The input is
     * HTML-escaped first, so the output is safe to inject.
     */
    private highlightJson(json: string): string {
        const escaped = json
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        return escaped.replace(
            /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false)\b|\bnull\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
            (match: string) => {
                let cls = 'aaxis-json-num';
                if (/^"/.test(match)) {
                    cls = /:$/.test(match) ? 'aaxis-json-key' : 'aaxis-json-str';
                } else if (/true|false/.test(match)) {
                    cls = 'aaxis-json-bool';
                } else if (/null/.test(match)) {
                    cls = 'aaxis-json-null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            }
        );
    }

    private setBusy(busy: boolean): void {
        this.$el.find('[data-role="refresh"]').prop('disabled', busy)
            .find('.fa').toggleClass('fa-spin', busy);
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisOntologyData');
        if (this.grid) {
            this.grid.dispose();
        }
        super.dispose();
    }
}

export default OntologyDataViewComponent;

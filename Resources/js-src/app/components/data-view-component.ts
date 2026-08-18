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
    systemId: number | null;
    entityId: number | null;
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
    /** Counter used to give each versions dialog its own datalist id. */
    private static dialogSeq = 0;

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
    /**
     * Update mode only: the payload exactly as it was loaded. Submit unlocks once the textarea
     * differs from it, so an accidental open-and-close cannot rewrite the record. Null = Add mode.
     */
    private addBaseline: string | null = null;
    private $addError: any = null;
    private $addEntity: any = null;
    private $addFormat: any = null;
    private $addPayload: any = null;
    private $addPayloadError: any = null;
    private $addSubmit: any = null;
    /**
     * `?entity=` seeds the grid filter once; later refreshes must not re-impose it.
     * Declared with `!` and assigned in initialize() — a field initializer would run AFTER the base
     * constructor has already called initialize() (see CommonBundle/CLAUDE.md).
     */
    private entityFilterApplied!: boolean;

    initialize(options: OntologyDataViewOptions): void {
        this.$el = options._sourceElement;
        this.entityFilterApplied = false;

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
                // Update writes through the create endpoint, so it is offered only to users who
                // may create — same gate as the "+ Add Data" button.
                ...(options.canCreate ? [{key: 'update', label: __('aaxis.ontology.data_view.update'), icon: 'fa-pencil'}] : []),
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
        } else if (action === 'update') {
            // The same Add Data form, preloaded with this record and locked to its system/entity.
            this.openAddData(row);
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

        // Version picker. Deliberately an editable box holding ONLY the current selection instead
        // of a list of every version: the option list would grow with the record's history, while
        // jumping is done by typing a version number or a uuid (looked up below) or by dragging the
        // slider. The two controls mirror each other.
        const $select = $('<input/>', {
            type: 'text', 'class': 'form-control aaxis-json-version__select', 'data-role': 'version',
            autocomplete: 'off', spellcheck: false,
            title: __('aaxis.ontology.data_view.version_search_hint'),
            placeholder: __('aaxis.ontology.data_view.version_search_placeholder')
        });
        const $versionError = $('<span/>', {'class': 'aaxis-json-version__error', role: 'alert'});
        // "/ 12" — how many versions exist, so the box reads as "this one out of that many".
        const $versionCount = $('<span/>', {
            'class': 'aaxis-json-version__count',
            text: '/ ' + versions.length
        });
        const $selectField = $('<div/>', {'class': 'aaxis-json-version'}).append(
            $('<label/>', {'class': 'aaxis-json-version__label', text: __('aaxis.ontology.data_view.version')}),
            $select,
            $versionCount,
            $versionError
        );

        // Slider: one stop per version, oldest on the left so dragging right moves forward in time.
        // Its value is the distance from the OLDEST version; `versions` is newest-first, so the two
        // are mirror images of each other (see toIndex/toSlider). Tick marks (one per version) make
        // the number of versions readable at a glance; it is a POSITION picker, not a progress bar,
        // so the track is not filled up to the thumb.
        const lastIndex = versions.length - 1;
        // Unique per open: a datalist is referenced by id, so a stale one from a previous dialog
        // must never be the one the browser resolves.
        const stopsId = 'aaxis-json-version-stops-' + (++OntologyDataViewComponent.dialogSeq);
        const $slider = $('<input/>', {
            type: 'range', 'class': 'aaxis-json-version__slider', min: 0, max: lastIndex, step: 1,
            list: stopsId,
            'aria-label': __('aaxis.ontology.data_view.version_slider')
        });
        const $stops = $('<datalist/>', {id: stopsId});
        versions.forEach((_v, i) => $stops.append($('<option/>', {value: String(i)})));
        // Tick spacing is per record (one mark per version), so the stylesheet reads it from here.
        if (lastIndex > 0) {
            $slider.css('--tick-gap', (100 / lastIndex) + '%');
        }
        const $sliderField = $('<div/>', {'class': 'aaxis-json-slider'}).append(
            $('<span/>', {'class': 'aaxis-json-slider__end', text: 'v' + (versions[lastIndex].version ?? '')}),
            $slider,
            $stops,
            $('<span/>', {'class': 'aaxis-json-slider__end', text: 'v' + (versions[0].version ?? '')})
        );
        const toIndex = (sliderValue: number): number => lastIndex - sliderValue;
        const toSlider = (index: number): number => lastIndex - index;

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
        // Full view ⇄ diff only. Sits before the search box, since it decides WHAT is searchable.
        const $diffToggle = $('<input/>', {type: 'checkbox', 'class': 'aaxis-json-mode__check'});
        const $mode = $('<label/>', {
            'class': 'aaxis-json-mode', title: __('aaxis.ontology.data_view.diff_only_hint')
        }).append(
            $diffToggle,
            $('<span/>', {'class': 'aaxis-json-mode__track', 'aria-hidden': 'true'})
                .append($('<span/>', {'class': 'aaxis-json-mode__thumb'})),
            $('<span/>', {'class': 'aaxis-json-mode__label', text: __('aaxis.ontology.data_view.diff_only')})
        );

        const $searchGroup = $('<div/>', {'class': 'aaxis-json-search'})
            .append($mode, $search, $count, $prev, $next);

        const $pre = $('<pre/>', {'class': 'aaxis-json-view'});

        const $copy = $('<button/>', {type: 'button', 'class': 'btn btn-sm aaxis-json-copy'}).append(
            $('<span/>', {'class': 'fa fa-clipboard', 'aria-hidden': 'true'}),
            $('<span/>', {text: ' ' + __('aaxis.ontology.data_view.copy')})
        );
        // Search and Copy share the bottom row: both act on the JSON above them, so they belong
        // together under it rather than split across the top and bottom of the dialog.
        const $footer = $('<div/>', {'class': 'aaxis-json-footer aaxis-json-footer--tools'})
            .append($searchGroup, $copy);

        // A single-version record has nothing to navigate between — no slider at all.
        $content.append($selectField);
        if (versions.length > 1) {
            $content.append($sliderField);
        }
        $content.append($pre, $footer);

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

        // The selection lives here (not in the input's value) because the input is free text the
        // user may be mid-way through typing.
        let selectedIndex = 0;

        /** Label shown in the box for a version: "v3 — 12/05/2026 14:22 — <uuid>  (current)". */
        const versionLabel = (v: VersionEntry): string => [
            'v' + v.version,
            this.renderDate(v.updatedAt),
            v.uuid || ''
        ].filter(part => part !== '').join(' — ')
            + (v.current ? '  (' + __('aaxis.ontology.data_view.version_current') + ')' : '');

        /** A muted one-liner in the JSON pane (nothing to diff / nothing changed). */
        const note = (text: string): string =>
            '<span class="aaxis-json-note">' + this.escapeHtml(text) + '</span>';

        const renderSelected = (): void => {
            const idx = selectedIndex;
            const selected = versions[idx];
            const selectedPayload = selected.payload || {};
            const diffOnly = $diffToggle.is(':checked');
            plainText = JSON.stringify(selectedPayload, null, 2);
            // In the DESC-ordered list, the immediately previous (older) version is the next index.
            const previous = versions[idx + 1];

            if (!previous) {
                // Oldest version: no previous to compare against, so no markers — and nothing a
                // diff-only view could show.
                baseHtml = diffOnly
                    ? note(__('aaxis.ontology.data_view.diff_no_previous'))
                    : this.highlightJson(plainText);
            } else if (diffOnly) {
                const pruned = this.pruneToDiff(selectedPayload, previous.payload || {});
                if (pruned === null) {
                    baseHtml = note(__('aaxis.ontology.data_view.diff_no_changes'));
                    plainText = '';
                } else {
                    baseHtml = this.renderVersionDiffHtml(pruned.sel, pruned.prev);
                    // Copy follows what is on screen.
                    plainText = JSON.stringify(pruned.sel, null, 2);
                }
            } else {
                baseHtml = this.renderVersionDiffHtml(selectedPayload, previous.payload || {});
            }
            $pre.html(baseHtml);
            runSearch();
        };

        /** Single entry point for changing version: keeps box, slider and JSON in lockstep. */
        const select = (index: number): void => {
            selectedIndex = Math.min(Math.max(index, 0), lastIndex);
            $select.val(versionLabel(versions[selectedIndex]));
            $slider.val(String(toSlider(selectedIndex)));
            $versionError.text('');
            renderSelected();
        };

        /**
         * Resolves what the user typed to a version: a bare number matches the version number
         * (a leading "v" is tolerated), anything else is matched against the uuid — full or a
         * unique prefix, so pasting a partial id works. Returns -1 when nothing matches.
         */
        const findVersion = (raw: string): number => {
            const query = raw.trim().toLowerCase();
            if (query === '') {
                return -1;
            }
            const asNumber = query.replace(/^v/, '');
            if (/^\d+$/.test(asNumber)) {
                const byNumber = versions.findIndex(v => String(v.version) === asNumber);
                if (byNumber >= 0) {
                    return byNumber;
                }
            }
            const exact = versions.findIndex(v => (v.uuid || '').toLowerCase() === query);
            if (exact >= 0) {
                return exact;
            }
            const partial = versions.filter(v => (v.uuid || '').toLowerCase().startsWith(query));

            return partial.length === 1 ? versions.indexOf(partial[0]) : -1;
        };

        const commitVersionSearch = (): void => {
            const raw = String($select.val() || '');
            // Unchanged text (the label we put there) is not a search — just restore it.
            if (raw === versionLabel(versions[selectedIndex])) {
                return;
            }
            const found = findVersion(raw);
            if (found >= 0) {
                select(found);
            } else {
                $versionError.text(__('aaxis.ontology.data_view.version_not_found'));
            }
        };

        $select.on('keydown', (e: any) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                commitVersionSearch();
            }
        });
        // Leaving the box without a match would strand a half-typed query on screen — put the
        // current version's label back so the box always reflects what is displayed.
        $select.on('blur', () => {
            const raw = String($select.val() || '');
            if (raw !== versionLabel(versions[selectedIndex])) {
                commitVersionSearch();
                if ($versionError.text() !== '') {
                    $select.val(versionLabel(versions[selectedIndex]));
                }
            }
        });
        $select.on('focus', () => $select.trigger('select'));
        $slider.on('input', () => select(toIndex(Number($slider.val()))));
        $diffToggle.on('change', () => renderSelected());

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

        select(0);
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
     * Reduces a pair of snapshots to only what differs, so "diff only" can reuse the normal diff
     * renderer and still emit VALID json (filtering the rendered lines would cut multi-line
     * highlight spans in half).
     *
     * Objects are pruned key by key, recursively. Arrays and scalars are kept WHOLE when they
     * differ — pruning array elements would renumber the indexes and misrepresent the data.
     * Returns null when the two snapshots are identical.
     */
    private pruneToDiff(sel: any, prev: any): {sel: any; prev: any} | null {
        if (JSON.stringify(sel) === JSON.stringify(prev)) {
            return null;
        }
        if (!this.isPlainObject(sel) || !this.isPlainObject(prev)) {
            return {sel, prev};
        }

        const outSel: Record<string, any> = {};
        const outPrev: Record<string, any> = {};
        const keys: string[] = Object.keys(sel);
        Object.keys(prev).forEach(key => {
            if (!keys.includes(key)) {
                keys.push(key);
            }
        });

        keys.forEach(key => {
            // hasOwnProperty via call, not Object.hasOwn: the build targets ES2020.
            const hasSel = Object.prototype.hasOwnProperty.call(sel, key);
            const hasPrev = Object.prototype.hasOwnProperty.call(prev, key);
            if (hasSel && hasPrev) {
                const branch = this.pruneToDiff(sel[key], prev[key]);
                if (branch !== null) {
                    outSel[key] = branch.sel;
                    outPrev[key] = branch.prev;
                }
                return;
            }
            // Added (renders highlighted) or removed (renders struck through).
            if (hasSel) {
                outSel[key] = sel[key];
            } else {
                outPrev[key] = prev[key];
            }
        });

        return {sel: outSel, prev: outPrev};
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

    /**
     * The Add Data form. With `record` it becomes "Update Data" for that row: same form and same
     * upsert submit, but the system/entity are preloaded and LOCKED (the record's identity is not
     * up for editing), the payload arrives pretty-printed, and Submit stays disabled until the
     * payload is actually edited — reopening and closing a record must not rewrite it.
     */
    private openAddData(record?: DataRecord): void {
        const isUpdate = !!record;
        const dialog = new Dialog({
            title: isUpdate ? __('aaxis.ontology.data_view.update_title') : __('aaxis.ontology.data_view.add_title'),
            subtitle: isUpdate
                ? [record?.entity, record?.uniqueId].filter(part => !!part).join(' — ') || undefined
                : undefined,
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

        if (isUpdate && record) {
            // Locked context: exactly this record's system/entity, so there is nothing to rebuild.
            // Disabled BEFORE Select2 initialises — it renders the disabled state from the source
            // select, and a jQuery .val() on a disabled control still reaches the submit payload.
            $system.val(String(record.systemId ?? ''));
            $entity.empty().append($('<option/>', {value: String(record.entityId ?? ''), text: record.entity || ''}));
            $entity.val(String(record.entityId ?? ''));
            $system.prop('disabled', true);
            $entity.prop('disabled', true);
            const ent = this.entities.find(e => String(e.id) === String(record.entityId));
            this.addUniqueAttribute = ent ? String(ent.uniqueAttribute || '') : '';

            // JSON, pretty-printed — the row carries it compact.
            $format.val('json');
            $payload.val(this.prettyJsonOrRaw(record.payload));
            this.addBaseline = String($payload.val() || '');
        } else {
            this.addBaseline = null;
        }

        // Initialise the comboboxes (single-select with an internal clear "x"). Format is also a
        // Select2 (no clear, no search) so its height matches System/Entity.
        this.systemSelect2 = this.initSelect2($system, {width: '100%', allowClear: true});
        this.formatSelect2 = this.initSelect2($format, {width: '100%', minimumResultsForSearch: Infinity});
        if (isUpdate) {
            this.entitySelect2 = this.initSelect2($entity, {width: '100%'});
        } else {
            this.rebuildEntityOptions($entity, null);
        }

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
        // Back to Add semantics: the next plain "+ Add Data" must not inherit an update baseline.
        this.addBaseline = null;
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
            // Textual re-indent: never round-trips numbers through JS floats (see reindentJson).
            this.$addPayload.val(this.prettyJsonOrRaw(raw));
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
        // Update mode additionally requires an actual edit — reopening a record and submitting it
        // untouched would write a pointless new version.
        const changed = this.addBaseline === null || String(this.$addPayload.val() || '') !== this.addBaseline;
        this.$addSubmit.prop('disabled', !(entityOk && payloadOk && changed));
    }

    /** Pretty-prints a JSON payload for editing; anything unparseable is shown as it came. */
    private prettyJsonOrRaw(payload: string): string {
        const raw = String(payload || '').trim();
        if (raw === '') {
            return '';
        }
        let parsed: any;
        try {
            parsed = JSON.parse(raw);
        } catch {
            return raw;
        }
        const pretty = this.reindentJson(raw);
        try {
            // Only trust the re-indenter when it round-trips to the same structure; otherwise show
            // the payload untouched rather than risk handing back something altered.
            if (JSON.stringify(JSON.parse(pretty)) === JSON.stringify(parsed)) {
                return pretty;
            }
        } catch {
            // fall through
        }
        return raw;
    }

    /**
     * Re-indents JSON **textually**: string literals are copied verbatim and number tokens are
     * never parsed. That matters because this text is what gets submitted back — running a payload
     * through JSON.parse/stringify would round an integer beyond 2^53 (a 16+ digit ERP id) to a
     * different value and silently rewrite a field the user never touched, since jsonb keeps such
     * numbers exactly and compares them numerically.
     */
    private reindentJson(raw: string): string {
        const pad = (depth: number): string => '\n' + '  '.repeat(depth);
        let out = '';
        let depth = 0;
        let inString = false;
        let escaped = false;

        for (let i = 0; i < raw.length; i++) {
            const ch = raw[i];
            if (inString) {
                out += ch;
                if (escaped) {
                    escaped = false;
                } else if (ch === '\\') {
                    escaped = true;
                } else if (ch === '"') {
                    inString = false;
                }
                continue;
            }
            if (ch === '"') {
                inString = true;
                out += ch;
                continue;
            }
            if (ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r') {
                continue; // structural whitespace is rebuilt below
            }
            if (ch === '{' || ch === '[') {
                // An empty container stays on one line, like JSON.stringify renders it.
                const next = raw.slice(i + 1).search(/\S/);
                const closer = ch === '{' ? '}' : ']';
                if (next !== -1 && raw[i + 1 + next] === closer) {
                    out += ch + closer;
                    i += 1 + next;
                    continue;
                }
                depth++;
                out += ch + pad(depth);
                continue;
            }
            if (ch === '}' || ch === ']') {
                depth = Math.max(0, depth - 1);
                out += pad(depth) + ch;
                continue;
            }
            if (ch === ',') {
                out += ',' + pad(depth);
                continue;
            }
            if (ch === ':') {
                out += ': ';
                continue;
            }
            out += ch;
        }

        return out;
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
                this.applyEntityFromUrl();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.data_view.load_error')))
            .finally(() => this.setBusy(false));
    }

    /**
     * Honours `?entity=<name>` (the Entities page's "View data" action links here) by pre-applying
     * it as the entity column's filter, so the deep link lands on that entity's records with the
     * filter visible and clearable like any other. Applied once, after the first load — it seeds the
     * view rather than pinning it, so Refresh doesn't re-impose a filter the user cleared.
     */
    private applyEntityFromUrl(): void {
        if (this.entityFilterApplied) {
            return;
        }
        this.entityFilterApplied = true;
        const entity = new URLSearchParams(window.location.search).get('entity');
        if (entity !== null && entity.trim() !== '') {
            this.grid.setFilter('entity', {operator: 'equals', value: entity.trim()});
        }
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

import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import mediator from 'oroui/js/mediator';
import BaseComponent from 'oroui/js/app/components/base/component';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import createDwlField from '../widgets/dwl-field';
import {apiFetch} from './component-support';

interface DataPlaygroundOptions {
    _sourceElement: any;
    canCreate?: boolean;
    canEdit?: boolean;
    canDelete?: boolean;
}

/** An input as stored and sent: an entity bound to the script under a variable name. */
export interface PlaygroundInput {
    entityId: number;
    name: string;
}

/** A saved script as the list endpoint serializes it (`mine`: the user may change it). */
export interface PlaygroundScript {
    id: number;
    name: string;
    script: string;
    public: boolean;
    mine: boolean;
    owner: string;
    inputs: PlaygroundInput[];
    limit: number | null;
    updatedAt?: string;
}

interface CatalogEntity {
    id: number;
    name: string;
    system: string | null;
}

/** An input being edited: its entity may not be picked yet, and `auto` is the name the page chose. */
interface DraftInput {
    entityId: number | null;
    name: string;
    auto?: string;
}

/** The editor's working copy: a saved script (id) or a new, unsaved one (id null). */
interface Draft {
    id: number | null;
    name: string;
    script: string;
    public: boolean;
    mine: boolean;
    owner: string;
    inputs: DraftInput[];
    limit: number | null;
}

/** What the last Run produced (`error` set = it failed). */
interface RunOutcome {
    text: string;
    error: string | null;
    mime: string;
    extension: string;
    /** Every input is read in full: its record count. */
    inputs: Array<{name: string; records: number}>;
    /** A LIST result's rows and how many max rows let through (null: not a list — shown whole). */
    output: {rows: number; shown: number; truncated: boolean} | null;
}

/** The pass-through a new script opens with, reading `name` (the first input follows it while untouched). */
const passThrough = (name: string): string => `output application/json\n---\n${name}`;
const DEFAULT_SCRIPT = passThrough('payload');
const DEFAULT_LIMIT = 100;
const MAX_INPUTS = 10;

/** The DWL lexer's keywords (case-insensitive) — an input cannot be named like one (mirrors Manager/DwlInputs). */
const DWL_KEYWORDS = new Set([
    'output', 'input', 'var', 'fun', 'type', 'ns', 'import', 'from', 'as', 'is', 'if', 'else', 'unless',
    'default', 'do', 'using', 'and', 'or', 'not', 'case', 'matches', 'match', 'true', 'false', 'null',
    'private', 'async', 'yield', 'for', 'throw', 'enum'
]);

const t = (key: string, params?: Record<string, unknown>): string => __('aaxis.ontology.data_playground.' + key, params);

/** Whether a DWL script can read `name` as a variable (the server applies the same rule). */
export function isInputName(name: string): boolean {
    return /^[A-Za-z_]\w*$/.test(name) && name.length <= 64 && !DWL_KEYWORDS.has(name.toLowerCase());
}

/**
 * A variable name derived from an entity name: `classification-of-product` → `classification_of_product`,
 * an Oro entity class `Oro\…\Entity\Product` → `Product`.
 */
export function inputNameFor(entityName: string, taken: string[]): string {
    const short = entityName.split('\\').pop() || entityName;
    // ('input' would be no fallback: it is a DWL keyword.)
    let base = short.replace(/\W+/g, '_').replace(/^_+|_+$/g, '') || 'records';
    if (/^\d/.test(base)) {
        base = `_${base}`;
    }
    if (DWL_KEYWORDS.has(base.toLowerCase())) {
        base = `${base}_`;
    }
    base = base.slice(0, 60);
    let name = base;
    for (let n = 2; taken.includes(name); n++) {
        name = `${base}_${n}`;
    }
    return name;
}

function button(role: string, label: string, className: string, icon?: string): any {
    const $button = $('<button/>', {type: 'button', 'class': className, 'data-role': role, title: label});
    if (icon) {
        $button.append($('<span/>', {'class': 'fa ' + icon, 'aria-hidden': 'true'}));
        $button.attr('aria-label', label);
    } else {
        $button.text(label);
    }
    return $button;
}

/**
 * The Data Playground page: saved DWL scripts on the left (filter, New; a lock before each name —
 * red = private, green = public — and, on the user's OWN scripts, a trash to delete them), the
 * selected script on the right —
 * - the top row: the Public switch, its name, its INPUTS (chips `variable ← entity · records`, each
 *   edited in a dropdown: variable name + a type-to-filter entity list; "Add input" adds one, named
 *   after the entity) and Save (a spinner in it while the save is under way);
 * - the code;
 * - the result row: the run's status, Max rows (how many rows of a LIST result are shown — the
 *   inputs are always read in full), Run and Save as… (the last result to a local file), above the
 *   result itself — rendered as the script's `output` header says.
 *
 * Scripts are private unless Public; others' public scripts are read-only (they run, and "Save a
 * copy" keeps an own copy). The inputs and max rows are picked at run time and saved WITH the script
 * as its defaults. Nothing runs until Run (or Ctrl/⌘+Enter); a result older than the script, inputs
 * or max rows on screen is greyed out. `?script=<id>` follows the selection; unsaved changes are
 * guarded on switching scripts and on leaving the page.
 */
class DataPlaygroundComponent extends BaseComponent {
    private $el!: any;
    private $root!: any;
    private opts!: DataPlaygroundOptions;
    private scripts!: PlaygroundScript[];
    private entities!: CatalogEntity[];
    /** Record counts per entity id (null while loading or when unknown). */
    private counts!: Map<number, number | null>;
    private draft!: Draft;
    /** Signature of the draft as loaded/saved — the draft is dirty while it differs. */
    private baseline!: string;
    private field!: {$el: any; $textarea: any; value: () => string};
    private outcome!: RunOutcome | null;
    /** Script + inputs + max rows of the run on screen — the result is stale once they drift. */
    private ranSignature!: string | null;
    private running!: boolean;
    private saving!: boolean;
    /** The input whose dropdown is open. */
    private menuIndex!: number | null;
    private $menu!: any;
    private onBeforeUnload!: (e: any) => any;
    private onOpenLinkBefore!: (e: any) => void;
    private onDocPointerDown!: (e: any) => void;

    initialize(options: DataPlaygroundOptions): void {
        this.$el = options._sourceElement;
        this.opts = options;
        this.$root = this.$el.find('[data-role="playground"]');
        this.scripts = [];
        this.entities = [];
        this.counts = new Map();
        this.outcome = null;
        this.ranSignature = null;
        this.running = false;
        this.saving = false;
        this.menuIndex = null;
        this.$menu = null;
        this.draft = this.newDraft();
        this.baseline = '';
        // Read BEFORE the first draft is shown: showing it rewrites the URL.
        const requested = Number(new URLSearchParams(window.location.search).get('script'));

        this.build();
        this.loadDraft(this.draft);

        this.onBeforeUnload = (e: any) => {
            if (this.isDirty()) {
                e.preventDefault();
                const message = __('oro.ui.leave_page_with_unsaved_data_confirm');
                (e.originalEvent || e).returnValue = message;
                return message;
            }
        };
        $(window).on('beforeunload.aaxisDataPlayground', this.onBeforeUnload);
        this.onOpenLinkBefore = (e: any) => {
            if (!e.prevented && this.isDirty()) {
                e.prevented = !window.confirm(__('oro.ui.leave_page_with_unsaved_data_confirm'));
            }
        };
        mediator.on('openLink:before', this.onOpenLinkBefore);
        this.onDocPointerDown = (e: any) => {
            const target = e.target as Node;
            if (this.$menu && !this.$menu[0].contains(target) && !$(target).closest('[data-role="input-chip"], [data-role="input-add"]').length) {
                this.closeMenu();
            }
        };
        document.addEventListener('pointerdown', this.onDocPointerDown, true);
        $(window).on('resize.aaxisDataPlayground', () => this.fitHeight());
        this.fitHeight();

        this.load(requested);
    }

    // --- Build -----------------------------------------------------------------------------------

    private build(): void {
        const $filter = $('<input/>', {
            type: 'text', 'class': 'form-control aaxis-dwl-pg__filter', 'data-role': 'script-filter',
            placeholder: t('filter_placeholder'), 'aria-label': t('filter_placeholder'), autocomplete: 'off'
        });
        const $sidebarBar = $('<div/>', {'class': 'aaxis-dwl-pg__sidebar-bar'}).append($filter);
        if (this.opts.canCreate) {
            $sidebarBar.append(button('script-new', t('new'), 'aaxis-dwl-pg__new', 'fa-plus'));
        }
        const $sidebar = $('<aside/>', {'class': 'aaxis-dwl-pg__sidebar'}).append(
            $sidebarBar,
            $('<div/>', {'class': 'aaxis-dwl-pg__list', 'data-role': 'script-list', role: 'listbox', 'aria-label': t('list_label')})
        );

        const $public = $('<label/>', {'class': 'aaxis-flow-editor__switch aaxis-dwl-pg__public', title: t('public_title')}).append(
            $('<input/>', {type: 'checkbox', 'data-role': 'script-public'}),
            $('<span/>', {'class': 'aaxis-flow-editor__switch-track'}).append($('<span/>', {'class': 'aaxis-flow-editor__switch-thumb'})),
            $('<span/>', {'class': 'aaxis-flow-editor__switch-text', text: t('public')})
        );
        const $head = $('<div/>', {'class': 'aaxis-dwl-pg__head'}).append(
            $public,
            $('<input/>', {
                type: 'text', 'class': 'form-control aaxis-dwl-pg__name', 'data-role': 'script-name',
                placeholder: t('name_placeholder'), 'aria-label': t('name_placeholder'), maxlength: 128, autocomplete: 'off'
            }),
            $('<div/>', {'class': 'aaxis-dwl-pg__inputs', 'data-role': 'inputs', role: 'group', 'aria-label': t('inputs_label')}),
            $('<span/>', {'class': 'aaxis-dwl-pg__head-actions'}).append(
                button('script-copy', t('save_copy'), 'btn'),
                button('script-save', t('save'), 'btn btn-primary')
            )
        );
        const $owner = $('<div/>', {'class': 'aaxis-dwl-pg__owner', 'data-role': 'script-owner'});

        this.field = createDwlField({label: t('script_label'), value: '', dwl: true, fixed: true, editorClass: 'aaxis-dwl-pg__script'});
        const $code = $('<div/>', {'class': 'aaxis-dwl-pg__code'}).append(this.field.$el);

        const $resultHead = $('<div/>', {'class': 'aaxis-dwl-pg__result-head'}).append(
            $('<span/>', {'class': 'aaxis-dwl-pg__result-label', text: t('result_label')}),
            $('<span/>', {'class': 'aaxis-dwl-pg__status', 'data-role': 'run-status'}),
            $('<label/>', {'class': 'aaxis-dwl-pg__limit', title: t('limit_title')}).append(
                $('<input/>', {type: 'checkbox', 'data-role': 'run-limit-on'}), $('<span/>', {text: t('limit_label')})
            ),
            $('<input/>', {
                type: 'number', 'class': 'form-control aaxis-dwl-pg__limit-value', 'data-role': 'run-limit', min: 1, step: 1,
                'aria-label': t('limit_label')
            }),
            button('run', t('run'), 'btn btn-primary aaxis-dwl-pg__run').attr('title', t('run_title'))
                .prepend($('<span/>', {'class': 'fa fa-play', 'aria-hidden': 'true'}), ' '),
            button('save-as', t('save_as'), 'btn aaxis-dwl-pg__save-as').attr('title', t('save_as_title'))
                .prepend($('<span/>', {'class': 'fa fa-download', 'aria-hidden': 'true'}), ' ')
        );
        const $result = $('<div/>', {'class': 'aaxis-dwl-pg__result'}).append(
            $resultHead,
            $('<textarea/>', {
                'class': 'form-control aaxis-dwl-pg__output', 'data-role': 'result', readonly: true, spellcheck: false,
                'aria-label': t('result_label'), placeholder: t('not_run')
            })
        );

        const $main = $('<section/>', {'class': 'aaxis-dwl-pg__main'}).append($head, $owner, $code, $result);
        this.$root.empty().append($sidebar, $main);
        this.bindEvents();
    }

    private part(role: string): any {
        return this.$root.find(`[data-role="${role}"]`);
    }

    private bindEvents(): void {
        const ns = '.aaxisDataPlayground';
        this.$root.on('input' + ns, '[data-role="script-filter"]', () => this.renderList());
        this.$root.on('click' + ns, '[data-role="script-new"]', () => this.guarded(() => this.loadDraft(this.newDraft())));
        this.$root.on('click' + ns, '[data-role="script-item"]', (e: any) => {
            if (!$(e.target).closest('[data-role="script-item-delete"]').length) {
                this.selectScript(Number($(e.currentTarget).attr('data-script-id')));
            }
        });
        this.$root.on('keydown' + ns, '[data-role="script-item"]', (e: any) => {
            if ((e.key === 'Enter' || e.key === ' ') && e.target === e.currentTarget) {
                e.preventDefault();
                this.selectScript(Number($(e.currentTarget).attr('data-script-id')));
            }
        });
        this.$root.on('click' + ns, '[data-role="script-item-delete"]', (e: any) => {
            e.stopPropagation();
            const script = this.scripts.find(s => s.id === Number($(e.currentTarget).closest('[data-role="script-item"]').attr('data-script-id')));
            if (script) {
                this.confirmDelete(script);
            }
        });
        this.$root.on('input' + ns, '[data-role="script-name"]', (e: any) => {
            this.draft.name = String($(e.currentTarget).val() || '');
            this.syncState();
        });
        this.$root.on('change' + ns, '[data-role="script-public"]', (e: any) => {
            this.draft.public = $(e.currentTarget).is(':checked');
            this.syncState();
        });
        this.field.$textarea.on('input' + ns + ' aaxis:dwl-sync' + ns, () => {
            this.draft.script = this.field.value();
            this.syncState();
        });
        this.$root.on('click' + ns, '[data-role="input-chip"]', (e: any) => {
            if ($(e.target).closest('[data-role="input-remove"]').length) {
                return;
            }
            const index = Number($(e.currentTarget).attr('data-index'));
            if (this.menuIndex === index) {
                this.closeMenu();
            } else {
                this.openMenu(index);
            }
        });
        this.$root.on('click' + ns, '[data-role="input-remove"]', (e: any) => {
            e.stopPropagation();
            this.removeInput(Number($(e.currentTarget).closest('[data-role="input-chip"]').attr('data-index')));
        });
        this.$root.on('click' + ns, '[data-role="input-add"]', () => this.addInput());
        this.$root.on('change' + ns + ' input' + ns, '[data-role="run-limit-on"], [data-role="run-limit"]', () => {
            this.draft.limit = this.readLimit();
            this.syncState();
        });
        this.$root.on('click' + ns, '[data-role="run"]', () => this.run());
        this.$root.on('click' + ns, '[data-role="save-as"]', () => this.saveAs());
        this.$root.on('click' + ns, '[data-role="script-save"]', () => this.save(false));
        this.$root.on('click' + ns, '[data-role="script-copy"]', () => this.save(true));
        // Ctrl/⌘+Enter runs, Ctrl/⌘+S saves (when this script can be saved); Escape closes a dropdown.
        this.$root.on('keydown' + ns, (e: any) => {
            if (e.key === 'Escape' && this.$menu) {
                this.closeMenu();
                return;
            }
            if (!(e.metaKey || e.ctrlKey)) {
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                this.run();
            } else if ((e.key === 's' || e.key === 'S') && this.canSave()) {
                e.preventDefault();
                this.save(false);
            }
        });
    }

    private selectScript(id: number): void {
        const script = this.scripts.find(s => s.id === id);
        if (script && script.id !== this.draft.id) {
            this.guarded(() => this.loadDraft(this.toDraft(script)));
        }
    }

    /** The page fills the window below its title; the code and the result share the rest. */
    private fitHeight(): void {
        const el = this.$root.get(0) as HTMLElement | undefined;
        if (!el?.isConnected) {
            return;
        }
        this.$root.css('height', `${Math.max(420, Math.floor(window.innerHeight - el.getBoundingClientRect().top - 16))}px`);
    }

    // --- Data --------------------------------------------------------------------------------------

    /** Loads the scripts and the input entities, then opens `requested` (the URL's script) when listed. */
    private load(requested: number): void {
        apiFetch(routing.generate('aaxis_ontology_dwl_script_list'), 'GET')
            .then(res => {
                if (!res.ok) {
                    throw new Error(res.data?.message || t('load_error'));
                }
                this.scripts = Array.isArray(res.data?.scripts) ? res.data.scripts : [];
                this.entities = Array.isArray(res.data?.entities) ? res.data.entities : [];
                const script = this.scripts.find(s => s.id === requested);
                this.loadDraft(script ? this.toDraft(script) : this.draft);
            })
            .catch((err: Error) => messenger.notificationFlashMessage('error', err.message || t('load_error')));
    }

    private newDraft(): Draft {
        return {id: null, name: '', script: DEFAULT_SCRIPT, public: false, mine: true, owner: '', inputs: [], limit: DEFAULT_LIMIT};
    }

    private toDraft(script: PlaygroundScript): Draft {
        return {
            id: script.id,
            name: script.name,
            script: script.script,
            public: script.public,
            mine: script.mine,
            owner: script.owner,
            // An input whose entity is gone (or not visible) is no default anymore.
            inputs: (Array.isArray(script.inputs) ? script.inputs : [])
                .filter(input => this.entities.some(e => e.id === input.entityId))
                .map(input => ({entityId: input.entityId, name: input.name})),
            limit: script.limit
        };
    }

    private storedInputs(): PlaygroundInput[] {
        return this.draft.inputs.map(input => ({entityId: input.entityId as number, name: input.name.trim()}));
    }

    private signature(draft: Draft = this.draft): string {
        const inputs = draft.inputs.map(input => [input.entityId, input.name.trim()]);
        return JSON.stringify([draft.name.trim(), draft.script, draft.public, inputs, draft.limit]);
    }

    private runSignature(): string {
        return JSON.stringify([this.draft.script, this.draft.inputs.map(input => [input.entityId, input.name.trim()]), this.draft.limit]);
    }

    private isDirty(): boolean {
        return this.signature() !== this.baseline;
    }

    /** Shows a script (or a new draft) in the editor; its state becomes the clean baseline. */
    private loadDraft(draft: Draft): void {
        this.closeMenu();
        this.draft = {...draft, inputs: draft.inputs.map(input => ({...input}))};
        this.baseline = this.signature();
        this.outcome = null;
        this.ranSignature = null;
        this.part('script-name').val(this.draft.name);
        this.part('script-public').prop('checked', this.draft.public);
        this.field.$textarea.val(this.draft.script);
        this.part('run-limit-on').prop('checked', this.draft.limit !== null);
        this.part('run-limit').val(String(this.draft.limit ?? DEFAULT_LIMIT));
        this.syncScriptUrl();
        this.loadCounts();
        this.renderOutcome();
        this.syncState();
    }

    private readLimit(): number | null {
        if (!this.part('run-limit-on').is(':checked')) {
            return null;
        }
        const value = Math.floor(Number(this.part('run-limit').val()));
        return Number.isFinite(value) && value > 0 ? value : DEFAULT_LIMIT;
    }

    /** `?script=<id>` follows the saved script on screen (a new draft drops it). */
    private syncScriptUrl(): void {
        const params = new URLSearchParams(window.location.search);
        if (this.draft.id === null) {
            params.delete('script');
        } else {
            params.set('script', String(this.draft.id));
        }
        const query = params.toString();
        window.history.replaceState(window.history.state, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash);
    }

    /** The record count of every input entity not counted yet — informational, failures stay blank. */
    private loadCounts(): void {
        this.draft.inputs.forEach(input => {
            const id = input.entityId;
            if (id === null || this.counts.has(id)) {
                return;
            }
            this.counts.set(id, null);
            apiFetch(routing.generate('aaxis_ontology_entity_dwl_count', {id}), 'GET')
                .then(res => {
                    if (res.ok && typeof res.data?.total === 'number') {
                        this.counts.set(id, res.data.total);
                        this.renderInputs();
                    }
                })
                .catch(() => undefined);
        });
    }

    // --- Inputs ------------------------------------------------------------------------------------

    private entityOf(id: number | null): CatalogEntity | undefined {
        return this.entities.find(entity => entity.id === id);
    }

    /** Why this input cannot run yet ('' = it can). */
    private inputProblem(index: number): string {
        const input = this.draft.inputs[index];
        const name = input.name.trim();
        if (input.entityId === null) {
            return t('input_entity_required');
        }
        if (!isInputName(name)) {
            return t('input_name_invalid');
        }
        if (this.draft.inputs.some((other, i) => i !== index && other.name.trim() === name)) {
            return t('input_name_duplicate');
        }
        return '';
    }

    private inputsValid(): boolean {
        return this.draft.inputs.every((_input, index) => this.inputProblem(index) === '');
    }

    /** A new input — the dropdown opens to pick its entity, which names it. */
    private addInput(): void {
        if (this.draft.inputs.length >= MAX_INPUTS) {
            return;
        }
        this.draft.inputs.push({entityId: null, name: '', auto: ''});
        this.syncState();
        this.openMenu(this.draft.inputs.length - 1);
    }

    /**
     * The untouched pass-through script of a one-input draft reads that input: renaming the input
     * renames what the script reads (the user's own code is never touched).
     */
    private followPassThrough(previous: string, name: string): void {
        if (this.draft.inputs.length !== 1 || previous === name || !isInputName(name)) {
            return;
        }
        if (this.draft.script === passThrough(previous) || this.draft.script === DEFAULT_SCRIPT) {
            this.draft.script = passThrough(name);
            this.field.$textarea.val(this.draft.script);
        }
    }

    private removeInput(index: number): void {
        if (!this.draft.inputs[index]) {
            return;
        }
        this.closeMenu(false);
        this.draft.inputs.splice(index, 1);
        this.syncState();
    }

    /** Picks the entity of an input; a name the page chose (or none yet) follows the entity's name. */
    private pickEntity(index: number, entity: CatalogEntity): void {
        const input = this.draft.inputs[index];
        input.entityId = entity.id;
        const name = input.name.trim();
        if (name === '' || name === input.auto) {
            const taken = this.draft.inputs.filter((_other, i) => i !== index).map(other => other.name.trim());
            input.auto = inputNameFor(entity.name, taken);
            input.name = input.auto;
            this.$menu?.find('[data-role="input-name"]').val(input.name);
            this.followPassThrough(name, input.name);
        }
        this.loadCounts();
        this.syncState();
        this.renderMenu();
    }

    /** The input's dropdown: its variable name and a type-to-filter entity list grouped by system. */
    private openMenu(index: number): void {
        this.closeMenu(false);
        this.menuIndex = index;
        this.$menu = $('<div/>', {'class': 'aaxis-dwl-pg__input-menu', 'data-role': 'input-menu', role: 'dialog', 'aria-label': t('inputs_label')});
        const $name = $('<input/>', {
            type: 'text', 'class': 'form-control aaxis-dwl-pg__menu-name', 'data-role': 'input-name',
            maxlength: 64, autocomplete: 'off', spellcheck: false, 'aria-label': t('input_variable')
        });
        const $filter = $('<input/>', {
            type: 'text', 'class': 'form-control aaxis-dwl-pg__menu-filter', 'data-role': 'input-filter',
            placeholder: t('input_filter_placeholder'), 'aria-label': t('input_filter_placeholder'), autocomplete: 'off'
        });
        this.$menu.append(
            $('<label/>', {'class': 'aaxis-dwl-pg__menu-label', text: t('input_variable')}),
            $name,
            $('<div/>', {'class': 'aaxis-dwl-pg__menu-hint', 'data-role': 'input-hint'}),
            $('<label/>', {'class': 'aaxis-dwl-pg__menu-label', text: t('input_entity')}),
            $filter,
            $('<div/>', {'class': 'aaxis-dwl-pg__menu-list', 'data-role': 'input-entities', role: 'listbox'}),
            $('<div/>', {'class': 'aaxis-dwl-pg__menu-actions'}).append(
                button('input-menu-remove', t('input_remove'), 'btn btn-sm'),
                button('input-menu-done', t('input_done'), 'btn btn-sm btn-primary')
            )
        );
        $name.val(this.draft.inputs[index].name);
        $name.on('input', () => {
            const previous = this.draft.inputs[index].name.trim();
            this.draft.inputs[index].name = String($name.val() || '');
            this.followPassThrough(previous, this.draft.inputs[index].name.trim());
            this.syncState();
            this.renderMenuHint();
        });
        $filter.on('input', () => this.renderMenuList());
        $filter.on('keydown', (e: any) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const entity = this.entityOf(Number(this.$menu?.find('[data-role="input-entity"]').first().attr('data-entity-id')));
                if (entity) {
                    this.pickEntity(index, entity);
                }
            }
        });
        // pointerdown, not click: the name/filter would blur first and re-render under the pointer.
        this.$menu.on('pointerdown', '[data-role="input-entity"]', (e: any) => {
            e.preventDefault();
            const entity = this.entityOf(Number($(e.currentTarget).attr('data-entity-id')));
            if (entity) {
                this.pickEntity(index, entity);
            }
        });
        this.$menu.on('click', '[data-role="input-menu-remove"]', () => this.removeInput(index));
        this.$menu.on('click', '[data-role="input-menu-done"]', () => this.closeMenu());
        this.part('inputs').append(this.$menu);
        this.renderMenu();
        this.renderInputs();
        // A new input starts at its entity, an existing one at its name.
        (this.draft.inputs[index].entityId === null ? $filter : $name).trigger('focus');
    }

    /** Closes the dropdown; an input still without an entity is dropped (`dropIncomplete`). */
    private closeMenu(dropIncomplete = true): void {
        if (this.$menu === null) {
            return;
        }
        const index = this.menuIndex;
        this.$menu.remove();
        this.$menu = null;
        this.menuIndex = null;
        if (dropIncomplete && index !== null && this.draft.inputs[index]?.entityId === null) {
            this.draft.inputs.splice(index, 1);
        }
        this.syncState();
    }

    private renderMenu(): void {
        this.renderMenuHint();
        this.renderMenuList();
    }

    private renderMenuHint(): void {
        if (this.$menu === null || this.menuIndex === null) {
            return;
        }
        const problem = this.inputProblem(this.menuIndex);
        const name = this.draft.inputs[this.menuIndex].name.trim();
        const nameProblem = problem !== '' && problem !== t('input_entity_required');
        this.$menu.find('[data-role="input-name"]').toggleClass('is-invalid', nameProblem && name !== '');
        let hint = isInputName(name) ? t('input_hint', {name}) : t('input_hint_entity');
        if (nameProblem && name !== '') {
            hint = problem;
        }
        this.$menu.find('[data-role="input-hint"]').toggleClass('is-error', nameProblem && name !== '').text(hint);
    }

    private renderMenuList(): void {
        if (this.$menu === null || this.menuIndex === null) {
            return;
        }
        const $list = this.$menu.find('[data-role="input-entities"]').empty();
        const query = String(this.$menu.find('[data-role="input-filter"]').val() || '').trim().toLowerCase();
        const picked = this.draft.inputs[this.menuIndex].entityId;
        const matches = this.entities.filter(entity => query === ''
            || entity.name.toLowerCase().includes(query) || (entity.system || '').toLowerCase().includes(query));
        let system: string | null | undefined;
        matches.forEach(entity => {
            if (entity.system !== system) {
                system = entity.system;
                $list.append($('<div/>', {'class': 'aaxis-dwl-pg__menu-group', text: system || '—'}));
            }
            $list.append($('<button/>', {
                type: 'button', 'class': 'aaxis-dwl-pg__menu-option' + (entity.id === picked ? ' is-selected' : ''),
                'data-role': 'input-entity', 'data-entity-id': String(entity.id), role: 'option',
                'aria-selected': String(entity.id === picked), text: entity.name
            }));
        });
        if (!matches.length) {
            $list.append($('<p/>', {'class': 'aaxis-dwl-pg__empty', text: t('input_none')}));
        }
    }

    /** The chips: `variable ← entity · records` (red while the input cannot run), then "Add input". */
    private renderInputs(): void {
        const $inputs = this.part('inputs');
        $inputs.children('[data-role="input-chip"], [data-role="input-add"]').remove();
        const chips = this.draft.inputs.map((input, index) => {
            const entity = this.entityOf(input.entityId);
            const count = input.entityId !== null ? this.counts.get(input.entityId) : null;
            const problem = this.inputProblem(index);
            const $chip = $('<span/>', {
                'class': 'aaxis-dwl-pg__input' + (problem !== '' ? ' is-invalid' : '') + (index === this.menuIndex ? ' is-open' : ''),
                'data-role': 'input-chip', 'data-index': String(index), role: 'button', tabindex: 0,
                title: problem || t('input_edit')
            }).append(
                $('<span/>', {'class': 'aaxis-dwl-pg__input-name', text: input.name.trim() || '?'}),
                $('<span/>', {'class': 'aaxis-dwl-pg__input-arrow', text: '←', 'aria-hidden': 'true'}),
                $('<span/>', {'class': 'aaxis-dwl-pg__input-entity', text: entity?.name || t('input_choose')})
            );
            if (typeof count === 'number') {
                $chip.append($('<span/>', {'class': 'aaxis-dwl-pg__input-count', text: count.toLocaleString()}));
            }
            return $chip.append($('<span/>', {
                'class': 'fa fa-times aaxis-dwl-pg__input-remove', 'data-role': 'input-remove', title: t('input_remove'), 'aria-label': t('input_remove')
            }));
        });
        const $add = button('input-add', t('input_add'), 'aaxis-dwl-pg__input-add').attr('title', t('input_add_title'))
            .prepend($('<span/>', {'class': 'fa fa-plus', 'aria-hidden': 'true'}), ' ')
            .prop('disabled', this.draft.inputs.length >= MAX_INPUTS);
        const $menu = $inputs.children('[data-role="input-menu"]');
        if ($menu.length) {
            $menu.before(...chips, $add);
        } else {
            $inputs.append(...chips, $add);
        }
    }

    // --- Render ------------------------------------------------------------------------------------

    private canSave(): boolean {
        return this.draft.id === null ? this.opts.canCreate === true : this.draft.mine && this.opts.canEdit === true;
    }

    /** Buttons, read-only state, the chips, the list marks and the stale result follow the draft. */
    private syncState(): void {
        const readOnly = !this.draft.mine;
        const inputsValid = this.inputsValid();
        this.part('script-name').prop('readonly', readOnly);
        this.part('script-public').prop('disabled', readOnly);
        this.part('script-owner').text(readOnly ? t('owner', {owner: this.draft.owner}) : '').prop('hidden', !readOnly);
        this.part('script-save').prop('hidden', !this.canSave())
            .prop('disabled', this.saving || !this.isDirty() || this.draft.name.trim() === '' || !inputsValid);
        this.part('script-copy').prop('hidden', !(readOnly && this.opts.canCreate === true)).prop('disabled', this.saving || !inputsValid);
        // The row count only applies while "Max rows" is on.
        this.part('run-limit').prop('disabled', this.draft.limit === null);
        this.part('run').prop('disabled', this.running || !inputsValid).toggleClass('is-busy', this.running);
        this.part('save-as').prop('disabled', this.outcome === null || this.outcome.error !== null);
        this.part('result').toggleClass('is-stale', this.outcome !== null && this.ranSignature !== this.runSignature());
        this.renderInputs();
        this.renderList();
    }

    private renderList(): void {
        const $list = this.part('script-list').empty();
        const query = String(this.part('script-filter').val() || '').trim().toLowerCase();
        const dirty = this.isDirty();
        if (this.draft.id === null) {
            // The new, unsaved script sits on top while it is being written.
            $list.append(this.listItem(null, this.draft.name.trim() || t('untitled'), true, dirty, this.draft));
        }
        const visible = this.scripts.filter(s => query === '' || s.name.toLowerCase().includes(query));
        visible.forEach(script => {
            const active = script.id === this.draft.id;
            // The open script's lock follows the Public switch before it is saved.
            $list.append(this.listItem(script.id, script.name, active, dirty && active, active ? {...script, public: this.draft.public} : script));
        });
        if (!this.scripts.length && this.draft.id !== null) {
            $list.append($('<p/>', {'class': 'aaxis-dwl-pg__empty', text: t('empty_list')}));
        } else if (!visible.length && query !== '') {
            $list.append($('<p/>', {'class': 'aaxis-dwl-pg__empty', text: t('no_match')}));
        }
    }

    /**
     * A list row: the lock (the API collection's padlock — red = private, green = public), the name,
     * the unsaved dot, the owner of someone else's script, and a trash on the user's own saved ones.
     */
    private listItem(id: number | null, name: string, active: boolean, dirty: boolean, meta: {public: boolean; mine: boolean; owner: string}): any {
        const $item = $('<div/>', {
            'class': 'aaxis-dwl-pg__item' + (active ? ' is-active' : '') + (id === null ? ' is-draft' : ''),
            'data-role': id === null ? 'script-draft' : 'script-item', role: 'option', tabindex: 0,
            'aria-selected': String(active), title: name
        });
        if (id !== null) {
            $item.attr('data-script-id', String(id));
        }
        const lock = meta.public ? t('lock_public') : t('lock_private');
        $item.append(
            $('<span/>', {
                'class': 'fa ' + (meta.public ? 'fa-unlock-alt is-public' : 'fa-lock') + ' aaxis-dwl-pg__item-lock',
                'data-role': 'script-lock', title: lock, 'aria-label': lock
            }),
            $('<span/>', {'class': 'aaxis-dwl-pg__item-name', text: name})
        );
        if (dirty) {
            $item.append($('<span/>', {'class': 'aaxis-dwl-pg__item-dirty', title: t('unsaved'), 'aria-label': t('unsaved')}));
        }
        if (!meta.mine) {
            $item.append($('<span/>', {'class': 'aaxis-dwl-pg__item-owner', text: meta.owner}));
        }
        if (id !== null && meta.mine && this.opts.canDelete === true) {
            $item.append(button('script-item-delete', t('delete'), 'aaxis-dwl-pg__item-delete', 'fa-trash-o'));
        }
        return $item;
    }

    /** The result pane: the last output (as the script's `output` header rendered it), or the error. */
    private renderOutcome(): void {
        const $result = this.part('result');
        const outcome = this.outcome;
        $result.toggleClass('is-error', outcome?.error != null);
        if (outcome === null) {
            $result.val('');
            this.part('run-status').text('').attr('title', '');
            return;
        }
        $result.val(outcome.error ?? outcome.text);
        // "100 of 6,236 rows shown · orders: 6,236 records · lines: 9,120 records"
        const parts = outcome.inputs.map(input => t('status_input', {name: input.name, records: input.records.toLocaleString()}));
        const output = outcome.output;
        if (output !== null) {
            parts.unshift(output.truncated
                ? t('status_rows_limited', {shown: output.shown.toLocaleString(), rows: output.rows.toLocaleString()})
                : t('status_rows', {rows: output.rows.toLocaleString()}));
        }
        let status = parts.join(' · ');
        if (outcome.error !== null) {
            status = status ? `${t('run_failed')} — ${status}` : t('run_failed');
        }
        this.part('run-status').text(status).attr('title', status);
    }

    // --- Actions -----------------------------------------------------------------------------------

    private run(): void {
        if (this.running || !this.inputsValid()) {
            return;
        }
        this.closeMenu();
        this.running = true;
        this.syncState();
        const signature = this.runSignature();
        apiFetch(routing.generate('aaxis_ontology_dwl_script_run'), 'POST', {
            script: this.draft.script,
            inputs: this.storedInputs(),
            limit: this.draft.limit
        })
            .then(res => {
                const data = res.data || {};
                const failed = !res.ok || data.success !== true;
                this.outcome = {
                    text: failed ? '' : String(data.result ?? ''),
                    error: failed ? String(data.error || data.message || t('run_error')) : null,
                    mime: String(data.mime || 'text/plain'),
                    extension: String(data.extension || 'txt'),
                    inputs: Array.isArray(data.inputs) ? data.inputs : [],
                    output: !failed && data.output ? data.output : null
                };
            })
            .catch(() => {
                this.outcome = {text: '', error: t('run_error'), mime: 'text/plain', extension: 'txt', inputs: [], output: null};
            })
            .finally(() => {
                this.ranSignature = signature;
                this.running = false;
                this.renderOutcome();
                this.syncState();
            });
    }

    /**
     * "Save as…": the last result, exactly as shown, to a local file named after the script with the
     * extension of its `output` format. Where the browser offers a save dialog (File System Access
     * API) the user picks the place and name; elsewhere the file downloads.
     */
    private saveAs(): void {
        const outcome = this.outcome;
        if (outcome === null || outcome.error !== null) {
            return;
        }
        const base = (this.draft.name.trim() || 'result').replace(/[\\/:*?"<>|]+/g, '_');
        const fileName = `${base}.${outcome.extension}`;
        const picker = (window as any).showSaveFilePicker;
        if (typeof picker !== 'function') {
            this.download(fileName, outcome);
            return;
        }
        picker.call(window, {suggestedName: fileName, types: [{description: outcome.mime, accept: {[outcome.mime]: [`.${outcome.extension}`]}}]})
            .then((handle: any) => handle.createWritable())
            .then((writable: any) => writable.write(outcome.text).then(() => writable.close()))
            .catch((err: any) => {
                // Cancelling the dialog is not an error.
                if (err?.name !== 'AbortError') {
                    messenger.notificationFlashMessage('error', t('save_as_error'));
                }
            });
    }

    private download(fileName: string, outcome: RunOutcome): void {
        const url = URL.createObjectURL(new Blob([outcome.text], {type: outcome.mime}));
        const $link = $('<a/>', {href: url, download: fileName}).css('display', 'none').appendTo(document.body);
        $link[0].click();
        $link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 0);
    }

    /** Saves the draft — or, `asCopy`, a new private script of the user's own with its content. */
    private save(asCopy: boolean): void {
        if (this.saving || !this.inputsValid() || (!asCopy && !this.canSave()) || (asCopy && this.opts.canCreate !== true)) {
            return;
        }
        this.closeMenu();
        const create = asCopy || this.draft.id === null;
        const body = {
            name: asCopy ? this.copyName(this.draft.name) : this.draft.name.trim(),
            script: this.draft.script,
            public: asCopy ? false : this.draft.public,
            inputs: this.storedInputs(),
            limit: this.draft.limit
        };
        this.saving = true;
        this.syncState();
        // The button that saves shows the request is under way until the answer arrives.
        const $button = this.part(asCopy ? 'script-copy' : 'script-save').attr('aria-busy', 'true')
            .prepend($('<span/>', {'class': 'fa fa-spinner fa-spin aaxis-dwl-pg__saving', 'data-role': 'saving', 'aria-hidden': 'true'}));
        const url = create
            ? routing.generate('aaxis_ontology_dwl_script_create')
            : routing.generate('aaxis_ontology_dwl_script_update', {id: this.draft.id});
        apiFetch(url, create ? 'POST' : 'PUT', body)
            .then(res => {
                if (!res.ok || res.data?.success !== true) {
                    throw new Error(res.data?.message || t('save_error'));
                }
                const saved = res.data.script as PlaygroundScript;
                this.scripts = [...this.scripts.filter(s => s.id !== saved.id), saved]
                    .sort((a, b) => a.name.localeCompare(b.name, undefined, {sensitivity: 'base', numeric: true}) || a.id - b.id);
                const outcome = this.outcome;
                const ranSignature = this.ranSignature;
                this.loadDraft(this.toDraft(saved));
                // Saving changes nothing about the run on screen.
                this.outcome = outcome;
                this.ranSignature = ranSignature;
                this.renderOutcome();
                messenger.notificationFlashMessage('success', t('saved'));
            })
            .catch((err: Error) => messenger.notificationFlashMessage('error', err.message || t('save_error')))
            .finally(() => {
                $button.removeAttr('aria-busy').find('[data-role="saving"]').remove();
                this.saving = false;
                this.syncState();
            });
    }

    /** "<name> (copy)", then "(copy 2)", … — free among the user's own scripts. */
    private copyName(name: string): string {
        const base = `${name.trim()} ${t('copy_suffix')}`;
        const taken = new Set(this.scripts.filter(s => s.mine).map(s => s.name));
        let candidate = base;
        for (let n = 2; taken.has(candidate); n++) {
            candidate = `${base.slice(0, -1)} ${n})`;
        }
        return candidate.slice(0, 128);
    }

    /** Deletes one of the user's own scripts (from its list row); the open one gives way to a new draft. */
    private confirmDelete(script: PlaygroundScript): void {
        if (!script.mine) {
            return;
        }
        const id = script.id;
        this.confirm(t('delete_title'), t('delete_confirm', {name: script.name}), t('delete'), () => {
            apiFetch(routing.generate('aaxis_ontology_dwl_script_delete', {id}), 'DELETE')
                .then(res => {
                    if (!res.ok || res.data?.success !== true) {
                        throw new Error(res.data?.message || t('delete_error'));
                    }
                    this.scripts = this.scripts.filter(s => s.id !== id);
                    if (this.draft.id === id) {
                        this.loadDraft(this.newDraft());
                    } else {
                        this.renderList();
                    }
                    messenger.notificationFlashMessage('success', t('deleted'));
                })
                .catch((err: Error) => messenger.notificationFlashMessage('error', err.message || t('delete_error')));
        });
    }

    /** Runs `next` at once — or after confirming that the unsaved changes may be discarded. */
    private guarded(next: () => void): void {
        if (!this.isDirty()) {
            next();
            return;
        }
        const name = this.draft.name.trim() || t('untitled');
        this.confirm(t('discard_title'), t('discard_confirm', {name}), t('discard'), next);
    }

    private confirm(title: string, question: string, confirmLabel: string, onConfirm: () => void): void {
        const dialog = new Dialog({title, width: '440px'});
        const $content = dialog.open();
        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', text: t('cancel')});
        const $confirm = $('<button/>', {type: 'button', 'class': 'btn btn-primary', text: confirmLabel});
        $content.append($('<div/>', {'class': 'aaxis-ontology-confirm'}).append(
            $('<p/>', {'class': 'aaxis-ontology-confirm__q', text: question}),
            $('<div/>', {'class': 'aaxis-ontology-confirm__actions'}).append($cancel, $confirm)
        ));
        $cancel.on('click', () => dialog.close());
        $confirm.on('click', () => {
            dialog.close();
            onConfirm();
        });
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$root?.off('.aaxisDataPlayground');
        $(window).off('.aaxisDataPlayground');
        mediator.off('openLink:before', this.onOpenLinkBefore);
        document.removeEventListener('pointerdown', this.onDocPointerDown, true);
        super.dispose();
    }
}

export default DataPlaygroundComponent;

import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import mediator from 'oroui/js/mediator';
import pageStateChecker from 'oronavigation/js/app/services/page-state-checker';
import BaseComponent from 'oroui/js/app/components/base/component';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import createDwlField from '../widgets/dwl-field';
import {apiFetch, csrfToken} from './component-support';

interface FlowStep {
    type: string;
    name: string;
    x: number;
    y: number;
    config?: Record<string, any> | null;
}

/**
 * Validates a linux (vixie) cron expression: the @-macros or 5 whitespace-separated fields
 * (minute, hour, day-of-month, month, day-of-week), each a comma list of `*`, values, ranges,
 * with optional /step; month and weekday accept their english names.
 */
/**
 * Strips every leading and trailing `ch` from `value`.
 *
 * A scan rather than /^c+|c+$/g: that pattern's trailing alternative backtracks quadratically on a
 * long run of the character, which a pasted label or path can trivially contain.
 */
function trimChar(value: string, ch: string): string {
    let start = 0;
    let end = value.length;
    while (start < end && value[start] === ch) {
        start++;
    }
    while (end > start && value[end - 1] === ch) {
        end--;
    }

    return value.slice(start, end);
}

function isValidCron(expression: string): boolean {
    const expr = expression.trim().toLowerCase();
    if (/^@(reboot|yearly|annually|monthly|weekly|daily|midnight|hourly)$/.test(expr)) {
        return true;
    }
    const fields = expr.split(/\s+/);
    if (fields.length !== 5) {
        return false;
    }
    const specs: {min: number; max: number; names?: string[]}[] = [
        {min: 0, max: 59},
        {min: 0, max: 23},
        {min: 1, max: 31},
        {min: 1, max: 12, names: ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec']},
        {min: 0, max: 7, names: ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']}
    ];
    return fields.every((field, i) => {
        const spec = specs[i];
        const value = (token: string): number => {
            if (spec.names) {
                const named = spec.names.indexOf(token);
                if (named >= 0) {
                    return named + (i === 3 ? 1 : 0); // months are 1-based, weekdays 0-based
                }
            }
            return /^\d+$/.test(token) ? Number(token) : Number.NaN;
        };
        return field.split(',').every(item => {
            const [range, step, extra] = item.split('/');
            if (extra !== undefined || range === '' || step === '') {
                return false;
            }
            if (step !== undefined && (!/^\d+$/.test(step) || Number(step) < 1)) {
                return false;
            }
            if (range === '*') {
                return true;
            }
            const [lo, hi, more] = range.split('-');
            if (more !== undefined) {
                return false;
            }
            const loV = value(lo);
            if (!Number.isFinite(loV) || loV < spec.min || loV > spec.max) {
                return false;
            }
            if (hi === undefined) {
                return true; // single value (cron tolerates a /step on it)
            }
            const hiV = value(hi);
            return Number.isFinite(hiV) && hiV >= loV && hiV <= spec.max;
        });
    });
}

/**
 * Renders a value as a collapsible JSON tree: objects/arrays carry a caret and expand/collapse on
 * click (everything starts expanded; a collapsed node shows an item-count preview), primitives
 * reuse the aaxis-json-* color classes. JSON-faithful punctuation, pure DOM.
 */
function renderJsonTree(value: any): HTMLElement {
    const root = document.createElement('div');
    root.className = 'aaxis-json-view aaxis-json-tree';
    root.appendChild(jsonTreeNode(value, null, true));
    return root;
}

function jsonTreeNode(value: any, key: string | null, isLast: boolean): HTMLElement {
    const node = document.createElement('div');
    node.className = 'aaxis-json-tree__node';
    const line = document.createElement('div');
    line.className = 'aaxis-json-tree__line';
    node.appendChild(line);
    const comma = isLast ? '' : ',';

    const spacer = (): HTMLElement => {
        const el = document.createElement('span');
        el.className = 'aaxis-json-tree__spacer';
        return el;
    };
    const appendKey = (): void => {
        if (key !== null) {
            const k = document.createElement('span');
            k.className = 'aaxis-json-key';
            k.textContent = JSON.stringify(key);
            line.appendChild(k);
            line.appendChild(document.createTextNode(': '));
        }
    };

    const isArray = Array.isArray(value);
    if (!isArray && (value === null || typeof value !== 'object')) {
        line.appendChild(spacer());
        appendKey();
        const v = document.createElement('span');
        v.className = value === null ? 'aaxis-json-null'
            : typeof value === 'string' ? 'aaxis-json-str'
                : typeof value === 'number' ? 'aaxis-json-num' : 'aaxis-json-bool';
        v.textContent = JSON.stringify(value);
        line.appendChild(v);
        line.appendChild(document.createTextNode(comma));
        return node;
    }

    const entries: [string | null, any][] = isArray
        ? value.map((item: any) => [null, item])
        : Object.keys(value).map(k => [k, value[k]]);
    const open = isArray ? '[' : '{';
    const closeCh = isArray ? ']' : '}';

    if (entries.length === 0) {
        line.appendChild(spacer());
        appendKey();
        line.appendChild(document.createTextNode(open + closeCh + comma));
        return node;
    }

    node.classList.add('aaxis-json-tree__node--collapsible');
    const caret = document.createElement('button');
    caret.type = 'button';
    caret.className = 'aaxis-json-tree__caret';
    caret.setAttribute('aria-label', 'Expand/collapse');
    line.appendChild(caret);
    appendKey();
    line.appendChild(document.createTextNode(open));
    const preview = document.createElement('span');
    preview.className = 'aaxis-json-tree__preview';
    preview.textContent = ` … ${entries.length} ${isArray ? 'item' : 'key'}${entries.length === 1 ? '' : 's'} … ${closeCh}${comma}`;
    line.appendChild(preview);

    const children = document.createElement('div');
    children.className = 'aaxis-json-tree__children';
    entries.forEach(([childKey, childValue], i) => {
        children.appendChild(jsonTreeNode(childValue, childKey, i === entries.length - 1));
    });
    node.appendChild(children);

    const closeLine = document.createElement('div');
    closeLine.className = 'aaxis-json-tree__line aaxis-json-tree__close';
    closeLine.appendChild(spacer());
    closeLine.appendChild(document.createTextNode(closeCh + comma));
    node.appendChild(closeLine);

    // The whole opening line toggles (bigger target than the caret alone).
    line.addEventListener('click', () => node.classList.toggle('is-collapsed'));
    return node;
}

/**
 * The debugger's Variables view: each top-level context entry on its own line — objects/arrays
 * START COLLAPSED (count preview, expandable), primitives inline. Same tree pieces as
 * renderJsonTree, minus the wrapping root braces.
 */
function renderVariablesList(context: Record<string, any>): HTMLElement {
    const root = document.createElement('div');
    root.className = 'aaxis-json-view aaxis-json-tree';
    const keys = Object.keys(context);
    if (keys.length === 0) {
        root.appendChild(jsonTreeNode({}, null, true));
        return root;
    }
    keys.forEach(key => {
        const node = jsonTreeNode(context[key], key, true);
        if (node.classList.contains('aaxis-json-tree__node--collapsible')) {
            node.classList.add('is-collapsed');
        }
        root.appendChild(node);
    });
    return root;
}

/** A directed connection: output port `fromPort` of step `from` → the input of step `to`. */
interface FlowLink {
    from: string;
    fromPort: number;
    to: string;
}

interface FlowRecord {
    id: number;
    name: string | null;
    enabled: boolean;
    type: string;
    steps: FlowStep[] | null;
    design: any;
    invalidSteps?: string[];
}

/** One open editor tab: a flow (or a new one) plus its stashed working state while inactive. */
interface EditorTab {
    flow: FlowRecord | null;
    /** The canvas state captured when the tab was last active; null = never activated/stashed. */
    design: any | null;
    /** The dirty-tracking baseline captured on stash; null = fresh (baseline set on activation). */
    savedState: string | null;
    invalidNames: string[];
}

/** Bump when the persisted design shape changes — older designs then load as "corrupted". */
const DESIGN_VERSION = 2;

const SVG_NS = 'http://www.w3.org/2000/svg';

/** How many output ports a step type exposes ("choice" branches, everything else is linear). */
function portCount(type: string): number {
    return type === 'choice' ? 2 : 1;
}

const OPPOSITE_SIDE: Record<AnchorSide, AnchorSide> = {north: 'south', south: 'north', east: 'west', west: 'east'};

/**
 * VSCode-style debugger glyphs (16×16, drawn with currentColor so CSS owns the palette):
 * continue = bar + hollow triangle, step over = arc onto a dot, step into/out = arrow to/from a
 * dot, stop = hollow square.
 */
const DEBUG_ICONS: Record<string, string> = {
    continue: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">'
        + '<rect x="3" y="3" width="2" height="10" fill="currentColor"/>'
        + '<path d="M7 3.6 L13.2 8 L7 12.4 Z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>',
    over: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">'
        + '<path d="M2.8 9.2 A5.2 5.2 0 0 1 13 8.2" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
        + '<path d="M13.9 4.6 L13.4 9.3 L9.7 7.1 Z" fill="currentColor"/>'
        + '<circle cx="8" cy="12.6" r="1.8" fill="currentColor"/></svg>',
    into: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">'
        + '<path d="M8 1.6 V7.4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
        + '<path d="M4.7 6.2 L11.3 6.2 L8 10.4 Z" fill="currentColor"/>'
        + '<circle cx="8" cy="13.4" r="1.8" fill="currentColor"/></svg>',
    out: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">'
        + '<path d="M8 9.4 V5.4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
        + '<path d="M4.7 5.8 L11.3 5.8 L8 1.6 Z" fill="currentColor"/>'
        + '<circle cx="8" cy="13.4" r="1.8" fill="currentColor"/></svg>',
    stop: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">'
        + '<rect x="3.5" y="3.5" width="9" height="9" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>'
};

/** Seeded into a freshly-dropped Endpoint trigger: the Response binding's example shape. */
const ENDPOINT_RESPONSE_EXAMPLE = '{\n    statusCode: 200,\n    body: payload\n}';

/**
 * Step types a fresh drop CANNOT run yet — they need configuration beyond the name, so the new
 * tile starts red (is-config-invalid) until its properties are confirmed/saved complete. MUST
 * mirror FlowStepValidator::isStepConfigValid (server side, where an absent config now counts as
 * incomplete for these types); triggers (seeded {enabled: true}) and the parameterless
 * placeholder types are valid empty and start clean.
 */
const REQUIRES_CONFIG = new Set([
    'choice', 'sub_flow', 'foreach', 'logger', 'event', 'ms_teams', 'invoke_php',
    'dwl_transform', 'invoke', 'entity_read', 'entity_write', 'sql_query',
    'file_read', 'file_write', 'file_list', 'file_delete', 'file_rename'
]);

/** The four tile sides a flow line can attach to. */
type AnchorSide = 'north' | 'south' | 'east' | 'west';

const ANCHOR_SIDES: AnchorSide[] = ['north', 'south', 'east', 'west'];

/** Outward unit vector of each side (canvas coordinates: y grows downward). */
const SIDE_VECTOR: Record<AnchorSide, {dx: number; dy: number}> = {
    north: {dx: 0, dy: -1},
    south: {dx: 0, dy: 1},
    west: {dx: -1, dy: 0},
    east: {dx: 1, dy: 0}
};

interface FlowEditorOptions {
    _sourceElement: any;
    flow?: FlowRecord | null;
    gridSpacing?: number;
    stepSizeFactor?: number;
    listUrl?: string;
}

interface StepMeta {
    category: string;
    icon: string;
    label: string;
}

interface PlacedStep extends FlowStep {
    id: string;
    el: HTMLElement;
    /** The stored config is incomplete/invalid — the tile renders red and the flow cannot run. */
    invalid?: boolean;
    /** Which side incoming links arrive at (default west). */
    inSide?: AnchorSide;
    /** Which side each output port leaves from (defaults: port 0 east; choice port 1 south). */
    outSides?: AnchorSide[];
}

/**
 * Flow editor page: top bar with the flow name + enabled switch, toolbox toggle and cancel/save,
 * over a dot-matrix canvas (spacing from System Configuration). Steps are dragged from the
 * floating toolbox onto the canvas as Z×Z tiles (Z = configurable multiple of the dot spacing,
 * default 8×) showing the step icon with the step NAME (unique, up to two rows) centered below.
 * Tiles move freely afterwards, always snapping to the grid; double-click opens the step settings
 * modal. Only one trigger step is allowed — dropping a second one asks to replace it.
 *
 * Flow LINKS are dragged from a tile's "×" output port (right edge; choice exposes two ports,
 * everything else one) onto another tile: each port drives one link (re-dragging re-wires it),
 * each element receives at most ONE incoming link, triggers accept none, and links render as
 * arrows arriving at the target's left-center (2px off the border). Everything is persisted in
 * the flow's `design` (steps carry stable ids; links reference them, so renames are free).
 */
class OntologyFlowEditorComponent extends BaseComponent {
    /**
     * Pointer travel (px) that separates a click from a drag. A `static readonly` is exempt from the
     * no-field-initializer rule below — it belongs to the class, not the instance, so it is set
     * before any constructor runs.
     */
    private static readonly DRAG_THRESHOLD = 5;

    // NO field initializers here: BaseComponent's constructor calls initialize() BEFORE the
    // subclass field initializers would run, and any initializer would then also OVERWRITE what
    // initialize() assigned. Everything is definite-assigned (!) and set up in initialize().
    private $el!: any;
    private flow!: FlowRecord | null;
    private listUrl!: string;
    private spacing!: number;
    private tileSize!: number;
    private stepMeta!: Record<string, StepMeta>;
    private steps!: PlacedStep[];

    private links!: FlowLink[];
    private discarding!: boolean;
    private pendingTabIds!: number[];
    /** While true, tab switches keep the debug session alive (the subflow visit). */
    private debugVisiting!: boolean;
    /** Synchronous copy of the flow catalog once fetched (context menus need sync reads). */
    private catalogFlowRecords!: FlowRecord[] | null;
    /** Seconds before a FINISHED debug pane closes itself (System Configuration; 0 = never). */
    private debugAutoCloseSeconds!: number;
    /** Index of the tab being dragged for reordering (null when no tab drag is active). */
    private tabDragIndex!: number | null;
    /** True while Save all is persisting the dirty tabs (both save buttons disabled). */
    private savingAll!: boolean;
    private dirtyChecker!: () => boolean;
    private onOpenLinkBefore!: (e: any) => void;
    private onBeforeRefresh!: (queue: any[]) => void;
    // Sub-flow entry marker: the id of the step the flow "starts at" when there is no trigger,
    // drawn as an origin-less arrow into that step.
    private startId!: string | null;
    private wires!: SVGSVGElement;
    private selection!: Set<PlacedStep>;
    private menuEl!: HTMLElement | null;
    private redrawScheduled!: boolean;
    private savedState!: string;

    private panelDrag!: {pointerId: number; dx: number; dy: number} | null;
    private tabs!: EditorTab[];
    private activeTab!: number;
    /**
     * Toolbox → canvas drag. `el` is null until the pointer has actually moved past
     * {@see DRAG_THRESHOLD}: a plain click on a toolbox item must not spawn anything.
     */
    private ghostDrag!: {
        pointerId: number;
        type: string;
        el: HTMLElement | null;
        startX: number;
        startY: number;
    } | null;
    private stepDrag!: {
        pointerId: number;
        step: PlacedStep;
        dx: number;
        dy: number;
        // Other selected tiles moving along, as offsets relative to the dragged one.
        group: {step: PlacedStep; offX: number; offY: number}[];
    } | null;
    /**
     * Drawing a wire. `editing` is set when the user grabbed the ARROW of an existing link to
     * re-route it: that link is pulled out of `links` for the duration of the drag and either
     * re-added (retargeted / cancelled) or left out (deleted) on drop.
     */
    private linkDrag!: {
        pointerId: number;
        from: PlacedStep;
        fromPort: number;
        path: SVGPathElement;
        target: PlacedStep | null;
        editing: FlowLink | null;
    } | null;
    private marqueeDrag!: {pointerId: number; x0: number; y0: number; el: HTMLElement; moved: boolean} | null;
    /** Active Debug / Run Now session (the sidebar); steps+links are a SNAPSHOT taken at start. */
    private debugSession!: {
        mode: 'step' | 'run';
        input: Record<string, any>;
        steps: {id: string; type: string; name: string; config: Record<string, any> | null}[];
        links: {from: string; fromPort: number; to: string}[];
        context: Record<string, any> | null;
        /** Server-side session handle: the context lives in the app cache between step calls. */
        contextKey: string | null;
        index: number;
        total: number;
        done: boolean;
        busy: boolean;
        /** Which sidebar button started the in-flight request — it carries the spinner. */
        busyAction: 'next' | 'runAll' | 'into' | 'out' | 'visit' | null;
        error: string | null;
        statusLabel: string;
        /** Amber ids per canvas (key: flow id, or 'root' for the debugged flow) — a tab switch
         * re-renders the canvas, so the marks are repainted from here. */
        marksByFlow: Record<string, string[]>;
        failedId: string | null;
        /** The debugged flow's tab index — where 'returned' transitions come home to. */
        homeIndex: number;
        /** What the next tick would execute — Step into appears when it is a subflow invoker. */
        next: {id: string; name: string; type: string} | null;
        /** Current frame depth (0 = the debugged flow) — Step out appears inside a subflow. */
        depth: number;
        /** The auto-dismiss countdown once the run FINISHED (config seconds; 0 = never). */
        autoClose: {deadline: number; duration: number; timer: number} | null;
    } | null;
    private debugUi!: {$status: any; $vars: any; $actions: any; evalField: any; $evalRun: any} | null;
    private onPointerMove!: (e: PointerEvent) => void;
    private onPointerUp!: (e: PointerEvent) => void;
    private onDocPointerDown!: (e: PointerEvent) => void;
    /**
     * Catalogs the step settings panel needs (systems+entities, connectors), fetched at most ONCE
     * per editor session. Reopening a reader/writer panel used to re-request both every time, and a
     * round trip costs well over a second in dev (kernel boot, not the queries) — so the panel took
     * seconds to populate on every double-click. Holding the promises makes reopening instant.
     * Null = not requested yet (or the last attempt failed and may be retried).
     */
    private catalogEntities!: Promise<any> | null;
    private catalogConnectors!: Promise<any> | null;
    private catalogFlows!: Promise<any> | null;
    private catalogPhpClasses!: Promise<any> | null;
    private phpMethodsCache!: Record<string, Promise<any>>;

    initialize(options: FlowEditorOptions): void {
        this.$el = options._sourceElement;
        this.flow = options.flow || null;
        this.listUrl = options.listUrl || routing.generate('aaxis_ontology_flows');
        this.stepMeta = {};
        this.catalogEntities = null;
        this.catalogConnectors = null;
        this.catalogFlows = null;
        this.catalogPhpClasses = null;
        this.phpMethodsCache = {};
        this.steps = [];
        this.links = [];
        this.startId = null;
        this.selection = new Set();
        this.menuEl = null;
        this.redrawScheduled = false;
        this.savedState = '';
        this.panelDrag = null;
        this.ghostDrag = null;
        this.stepDrag = null;
        this.linkDrag = null;
        this.marqueeDrag = null;
        this.debugSession = null;
        this.debugUi = null;
        this.onPointerMove = (e: PointerEvent): void => this.pointerMove(e);
        this.onPointerUp = (e: PointerEvent): void => this.pointerUp(e);
        // Closes the context menu on any press outside it (including outside the editor).
        this.onDocPointerDown = (e: PointerEvent): void => {
            if (this.menuEl && !this.menuEl.contains(e.target as Node)) {
                this.closeContextMenu();
            }
        };

        this.spacing = Math.min(100, Math.max(4, Number(options.gridSpacing) || 10));
        const factor = Math.min(16, Math.max(2, Number(options.stepSizeFactor) || 8));
        this.debugAutoCloseSeconds = Math.max(0, Number((options as any).debugAutoCloseSeconds ?? 15) || 0);
        this.tabDragIndex = null;
        this.savingAll = false;
        this.tileSize = factor * this.spacing;

        this.syncFlowNameDisplay();

        // Dot-matrix spacing is a CSS custom property so the SCSS owns the pattern itself.
        this.canvas().style.setProperty('--aaxis-flow-grid', `${this.spacing}px`);

        // type → {category, icon, label}, harvested from the server-rendered toolbox items.
        this.$el.find('[data-role="toolbox"] [data-step-type]').each((_i: number, el: HTMLElement) => {
            const $item = $(el);
            this.stepMeta[String($item.data('stepType'))] = {
                category: String($item.data('stepCategory')),
                icon: String($item.data('stepIcon')),
                label: String($item.data('stepLabel'))
            };
        });

        // Fill the viewport below Oro's page chrome exactly (the CSS calc is only a fallback) and
        // keep it fitted — and the toolbox reachable — across window resizes.
        this.fitEditorHeight();
        $(window).on('resize.aaxisFlowEditor', () => {
            this.fitEditorHeight();
            this.clampToolboxIntoView();
        });

        // Unsaved-changes guards, ANY open tab counts: the browser's native prompt for close /
        // reload / external URLs, the same mediator hook Oro's PageStateView uses for its forms
        // for in-app (pushState) navigation and the toolbar refresh, plus the checker registry
        // other components (grid inline editing, config form, hidden redirects) consult.
        this.discarding = false;
        this.debugVisiting = false;
        this.catalogFlowRecords = null;
        this.dirtyChecker = () => this.anyTabDirty();
        pageStateChecker.registerChecker(this.dirtyChecker);
        $(window).on('beforeunload.aaxisFlowEditor', (e: any) => {
            if (this.anyTabDirty()) {
                e.preventDefault();
                const message = __('oro.ui.leave_page_with_unsaved_data_confirm');
                (e.originalEvent || e).returnValue = message;
                return message;
            }
        });
        this.onOpenLinkBefore = (e: any) => {
            if (!e.prevented && this.anyTabDirty()) {
                e.prevented = !window.confirm(__('oro.ui.leave_page_with_unsaved_data_confirm'));
            }
        };
        mediator.on('openLink:before', this.onOpenLinkBefore);
        this.onBeforeRefresh = (queue: any[]) => {
            if (this.anyTabDirty()) {
                const deferred = ($ as any).Deferred();
                if (window.confirm(__('oro.ui.leave_page_with_unsaved_data_confirm'))) {
                    deferred.resolve();
                } else {
                    deferred.reject();
                }
                queue.push(deferred);
            }
        };
        mediator.on('page:beforeRefresh', this.onBeforeRefresh);

        this.buildWires();
        this.restore();
        this.applyInvalidStepNames((this.flow && (this.flow as any).invalidSteps) || []);
        this.restoreToolboxState();
        // Sync the toggle's active state with however the restore left the toolbox.
        this.setToolboxVisible(!this.toolbox().hidden, false);
        this.markSaved();
        // The tab bar: the URL's flow is the first tab; more open via the folder/+ action tabs.
        // ?tabs= (a previous session's open set) is parsed BEFORE anything rewrites the URL.
        this.pendingTabIds = (new URLSearchParams(window.location.search).get('tabs') || '')
            .split(',')
            .map(x => parseInt(x, 10))
            .filter(n => Number.isFinite(n) && n > 0);
        this.tabs = [{flow: this.flow, design: null, savedState: null, invalidNames: (this.flow as any)?.invalidSteps || []}];
        this.activeTab = 0;
        this.renderTabs();
        this.restoreTabsFromUrl();
        this.$el.on('click.aaxisFlowEditor', '[data-role="tab"]', (e: any) => {
            e.preventDefault();
            this.switchTab(Number($(e.currentTarget).data('tabIndex')));
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="tab-close"]', (e: any) => {
            e.preventDefault();
            e.stopPropagation();
            this.closeTab(Number($(e.currentTarget).closest('[data-role="tab"]').data('tabIndex')));
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="tab-open"]', (e: any) => {
            e.preventDefault();
            this.openFlowPicker();
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="tab-add"]', (e: any) => {
            e.preventDefault();
            this.tabs.push({flow: null, design: null, savedState: null, invalidNames: []});
            this.switchTab(this.tabs.length - 1);
        });
        // Warm the settings catalogs in the background so the first panel opens instantly too.
        this.prefetchCatalogs();

        // While the finished-debug COUNTDOWN runs, any click outside the debug pane (the design
        // space) dismisses it immediately — the user is back to work, no need to wait it out.
        // The click keeps its normal effect (select a tile, open a menu, …).
        this.$el.on('click.aaxisFlowEditor', (e: any) => {
            if (this.debugSession && this.debugSession.autoClose
                && !$(e.target).closest('[data-role="debugger"]').length
            ) {
                this.closeDebugSession();
            }
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="cancel"]', (e: any) => {
            e.preventDefault();
            // Discard/Close is SCOPED TO THE ACTIVE TAB: its changes are dropped and the tab
            // closes; the other tabs keep their work untouched. Only closing the LAST tab
            // leaves the editor — and being explicit, neither path raises the unsaved prompts
            // for THIS tab (a lone tab means no other work to guard).
            if (this.tabs.length > 1) {
                this.closeTab(this.activeTab, true);
                return;
            }
            this.discarding = true;
            window.location.href = this.listUrl;
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="save-all"]', (e: any) => {
            e.preventDefault();
            this.saveAll();
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="save"]', (e: any) => {
            e.preventDefault();
            this.save();
        });
        this.$el.on('pointerdown.aaxisFlowEditor', '[data-role="toolbox-handle"]', (e: any) => {
            this.closeContextMenu();
            this.clearSelection();
            // The hide button inside the handle must click, not start a drag.
            if (e.originalEvent.button === 0 && !$(e.target).closest('[data-role="toolbox-hide"]').length) {
                this.startPanelDrag(e.originalEvent as PointerEvent);
            }
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="toolbox-toggle"]', (e: any) => {
            e.preventDefault();
            this.closeContextMenu();
            this.clearSelection();
            // DOM lib types `hidden` as string | boolean ("until-found") — coerce for the toggle.
            this.setToolboxVisible(Boolean(this.toolbox().hidden));
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="toolbox-hide"]', (e: any) => {
            e.preventDefault();
            this.setToolboxVisible(false);
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="organize"]', (e: any) => {
            e.preventDefault();
            this.closeContextMenu();
            this.clearSelection();
            this.organizeSteps();
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="debug"]', (e: any) => {
            e.preventDefault();
            this.closeContextMenu();
            // Run Now: the whole flow in one request; the sidebar shows the result at the end.
            this.collectDebugInput(input => this.startDebugSession('run', input));
        });
        this.$el.on('click.aaxisFlowEditor', '[data-role="debug-step"]', (e: any) => {
            e.preventDefault();
            this.closeContextMenu();
            // Debug: step-by-step in the sidebar — variables, stepper buttons, DWL evaluator.
            this.collectDebugInput(input => this.startDebugSession('step', input));
        });
        this.$el.on('pointerdown.aaxisFlowEditor', '[data-role="toolbox"] [data-step-type]', (e: any) => {
            this.closeContextMenu();
            this.clearSelection();
            if (e.originalEvent.button === 0) {
                this.startGhostDrag(e.originalEvent as PointerEvent, String($(e.currentTarget).data('stepType')));
            }
        });
        // Toolbox sections expand/collapse via the +/- at the right of their title.
        this.$el.on('click.aaxisFlowEditor', '[data-role="toolbox-section-toggle"]', (e: any) => {
            e.preventDefault();
            e.stopPropagation();
            const $section = $(e.currentTarget).closest('.aaxis-flow-editor__toolbox-section');
            const collapsed = $section.toggleClass('is-collapsed').hasClass('is-collapsed');
            $(e.currentTarget).find('.fa')
                .toggleClass('fa-minus', !collapsed)
                .toggleClass('fa-plus', collapsed);
            this.clampToolboxIntoView(); // the content height changed
        });
        // Dragging the arrow head of an existing wire re-routes that wire.
        this.$el.on('pointerdown.aaxisFlowEditor', '[data-role="wire-end"]', (e: any) => {
            this.closeContextMenu();
            if (e.originalEvent.button !== 0) {
                return;
            }
            const group = (e.currentTarget as SVGElement).parentElement as unknown as SVGGElement | null;
            if (!group) {
                return;
            }
            const from = String(group.getAttribute('data-from'));
            const fromPort = Number(group.getAttribute('data-from-port')) || 0;
            const to = String(group.getAttribute('data-to'));
            const link = this.links.find(l => l.from === from && l.fromPort === fromPort && l.to === to);
            if (link) {
                e.stopPropagation();
                this.startRelinkDrag(e.originalEvent as PointerEvent, link);
            }
        });
        this.$el.on('pointerdown.aaxisFlowEditor', '[data-role="port"]', (e: any) => {
            this.closeContextMenu();
            const stepEl = (e.currentTarget as HTMLElement).closest('[data-role="step"]') as HTMLElement;
            const step = this.steps.find(s => s.el === stepEl);
            if (step && e.originalEvent.button === 0) {
                this.startLinkDrag(e.originalEvent as PointerEvent, step, Number($(e.currentTarget).data('port')) || 0);
            }
        });
        this.$el.on('pointerdown.aaxisFlowEditor', '[data-role="step"]', (e: any) => {
            this.closeContextMenu();
            // Ports start a link drag, not a tile move.
            if ($(e.target).closest('[data-role="port"]').length) {
                return;
            }
            const step = this.steps.find(s => s.el === e.currentTarget);
            if (!step) {
                return;
            }
            // Clicking a step selects it; clicking inside an existing multi-selection keeps it
            // (so the group survives a right-click or an accidental micro-drag).
            if (!this.selection.has(step)) {
                this.select([step]);
            }
            if (e.originalEvent.button === 0) {
                this.startStepDrag(e.originalEvent as PointerEvent, e.currentTarget as HTMLElement);
            }
        });
        this.$el.on('pointerdown.aaxisFlowEditor', '[data-role="canvas"]', (e: any) => {
            this.closeContextMenu();
            // Only the bare canvas (not tiles/toolbox/ports) starts a rubber-band selection.
            if (e.target === this.canvas() && e.originalEvent.button === 0) {
                this.startMarquee(e.originalEvent as PointerEvent);
            }
        });
        this.$el.on('contextmenu.aaxisFlowEditor', '[data-role="step"]', (e: any) => {
            e.preventDefault();
            const step = this.steps.find(s => s.el === e.currentTarget);
            if (step) {
                this.openContextMenu(e.originalEvent as PointerEvent, step);
            }
        });
        this.$el.on('contextmenu.aaxisFlowEditor', '[data-role="start-group"]', (e: any) => {
            e.preventDefault();
            this.showMenuAt(e.originalEvent as PointerEvent, addItem => {
                addItem(__('aaxis.ontology.flow_editor.menu_remove'), 'fa-trash-o', () => this.setStart(null));
            });
        });
        this.$el.on('contextmenu.aaxisFlowEditor', '[data-role="wire-group"]', (e: any) => {
            e.preventDefault();
            const g = e.currentTarget as SVGGElement;
            const link = this.links.find(l => l.from === g.getAttribute('data-from')
                && l.fromPort === Number(g.getAttribute('data-from-port'))
                && l.to === g.getAttribute('data-to'));
            if (link) {
                this.openLinkContextMenu(e.originalEvent as PointerEvent, link);
            }
        });
        this.$el.on('dblclick.aaxisFlowEditor', '[data-role="step"]', (e: any) => {
            const step = this.steps.find(s => s.el === e.currentTarget);
            if (step) {
                this.openStepSettings(step);
            }
        });
    }

    /**
     * Sizes the editor to end at the viewport bottom (minus a small page margin): the measured
     * top position adapts to whatever chrome Oro renders above it, where the CSS calc fallback
     * can only guess — the difference is the "dead" stripe of unused page below the canvas.
     */
    private fitEditorHeight(): void {
        const root: HTMLElement = this.$el.hasClass('aaxis-flow-editor')
            ? this.$el[0]
            : this.$el.find('.aaxis-flow-editor')[0];
        if (!root) {
            return;
        }
        const top = root.getBoundingClientRect().top;
        root.style.minHeight = `${Math.max(320, window.innerHeight - top - 16)}px`;
    }

    /** The content plane where steps/wires live — every canvas coordinate is measured on it. */
    private canvas(): HTMLElement {
        return this.$el.find('[data-role="canvas"]')[0];
    }

    /** The scroll viewport around the content plane (also the toolbox's pinning area). */
    private canvasViewport(): HTMLElement {
        return this.$el.find('[data-role="canvas-viewport"]')[0];
    }

    /**
     * Sizes the content plane to the step extent (plus one tile of margin), so anything placed
     * beyond the visible area shows up as scrollbars on the viewport instead of being clipped.
     * The plane's CSS min 100% keeps it at least viewport-sized.
     */
    private syncCanvasExtent(): void {
        const inner = this.canvas();
        let maxX = 0;
        let maxY = 0;
        for (const step of this.steps) {
            maxX = Math.max(maxX, step.x + this.tileSize);
            maxY = Math.max(maxY, step.y + this.tileSize);
        }
        inner.style.width = maxX > 0 ? `${maxX + this.tileSize}px` : '';
        inner.style.height = maxY > 0 ? `${maxY + this.tileSize}px` : '';
    }

    private toolbox(): HTMLElement {
        return this.$el.find('[data-role="toolbox"]')[0];
    }

    /** SVG layer for the flow links: first child of the canvas so tiles paint above the wires. */
    private buildWires(): void {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'aaxis-flow-editor__wires');
        const defs = document.createElementNS(SVG_NS, 'defs');
        // One arrow head per wire color: the default blue plus the choice branches (markers cannot
        // inherit their wire's stroke, so each branch needs its own tinted twin).
        [['aaxis-flow-arrow', ''], ['aaxis-flow-arrow-green', 'matched'], ['aaxis-flow-arrow-red', 'not-matched']]
            .forEach(([id, branch]) => {
                const marker = document.createElementNS(SVG_NS, 'marker');
                marker.setAttribute('id', id);
                if (branch !== '') {
                    marker.setAttribute('data-branch', branch);
                }
                marker.setAttribute('viewBox', '0 0 10 10');
                marker.setAttribute('refX', '9');
                marker.setAttribute('refY', '5');
                marker.setAttribute('markerWidth', '7');
                marker.setAttribute('markerHeight', '7');
                marker.setAttribute('orient', 'auto-start-reverse');
                const tip = document.createElementNS(SVG_NS, 'path');
                tip.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
                marker.appendChild(tip);
                defs.appendChild(marker);
            });
        svg.appendChild(defs);
        this.canvas().prepend(svg);
        this.wires = svg;
    }

    /** The branch a link carries: green/red for a choice source, '' for everything else. */
    private linkBranch(link: FlowLink): '' | 'matched' | 'not-matched' {
        const src = this.stepById(link.from);
        if (!src || portCount(src.type) !== 2) {
            return '';
        }
        return link.fromPort === 0 ? 'matched' : 'not-matched';
    }

    /** Colors an in-drag temp wire like the link it would create (branch stroke + arrow head). */
    private brandTempWire(path: SVGPathElement, from: PlacedStep, fromPort: number): void {
        const branch = this.linkBranch({from: from.id, fromPort, to: ''});
        if (branch === '') {
            path.setAttribute('marker-end', 'url(#aaxis-flow-arrow)');
            return;
        }
        path.setAttribute('data-branch', branch);
        path.setAttribute('marker-end', branch === 'matched' ? 'url(#aaxis-flow-arrow-green)' : 'url(#aaxis-flow-arrow-red)');
    }

    // --- Link geometry / rendering ------------------------------------------------

    /** Center of an output port, in canvas coordinates. */
    /** The side incoming links arrive at (user-configurable via the context menu). */
    private inSideOf(step: PlacedStep): AnchorSide {
        return step.inSide || 'west';
    }

    /** The side an output port leaves from (choice's red port defaults to south). */
    private outSideOf(step: PlacedStep, port: number): AnchorSide {
        return (step.outSides && step.outSides[port]) || (port === 1 ? 'south' : 'east');
    }

    /** Midpoint of one side of the tile, in canvas coordinates. */
    private anchorPoint(step: PlacedStep, side: AnchorSide): {x: number; y: number} {
        const half = this.tileSize / 2;
        switch (side) {
            case 'north': return {x: step.x + half, y: step.y};
            case 'south': return {x: step.x + half, y: step.y + this.tileSize};
            case 'west': return {x: step.x, y: step.y + half};
            default: return {x: step.x + this.tileSize, y: step.y + half};
        }
    }

    private outputPos(step: PlacedStep, port: number): {x: number; y: number} {
        return this.anchorPoint(step, this.outSideOf(step, port));
    }

    /** Where links arrive: 2px off the configured in-side, centered on it. */
    private inputPos(step: PlacedStep): {x: number; y: number} {
        const side = this.inSideOf(step);
        const v = SIDE_VECTOR[side];
        const a = this.anchorPoint(step, side);
        return {x: a.x + 2 * v.dx, y: a.y + 2 * v.dy};
    }

    /** Places a tile's "×" port handles on their configured sides (inline styles beat the CSS). */
    private positionPortsEl(el: HTMLElement, type: string, outSides: AnchorSide[]): void {
        el.querySelectorAll('[data-role="port"]').forEach(node => {
            const port = node as HTMLElement;
            const side = outSides[Number(port.getAttribute('data-port')) || 0] || 'east';
            port.style.left = side === 'west' ? '-9px' : (side === 'east' ? 'auto' : '50%');
            port.style.right = side === 'east' ? '-9px' : 'auto';
            port.style.top = side === 'north' ? '-9px' : (side === 'south' ? 'auto' : '50%');
            port.style.bottom = side === 'south' ? '-9px' : 'auto';
            port.style.transform = side === 'north' || side === 'south' ? 'translateX(-50%)' : 'translateY(-50%)';
        });
    }

    private positionPorts(step: PlacedStep): void {
        const sides: AnchorSide[] = [];
        for (let p = 0; p < portCount(step.type); p++) {
            sides.push(this.outSideOf(step, p));
        }
        this.positionPortsEl(step.el, step.type, sides);
    }

    /** The sides a tile's OTHER anchors occupy — what the submenu must offer as unavailable. */
    private usedAnchorSides(step: PlacedStep, except: 'in' | number): Set<AnchorSide> {
        const used = new Set<AnchorSide>();
        if (except !== 'in' && this.stepMeta[step.type].category !== 'trigger') {
            used.add(this.inSideOf(step));
        }
        for (let p = 0; p < portCount(step.type); p++) {
            if (except !== p) {
                used.add(this.outSideOf(step, p));
            }
        }
        return used;
    }

    /** Applies an anchor-side choice (guarded against a side another anchor already uses). */
    private setAnchorSide(step: PlacedStep, kind: 'in' | number, side: AnchorSide): void {
        if (this.usedAnchorSides(step, kind).has(side)) {
            return;
        }
        if (kind === 'in') {
            step.inSide = side;
        } else {
            const sides = step.outSides ? [...step.outSides] : [];
            sides[kind] = side;
            step.outSides = sides;
            this.positionPorts(step);
        }
        this.redrawLinks();
        this.syncDirty();
    }

    private wirePath(x1: number, y1: number, x2: number, y2: number): string {
        const dx = Math.max(24, Math.abs(x2 - x1) / 2);
        return `M ${x1} ${y1} C ${x1 + dx} ${y1}, ${x2 - dx} ${y2}, ${x2} ${y2}`;
    }

    private stepById(id: string): PlacedStep | null {
        return this.steps.find(s => s.id === id) || null;
    }

    /** Coalesces the redraws fired on every pointermove of a tile drag into one per frame. */
    private scheduleRedraw(): void {
        if (this.redrawScheduled) {
            return;
        }
        this.redrawScheduled = true;
        requestAnimationFrame(() => {
            this.redrawScheduled = false;
            this.redrawLinks();
        });
    }

    private redrawLinks(): void {
        // Links define what executes — refresh the gray "won't run" marking with every redraw
        // (link add/remove, step removal, restore; step additions hook it in addStep).
        this.updateReachability();
        this.syncCanvasExtent();
        this.wires.querySelectorAll('[data-role="wire-group"], [data-role="start-group"]').forEach(el => el.remove());
        this.drawStartArrow();
        if (!this.links.length) {
            return;
        }

        const grid = this.buildObstacleGrid();
        const routes: {link: FlowLink; points: {x: number; y: number}[]}[] = [];
        for (const link of this.links) {
            const from = this.stepById(link.from);
            const to = this.stepById(link.to);
            if (from && to) {
                routes.push({link, points: this.routeLink(from, link.fromPort, to, grid)});
            }
        }
        const hops = this.computeHops(routes.map(r => r.points));

        routes.forEach((route, i) => {
            const d = this.pathWithHops(route.points, hops[i]);
            const group = document.createElementNS(SVG_NS, 'g');
            group.setAttribute('data-role', 'wire-group');
            group.setAttribute('data-from', route.link.from);
            group.setAttribute('data-from-port', String(route.link.fromPort));
            group.setAttribute('data-to', route.link.to);
            // Choice branches paint green (matched) / red (not matched) — attribute-driven CSS.
            const branch = this.linkBranch(route.link);
            if (branch !== '') {
                group.setAttribute('data-branch', branch);
            }
            // Visible arrow + an invisible wide twin that makes the thin line right-clickable
            // (the svg layer itself keeps pointer-events: none).
            const path = document.createElementNS(SVG_NS, 'path');
            path.setAttribute('data-role', 'wire');
            path.setAttribute('d', d);
            path.setAttribute('marker-end', branch === '' ? 'url(#aaxis-flow-arrow)'
                : (branch === 'matched' ? 'url(#aaxis-flow-arrow-green)' : 'url(#aaxis-flow-arrow-red)'));
            const hit = document.createElementNS(SVG_NS, 'path');
            hit.setAttribute('data-role', 'wire-hit');
            hit.setAttribute('d', d);
            group.appendChild(path);
            group.appendChild(hit);

            // Grab handle over the arrow head: dragging it re-routes (or deletes) this link.
            const end = route.points[route.points.length - 1];
            if (end) {
                const grip = document.createElementNS(SVG_NS, 'circle');
                grip.setAttribute('data-role', 'wire-end');
                grip.setAttribute('cx', String(end.x));
                grip.setAttribute('cy', String(end.y));
                grip.setAttribute('r', '9');
                group.appendChild(grip);
            }

            this.wires.appendChild(group);
        });
    }

    /** Sets/clears the sub-flow entry marker and refreshes arrows, marking and buttons. */
    private setStart(id: string | null): void {
        this.startId = id;
        this.redrawLinks();
        this.syncDirty();
    }

    /** The "Start here" arrow: a short origin-less arrow into the started element's input. */
    private drawStartArrow(): void {
        const step = this.startId ? this.stepById(this.startId) : null;
        if (!step || this.findTrigger()) {
            return;
        }
        const tip = this.inputPos(step);
        const v = SIDE_VECTOR[this.inSideOf(step)];
        const originX = Math.max(2, tip.x + v.dx * 3 * this.spacing);
        const originY = Math.max(2, tip.y + v.dy * 3 * this.spacing);
        const d = `M ${originX} ${originY} L ${tip.x} ${tip.y}`;
        const group = document.createElementNS(SVG_NS, 'g');
        group.setAttribute('data-role', 'start-group');
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('data-role', 'wire');
        path.setAttribute('d', d);
        path.setAttribute('marker-end', 'url(#aaxis-flow-arrow)');
        const hit = document.createElementNS(SVG_NS, 'path');
        hit.setAttribute('data-role', 'wire-hit');
        hit.setAttribute('d', d);
        group.appendChild(path);
        group.appendChild(hit);
        this.wires.appendChild(group);
    }

    // --- Orthogonal routing (lines never run over a tile) ---------------------------------

    /**
     * Obstacle grid over the canvas at dot-spacing resolution: every tile inflated by one cell of
     * clearance, so routed lines always deviate before reaching an element.
     */
    private buildObstacleGrid(): {cols: number; rows: number; blocked: Uint8Array} {
        const canvas = this.canvas();
        const cell = this.spacing;
        const cols = Math.max(2, Math.floor(canvas.clientWidth / cell) + 1);
        const rows = Math.max(2, Math.floor(canvas.clientHeight / cell) + 1);
        const blocked = new Uint8Array(cols * rows);
        for (const step of this.steps) {
            const c0 = Math.max(0, Math.floor(step.x / cell) - 1);
            const c1 = Math.min(cols - 1, Math.ceil((step.x + this.tileSize) / cell) + 1);
            const r0 = Math.max(0, Math.floor(step.y / cell) - 1);
            const r1 = Math.min(rows - 1, Math.ceil((step.y + this.tileSize) / cell) + 1);
            for (let r = r0; r <= r1; r++) {
                for (let c = c0; c <= c1; c++) {
                    blocked[r * cols + c] = 1;
                }
            }
        }
        return {cols, rows, blocked};
    }

    /**
     * Full polyline for one link: a horizontal stub out of the output port, an A*-found
     * orthogonal path between the clearance points, and a horizontal stub into the arrow tip.
     */
    private routeLink(from: PlacedStep, fromPort: number, to: PlacedStep, grid: {cols: number; rows: number; blocked: Uint8Array}): {x: number; y: number}[] {
        const cell = this.spacing;
        const a = this.outputPos(from, fromPort);
        const b = this.inputPos(to);
        // Both stubs leave/arrive along their anchor side's outward normal.
        const av = SIDE_VECTOR[this.outSideOf(from, fromPort)];
        const bv = SIDE_VECTOR[this.inSideOf(to)];

        const clampC = (v: number): number => Math.min(Math.max(v, 0), grid.cols - 1);
        const clampR = (v: number): number => Math.min(Math.max(v, 0), grid.rows - 1);
        const sc = clampC(Math.round(a.x / cell) + av.dx * 2);
        const sr = clampR(Math.round(a.y / cell) + av.dy * 2);
        const ec = clampC(Math.round(b.x / cell) + bv.dx * 2);
        const er = clampR(Math.round(b.y / cell) + bv.dy * 2);

        const cells = this.astar(grid, sc, sr, ec, er);
        const points: {x: number; y: number}[] = [{x: a.x, y: a.y}];
        const push = (x: number, y: number): void => {
            const last = points[points.length - 1];
            if (last.x !== x || last.y !== y) {
                points.push({x, y});
            }
        };

        // Stub away from the source tile, at the port's own height/column.
        if (av.dx !== 0) {
            push(sc * cell, a.y);
        } else {
            push(a.x, sr * cell);
        }
        if (cells) {
            for (const c of cells) {
                push(c.c * cell, c.r * cell);
            }
        } else {
            // No path (jammed canvas): plain 3-segment fallback, obstacle checks waived.
            const midX = Math.round(((sc + ec) / 2)) * cell;
            push(sc * cell, sr * cell);
            push(midX, sr * cell);
            push(midX, er * cell);
            push(ec * cell, er * cell);
        }
        // Approach segment aligned to the arrival anchor, then the stub into the arrow tip.
        if (bv.dx !== 0) {
            push(ec * cell, b.y);
        } else {
            push(b.x, er * cell);
        }
        push(b.x, b.y);
        return this.compressPolyline(points);
    }

    /** A* on the obstacle grid, 4-directional with a turn penalty so runs stay straight. */
    private astar(grid: {cols: number; rows: number; blocked: Uint8Array}, sc: number, sr: number, ec: number, er: number): {c: number; r: number}[] | null {
        const {cols, rows, blocked} = grid;
        const start = sr * cols + sc;
        const goal = er * cols + ec;
        // Endpoints may fall inside another tile's clearance ring — allow standing on them.
        const isFree = (idx: number): boolean => idx === start || idx === goal || !blocked[idx];

        const g = new Float64Array(cols * rows).fill(Infinity);
        const parent = new Int32Array(cols * rows).fill(-1);
        const dirs = [[1, 0], [-1, 0], [0, 1], [0, -1]];
        const h = (idx: number): number => Math.abs((idx % cols) - ec) + Math.abs(Math.floor(idx / cols) - er);

        // Tiny binary heap of [f, idx] pairs.
        const heap: number[][] = [[h(start), start]];
        g[start] = 0;
        let expansions = 0;
        while (heap.length && expansions < 20000) {
            let best = 0;
            for (let i = 1; i < heap.length; i++) {
                if (heap[i][0] < heap[best][0]) {
                    best = i;
                }
            }
            const [, idx] = heap.splice(best, 1)[0];
            if (idx === goal) {
                const cells: {c: number; r: number}[] = [];
                for (let cur = goal; cur !== -1; cur = parent[cur]) {
                    cells.push({c: cur % cols, r: Math.floor(cur / cols)});
                }
                return cells.reverse();
            }
            expansions++;
            const c = idx % cols;
            const r = Math.floor(idx / cols);
            const pdir = parent[idx] === -1 ? -1 : idx - parent[idx];
            for (const [dc, dr] of dirs) {
                const nc = c + dc;
                const nr = r + dr;
                if (nc < 0 || nr < 0 || nc >= cols || nr >= rows) {
                    continue;
                }
                const nidx = nr * cols + nc;
                if (!isFree(nidx)) {
                    continue;
                }
                const turn = pdir !== -1 && (nidx - idx) !== pdir ? 3 : 0;
                const ng = g[idx] + 1 + turn;
                if (ng < g[nidx]) {
                    g[nidx] = ng;
                    parent[nidx] = idx;
                    heap.push([ng + h(nidx), nidx]);
                }
            }
        }
        return null;
    }

    /** Drops collinear intermediate points so each polyline entry is a real corner. */
    private compressPolyline(points: {x: number; y: number}[]): {x: number; y: number}[] {
        const out = [points[0]];
        for (let i = 1; i < points.length - 1; i++) {
            const a = out[out.length - 1];
            const b = points[i];
            const c = points[i + 1];
            if ((a.x === b.x && b.x === c.x) || (a.y === b.y && b.y === c.y)) {
                continue;
            }
            out.push(b);
        }
        out.push(points[points.length - 1]);
        return out;
    }

    // --- Crossing "jumps" -------------------------------------------------------------------

    /**
     * Where a horizontal run of one line properly crosses a vertical run of ANOTHER line, the
     * horizontal one jumps over it with a small arc. Returns, per route, a map of
     * segment-index → crossing xs.
     */
    private computeHops(routes: {x: number; y: number}[][]): Record<number, number[]>[] {
        const hops: Record<number, number[]>[] = routes.map(() => ({}));
        for (let i = 0; i < routes.length; i++) {
            const pts = routes[i];
            for (let s = 0; s < pts.length - 1; s++) {
                const p = pts[s];
                const q = pts[s + 1];
                if (p.y !== q.y || p.x === q.x) {
                    continue; // hops live on horizontal segments only
                }
                const x1 = Math.min(p.x, q.x);
                const x2 = Math.max(p.x, q.x);
                for (let j = 0; j < routes.length; j++) {
                    if (j === i) {
                        continue;
                    }
                    const other = routes[j];
                    for (let t = 0; t < other.length - 1; t++) {
                        const v1 = other[t];
                        const v2 = other[t + 1];
                        if (v1.x !== v2.x || v1.y === v2.y) {
                            continue;
                        }
                        const yLo = Math.min(v1.y, v2.y);
                        const yHi = Math.max(v1.y, v2.y);
                        if (v1.x > x1 && v1.x < x2 && p.y > yLo && p.y < yHi) {
                            (hops[i][s] = hops[i][s] || []).push(v1.x);
                        }
                    }
                }
            }
        }
        return hops;
    }

    /** Builds the SVG path of a polyline, replacing crossings with small semicircular jumps. */
    private pathWithHops(points: {x: number; y: number}[], hops: Record<number, number[]>): string {
        const r = Math.min(6, Math.max(4, Math.round(this.spacing / 2)));
        let d = `M ${points[0].x} ${points[0].y}`;
        for (let s = 0; s < points.length - 1; s++) {
            const p = points[s];
            const q = points[s + 1];
            const xs = (hops[s] || []).slice();
            if (p.y === q.y && xs.length) {
                const dir = q.x > p.x ? 1 : -1;
                xs.sort((a, b) => (a - b) * dir);
                for (const hx of xs) {
                    // Skip jumps that would collide with the segment's own corners.
                    if (Math.abs(hx - p.x) < 2 * r || Math.abs(hx - q.x) < 2 * r) {
                        continue;
                    }
                    d += ` L ${hx - r * dir} ${p.y}`;
                    // Semicircle over the crossed line (bulge upward on screen).
                    d += ` A ${r} ${r} 0 0 ${dir > 0 ? 1 : 0} ${hx + r * dir} ${p.y}`;
                }
            }
            d += ` L ${q.x} ${q.y}`;
        }
        return d;
    }

    private setToolboxVisible(visible: boolean, persist = true): void {
        this.toolbox().hidden = !visible;
        if (visible) {
            // A position saved/dragged on a larger window can sit entirely off-canvas after a
            // resize — showing the toolbox always pulls it back into view.
            this.clampToolboxIntoView();
        }
        this.$el.find('[data-role="toolbox-toggle"]').toggleClass('is-active', visible);
        // Visibility is a workspace preference (NOT flow state) — shared across flows. Only USER
        // actions persist (persist=false for the init sync, which must not overwrite a stored
        // position that did not fit this window).
        if (persist) {
            this.saveToolboxState();
        }
    }

    /**
     * "Organize": lays every step out in EXECUTION order (BFS from the trigger — or the
     * "Start here" step in sub-flows; unreachable steps follow in reading order), left to right
     * with one-tile gaps like Align, wrapping to a new row before passing the viewport width.
     */
    private organizeSteps(): void {
        if (this.steps.length === 0) {
            return;
        }
        const byId = new Map(this.steps.map(s => [s.id, s]));
        const outgoing = new Map<string, FlowLink[]>();
        [...this.links].sort((a, b) => a.fromPort - b.fromPort).forEach(link => {
            const list = outgoing.get(link.from) || [];
            list.push(link);
            outgoing.set(link.from, list);
        });

        const ordered: PlacedStep[] = [];
        const visited = new Set<string>();
        const trigger = this.findTrigger();
        const rootId = trigger ? trigger.id : this.startId;
        if (rootId && byId.has(rootId)) {
            visited.add(rootId);
            const queue = [rootId];
            while (queue.length > 0) {
                const id = queue.shift() as string;
                ordered.push(byId.get(id) as PlacedStep);
                (outgoing.get(id) || []).forEach(link => {
                    if (byId.has(link.to) && !visited.has(link.to)) {
                        visited.add(link.to);
                        queue.push(link.to);
                    }
                });
            }
        }
        this.steps.filter(s => !visited.has(s.id))
            .sort((a, b) => (a.x - b.x) || (a.y - b.y))
            .forEach(s => ordered.push(s));

        const size = this.tileSize;
        const gap = size; // one-tile gaps, like Align
        const margin = Math.max(this.spacing, this.snap(size / 2));
        const usable = Math.max(this.canvasViewport().clientWidth, margin + 2 * size);
        let x = margin;
        let y = margin;
        for (const step of ordered) {
            if (x > margin && x + size + margin > usable) { // row break before passing the width
                x = margin;
                y += size + gap;
            }
            step.x = this.snap(x);
            step.y = this.snap(y);
            step.el.style.left = `${step.x}px`;
            step.el.style.top = `${step.y}px`;
            x += size + gap;
        }
        this.canvasViewport().scrollTo({left: 0, top: 0});
        this.redrawLinks();
        this.syncDirty();
    }

    /** Clamps the toolbox's explicit position into the current canvas (no-op at the CSS default spot). */
    private clampToolboxIntoView(): void {
        const el = this.toolbox();
        // Never taller than the working area (48 = the default spot's 24px top + bottom margins):
        // the section list scrolls inside __toolbox-body instead, or the user collapses sections.
        el.style.maxHeight = `${Math.max(120, this.canvasViewport().clientHeight - 48)}px`;
        if (el.hidden || el.style.left === '') {
            return; // hidden (not measurable) or never moved — the default top-right spot is visible
        }
        // Clamp into the VIEWPORT's sub-area of the wrap (the viewport starts right of the
        // debugger sidebar when that is open — offsetLeft carries that shift).
        const viewport = this.canvasViewport();
        const minLeft = viewport.offsetLeft;
        const minTop = viewport.offsetTop;
        const maxLeft = Math.max(minLeft, minLeft + viewport.clientWidth - el.offsetWidth);
        const maxTop = Math.max(minTop, minTop + viewport.clientHeight - el.offsetHeight);
        el.style.left = `${Math.min(Math.max(minLeft, Number.parseInt(el.style.left, 10) || 0), maxLeft)}px`;
        el.style.top = `${Math.min(Math.max(minTop, Number.parseInt(el.style.top, 10) || 0), maxTop)}px`;
    }

    /** localStorage key of the toolbox workspace preference — ONE spot shared by every flow. */
    private static readonly TOOLBOX_STORE_KEY = 'aaxis.ontology.flowEditor.toolbox';

    /** Persists the toolbox position/visibility as the user's workspace preference. */
    private saveToolboxState(): void {
        const el = this.toolbox();
        const state: Record<string, any> = {visible: !el.hidden};
        const x = Number.parseInt(el.style.left, 10);
        const y = Number.parseInt(el.style.top, 10);
        if (Number.isFinite(x) && Number.isFinite(y)) {
            state.x = x;
            state.y = y;
        }
        try {
            window.localStorage.setItem(OntologyFlowEditorComponent.TOOLBOX_STORE_KEY, JSON.stringify(state));
        } catch (e) {
            // Storage unavailable (privacy mode) — the toolbox just uses its defaults next time.
        }
    }

    /**
     * Applies the stored workspace preference: visibility always; the position only when the
     * stored spot still fits FULLY inside the current viewport — a spot saved on a bigger window
     * (or none at all) falls back to the default show/hide placement instead of being clipped.
     */
    private restoreToolboxState(): void {
        let state: any = null;
        try {
            state = JSON.parse(window.localStorage.getItem(OntologyFlowEditorComponent.TOOLBOX_STORE_KEY) || 'null');
        } catch (e) {
            state = null;
        }
        if (typeof state !== 'object' || state === null) {
            return;
        }
        if (state.visible === false) {
            // Hidden toolboxes keep no explicit spot — showing it again uses the default position.
            this.toolbox().hidden = true;
            return;
        }
        if (!Number.isFinite(state.x) || !Number.isFinite(state.y)) {
            return;
        }
        const el = this.toolbox();
        this.clampToolboxIntoView(); // sets the height cap first, so the fit check measures reality
        const viewport = this.canvasViewport();
        const minLeft = viewport.offsetLeft;
        const minTop = viewport.offsetTop;
        const fits = state.x >= minLeft && state.x <= minLeft + viewport.clientWidth - el.offsetWidth
            && state.y >= minTop && state.y <= minTop + viewport.clientHeight - el.offsetHeight;
        if (fits) {
            el.style.left = `${state.x}px`;
            el.style.top = `${state.y}px`;
            el.style.right = 'auto';
        }
    }

    // --- Persisted editor state (the `design` column) -----------------------------

    // --- Dirty tracking (Save enabled / Cancel↔Close) -----------------------------------

    /** Everything the save payload carries — the unit of "changes pending". */
    // (syncDirty below also refreshes the debug buttons: the trigger's enabled flag — the run
    // switch — changes through step edits/removals, all of which funnel through syncDirty.)
    private snapshot(): string {
        // The flow name lives on the trigger step, which the design already carries.
        return JSON.stringify({design: this.currentDesign()});
    }

    /** Records the current state as saved and refreshes the buttons. */
    private markSaved(): void {
        this.savedState = this.snapshot();
        this.syncDirty();
    }

    /** Save is enabled only with pending changes; the cancel button reads Close when clean. */
    private syncDirty(): void {
        const dirty = this.snapshot() !== this.savedState;
        this.$el.find('[data-role="save"]').prop('disabled', !dirty);
        this.$el.find('[data-role="save-all"]').prop('disabled', this.savingAll || !this.anyTabDirty());
        const label = dirty ? __('aaxis.ontology.flow_editor.cancel') : __('aaxis.ontology.flow_editor.close');
        // Only the label SPAN changes (the button also holds the icon); the title follows for
        // the icon-only narrow-viewport mode.
        this.$el.find('[data-role="cancel-label"]').text(label);
        this.$el.find('[data-role="cancel"]').attr('title', label);
        this.syncDebugButtons();
        this.syncFlowNameDisplay();
    }

    /** The ACTIVE tab mirrors the live canvas (title = trigger name, dirty dot) — re-rendered
     * through syncDirty so renames/edits show up as they happen. */
    private syncFlowNameDisplay(): void {
        this.renderTabs();
    }

    /** The title a tab shows: the trigger step's name — live for the active tab, stashed else. */
    private tabTitle(tab: EditorTab, active: boolean): string {
        if (active) {
            const trigger = this.findTrigger();
            return (trigger && trigger.name) || (this.flow && this.flow.name) || __('aaxis.ontology.flow_editor.name_unset');
        }
        const steps = (tab.design && tab.design.steps) || (tab.flow && tab.flow.design && tab.flow.design.steps) || [];
        const trigger = (steps as any[]).find(st => st && this.stepMeta[st.type] && this.stepMeta[st.type].category === 'trigger');
        return (trigger && trigger.name) || (tab.flow && tab.flow.name) || __('aaxis.ontology.flow_editor.name_unset');
    }

    /** Whether a STASHED tab holds unsaved changes (the active tab asks the live snapshot). */
    private tabDirty(tab: EditorTab, active: boolean): boolean {
        if (active) {
            return this.snapshot() !== this.savedState;
        }
        return tab.design !== null && tab.savedState !== null
            && JSON.stringify({design: tab.design}) !== tab.savedState;
    }

    /**
     * The URL mirrors the WHOLE tab set: the path is the active tab (deep links keep working)
     * and `?tabs=` lists every open SAVED flow in tab order — so a refresh restores all tabs.
     * Unsaved (never-saved) tabs cannot travel through a URL and are simply not listed.
     */
    private syncTabsUrl(): void {
        const active = this.tabs[this.activeTab];
        const activeId = active && active.flow ? (active.flow as any).id : null;
        const base = activeId
            ? routing.generate('aaxis_ontology_flow_editor', {id: activeId})
            : routing.generate('aaxis_ontology_flow_editor');
        const ids = this.tabs
            .filter(t => t.flow !== null && (t.flow as any).id)
            .map(t => (t.flow as any).id);
        // The param only appears when it adds information beyond the path itself.
        const url = ids.length > 1 || (ids.length === 1 && activeId === null)
            ? base + '?tabs=' + ids.join(',')
            : base;
        window.history.replaceState(null, '', url);
    }

    /** Reopens the tabs a previous session listed in `?tabs=` (parsed BEFORE the first URL sync). */
    private restoreTabsFromUrl(): void {
        const ids = this.pendingTabIds;
        this.pendingTabIds = [];
        const currentId = this.flow ? (this.flow as any).id : null;
        if (!ids.length || (ids.length === 1 && ids[0] === currentId)) {
            return;
        }
        this.flowCatalog().then((data: {records?: FlowRecord[]}) => {
            const byId: Record<number, FlowRecord> = {};
            (data.records || []).forEach(f => {
                if (f.type !== 'native') {
                    byId[f.id] = f;
                }
            });
            const activeTab = this.tabs[this.activeTab];
            const rebuilt: EditorTab[] = [];
            let activeIndex = -1;
            ids.forEach(id => {
                if (id === currentId) {
                    activeIndex = rebuilt.length;
                    rebuilt.push(activeTab);
                    return;
                }
                if (byId[id] && !rebuilt.some(t => t.flow !== null && (t.flow as any).id === id)) {
                    rebuilt.push({flow: byId[id], design: null, savedState: null, invalidNames: byId[id].invalidSteps || []});
                }
            });
            if (activeIndex < 0) {
                // The path's flow (or a brand-new tab) was not in the list — keep it, in front.
                rebuilt.unshift(activeTab);
                activeIndex = 0;
            }
            if (!rebuilt.length) {
                return;
            }
            this.tabs = rebuilt;
            this.activeTab = activeIndex;
            this.renderTabs();
        }).catch(() => undefined);
    }

    private renderTabs(): void {
        const $bar = this.$el.find('[data-role="tabs"]');
        if (!$bar.length || !this.tabs) {
            return;
        }
        $bar.empty();
        this.tabs.forEach((tab, index) => {
            const active = index === this.activeTab;
            const $tab = $('<button/>', {
                type: 'button',
                'class': 'aaxis-flow-editor__tab' + (active ? ' is-active' : ''),
                'data-role': 'tab',
                'data-tab-index': index
            });
            $tab.append($('<span/>', {'class': 'aaxis-flow-editor__tab-title', text: this.tabTitle(tab, active)}));
            if (this.tabDirty(tab, active)) {
                $tab.append($('<span/>', {'class': 'aaxis-flow-editor__tab-dirty', 'aria-hidden': 'true', text: '•'}));
            }
            $tab.append($('<span/>', {
                'class': 'fa fa-times aaxis-flow-editor__tab-close', 'data-role': 'tab-close',
                title: __('aaxis.ontology.flow_editor.tab_close'), 'aria-label': __('aaxis.ontology.flow_editor.tab_close')
            }));
            // Drag to REORDER (flow tabs only): drop before/after by the pointer's half of the
            // target tab. The active tab travels by IDENTITY — indexes shift under it.
            $tab.attr('draggable', 'true');
            $tab.on('dragstart', (e: any) => {
                this.tabDragIndex = index;
                (e.originalEvent as DragEvent).dataTransfer?.setData('text/plain', String(index));
                (e.originalEvent as DragEvent).dataTransfer!.effectAllowed = 'move';
            });
            $tab.on('dragover', (e: any) => {
                if (this.tabDragIndex === null) {
                    return;
                }
                e.preventDefault(); // allow dropping here
                (e.originalEvent as DragEvent).dataTransfer!.dropEffect = 'move';
                const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
                const after = (e.originalEvent as DragEvent).clientX > rect.left + rect.width / 2;
                $tab.toggleClass('is-drop-after', after).toggleClass('is-drop-before', !after);
            });
            $tab.on('dragleave', () => $tab.removeClass('is-drop-after is-drop-before'));
            $tab.on('drop', (e: any) => {
                e.preventDefault();
                $tab.removeClass('is-drop-after is-drop-before');
                const from = this.tabDragIndex;
                this.tabDragIndex = null;
                if (from === null || from === index) {
                    return;
                }
                const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
                const after = (e.originalEvent as DragEvent).clientX > rect.left + rect.width / 2;
                let to = index + (after ? 1 : 0);
                if (from < to) {
                    to--; // removing the dragged tab first shifts the insertion point
                }
                const activeTab = this.tabs[this.activeTab];
                const [moved] = this.tabs.splice(from, 1);
                this.tabs.splice(to, 0, moved);
                this.activeTab = this.tabs.indexOf(activeTab);
                this.renderTabs();
                this.syncTabsUrl();
            });
            $tab.on('dragend', () => {
                this.tabDragIndex = null;
                $bar.find('.is-drop-after, .is-drop-before').removeClass('is-drop-after is-drop-before');
            });
            $bar.append($tab);
        });
        // The two ACTION tabs (open an existing flow / start a new one) sit at the FAR RIGHT of
        // the bar — away from the flow tabs, so a close/switch click cannot slip onto them.
        $bar.append($('<button/>', {
            type: 'button',
            'class': 'aaxis-flow-editor__tab aaxis-flow-editor__tab--action aaxis-flow-editor__tab--push-right',
            'data-role': 'tab-open',
            title: __('aaxis.ontology.flow_editor.tab_open'), 'aria-label': __('aaxis.ontology.flow_editor.tab_open')
        }).append($('<span/>', {'class': 'fa fa-folder-open-o', 'aria-hidden': 'true'})));
        $bar.append($('<button/>', {
            type: 'button', 'class': 'aaxis-flow-editor__tab aaxis-flow-editor__tab--action', 'data-role': 'tab-add',
            title: __('aaxis.ontology.flow_editor.tab_add'), 'aria-label': __('aaxis.ontology.flow_editor.tab_add')
        }).append($('<span/>', {'class': 'fa fa-plus', 'aria-hidden': 'true'})));
    }

    /** Captures the live canvas into the active tab (before switching away). */
    private stashActiveTab(): void {
        const tab = this.tabs[this.activeTab];
        if (!tab) {
            return;
        }
        tab.flow = this.flow;
        tab.design = this.currentDesign();
        tab.savedState = this.savedState;
        tab.invalidNames = this.steps.filter(s => s.invalid === true).map(s => s.name);
    }

    /** Clears the canvas completely (steps, links, marks, session) for the next tab's content. */
    private resetCanvas(): void {
        if (!this.debugVisiting) {
            this.closeDebugSession();
        }
        this.clearSelection();
        this.closeContextMenu();
        this.steps.forEach(s => s.el.remove());
        this.steps = [];
        this.links = [];
        this.startId = null;
        this.redrawLinks();
    }

    private switchTab(index: number): void {
        if (index === this.activeTab || !this.tabs[index]) {
            return;
        }
        this.stashActiveTab();
        this.activeTab = index;
        this.loadTab(this.tabs[index]);
    }

    private loadTab(tab: EditorTab): void {
        this.resetCanvas();
        this.flow = tab.flow;
        if (tab.design !== null) {
            // The tab was active before — its working state continues exactly where it was.
            this.restoreFromDesign(tab.design);
        } else {
            this.restore();
        }
        this.applyInvalidStepNames(tab.invalidNames);
        // A fresh baseline for a never-stashed tab; a stashed one keeps its dirty state.
        if (tab.savedState !== null) {
            this.savedState = tab.savedState;
        } else {
            this.markSaved();
        }
        // The URL follows the active tab AND lists every open saved tab (?tabs=), so a refresh
        // or bookmark restores the whole set with this flow on screen.
        this.syncTabsUrl();
        this.syncDirty();
    }

    /** Closes a tab (confirming when it holds unsaved changes); the editor always keeps one. */
    private closeTab(index: number, force = false): void {
        const tab = this.tabs[index];
        if (!tab) {
            return;
        }
        const dirty = !force && this.tabDirty(tab, index === this.activeTab);
        const doClose = (): void => {
            this.tabs.splice(index, 1);
            if (this.tabs.length === 0) {
                this.tabs.push({flow: null, design: null, savedState: null, invalidNames: []});
            }
            if (index === this.activeTab) {
                this.activeTab = Math.max(0, index - 1);
                this.loadTab(this.tabs[this.activeTab]);
            } else {
                if (index < this.activeTab) {
                    this.activeTab--;
                }
                this.renderTabs();
                this.syncTabsUrl();
            }
        };
        if (dirty) {
            this.confirmDialog(
                __('aaxis.ontology.flow_editor.tab_close_title'),
                __('aaxis.ontology.flow_editor.tab_close_question'),
                __('aaxis.ontology.flow_editor.tab_close_confirm'),
                doClose
            );
        } else {
            doClose();
        }
    }

    /** The "open" action tab: pick any existing (non-native) flow and open it in a NEW tab. */
    /**
     * The "open a flow in a new tab" picker: a full-width name filter, a Flows | Subflows tab
     * pair whose COUNTS follow the filter, and the matching list below (only the LIST scrolls;
     * the popup itself is capped at ~60% of the window).
     */
    private openFlowPicker(): void {
        const dialog = new Dialog({title: __('aaxis.ontology.flow_editor.tab_open'), width: '560px'});
        const $content = dialog.open();
        const $picker = $('<div/>', {'class': 'aaxis-flow-picker'});
        $content.append($picker);
        $picker.append($('<p/>', {'class': 'aaxis-json-note', text: __('aaxis.ontology.flow_editor.loading')}));
        fetch(routing.generate('aaxis_ontology_flow_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {records?: FlowRecord[]}) => {
                $picker.empty();
                const rows = (data.records || []).filter(f => f.type !== 'native');
                if (!rows.length) {
                    $picker.append($('<p/>', {'class': 'aaxis-json-note', text: __('aaxis.ontology.flow_editor.tab_open_empty')}));
                    return;
                }

                const $filter = $('<input/>', {
                    type: 'text', 'class': 'form-control aaxis-flow-picker__filter',
                    placeholder: __('aaxis.ontology.flow_editor.tab_open_filter_placeholder'),
                    autocomplete: 'off', spellcheck: false
                });
                const $tabs = $('<div/>', {'class': 'aaxis-flow-picker__tabs'});
                const $list = $('<div/>', {'class': 'aaxis-flow-picker__list'});
                $picker.append($filter, $tabs, $list);

                let active: 'flow' | 'subflow' = 'flow';
                const matches = (kind: 'flow' | 'subflow'): FlowRecord[] => {
                    const query = String($filter.val() || '').trim().toLowerCase();
                    return rows.filter(f => f.type === kind
                        && (query === '' || (f.name || '').toLowerCase().indexOf(query) >= 0));
                };
                const render = (): void => {
                    $tabs.empty();
                    const kinds: Array<['flow' | 'subflow', string]> = [
                        ['flow', __('aaxis.ontology.flow_editor.tab_open_flows')],
                        ['subflow', __('aaxis.ontology.flow_editor.tab_open_subflows')]
                    ];
                    kinds.forEach(([kind, label]) => {
                        const $tab = $('<button/>', {
                            type: 'button',
                            'class': 'aaxis-flow-picker__tab' + (active === kind ? ' is-active' : ''),
                            text: `${label} (${matches(kind).length})`
                        });
                        $tab.on('click', () => {
                            active = kind;
                            render();
                        });
                        $tabs.append($tab);
                    });

                    $list.empty();
                    const visible = matches(active);
                    if (!visible.length) {
                        $list.append($('<p/>', {'class': 'aaxis-json-note', text: __('aaxis.ontology.flow_editor.tab_open_no_match')}));
                        return;
                    }
                    visible.forEach(flow => {
                        const $row = $('<button/>', {type: 'button', 'class': 'aaxis-flow-picker__row'});
                        $row.append($('<span/>', {'class': 'aaxis-flow-picker__name', text: flow.name || ''}));
                        if (!flow.enabled) {
                            $row.append($('<span/>', {'class': 'aaxis-flow-picker__badge aaxis-flow-picker__badge--off', text: __('aaxis.ontology.flow_editor.tab_open_disabled')}));
                        }
                        $row.on('click', () => {
                            dialog.close();
                            const already = this.tabs.findIndex(t => t.flow !== null && t.flow.id === flow.id);
                            if (already >= 0) {
                                this.switchTab(already);
                                return;
                            }
                            this.tabs.push({flow, design: null, savedState: null, invalidNames: flow.invalidSteps || []});
                            this.switchTab(this.tabs.length - 1);
                        });
                        $list.append($row);
                    });
                };
                $filter.on('input', render);
                render();
                $filter.trigger('focus');
            })
            .catch(() => {
                $picker.empty();
                messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.catalog_load_error'));
            });
    }

    /** The full canvas state persisted alongside the logical steps. */
    private currentDesign(): any {
        // NOTE: the toolbox is deliberately NOT part of the design — its position/visibility are
        // the USER's workspace preference, shared across every flow via localStorage
        // ({@see saveToolboxState}); old designs may still carry a `toolbox` key, which is ignored.
        return {
            version: DESIGN_VERSION,
            steps: this.steps.map(s => ({
                id: s.id, type: s.type, name: s.name, x: s.x, y: s.y, config: s.config || null,
                // Anchor sides travel only when the user changed them (defaults stay implicit).
                ...(s.inSide ? {inSide: s.inSide} : {}),
                ...(s.outSides && s.outSides.some(v => Boolean(v)) ? {outSides: s.outSides} : {})
            })),
            links: this.links.map(l => ({from: l.from, fromPort: l.fromPort, to: l.to})),
            start: this.startId
        };
    }

    /**
     * Rebuilds the canvas from the saved state: the stored design when present (an unreadable or
     * outdated one is reported as corrupted and the editor starts empty), otherwise the logical
     * steps (flows saved before the design column existed).
     */
    private restore(): void {
        const design = this.flow ? this.flow.design : null;
        if (design !== null && design !== undefined) {
            if (!this.restoreFromDesign(design)) {
                messenger.notificationFlashMessage('warning', __('aaxis.ontology.flow_editor.design_corrupted'));
            }
            return;
        }

        (this.flow?.steps || []).forEach(step => {
            if (this.stepMeta[step.type]) {
                this.addStep(step.type, this.snap(step.x), this.snap(step.y), step.name);
            }
        });
    }

    /**
     * Validates a stored step's optional anchor sides LENIENTLY (they are cosmetic): unknown
     * values or a collision between the resolved in/out sides just falls back to the defaults —
     * never "corrupted".
     */
    private sanitizeAnchors(step: any): {inSide?: AnchorSide; outSides?: AnchorSide[]} | undefined {
        const isSide = (v: any): v is AnchorSide => ANCHOR_SIDES.indexOf(v) >= 0;
        const inSide = isSide(step.inSide) ? step.inSide : undefined;
        let outSides: AnchorSide[] | undefined;
        if (Array.isArray(step.outSides) && step.outSides.length <= portCount(step.type)
            && step.outSides.every((v: any) => v === undefined || v === null || isSide(v))
        ) {
            outSides = step.outSides.map((v: any) => (isSide(v) ? v : undefined)) as AnchorSide[];
        }
        // Resolve every anchor (stored or default) and require distinct sides.
        const resolved: string[] = [];
        const isTrigger = this.stepMeta[step.type] && this.stepMeta[step.type].category === 'trigger';
        if (!isTrigger) {
            resolved.push(inSide || 'west');
        }
        for (let p = 0; p < portCount(step.type); p++) {
            resolved.push((outSides && outSides[p]) || (p === 1 ? 'south' : 'east'));
        }
        if (new Set(resolved).size !== resolved.length) {
            return undefined;
        }
        return inSide || outSides ? {inSide, outSides} : undefined;
    }

    /** Strictly validates the stored design BEFORE applying anything; false = corrupted. */
    private restoreFromDesign(design: any): boolean {
        if (typeof design !== 'object' || Array.isArray(design) || design.version !== DESIGN_VERSION
            || !Array.isArray(design.steps)
        ) {
            return false;
        }
        const ids = new Set<string>();
        const names = new Set<string>();
        const byId: Record<string, any> = {};
        for (const step of design.steps) {
            if (typeof step !== 'object' || step === null || !this.stepMeta[step.type]
                || typeof step.id !== 'string' || step.id === '' || ids.has(step.id)
                || typeof step.name !== 'string' || step.name.trim() === ''
                || names.has(step.name.trim().toLowerCase())
                || !Number.isFinite(step.x) || !Number.isFinite(step.y)
                || (step.config !== undefined && step.config !== null
                    && (typeof step.config !== 'object' || Array.isArray(step.config)))
            ) {
                return false;
            }
            ids.add(step.id);
            names.add(step.name.trim().toLowerCase());
            byId[step.id] = step;
        }
        const links = design.links === undefined ? [] : design.links;
        if (!Array.isArray(links)) {
            return false;
        }
        const usedPorts = new Set<string>();
        const usedInputs = new Set<string>();
        for (const link of links) {
            if (typeof link !== 'object' || link === null
                || !byId[link.from] || !byId[link.to] || link.from === link.to
                || !Number.isInteger(link.fromPort) || link.fromPort < 0
                || link.fromPort >= portCount(byId[link.from].type)
                || this.stepMeta[byId[link.to].type].category === 'trigger'
                || usedPorts.has(`${link.from}:${link.fromPort}`) || usedInputs.has(link.to)
            ) {
                return false;
            }
            usedPorts.add(`${link.from}:${link.fromPort}`);
            usedInputs.add(link.to);
        }
        // Optional sub-flow entry marker: must reference an existing non-trigger step with no
        // incoming link, and can only exist while the design holds no trigger at all.
        const start = design.start === undefined || design.start === null ? null : design.start;
        if (start !== null && (typeof start !== 'string' || !byId[start]
            || usedInputs.has(start)
            || design.steps.some((s: any) => this.stepMeta[s.type].category === 'trigger'))
        ) {
            return false;
        }

        design.steps.forEach((step: any) => this.addStep(
            step.type, this.snap(step.x), this.snap(step.y), step.name, step.id, step.config || null,
            this.sanitizeAnchors(step)
        ));
        this.links = links.map((l: any) => ({from: l.from, fromPort: l.fromPort, to: l.to}));
        this.startId = start;
        this.redrawLinks();
        // Old designs may carry a `toolbox` key — ignored: the toolbox is a per-user workspace
        // preference restored from localStorage ({@see restoreToolboxState}), not flow state.
        return true;
    }

    private snap(value: number): number {
        return Math.round(value / this.spacing) * this.spacing;
    }

    /**
     * Snaps a canvas-relative tile position; only the top-left is clamped — the canvas scrolls,
     * so placing a tile beyond the visible area just grows the scrollable plane.
     */
    private place(x: number, y: number): {x: number; y: number} {
        return {
            x: Math.max(0, this.snap(x)),
            y: Math.max(0, this.snap(y))
        };
    }

    // --- Step tiles ------------------------------------------------------------

    /**
     * First "<base>-<n>" not used by any step on the canvas (names are unique per flow). The base
     * is the toolbox LABEL, sanitized — so a relabeled type (cron shown as "Schedule") names its
     * steps after what the user sees ("schedule-1"), while every other label matches its type.
     */
    private defaultName(type: string): string {
        const label = this.stepMeta[type] ? this.stepMeta[type].label : type;
        const base = trimChar(label.toLowerCase().replace(/[^a-z0-9]+/g, '_'), '_') || type;
        for (let n = 1; ; n++) {
            const candidate = `${base}-${n}`;
            if (!this.nameTaken(candidate)) {
                return candidate;
            }
        }
    }

    private nameTaken(name: string, except?: PlacedStep): boolean {
        const needle = name.toLowerCase();
        return this.steps.some(s => s !== except && s.name.toLowerCase() === needle);
    }

    private buildTile(type: string, name: string): HTMLElement {
        const meta = this.stepMeta[type];
        const el = document.createElement('div');
        el.className = 'aaxis-flow-editor__step';
        el.setAttribute('data-role', 'step');
        el.setAttribute('data-step-type', type);
        el.title = `${name} (${meta.label})`;
        el.style.width = `${this.tileSize}px`;
        el.style.height = `${this.tileSize}px`;
        const icon = document.createElement('span');
        icon.className = `fa ${meta.icon} aaxis-flow-editor__step-icon`;
        icon.setAttribute('aria-hidden', 'true');
        icon.style.fontSize = `${Math.round(this.tileSize * 0.34)}px`;
        el.appendChild(icon);
        const label = document.createElement('span');
        label.className = 'aaxis-flow-editor__step-name';
        label.textContent = name;
        label.style.fontSize = `${Math.min(13, Math.max(9, Math.round(this.tileSize * 0.14)))}px`;
        el.appendChild(label);
        // Output port(s) on the right edge — the drag source for flow links ("×" handle). A
        // choice's two ports are its branches: green (port 0) when the expression matches, red
        // (port 1) when it does not.
        const ports = portCount(type);
        for (let p = 0; p < ports; p++) {
            const port = document.createElement('span');
            port.className = 'aaxis-flow-editor__port'
                + (ports === 2 ? (p === 0 ? ' aaxis-flow-editor__port--a' : ' aaxis-flow-editor__port--b') : '');
            port.setAttribute('data-role', 'port');
            port.setAttribute('data-port', String(p));
            if (ports === 2) {
                port.setAttribute('title', __(p === 0
                    ? 'aaxis.ontology.flow_editor.choice_port_matched'
                    : 'aaxis.ontology.flow_editor.choice_port_not_matched'));
            }
            port.textContent = '×';
            el.appendChild(port);
        }
        return el;
    }

    /** "s" + 6 random alphanumerics, unique among the placed steps. */
    private newStepId(): string {
        const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        for (;;) {
            let id = 's';
            for (let i = 0; i < 6; i++) {
                id += alphabet[Math.floor(Math.random() * alphabet.length)];
            }
            if (!this.stepById(id)) {
                return id;
            }
        }
    }

    private addStep(type: string, x: number, y: number, name?: string, id?: string, config?: Record<string, any> | null, anchors?: {inSide?: AnchorSide; outSides?: AnchorSide[]}): PlacedStep {
        const stepName = (name || '').trim() || this.defaultName(type);
        // A trigger IS the flow's enabled switch — a fresh one arms the flow right away (the old
        // top-bar switch defaulted ON too); without this, a just-dropped trigger reads as
        // disabled until the user happens to open its properties once.
        if (!config && this.stepMeta[type].category === 'trigger') {
            // Endpoint triggers also seed the Response example so the expected shape is visible
            // the first time the properties open (the validator treats enabled+response-only as
            // "not configured yet", so the fresh tile stays green).
            config = type === 'endpoint' ? {enabled: true, response: ENDPOINT_RESPONSE_EXAMPLE} : {enabled: true};
        }
        const pos = this.place(x, y);
        const el = this.buildTile(type, stepName);
        el.style.left = `${pos.x}px`;
        el.style.top = `${pos.y}px`;
        this.canvas().appendChild(el);
        const step: PlacedStep = {id: id || this.newStepId(), type, name: stepName, x: pos.x, y: pos.y, config: config || null, el};
        if (anchors?.inSide) {
            step.inSide = anchors.inSide;
        }
        if (anchors?.outSides) {
            step.outSides = anchors.outSides;
        }
        this.steps.push(step);
        this.positionPorts(step);
        this.updateReachability();
        this.syncDirty();

        return step;
    }

    private renameStep(step: PlacedStep, name: string): void {
        step.name = name;
        step.el.title = `${name} (${this.stepMeta[step.type].label})`;
        const label = step.el.querySelector('.aaxis-flow-editor__step-name');
        if (label) {
            label.textContent = name;
        }
        this.syncDirty();
    }

    /**
     * "Flying" step settings: a panel positioned next to the tile, over a full-viewport backdrop
     * that swallows every outside click — the user must Confirm or Cancel. Title is
     * "<type label> - <name>". For now it only edits the step name (unique, non-empty; errors
     * shown inline); per-type configuration will move in here later.
     */
    private openStepSettings(step: PlacedStep): void {
        const meta = this.stepMeta[step.type];

        const $backdrop = $('<div/>', {'class': 'aaxis-flow-editor__settings-backdrop'});
        const $panel = $('<div/>', {'class': 'aaxis-flow-editor__settings', role: 'dialog', 'aria-modal': 'true'});
        const $title = $('<div/>', {
            'class': 'aaxis-flow-editor__settings-title',
            text: `${meta.label} - ${step.name}`
        });
        $panel.append($title);
        this.makeSettingsDraggable($panel, $title);

        // Layout: [$top: full-width fixed rows] over [$columns: left fields | right side column],
        // then one full-width feedback line and the actions. The panel is height-capped with the
        // middle area scrolling so Cancel/Confirm always stay reachable.
        const $top = $('<div/>', {'class': 'aaxis-flow-editor__settings-top', hidden: true});
        const $body = $('<div/>', {'class': 'aaxis-flow-editor__settings-body'});
        const $side = $('<div/>', {'class': 'aaxis-flow-editor__settings-side', hidden: true});
        const $columns = $('<div/>', {'class': 'aaxis-flow-editor__settings-columns'});
        const $input = $('<input/>', {type: 'text', 'class': 'form-control', maxlength: 64, value: step.name});
        const $error = $('<p/>', {'class': 'aaxis-flow-editor__settings-error aaxis-flow-editor__settings-feedback', text: ''});
        const reposition = (): void => this.positionSettings($panel[0], step.el);

        // Type-specific configuration blocks; each returns error() + merge() for the submit, and
        // optionally a `ready` promise while its catalog data (systems/connectors…) still loads.
        const sections: {error: () => string; merge: (config: Record<string, any>) => Record<string, any>; ready?: Promise<any>}[] = [];
        if (step.type === 'entity_read' || step.type === 'entity_write') {
            $top.prop('hidden', false);
            sections.push(this.entityIoSection(
                step.type === 'entity_read' ? 'reader' : 'writer',
                $top, $body, $panel[0], step.config || {}, $input, reposition
            ));
        } else if (step.type === 'invoke') {
            $top.prop('hidden', false);
            sections.push(this.httpRequestSection($top, $body, $panel[0], step.config || {}, $input, reposition));
        } else if (step.type === 'sql_query') {
            $top.prop('hidden', false);
            sections.push(this.sqlQuerySection($top, $body, $panel[0], step.config || {}, $input));
        } else if (step.type === 'sub_flow') {
            $top.prop('hidden', false);
            sections.push(this.subFlowSection($top, step.config || {}, $input));
        } else if (step.type === 'foreach') {
            $top.prop('hidden', false);
            sections.push(this.foreachSection($top, step.config || {}, $input));
        } else if (step.type.indexOf('file_') === 0) {
            $top.prop('hidden', false);
            sections.push(this.fileOpSection(step.type, $top, $body, $panel[0], step.config || {}, $input));
        } else if (step.type === 'dwl_transform') {
            $top.prop('hidden', false);
            sections.push(this.dwlSection($top, $body, $panel[0], step.config || {}, $input));
        } else if (step.type === 'choice') {
            $top.prop('hidden', false);
            sections.push(this.choiceSection($top, $body, $panel[0], step.config || {}, $input));
        } else if (step.type === 'logger') {
            $top.prop('hidden', false);
            sections.push(this.loggerSection($top, $body, $panel[0], step.config || {}, $input));
        } else if (step.type === 'event') {
            $top.prop('hidden', false);
            sections.push(this.eventSection($top, $body, $panel[0], step.config || {}, $input));
        } else if (step.type === 'ms_teams') {
            $top.prop('hidden', false);
            sections.push(this.msTeamsSection($top, $body, step.config || {}, $input));
        } else if (step.type === 'invoke_php') {
            $top.prop('hidden', false);
            sections.push(this.invokePhpSection($top, $body, $panel[0], step.config || {}, $input));
        } else if (step.type === 'cron') {
            // Schedule owns the first row too (Enabled | Name | Mode).
            $top.prop('hidden', false);
            sections.push(this.scheduleSection($top, $body, step.config || {}, $input));
        } else if (step.type === 'endpoint') {
            $top.prop('hidden', false);
            sections.push(this.endpointSection($top, $body, step.config || {}, $input));
        } else if (this.stepMeta[step.type].category === 'trigger') {
            // Every trigger leads its first row with the Enabled switch — the one place the flow
            // is turned on/off (the server derives flows.enabled from it) — then the name.
            const enabled = this.enabledSwitch(step.config || {});
            $top.prop('hidden', false);
            $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
                enabled.$col,
                this.settingsCol('step_name_label', $input, 2)
            ));
            sections.push({error: () => '', merge: enabled.merge});
            if (step.type === 'entity_change') {
                sections.push(this.systemEntitySection($body, step.config || {}));
            }
        } else {
            $body.append(
                $('<label/>', {'class': 'aaxis-flow-editor__settings-label', text: __('aaxis.ontology.flow_editor.step_name_label')}),
                $input
            );
        }
        $columns.append($body, $side);
        $panel.append($top, $columns, $error);

        const $actions = $('<div/>', {'class': 'aaxis-flow-editor__settings-actions'});
        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', text: __('aaxis.ontology.flow_editor.cancel')});
        const $confirm = $('<button/>', {type: 'button', 'class': 'btn btn-primary', text: __('aaxis.ontology.flow_editor.confirm')});
        $actions.append($cancel, $confirm);
        $panel.append($actions);

        $(document.body).append($backdrop, $panel);
        this.positionSettings($panel[0], step.el);

        // While section catalogs load, a spinner overlay blocks every interaction in the panel
        // (buttons included); focus and submit unlock once all loads settle.
        const readies = sections.map(s => s.ready).filter(Boolean) as Promise<any>[];
        let loading = readies.length > 0;
        if (loading) {
            const $loading = $('<div/>', {'class': 'aaxis-flow-editor__settings-loading'})
                .append($('<span/>', {'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true'}));
            $panel.append($loading);
            Promise.allSettled(readies).then(() => {
                loading = false;
                $loading.remove();
                this.positionSettings($panel[0], step.el);
                $input.trigger('focus').trigger('select');
            });
        } else {
            $input.trigger('focus').trigger('select');
        }

        const close = (): void => {
            $(document).off('keydown.aaxisFlowSettings');
            // Drop any in-flight drag listeners (the panel can be closed mid-drag via Escape).
            $(document).off('.aaxisFlowSettingsDrag');
            $backdrop.remove();
            $panel.remove();
        };
        // Escape anywhere = the Cancel button (works during the loading overlay too — closing
        // discards everything, so there is nothing half-loaded to protect).
        $(document).on('keydown.aaxisFlowSettings', (e: any) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                close();
            }
        });
        const submit = (): void => {
            if (loading) {
                return;
            }
            $error.text('');
            const name = String($input.val() || '').trim();
            if (name === '') {
                $error.text(__('aaxis.ontology.flow_editor.step_name_required'));
                return;
            }
            if (this.nameTaken(name, step)) {
                $error.text(__('aaxis.ontology.flow_editor.step_name_exists'));
                return;
            }
            // Section problems (missing/incomplete fields) do NOT block the confirm anymore:
            // the config is stored as-is, the TILE turns red and the flow cannot run until fixed
            // (the server re-checks the same rules on every run attempt). Only structural name
            // problems above still block.
            let sectionError = '';
            for (const section of sections) {
                const message = section.error();
                if (message !== '') {
                    sectionError = message;
                    break;
                }
            }
            const config = sections.reduce((acc, section) => section.merge(acc), {} as Record<string, any>);
            step.config = Object.keys(config).length ? config : null;
            this.renameStep(step, name);
            this.markStepInvalid(step, sectionError !== '');
            this.syncDirty();
            close();
        };

        $cancel.on('click', close);
        $confirm.on('click', submit);
        $input.on('keydown', (e: any) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                submit();
            }
        });
        // Modal contract: outside clicks are absorbed, never acted on.
        $backdrop.on('pointerdown click', (e: any) => {
            e.preventDefault();
            e.stopPropagation();
        });
    }

    // --- Step settings: per-type configuration sections ---------------------------------

    private settingsLabel(key: string): any {
        return $('<label/>', {'class': 'aaxis-flow-editor__settings-label', text: __(`aaxis.ontology.flow_editor.${key}`)});
    }

    /**
     * The trigger's Enabled switch — the flow's ONE on/off control (the server derives
     * flows.enabled from it; a missing/unconfigured trigger reads as disabled). Defaults ON for a
     * fresh trigger so configuring it is what arms the flow.
     */
    /** The shared on/off switch control (Enabled, Public, …) — same markup everywhere. */
    private plainSwitch(on: boolean, titleKey: string): {$switch: any; isOn: () => boolean} {
        const $check = $('<input/>', {type: 'checkbox'});
        $check.prop('checked', on);
        const $switch = $('<label/>', {'class': 'aaxis-flow-editor__switch', title: __('aaxis.ontology.flow_editor.' + titleKey)}).append(
            $check,
            $('<span/>', {'class': 'aaxis-flow-editor__switch-track'}).append(
                $('<span/>', {'class': 'aaxis-flow-editor__switch-thumb'})
            )
        );

        return {$switch, isOn: () => $check.is(':checked')};
    }

    /**
     * The trigger's Enabled flag as a FIRST-ROW column, shown before the name on every trigger —
     * the one place the flow is turned on/off (the server derives flows.enabled from it).
     */
    private enabledSwitch(initial: Record<string, any>): {$col: any; merge: (c: Record<string, any>) => Record<string, any>} {
        const control = this.plainSwitch(initial.enabled !== false, 'trigger_enabled_label');

        return {
            $col: this.settingsCol('trigger_enabled_label', control.$switch, 0.4),
            merge: config => ({...config, enabled: control.isOn()})
        };
    }

    /**
     * Schedule trigger (type `cron`): first row Name | Mode (Interval | Cron). Interval asks for
     * a value + unit; Cron asks for a linux cron expression whose textbox turns light red while
     * the value is invalid and shows, below it, symbol guidance for the cron FIELD the caret is
     * currently editing (updates as the input/caret changes). Legacy configs ({expression} only)
     * open in Cron mode.
     */
    /**
     * Foreach Loop — Name | Subflow on the first row (same searchable picker as Call Subflow),
     * Array variable | Flow variable on the second: the subflow runs once per element of the
     * array variable, seeing the element under the flow-variable name and its 0-based position
     * under `index` (both loop-scoped), over the SHARED context.
     */
    private foreachSection($top: any, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>; ready: Promise<any>} {
        let idByName: Record<string, number> = {};
        const combo = this.stringCombo('subflow_placeholder', () => undefined);
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput, 1.1),
            this.settingsCol('subflow_label', combo.$combo, 1.4)
        ));

        const $array = $('<input/>', {type: 'text', 'class': 'form-control', maxlength: 128, placeholder: 'payload'});
        $array.val(String(initial.array || ''));
        const $item = $('<input/>', {type: 'text', 'class': 'form-control', maxlength: 128, placeholder: 'item'});
        $item.val(String(initial.item || ''));
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('foreach_array_label', $array),
            this.settingsCol('foreach_item_label', $item)
        ));
        $top.append($('<p/>', {
            'class': 'aaxis-flow-editor__settings-hint',
            html: __('aaxis.ontology.flow_editor.foreach_hint')
        }));

        const selectedId = initial.subflow ? String(initial.subflow) : '';
        const ready = this.flowCatalog()
            .then((data: {records?: Array<{id: number; name: string | null; type: string}>}) => {
                idByName = {};
                const names: string[] = [];
                (data.records || [])
                    .filter(f => f.type === 'subflow' && (!this.flow || f.id !== this.flow.id))
                    .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
                    .forEach(f => {
                        const name = f.name || String(f.id);
                        idByName[name] = f.id;
                        names.push(name);
                    });
                combo.setOptions(names);
                combo.setEnabled(true);
                const current = names.find(n => String(idByName[n]) === selectedId);
                if (current) {
                    combo.setValue(current);
                }
            });

        const RESERVED = ['index', 'flowuuid', 'flow-uuid', 'choiceresults'];
        return {
            error: () => {
                if (combo.value() === '' || idByName[combo.value()] === undefined) {
                    return __('aaxis.ontology.flow_editor.subflow_required');
                }
                if (String($array.val() || '').trim() === '') {
                    return __('aaxis.ontology.flow_editor.foreach_array_required');
                }
                const item = String($item.val() || '').trim();
                if (item === '') {
                    return __('aaxis.ontology.flow_editor.foreach_item_required');
                }
                if (RESERVED.includes(item.toLowerCase())) {
                    return __('aaxis.ontology.flow_editor.foreach_item_reserved', {name: item});
                }
                return '';
            },
            merge: config => ({
                ...config,
                subflow: String(idByName[combo.value()] ?? ''),
                array: String($array.val() || '').trim(),
                item: String($item.val() || '').trim()
            }),
            ready
        };
    }

    /**
     * A type-ahead combobox over plain string options (the subflow picker's interaction:
     * pointerdown selection so the input's blur cannot swallow the click, typing reopens the
     * choice, blur restores the picked value).
     */
    private stringCombo(placeholderKey: string, onPick: (value: string) => void): {$combo: any; setOptions: (o: string[]) => void; value: () => string; setValue: (v: string) => void; setEnabled: (on: boolean) => void} {
        let selected = '';
        let options: string[] = [];
        const $combo = $('<div/>', {'class': 'aaxis-flow-editor__combo'});
        const $filter = $('<input/>', {
            type: 'text', 'class': 'form-control', autocomplete: 'off', spellcheck: false,
            placeholder: __('aaxis.ontology.flow_editor.' + placeholderKey), disabled: true
        });
        const $list = $('<div/>', {'class': 'aaxis-flow-editor__combo-list', hidden: true});
        $combo.append($filter, $list);

        const renderList = (): void => {
            const query = String($filter.val() || '').trim().toLowerCase();
            $list.empty();
            const matches = options.filter(o => query === '' || o.toLowerCase().indexOf(query) >= 0);
            if (!matches.length) {
                $list.append($('<div/>', {'class': 'aaxis-flow-editor__combo-empty', text: __('aaxis.ontology.flow_editor.combo_no_match')}));
            }
            matches.forEach(o => {
                const $option = $('<button/>', {
                    type: 'button',
                    'class': 'aaxis-flow-editor__combo-option' + (o === selected ? ' is-selected' : ''),
                    text: o
                });
                $option.on('pointerdown', (e: any) => {
                    e.preventDefault();
                    selected = o;
                    $filter.val(o);
                    $list.prop('hidden', true);
                    onPick(o);
                });
                $list.append($option);
            });
        };

        $filter.on('focus input', () => {
            if ($filter.is(':focus')) {
                if (String($filter.val() || '') !== selected) {
                    selected = '';
                }
                renderList();
                $list.prop('hidden', false);
            }
        });
        $filter.on('blur', () => {
            window.setTimeout(() => {
                $list.prop('hidden', true);
                $filter.val(selected);
            }, 120);
        });

        return {
            $combo,
            setOptions: o => {
                options = o;
            },
            value: () => selected,
            setValue: v => {
                selected = v;
                $filter.val(v);
            },
            setEnabled: on => {
                $filter.prop('disabled', !on);
            }
        };
    }

    /**
     * Invoke PHP — Name | Destination, then Class | Method (both type-ahead comboboxes: classes
     * are the app-namespace services collected by InvokableServicesPass, methods load after the
     * class is picked), then the always-DWL Parameters object. Picking a method PRE-FILLS the
     * parameters with a template of its REQUIRED parameters (one placeholder per declared type);
     * reopening a saved step keeps the stored expression untouched.
     */
    private invokePhpSection($top: any, $body: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>; ready: Promise<any>} {
        const $destination = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 128,
            value: String(initial.destination || 'payload')
        });
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput, 1.2),
            this.settingsCol('destination_label', $destination)
        ));

        let methodsByName: Record<string, {name: string; params: Array<{name: string; type: string; required: boolean}>}> = {};

        const params = createDwlField({
            label: __('aaxis.ontology.flow_editor.invoke_php_params_label'),
            value: String(initial.params || ''),
            dwl: true,
            fixed: true, // the parameters object IS DWL — no pure-text mode
            editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
        });

        // Picking a method replaces the parameters with its REQUIRED-parameters template.
        const applyTemplate = (methodName: string): void => {
            const spec = methodsByName[methodName];
            if (!spec) {
                return;
            }
            const placeholder = (type: string): string => {
                const base = type.replace(/^\?/, '');
                if (base === 'int') {
                    return '0';
                }
                if (base === 'float') {
                    return '0.0';
                }
                if (base === 'string') {
                    return "''";
                }
                if (base === 'bool') {
                    return 'false';
                }
                if (base === 'array' || base === 'iterable') {
                    return '[]';
                }
                return 'null';
            };
            const required = spec.params.filter(p => p.required);
            params.$textarea.val(required.length
                ? '{\n' + required.map(p => `    ${p.name}: ${placeholder(p.type)}`).join(',\n') + '\n}'
                : '');
        };

        const methodCombo = this.stringCombo('invoke_php_method_placeholder', m => applyTemplate(m));
        const loadMethods = (className: string, fromPick: boolean): Promise<void> => this.phpMethods(className)
            .then(methods => {
                methodsByName = {};
                methods.forEach(m => {
                    methodsByName[m.name] = m;
                });
                methodCombo.setOptions(methods.map(m => m.name));
                methodCombo.setEnabled(true);
                if (fromPick) {
                    // A new class invalidates the previous method choice and its template.
                    methodCombo.setValue('');
                    params.$textarea.val('');
                }
            });
        const classCombo = this.stringCombo('invoke_php_class_placeholder', c => {
            void loadMethods(c, true);
        });

        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('invoke_php_class_label', classCombo.$combo, 1.5),
            this.settingsCol('invoke_php_method_label', methodCombo.$combo)
        ));

        $body.append(params.$el);
        $body.append($('<p/>', {
            'class': 'aaxis-flow-editor__settings-hint',
            html: __('aaxis.ontology.flow_editor.invoke_php_hint')
        }));
        panel.classList.add('is-wide');

        const ready = this.phpClasses().then(classes => {
            classCombo.setOptions(classes);
            classCombo.setEnabled(true);
            if (initial.class) {
                classCombo.setValue(String(initial.class));
                return loadMethods(String(initial.class), false).then(() => {
                    if (initial.method) {
                        methodCombo.setValue(String(initial.method));
                    }
                });
            }
            return undefined;
        });

        return {
            error: () => {
                if (String($destination.val() || '').trim() === '') {
                    return __('aaxis.ontology.flow_editor.destination_required');
                }
                if (classCombo.value() === '') {
                    return __('aaxis.ontology.flow_editor.invoke_php_class_required');
                }
                if (methodCombo.value() === '') {
                    return __('aaxis.ontology.flow_editor.invoke_php_method_required');
                }
                return '';
            },
            merge: config => ({
                ...config,
                destination: String($destination.val() || '').trim(),
                class: classCombo.value(),
                method: methodCombo.value(),
                params: params.value().trim()
            }),
            ready
        };
    }

    /**
     * Event — Name plus the Value (hardcoded text, or a DWL expression via its toggle): the
     * resolved value is queued as a log-message FLOW EVENT (aaxis_ontology_flow_events) and the
     * flow simply continues.
     */
    private eventSection($top: any, $body: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput)
        ));

        const value = createDwlField({
            label: __('aaxis.ontology.flow_editor.event_value_label'),
            value: String(initial.value || ''),
            dwl: initial.value_dwl === true, // toggleable: hardcoded text, or a DWL expression
            editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
        });
        $body.append(value.$el);
        $body.append($('<p/>', {
            'class': 'aaxis-flow-editor__settings-hint',
            html: __('aaxis.ontology.flow_editor.event_hint')
        }));
        panel.classList.add('is-wide');

        return {
            error: () => value.value().trim() === ''
                ? __('aaxis.ontology.flow_editor.event_value_required')
                : '',
            merge: config => ({...config, value: value.value(), value_dwl: value.isDwl()})
        };
    }

    /**
     * MS Teams notification — Name | Message variable on the first row, the Power Automate
     * webhook URL (plain text, NOT DWL) below: the step sends the named context variable's value
     * as a Teams Adaptive Card message (stringified when not a string). Only well-formed
     * https:// URLs are accepted (any domain).
     */
    private msTeamsSection($top: any, $body: any, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
        // The context variable whose value becomes the message (stringified when not a string).
        const $message = $('<input/>', {type: 'text', 'class': 'form-control', maxlength: 128, placeholder: 'payload'});
        $message.val(String(initial.message || ''));
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput, 1.4),
            this.settingsCol('msteams_message_label', $message)
        ));

        const $webhook = $('<textarea/>', {
            'class': 'form-control aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact',
            rows: 3, spellcheck: false,
            placeholder: 'https://…powerplatform.com/…/invoke?…'
        });
        $webhook.val(String(initial.webhook || ''));
        $body.append(
            this.settingsLabel('msteams_webhook_label'),
            $webhook
        );
        $body.append($('<p/>', {
            'class': 'aaxis-flow-editor__settings-hint',
            html: __('aaxis.ontology.flow_editor.msteams_hint')
        }));

        const httpsOk = (value: string): boolean => {
            try {
                return new URL(value).protocol === 'https:';
            } catch {
                return false;
            }
        };

        return {
            error: () => {
                if (String($message.val() || '').trim() === '') {
                    return __('aaxis.ontology.flow_editor.msteams_message_required');
                }
                const webhook = String($webhook.val() || '').trim();
                if (webhook === '') {
                    return __('aaxis.ontology.flow_editor.msteams_webhook_required');
                }
                return httpsOk(webhook) ? '' : __('aaxis.ontology.flow_editor.msteams_webhook_invalid');
            },
            merge: config => ({
                ...config,
                message: String($message.val() || '').trim(),
                webhook: String($webhook.val() || '').trim()
            })
        };
    }

    /**
     * Endpoint trigger: Enabled | Name | Public on the first row, Method | Path on the second,
     * and the optional Response binding (always-DWL) on the third. The path is matched under
     * /api/aaxis/ontology/flow/…; each {param} placeholder becomes a context variable when the
     * endpoint is called (body/headers arrive as `body`/`headers`), the Public switch decides
     * whether the call needs an authenticated API user (default), and the Response expression —
     * evaluated against the FINAL context — binds `{statusCode, body}` onto the HTTP response.
     */
    private endpointSection($top: any, $body: any, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
        const enabled = this.enabledSwitch(initial);
        // Default is REQUIRE AUTH: public only when explicitly switched on.
        const pub = this.plainSwitch(initial.public === true, 'endpoint_public_label');
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            enabled.$col,
            this.settingsCol('step_name_label', $nameInput, 2),
            this.settingsCol('endpoint_public_label', pub.$switch, 0.4)
        ));

        const $method = $('<select/>', {'class': 'form-control'});
        ['GET', 'POST', 'PUT', 'QUERY', 'PATCH', 'DELETE'].forEach(m => {
            $method.append($('<option/>', {value: m, text: m, selected: String(initial.method || 'GET') === m}));
        });
        const $path = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 256,
            placeholder: __('aaxis.ontology.flow_editor.endpoint_path_placeholder')
        });
        $path.val(String(initial.path || ''));
        $body.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('endpoint_method_label', $method, 0.6),
            this.settingsCol('endpoint_path_label', $path, 2.4)
        ));

        const response = createDwlField({
            label: __('aaxis.ontology.flow_editor.endpoint_response_label'),
            value: String(initial.response || ''),
            dwl: true,
            fixed: true, // the response binding IS DWL — no pure-text mode
            editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
        });
        $body.append(response.$el);
        $body.append($('<p/>', {
            'class': 'aaxis-flow-editor__settings-hint',
            html: __('aaxis.ontology.flow_editor.endpoint_hint')
        }));

        const RESERVED = ['body', 'headers', 'queryparams', 'oauthapplication', 'flowuuid', 'flow-uuid', 'choiceresults', 'payload'];
        const pathError = (): string => {
            const path = trimChar(String($path.val() || '').trim(), '/');
            if (path === '') {
                return __('aaxis.ontology.flow_editor.endpoint_path_required');
            }
            for (const segment of path.split('/')) {
                const param = segment.match(/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/);
                if (param) {
                    if (RESERVED.includes(param[1].toLowerCase())) {
                        return __('aaxis.ontology.flow_editor.endpoint_path_reserved', {name: param[1]});
                    }
                    continue;
                }
                if (!/^[A-Za-z0-9_.-]+$/.test(segment)) {
                    return __('aaxis.ontology.flow_editor.endpoint_path_invalid');
                }
            }
            return '';
        };

        return {
            error: pathError,
            merge: config => enabled.merge({
                ...config,
                method: String($method.val()),
                path: String($path.val() || '').trim(),
                public: pub.isOn(),
                response: response.value().trim()
            })
        };
    }

    private scheduleSection($top: any, $body: any, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
        const enabled = this.enabledSwitch(initial);
        const initialMode = initial.mode === 'interval' || (initial.mode === undefined && !initial.expression)
            ? 'interval' : 'cron';
        const $mode = $('<select/>', {'class': 'form-control'});
        $mode.append($('<option/>', {value: 'interval', text: __('aaxis.ontology.flow_editor.schedule_mode_interval'), selected: initialMode === 'interval'}));
        $mode.append($('<option/>', {value: 'cron', text: __('aaxis.ontology.flow_editor.schedule_mode_cron'), selected: initialMode === 'cron'}));
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            enabled.$col,
            this.settingsCol('step_name_label', $nameInput, 1.4),
            this.settingsCol('schedule_mode_label', $mode)
        ));

        // Interval: every <value> <unit>.
        const $value = $('<input/>', {type: 'number', 'class': 'form-control', min: 1, step: 1});
        $value.val(initialMode === 'interval' && initial.value ? String(initial.value) : '1');
        const $unit = $('<select/>', {'class': 'form-control'});
        ['minute', 'hour', 'day', 'week', 'month', 'year'].forEach(unit => {
            $unit.append($('<option/>', {
                value: unit, text: __(`aaxis.ontology.flow_editor.schedule_unit_${unit}`),
                selected: initialMode === 'interval' && initial.unit === unit
            }));
        });
        const $intervalRow = $('<div/>', {'class': 'aaxis-flow-editor__settings-row'})
            .append(this.settingsCol('schedule_value_label', $value), this.settingsCol('schedule_unit_label', $unit));

        // Cron: the expression + live validity tint + per-field symbol guidance.
        const $cron = $('<input/>', {
            type: 'text', 'class': 'form-control aaxis-flow-editor__cron-input', maxlength: 128,
            placeholder: __('aaxis.ontology.flow_editor.cron_expression_placeholder')
        });
        $cron.val(initialMode === 'cron' ? String(initial.expression || '') : '');
        const $hint = $('<div/>', {'class': 'aaxis-flow-editor__cron-hint'});
        const $cronBlock = $('<div/>').append(this.settingsLabel('cron_expression_label'), $cron, $hint);
        $body.append($intervalRow, $cronBlock);

        const FIELD_EXAMPLES = ['*  */15  0,30  10-20', '*  */2  8-18  0,12', '*  1  1,15  */2', '*  1-12  JAN,JUL  */3', '*  0-6  MON-FRI  SUN'];
        const syncCron = (): void => {
            const text = String($cron.val() || '');
            $cron.toggleClass('aaxis-flow-editor__cron-input--invalid', !isValidCron(text));
            if (text.trim().startsWith('@')) {
                $hint.text(__('aaxis.ontology.flow_editor.schedule_cron_macros'));
                return;
            }
            // Which of the 5 fields is being edited: whitespace groups fully before the caret.
            const caret = ($cron[0] as HTMLInputElement).selectionStart ?? text.length;
            const before = text.slice(0, caret);
            const tokens = before.trim() === '' ? [] : before.trim().split(/\s+/);
            const idx = Math.min(4, /\s$/.test(before) || tokens.length === 0 ? tokens.length : tokens.length - 1);
            const field = __(`aaxis.ontology.flow_editor.schedule_cron_field_${idx}`);
            $hint.text(`${field} — ${FIELD_EXAMPLES[idx]}`);
        };
        $cron.on('input keyup click focus', syncCron);
        syncCron();

        const syncMode = (): void => {
            const mode = String($mode.val());
            $intervalRow.toggle(mode === 'interval');
            $cronBlock.toggle(mode === 'cron');
        };
        $mode.on('change', syncMode);
        syncMode();

        return {
            error: () => {
                if (String($mode.val()) === 'interval') {
                    const value = Number($value.val());
                    return Number.isInteger(value) && value >= 1 ? '' : __('aaxis.ontology.flow_editor.schedule_value_invalid');
                }
                return isValidCron(String($cron.val() || '')) ? '' : __('aaxis.ontology.flow_editor.cron_expression_invalid');
            },
            merge: config => enabled.merge(String($mode.val()) === 'interval'
                ? {...config, mode: 'interval', value: Number($value.val()), unit: String($unit.val())}
                : {...config, mode: 'cron', expression: String($cron.val() || '').trim()})
        };
    }

    /** One labeled control wrapped as a flex column for the settings rows. */
    private settingsCol(labelKey: string, $control: any, grow = 1): any {
        return $('<div/>', {'class': 'aaxis-flow-editor__settings-col', css: {flex: String(grow)}})
            .append(this.settingsLabel(labelKey), $control);
    }

    /**
     * System + Entity pair (entity options follow the chosen system), fed by ONE
     * aaxis_ontology_entity_list fetch. Both values are required. `inline` renders the two
     * selects side by side on one row instead of stacked. `onChange` fires whenever the selected
     * entity may have changed (initial load included); `attributes()` exposes the currently
     * selected entity's attributes (e.g. for the reader's Order By options).
     */
    private systemEntitySection($body: any, initial: Record<string, any>, inline = false, onChange?: () => void): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>; ready: Promise<any>; attributes: () => {name: string}[]} {
        const $system = $('<select/>', {'class': 'form-control', disabled: true});
        const $entity = $('<select/>', {'class': 'form-control', disabled: true});
        const loadingOption = (): any => $('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.loading')});
        $system.append(loadingOption());
        $entity.append(loadingOption());
        if (inline) {
            $body.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'})
                .append(this.settingsCol('config_system_label', $system), this.settingsCol('config_entity_label', $entity)));
        } else {
            $body.append(this.settingsLabel('config_system_label'), $system, this.settingsLabel('config_entity_label'), $entity);
        }

        let entities: {name: string; displayName?: string; systemName?: string; attributes?: {name: string}[]; readerAttributes?: string[]}[] = [];
        const fillEntities = (): void => {
            const system = String($system.val() || '');
            const current = String(initial.entity || '');
            $entity.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
            entities.filter(e => e.systemName === system).forEach(e => {
                $entity.append($('<option/>', {value: e.name, text: e.displayName || e.name, selected: e.name === current}));
            });
        };

        const ready = this.entityCatalog()
            .then((data: {systems?: {name: string}[]; entities?: any[]}) => {
                entities = data.entities || [];
                $system.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
                (data.systems || []).forEach(s => {
                    $system.append($('<option/>', {value: s.name, text: s.name, selected: s.name === String(initial.system || '')}));
                });
                $system.prop('disabled', false);
                $entity.prop('disabled', false);
                fillEntities();
                if (onChange) {
                    onChange();
                }
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.catalog_load_error')));
        $system.on('change', () => {
            fillEntities();
            if (onChange) {
                onChange();
            }
        });
        if (onChange) {
            $entity.on('change', onChange);
        }

        return {
            error: () => (String($system.val() || '') !== '' && String($entity.val() || '') !== '')
                ? '' : __('aaxis.ontology.flow_editor.config_system_entity_required'),
            merge: config => ({...config, system: String($system.val()), entity: String($entity.val())}),
            ready,
            attributes: () => {
                const system = String($system.val() || '');
                const entity = String($entity.val() || '');
                const match = entities.find(e => e.systemName === system && e.name === entity);
                if (!match) {
                    return [];
                }
                // What a reader can address: internal entities list their readable Oro fields here
                // (all scalar columns when no attributes are configured); falls back to the
                // configured attributes for older catalog payloads.
                if (match.readerAttributes && match.readerAttributes.length) {
                    return match.readerAttributes.map(name => ({name: name}));
                }
                return match.attributes || [];
            }
        };
    }

    /**
     * DWL transform: fixed first row (Name | Destination) and a wide code textarea. The script
     * sees every key of the debug context (payload, prior destinations…) as a variable; its
     * result lands under the destination. Syntax is validated server-side on save.
     */
    private dwlSection($top: any, $body: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
        const $destination = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 128,
            value: String(initial.destination || 'payload')
        });
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput),
            this.settingsCol('destination_label', $destination)
        ));

        const code = createDwlField({
            label: __('aaxis.ontology.flow_editor.dwl_code_label'),
            value: String(initial.code || ''),
            dwl: true,
            fixed: true, // the transform's code IS DWL — no pure-text mode, switch hidden
            editorClass: 'aaxis-flow-editor__settings-textarea'
        });
        $body.append(code.$el);
        // A code editor deserves the wide panel from the start.
        panel.classList.add('is-wide');

        return {
            error: () => {
                if (code.value().trim() === '') {
                    return __('aaxis.ontology.flow_editor.dwl_code_required');
                }
                return this.destinationError($destination);
            },
            merge: config => ({
                ...config,
                code: code.value(),
                destination: String($destination.val() || '').trim()
            })
        };
    }

    /**
     * Choice ("if"): first row = Name, body = the success DWL expression. When the expression
     * evaluates truthy against the context the flow continues on the GREEN output; otherwise on
     * the RED one (optional — leaving it unconnected simply ends the flow there).
     */
    private choiceSection($top: any, $body: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput)
        ));

        const expression = createDwlField({
            label: __('aaxis.ontology.flow_editor.choice_expression_label'),
            value: String(initial.expression || ''),
            dwl: true,
            fixed: true, // the condition IS DWL — no pure-text mode, switch hidden
            editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
        });
        $body.append(expression.$el);
        $body.append($('<p/>', {
            'class': 'aaxis-flow-editor__settings-hint',
            html: __('aaxis.ontology.flow_editor.choice_hint')
        }));
        panel.classList.add('is-wide');

        return {
            error: () => expression.value().trim() === ''
                ? __('aaxis.ontology.flow_editor.choice_expression_required')
                : '',
            merge: config => ({...config, expression: expression.value()})
        };
    }

    /**
     * Logger — name plus the (always-DWL) message: the resolved text is written to the PHP
     * application log as one "[Aaxis Flow - <flow name>] <message>" line.
     */
    private loggerSection($top: any, $body: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput)
        ));

        const message = createDwlField({
            label: __('aaxis.ontology.flow_editor.logger_message_label'),
            value: String(initial.message || ''),
            dwl: initial.message_dwl === true, // toggleable: hardcoded text, or a DWL expression
            editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
        });
        $body.append(message.$el);
        $body.append($('<p/>', {
            'class': 'aaxis-flow-editor__settings-hint',
            html: __('aaxis.ontology.flow_editor.logger_hint')
        }));
        panel.classList.add('is-wide');

        return {
            error: () => message.value().trim() === ''
                ? __('aaxis.ontology.flow_editor.logger_message_required')
                : '',
            merge: config => ({...config, message: message.value(), message_dwl: message.isDwl()})
        };
    }

    /**
     * Entity Read / Entity Write — the typed successors of the generic reader/writer's ENTITY
     * variant: same fields and config keys, no type selector. First fixed row: Name | Destination;
     * then System | Entity; readers add the Load row (All / By id / By attribute + the "All"
     * extras), writers the Content field (context key, or a DWL expression via its toggle).
     */
    private entityIoSection(kind: 'reader' | 'writer', $top: any, $body: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any, reposition: () => void): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>; ready: Promise<any>} {
        const $destination = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 128,
            value: String(initial.destination || 'payload')
        });
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput, 1.2),
            this.settingsCol('destination_label', $destination)
        ));

        let onEntityChange: () => void = () => undefined;
        const entityPair = this.systemEntitySection($body, initial, true, () => onEntityChange());

        // Reader: the Load row (mode + its per-mode fields). Same controls as the generic reader.
        const $mode = $('<select/>', {'class': 'form-control'});
        const $recordId = $('<input/>', {type: 'text', 'class': 'form-control', maxlength: 255, value: String(initial.record_id || '')});
        const $searchAttr = $('<select/>', {'class': 'form-control'});
        const $searchValue = $('<input/>', {type: 'text', 'class': 'form-control', maxlength: 255, value: String(initial.attr_value || '')});
        const $orderBy = $('<select/>', {'class': 'form-control'});
        const $orderDir = $('<select/>', {'class': 'form-control'});
        const $limit = $('<select/>', {'class': 'form-control'});
        let $recordIdCol: any = null;
        let $searchAttrCol: any = null;
        let $searchValueCol: any = null;
        let $orderByCol: any = null;
        let $orderDirCol: any = null;
        let $limitCol: any = null;

        // Writer: the Content to write — a context key, or a DWL expression when its toggle is ON.
        const content = createDwlField({
            label: __('aaxis.ontology.flow_editor.writer_content_label'),
            value: String(initial.content || 'payload'),
            dwl: initial.content_dwl === true,
            editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
        });

        const syncModeRow = (): void => {
            const mode = String($mode.val());
            $recordIdCol.toggle(mode === 'by_id');
            $searchAttrCol.toggle(mode === 'by_attribute');
            $searchValueCol.toggle(mode === 'by_attribute');
            $orderByCol.toggle(mode === 'all');
            $orderDirCol.toggle(mode === 'all' && String($orderBy.val() || '') !== '');
            $limitCol.toggle(mode === 'all');
            reposition();
        };

        if (kind === 'reader') {
            [['all', 'reader_mode_all'], ['by_id', 'reader_mode_by_id'], ['by_attribute', 'reader_mode_by_attribute']]
                .forEach(([value, key]) => $mode.append($('<option/>', {
                    value, text: __(`aaxis.ontology.flow_editor.${key}`),
                    selected: String(initial.mode || 'all') === value
                })));
            ['asc', 'desc'].forEach(dir => $orderDir.append($('<option/>', {
                value: dir, text: dir.toUpperCase(), selected: String(initial.order_dir || 'asc') === dir
            })));
            const currentLimit = initial.limit ? String(initial.limit) : '';
            $limit.append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.reader_limit_none'), selected: currentLimit === ''}));
            ['1', '10', '100', '1000'].forEach(value => $limit.append($('<option/>', {value, text: value, selected: value === currentLimit})));

            $recordIdCol = this.settingsCol('reader_record_id_label', $recordId, 1.4);
            $searchAttrCol = this.settingsCol('reader_attribute_label', $searchAttr, 1.3);
            $searchValueCol = this.settingsCol('reader_attr_value_label', $searchValue, 1.4);
            $orderByCol = this.settingsCol('reader_order_by_label', $orderBy, 1.3);
            $orderDirCol = this.settingsCol('reader_order_dir_label', $orderDir, 0.8);
            $limitCol = this.settingsCol('reader_limit_label', $limit, 0.9);
            $body.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
                this.settingsCol('reader_mode_label', $mode, 0.9),
                $recordIdCol, $searchAttrCol, $searchValueCol, $orderByCol, $orderDirCol, $limitCol
            ));

            // Order By / search-attribute options follow the SELECTED entity; saved values survive
            // the first fill.
            let pendingOrderBy = String(initial.order_by || '');
            let pendingSearchAttr = String(initial.attribute || '');
            onEntityChange = (): void => {
                const attributes = entityPair.attributes();
                const currentOrder = String($orderBy.val() || '') || pendingOrderBy;
                pendingOrderBy = '';
                $orderBy.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.reader_order_by_none')}));
                attributes.forEach(attribute => $orderBy.append($('<option/>', {
                    value: attribute.name, text: attribute.name, selected: attribute.name === currentOrder
                })));
                const currentAttr = String($searchAttr.val() || '') || pendingSearchAttr;
                pendingSearchAttr = '';
                $searchAttr.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
                attributes.forEach(attribute => $searchAttr.append($('<option/>', {
                    value: attribute.name, text: attribute.name, selected: attribute.name === currentAttr
                })));
                syncModeRow();
            };
            $mode.on('change', syncModeRow);
            $orderBy.on('change', syncModeRow);
            syncModeRow();
        } else {
            $body.append(content.$el);
        }

        return {
            error: () => {
                const pairError = entityPair.error();
                if (pairError !== '') {
                    return pairError;
                }
                if (kind === 'reader' && String($mode.val()) === 'by_id' && String($recordId.val() || '').trim() === '') {
                    return __('aaxis.ontology.flow_editor.reader_record_id_required');
                }
                if (kind === 'reader' && String($mode.val()) === 'by_attribute') {
                    if (String($searchAttr.val() || '') === '') {
                        return __('aaxis.ontology.flow_editor.reader_attribute_required');
                    }
                    if (String($searchValue.val() || '').trim() === '') {
                        return __('aaxis.ontology.flow_editor.reader_attr_value_required');
                    }
                }
                if (kind === 'writer' && content.value().trim() === '') {
                    return __('aaxis.ontology.flow_editor.writer_content_required');
                }
                return this.destinationError($destination);
            },
            merge: config => {
                const withPair = entityPair.merge({...config, destination: String($destination.val() || '').trim()});
                if (kind === 'reader') {
                    withPair.mode = String($mode.val());
                    if (withPair.mode === 'by_id') {
                        withPair.record_id = String($recordId.val() || '').trim();
                    } else if (withPair.mode === 'by_attribute') {
                        withPair.attribute = String($searchAttr.val() || '');
                        withPair.attr_value = String($searchValue.val() || '').trim();
                    } else {
                        const orderBy = String($orderBy.val() || '');
                        if (orderBy !== '') {
                            withPair.order_by = orderBy;
                            withPair.order_dir = String($orderDir.val());
                        }
                        const limit = String($limit.val() || '');
                        if (limit !== '') {
                            withPair.limit = Number(limit);
                        }
                    }
                } else {
                    withPair.content = content.value().trim();
                    withPair.content_dwl = content.isDwl();
                }
                return withPair;
            },
            ready: entityPair.ready
        };
    }

    /**
     * HTTP Request (type `invoke`): a rest_api connector call, the response stored under the
     * destination. Row 1: Name | Connector (rest_api only) | Destination; row 2: Operation | Path
     * | Body; row 3: the Body content DWL field — always visible, enabled only while the Body
     * selection is not Empty.
     */
    private httpRequestSection($top: any, $body: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any, reposition: () => void): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>; ready: Promise<any>} {
        const $connector = $('<select/>', {'class': 'form-control', disabled: true});
        $connector.append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.loading')}));
        const $destination = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 128,
            value: String(initial.destination || 'payload')
        });
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput, 1.2),
            this.settingsCol('connector_label', $connector, 1.4),
            this.settingsCol('destination_label', $destination)
        ));

        const $operation = $('<select/>', {'class': 'form-control'});
        ['get', 'put', 'post', 'patch', 'delete'].forEach(op => $operation.append($('<option/>', {
            value: op, text: op.toUpperCase(), selected: initial.operation === op
        })));
        const $bodyType = $('<select/>', {'class': 'form-control'});
        ['empty', 'json', 'text', 'xml'].forEach(b => $bodyType.append($('<option/>', {
            value: b, text: __(`aaxis.ontology.flow_editor.body_${b}`), selected: initial.body === b
        })));
        const $path = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 255,
            value: String(initial.path || '')
        });
        $body.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('operation_label', $operation),
            this.settingsCol('path_label', $path, 1.6),
            this.settingsCol('body_label', $bodyType)
        ));

        const bodyContent = createDwlField({
            label: __('aaxis.ontology.flow_editor.body_content_label'),
            value: String(initial.body_content || ''),
            dwl: initial.body_dwl === true,
            editorClass: 'aaxis-flow-editor__settings-textarea'
        });
        $body.append(bodyContent.$el);
        panel.classList.add('is-wide');

        // rest_api connectors only — this step IS an HTTP call.
        const connectorsReady = this.connectorCatalog()
            .then((data: {records?: {id: number; name: string; type: string; systemName?: string}[]}) => {
                const current = String(initial.connector || '');
                $connector.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
                (data.records || []).filter(c => c.type === 'rest_api').forEach(c => {
                    $connector.append($('<option/>', {
                        value: String(c.id),
                        text: `${c.name} (${c.type}${c.systemName ? ' · ' + c.systemName : ''})`,
                        selected: String(c.id) === current
                    }));
                });
                $connector.prop('disabled', false);
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.catalog_load_error')));

        // The Body content stays in place whatever the Body selection — it is merely disabled
        // (dimmed, textarea + DWL switch inert) while the request carries no body.
        const syncBodyContent = (): void => {
            const enabled = String($bodyType.val()) !== 'empty';
            bodyContent.$el.toggleClass('aaxis-dwl-field--disabled', !enabled);
            bodyContent.$textarea.prop('disabled', !enabled);
            bodyContent.$el.find('input[type="checkbox"]').prop('disabled', !enabled);
            reposition();
        };
        $bodyType.on('change', syncBodyContent);
        syncBodyContent();

        return {
            error: () => {
                if (String($connector.val() || '') === '') {
                    return __('aaxis.ontology.flow_editor.reader_connector_required');
                }
                if (String($path.val() || '').trim() === '') {
                    return __('aaxis.ontology.flow_editor.reader_path_required');
                }
                return this.destinationError($destination);
            },
            merge: config => {
                const merged: Record<string, any> = {
                    ...config,
                    connector: String($connector.val()),
                    operation: String($operation.val()),
                    path: String($path.val() || '').trim(),
                    body: String($bodyType.val()),
                    destination: String($destination.val() || '').trim()
                };
                if (merged.body !== 'empty') {
                    merged.body_content = bodyContent.value();
                    merged.body_dwl = bodyContent.isDwl();
                }
                return merged;
            },
            ready: connectorsReady
        };
    }

    /**
     * SQL Query (type `sql_query`): runs SQL against a DATABASE connector, the result under the
     * destination. Row 1: Name | Connector (database only) | Destination; row 2: the SQL — a DWL
     * field, so it can be a literal statement or an expression building one; `:name` placeholders
     * are bound from row 3's Bindings (a context key or DWL expression: an object = one run, an
     * ARRAY = the query runs once per element).
     */
    private sqlQuerySection($top: any, $body: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>; ready: Promise<any>} {
        const $connector = $('<select/>', {'class': 'form-control', disabled: true});
        $connector.append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.loading')}));
        const $destination = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 128,
            value: String(initial.destination || 'payload')
        });
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput, 1.2),
            this.settingsCol('connector_label', $connector, 1.4),
            this.settingsCol('destination_label', $destination)
        ));

        // Database connectors only — this step IS a SQL call.
        const connectorsReady = this.connectorCatalog()
            .then((data: {records?: {id: number; name: string; type: string; systemName?: string}[]}) => {
                const current = String(initial.connector || '');
                $connector.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
                (data.records || []).filter(c => c.type === 'database').forEach(c => {
                    $connector.append($('<option/>', {
                        value: String(c.id),
                        text: `${c.name} (${c.type}${c.systemName ? ' · ' + c.systemName : ''})`,
                        selected: String(c.id) === current
                    }));
                });
                $connector.prop('disabled', false);
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.catalog_load_error')));

        const sql = createDwlField({
            label: __('aaxis.ontology.flow_editor.sql_label'),
            value: String(initial.sql || ''),
            dwl: initial.sql_dwl === true,
            editorClass: 'aaxis-flow-editor__settings-textarea'
        });
        $body.append(sql.$el);
        const binding = createDwlField({
            label: __('aaxis.ontology.flow_editor.sql_bindings_label'),
            value: String(initial.binding || ''),
            dwl: initial.binding_dwl === true,
            editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
        });
        $body.append(binding.$el);
        $body.append($('<p/>', {
            'class': 'aaxis-flow-editor__settings-hint',
            text: __('aaxis.ontology.flow_editor.sql_hint')
        }));
        panel.classList.add('is-wide');

        return {
            error: () => {
                if (String($connector.val() || '') === '') {
                    return __('aaxis.ontology.flow_editor.reader_connector_required');
                }
                if (sql.value().trim() === '') {
                    return __('aaxis.ontology.flow_editor.sql_required');
                }
                return this.destinationError($destination);
            },
            merge: config => {
                const merged: Record<string, any> = {
                    ...config,
                    connector: String($connector.val()),
                    sql: sql.value().trim(),
                    sql_dwl: sql.isDwl(),
                    destination: String($destination.val() || '').trim()
                };
                if (binding.value().trim() !== '') {
                    merged.binding = binding.value().trim();
                    merged.binding_dwl = binding.isDwl();
                }
                return merged;
            },
            ready: connectorsReady
        };
    }

    /**
     * Call Subflow (type `sub_flow`): just the Name and WHICH subflow to invoke — a searchable
     * combobox (name-ordered; typing filters until an item is picked; the current flow itself is
     * never offered). The config stores the subflow's id, so renames don't break the call.
     */
    private subFlowSection($top: any, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>; ready: Promise<any>} {
        let selectedId: string = initial.subflow ? String(initial.subflow) : '';
        let options: Array<{id: number; name: string}> = [];

        const $combo = $('<div/>', {'class': 'aaxis-flow-editor__combo'});
        const $filter = $('<input/>', {
            type: 'text', 'class': 'form-control', autocomplete: 'off', spellcheck: false,
            placeholder: __('aaxis.ontology.flow_editor.subflow_placeholder'), disabled: true
        });
        const $list = $('<div/>', {'class': 'aaxis-flow-editor__combo-list', hidden: true});
        $combo.append($filter, $list);
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput, 1.1),
            this.settingsCol('subflow_label', $combo, 1.4)
        ));

        const renderList = (): void => {
            const query = String($filter.val() || '').trim().toLowerCase();
            $list.empty();
            const matches = options.filter(o => query === '' || o.name.toLowerCase().indexOf(query) >= 0);
            if (!matches.length) {
                $list.append($('<div/>', {'class': 'aaxis-flow-editor__combo-empty', text: __('aaxis.ontology.flow_editor.subflow_none')}));
            }
            matches.forEach(o => {
                const $option = $('<button/>', {
                    type: 'button',
                    'class': 'aaxis-flow-editor__combo-option' + (String(o.id) === selectedId ? ' is-selected' : ''),
                    text: o.name
                });
                // pointerdown, not click: the input's blur would hide the list first.
                $option.on('pointerdown', (e: any) => {
                    e.preventDefault();
                    selectedId = String(o.id);
                    $filter.val(o.name);
                    $list.prop('hidden', true);
                });
                $list.append($option);
            });
        };

        $filter.on('focus input', () => {
            // Typing again REOPENS the choice: nothing counts as selected until an item is picked.
            if ($filter.is(':focus')) {
                const typed = String($filter.val() || '');
                const current = options.find(o => String(o.id) === selectedId);
                if (!current || current.name !== typed) {
                    selectedId = '';
                }
                renderList();
                $list.prop('hidden', false);
            }
        });
        $filter.on('blur', () => {
            window.setTimeout(() => {
                $list.prop('hidden', true);
                // Restore the picked name (or clear a half-typed non-selection).
                const current = options.find(o => String(o.id) === selectedId);
                $filter.val(current ? current.name : '');
            }, 120);
        });

        const ready = this.flowCatalog()
            .then((data: {records?: Array<{id: number; name: string | null; type: string}>}) => {
                options = (data.records || [])
                    .filter(f => f.type === 'subflow' && (!this.flow || f.id !== this.flow.id))
                    .map(f => ({id: f.id, name: f.name || String(f.id)}))
                    .sort((a, b) => a.name.localeCompare(b.name));
                const current = options.find(o => String(o.id) === selectedId);
                if (current) {
                    $filter.val(current.name);
                } else {
                    selectedId = '';
                }
                $filter.prop('disabled', false);
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.catalog_load_error')));

        return {
            error: () => (selectedId === '' ? __('aaxis.ontology.flow_editor.subflow_required') : ''),
            merge: config => ({...config, subflow: selectedId}),
            ready
        };
    }

    /**
     * File Operations (file_read / file_write / file_list / file_delete / file_rename): one
     * file-based connector (file_system/sftp/bucket), a DWL-capable Path, and per-type extras —
     * Write File adds the Content field, Rename the New name. Row 1: Name | Connector |
     * Destination; row 2: the Path field; row 3 (write/rename only): the extra field.
     */
    private fileOpSection(type: string, $top: any, $body: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>; ready: Promise<any>} {
        const $connector = $('<select/>', {'class': 'form-control', disabled: true});
        $connector.append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.loading')}));
        const $destination = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 128,
            value: String(initial.destination || 'payload')
        });
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput, 1.2),
            this.settingsCol('connector_label', $connector, 1.4),
            this.settingsCol('destination_label', $destination)
        ));

        // File-based connectors only — these steps talk to a storage.
        const connectorsReady = this.connectorCatalog()
            .then((data: {records?: {id: number; name: string; type: string; systemName?: string}[]}) => {
                const current = String(initial.connector || '');
                $connector.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
                (data.records || [])
                    .filter(c => ['file_system', 'sftp', 'bucket'].indexOf(c.type) >= 0)
                    .forEach(c => {
                        $connector.append($('<option/>', {
                            value: String(c.id),
                            text: `${c.name} (${c.type}${c.systemName ? ' · ' + c.systemName : ''})`,
                            selected: String(c.id) === current
                        }));
                    });
                $connector.prop('disabled', false);
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.catalog_load_error')));

        const path = createDwlField({
            label: __('aaxis.ontology.flow_editor.file_path_label'),
            value: String(initial.path || ''),
            dwl: initial.path_dwl === true,
            editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
        });
        $body.append(path.$el);

        // Row 3: Write File carries the Content to write (context key or DWL, like every writer);
        // Rename the New name (bare name = same folder, with "/" = a full path, i.e. a move).
        let content: ReturnType<typeof createDwlField> | null = null;
        let newName: ReturnType<typeof createDwlField> | null = null;
        if (type === 'file_write') {
            content = createDwlField({
                label: __('aaxis.ontology.flow_editor.writer_content_label'),
                value: String(initial.content || 'payload'),
                dwl: initial.content_dwl === true,
                editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
            });
            $body.append(content.$el);
        } else if (type === 'file_rename') {
            newName = createDwlField({
                label: __('aaxis.ontology.flow_editor.file_new_name_label'),
                value: String(initial.new_name || ''),
                dwl: initial.new_name_dwl === true,
                editorClass: 'aaxis-flow-editor__settings-textarea aaxis-flow-editor__settings-textarea--compact'
            });
            $body.append(newName.$el);
        }
        panel.classList.add('is-wide');

        return {
            error: () => {
                if (String($connector.val() || '') === '') {
                    return __('aaxis.ontology.flow_editor.reader_connector_required');
                }
                if (path.value().trim() === '') {
                    return __('aaxis.ontology.flow_editor.reader_path_required');
                }
                if (content && content.value().trim() === '') {
                    return __('aaxis.ontology.flow_editor.writer_content_required');
                }
                if (newName && newName.value().trim() === '') {
                    return __('aaxis.ontology.flow_editor.file_new_name_required');
                }
                return this.destinationError($destination);
            },
            merge: config => {
                const merged: Record<string, any> = {
                    ...config,
                    connector: String($connector.val()),
                    path: path.value().trim(),
                    path_dwl: path.isDwl(),
                    destination: String($destination.val() || '').trim()
                };
                if (content) {
                    merged.content = content.value().trim();
                    merged.content_dwl = content.isDwl();
                }
                if (newName) {
                    merged.new_name = newName.value().trim();
                    merged.new_name_dwl = newName.isDwl();
                }
                return merged;
            },
            ready: connectorsReady
        };
    }

    /**
     * Validates a destination input: required, and "flowUuid" is reserved (every execution seeds
     * its uuid into the context under that key; "choiceResults" too — choice steps record their
     * branch verdicts there; the legacy "flow-uuid" spelling stays rejected too). Returns '' when
     * valid.
     */
    private destinationError($destination: any): string {
        const value = String($destination.val() || '').trim();
        if (value === '') {
            return __('aaxis.ontology.flow_editor.destination_required');
        }
        if (['flowuuid', 'flow-uuid', 'choiceresults'].indexOf(value.toLowerCase()) >= 0) {
            return __('aaxis.ontology.flow_editor.destination_reserved');
        }
        return '';
    }

    /** Places the settings panel next to the tile (right side preferred), clamped to the viewport. */
    /**
     * Lets the settings popup be dragged by its title bar. Once the user has moved it the panel is
     * PINNED — {@see positionSettings} stops re-anchoring it to the tile, so a late reposition (e.g.
     * when a section's catalog finishes loading and the panel grows) can't yank it back.
     */
    private makeSettingsDraggable($panel: any, $title: any): void {
        let from: {x: number; y: number} | null = null;

        const onMove = (event: any): void => {
            if (from === null) {
                return;
            }
            const width = $panel.outerWidth();
            // Always keep a grabbable strip of the panel (and its title) on screen.
            const left = Math.min(Math.max(event.clientX - from.x, 40 - width), window.innerWidth - 40);
            const top = Math.min(Math.max(event.clientY - from.y, 0), window.innerHeight - 32);
            $panel.css({left: `${left}px`, top: `${top}px`});
        };

        $title.on('mousedown', (event: any) => {
            event.preventDefault();
            const rect = $panel[0].getBoundingClientRect();
            from = {x: event.clientX - rect.left, y: event.clientY - rect.top};
            $panel[0].dataset.userMoved = '1';
            $(document).on('mousemove.aaxisFlowSettingsDrag', onMove);
            $(document).on('mouseup.aaxisFlowSettingsDrag', () => {
                from = null;
                $(document).off('.aaxisFlowSettingsDrag');
            });
        });
    }

    private positionSettings(panel: HTMLElement, tile: HTMLElement): void {
        // The user placed it deliberately — leave it where they put it.
        if (panel.dataset.userMoved === '1') {
            return;
        }
        const rect = tile.getBoundingClientRect();
        const panelW = panel.offsetWidth;
        const panelH = panel.offsetHeight;
        let left = rect.right + 12;
        if (left + panelW > window.innerWidth - 8) {
            left = rect.left - panelW - 12;
        }
        left = Math.max(8, left);
        const top = Math.max(8, Math.min(rect.top, window.innerHeight - panelH - 8));
        panel.style.left = `${left}px`;
        panel.style.top = `${top}px`;
    }

    private removeStep(step: PlacedStep): void {
        step.el.remove();
        this.steps = this.steps.filter(s => s !== step);
        this.links = this.links.filter(l => l.from !== step.id && l.to !== step.id);
        if (this.startId === step.id) {
            this.startId = null;
        }
        this.selection.delete(step);
        this.redrawLinks();
        this.syncDirty();
    }

    private findTrigger(): PlacedStep | null {
        return this.steps.find(s => this.stepMeta[s.type]?.category === 'trigger') || null;
    }

    /**
     * Marks every step that would NOT execute: reachability is a BFS from the trigger along the
     * directed links, so without a trigger nothing is reachable and every tile grays out.
     * Re-run on every step/link mutation (hooked into addStep + redrawLinks).
     */
    private updateReachability(): void {
        // Execution enters at the trigger — or, in a sub-flow, at the "Start here" element.
        const trigger = this.findTrigger() || (this.startId ? this.stepById(this.startId) : null);
        const reachable = new Set<string>();
        if (trigger) {
            reachable.add(trigger.id);
            const queue = [trigger.id];
            while (queue.length) {
                const id = queue.shift() as string;
                for (const link of this.links) {
                    if (link.from === id && !reachable.has(link.to)) {
                        reachable.add(link.to);
                        queue.push(link.to);
                    }
                }
            }
        }
        this.steps.forEach(s => s.el.classList.toggle('is-unreachable', !reachable.has(s.id)));
        // Debug is only offered for flows that contain a REAL trigger (not a sub-flow start).
        this.$el.find('[data-role="debug"], [data-role="debug-step"]').prop('hidden', !this.findTrigger());
        this.syncDebugButtons();
        this.$el.find('[data-role="organize"]').prop('hidden', this.steps.length === 0);
    }

    /** Small confirm dialog (Cancel / <confirmLabel>) used by the trigger/start interplays. */
    private confirmDialog(title: string, question: string, confirmLabel: string, onConfirm: () => void): void {
        const dialog = new Dialog({title, width: '460px'});
        const $content = dialog.open();

        const $body = $('<div/>', {'class': 'aaxis-ontology-confirm'});
        $body.append($('<p/>', {'class': 'aaxis-ontology-confirm__q', text: question}));
        const $actions = $('<div/>', {'class': 'aaxis-ontology-confirm__actions'});
        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', text: __('aaxis.ontology.flow_editor.cancel')});
        const $confirm = $('<button/>', {type: 'button', 'class': 'btn btn-primary', text: confirmLabel});
        $actions.append($cancel, $confirm);
        $body.append($actions);
        $content.append($body);

        $cancel.on('click', () => dialog.close());
        $confirm.on('click', () => {
            dialog.close();
            onConfirm();
        });
    }

    // --- Pointer drag state machine ---------------------------------------------

    private trackPointer(): void {
        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
    }

    private untrackPointer(): void {
        if (!this.panelDrag && !this.ghostDrag && !this.stepDrag && !this.linkDrag && !this.marqueeDrag) {
            window.removeEventListener('pointermove', this.onPointerMove);
            window.removeEventListener('pointerup', this.onPointerUp);
        }
    }

    private pointerMove(e: PointerEvent): void {
        if (this.panelDrag?.pointerId === e.pointerId) {
            this.movePanel(e);
        } else if (this.ghostDrag?.pointerId === e.pointerId) {
            this.moveGhost(e);
        } else if (this.stepDrag?.pointerId === e.pointerId) {
            this.moveStep(e);
        } else if (this.linkDrag?.pointerId === e.pointerId) {
            this.moveLinkDrag(e);
        } else if (this.marqueeDrag?.pointerId === e.pointerId) {
            this.moveMarquee(e);
        }
    }

    private pointerUp(e: PointerEvent): void {
        if (this.panelDrag?.pointerId === e.pointerId) {
            this.panelDrag = null;
            this.saveToolboxState(); // the settled spot becomes the shared workspace preference
        } else if (this.ghostDrag?.pointerId === e.pointerId) {
            this.dropGhost(e);
        } else if (this.stepDrag?.pointerId === e.pointerId) {
            this.stepDrag.step.el.classList.remove('is-moving');
            this.stepDrag.group.forEach(g => g.step.el.classList.remove('is-moving'));
            this.stepDrag = null;
        } else if (this.linkDrag?.pointerId === e.pointerId) {
            this.dropLink(e);
        } else if (this.marqueeDrag?.pointerId === e.pointerId) {
            this.endMarquee();
        }
        this.untrackPointer();
        // Ends of tile/toolbox/link drags are the moments positions and links settle.
        this.syncDirty();
    }

    // --- Toolbox panel dragging ---------------------------------------------------

    private startPanelDrag(e: PointerEvent): void {
        const toolbox = this.$el.find('[data-role="toolbox"]')[0];
        const rect = toolbox.getBoundingClientRect();
        this.panelDrag = {pointerId: e.pointerId, dx: e.clientX - rect.left, dy: e.clientY - rect.top};
        this.trackPointer();
        e.preventDefault();
    }

    private movePanel(e: PointerEvent): void {
        if (!this.panelDrag) {
            return;
        }
        // The toolbox is positioned within the WRAP (its offset parent) but must stay inside the
        // canvas VIEWPORT — which starts to the wrap's right of the debugger sidebar when that is
        // open, so the two origins differ and both must be respected.
        const toolbox = this.$el.find('[data-role="toolbox"]')[0];
        const viewport = this.canvasViewport();
        const wrapBounds = (viewport.parentElement as HTMLElement).getBoundingClientRect();
        const minLeft = viewport.offsetLeft;
        const minTop = viewport.offsetTop;

        let left = e.clientX - wrapBounds.left - this.panelDrag.dx;
        let top = e.clientY - wrapBounds.top - this.panelDrag.dy;
        left = Math.min(Math.max(minLeft, left), Math.max(minLeft, minLeft + viewport.clientWidth - toolbox.offsetWidth));
        top = Math.min(Math.max(minTop, top), Math.max(minTop, minTop + viewport.clientHeight - toolbox.offsetHeight));

        toolbox.style.left = `${left}px`;
        toolbox.style.top = `${top}px`;
        toolbox.style.right = 'auto';
    }

    // --- Dragging a NEW step out of the toolbox ------------------------------------

    /**
     * Arms a toolbox drag. The tile is NOT created here — only once the pointer has travelled far
     * enough ({@see moveGhost}), so clicking a toolbox item just selects nothing and adds nothing.
     */
    private startGhostDrag(e: PointerEvent, type: string): void {
        if (!this.stepMeta[type]) {
            return;
        }
        this.ghostDrag = {pointerId: e.pointerId, type, el: null, startX: e.clientX, startY: e.clientY};
        this.trackPointer();
        e.preventDefault();
    }

    private positionGhost(e: PointerEvent): void {
        if (!this.ghostDrag?.el) {
            return;
        }
        this.ghostDrag.el.style.left = `${e.clientX - this.tileSize / 2}px`;
        this.ghostDrag.el.style.top = `${e.clientY - this.tileSize / 2}px`;
    }

    private moveGhost(e: PointerEvent): void {
        if (!this.ghostDrag) {
            return;
        }
        // First real movement materialises the preview tile (the name is provisional — the final one
        // is assigned on drop, after a possible trigger swap).
        if (this.ghostDrag.el === null) {
            const travelled = Math.hypot(e.clientX - this.ghostDrag.startX, e.clientY - this.ghostDrag.startY);
            if (travelled < OntologyFlowEditorComponent.DRAG_THRESHOLD) {
                return;
            }
            const el = this.buildTile(this.ghostDrag.type, this.defaultName(this.ghostDrag.type));
            el.classList.add('is-ghost');
            document.body.appendChild(el);
            this.ghostDrag.el = el;
        }
        this.positionGhost(e);
    }

    private dropGhost(e: PointerEvent): void {
        if (!this.ghostDrag) {
            return;
        }
        const {type, el} = this.ghostDrag;
        this.ghostDrag = null;
        // No tile was ever materialised → this was a click, not a drag. Nothing to add.
        if (el === null) {
            return;
        }
        el.remove();

        // Released while still over the toolbox: the item was never dragged OUT, so it must not be
        // added — the toolbox floats above the canvas, which would otherwise count as a valid drop.
        const toolbox = this.toolbox();
        if (!toolbox.hidden) {
            const tb = toolbox.getBoundingClientRect();
            if (e.clientX >= tb.left && e.clientX <= tb.right && e.clientY >= tb.top && e.clientY <= tb.bottom) {
                return;
            }
        }

        const bounds = this.canvas().getBoundingClientRect();
        const inCanvas = e.clientX >= bounds.left && e.clientX <= bounds.right
            && e.clientY >= bounds.top && e.clientY <= bounds.bottom;
        if (!inCanvas) {
            return;
        }

        const x = e.clientX - bounds.left - this.tileSize / 2;
        const y = e.clientY - bounds.top - this.tileSize / 2;

        // Only one trigger per flow: dropping a second one asks to replace the existing trigger.
        if (this.stepMeta[type].category === 'trigger') {
            const existingTrigger = this.findTrigger();
            if (existingTrigger !== null) {
                this.confirmDialog(
                    __('aaxis.ontology.flow_editor.replace_trigger_title'),
                    __('aaxis.ontology.flow_editor.replace_trigger_question'),
                    __('aaxis.ontology.flow_editor.replace_trigger_confirm'),
                    () => {
                        // The replacement takes the OLD trigger's place — same spot, same
                        // out-anchor sides, same outgoing links (the drop position was just
                        // the gesture; a disconnected trigger helps nobody).
                        const spot = {x: existingTrigger.x, y: existingTrigger.y};
                        const outSides = existingTrigger.outSides ? [...existingTrigger.outSides] : undefined;
                        const outgoing = this.links
                            .filter(l => l.from === existingTrigger.id)
                            .map(l => ({...l}));
                        this.removeStep(existingTrigger);
                        const added = this.addStep(type, spot.x, spot.y, undefined, undefined, undefined, outSides ? {outSides} : undefined);
                        outgoing.forEach(l => this.links.push({...l, from: added.id}));
                        this.redrawLinks();
                        this.syncDirty();
                    }
                );
                return;
            }
            // A trigger displaces the sub-flow "Start here" marker — ask before overriding it.
            const startStep = this.startId ? this.stepById(this.startId) : null;
            if (startStep !== null) {
                this.confirmDialog(
                    __('aaxis.ontology.flow_editor.start_override_title'),
                    __('aaxis.ontology.flow_editor.start_override_question', {name: startStep.name}),
                    __('aaxis.ontology.flow_editor.start_override_confirm'),
                    () => {
                        this.setStart(null);
                        this.addStep(type, x, y);
                    }
                );
                return;
            }
        }

        this.addStepFromDrop(type, x, y, e.clientX - bounds.left, e.clientY - bounds.top);
    }

    /**
     * A toolbox drop: places the tile — CHAINED when released onto an existing element that still
     * has a free flow-out port (the drop makes it that element's NEXT step: positioned one
     * Align-gap away along that port's flow-out side and wired up immediately) — and marks it
     * broken (red) when its type needs configuration a fresh drop cannot have.
     */
    private addStepFromDrop(type: string, x: number, y: number, px: number, py: number): void {
        // Triggers accept no incoming line — they never chain.
        const target = this.stepMeta[type].category === 'trigger'
            ? null
            : this.steps.find(s => px >= s.x && px <= s.x + this.tileSize && py >= s.y && py <= s.y + this.tileSize) || null;
        const freePort = target === null
            ? -1
            : [...new Array(portCount(target.type)).keys()]
                .find(p => !this.links.some(l => l.from === target.id && l.fromPort === p)) ?? -1;

        let added: PlacedStep;
        if (target !== null && freePort >= 0) {
            const side = this.outSideOf(target, freePort);
            const v = SIDE_VECTOR[side];
            const stepGap = 2 * this.tileSize; // one tile + one tile-wide gap, same as Align
            const occupied = new Set(this.steps.map(s => `${s.x},${s.y}`));
            let pos = this.place(target.x + v.dx * stepGap, target.y + v.dy * stepGap);
            let dx = v.dx;
            let dy = v.dy;
            let guard = 0;
            while (occupied.has(`${pos.x},${pos.y}`) && guard++ < 128) {
                const next = this.place(pos.x + dx * stepGap, pos.y + dy * stepGap);
                if (next.x === pos.x && next.y === pos.y) {
                    // Clamped into a canvas wall: scan east from here on (same rule as Align).
                    dx = 1;
                    dy = 0;
                    pos = this.place(pos.x + stepGap, pos.y);
                } else {
                    pos = next;
                }
            }
            // Face the parent: the input lands on the side the line arrives from — unless that
            // side is the fresh tile's own default flow-out (east; choice also south).
            const desiredIn = OPPOSITE_SIDE[side];
            const defaultOuts: AnchorSide[] = type === 'choice' ? ['east', 'south'] : ['east'];
            const anchors = desiredIn !== 'west' && !defaultOuts.includes(desiredIn) ? {inSide: desiredIn} : undefined;
            added = this.addStep(type, pos.x, pos.y, undefined, undefined, undefined, anchors);
            this.links.push({from: target.id, fromPort: freePort, to: added.id});
            this.redrawLinks();
        } else {
            added = this.addStep(type, x, y);
        }

        if (REQUIRES_CONFIG.has(type)) {
            this.markStepInvalid(added, true);
        }
        this.syncDirty();
    }

    /** Any unsaved work in ANY open tab (the active one asks the live canvas) — except while
     * leaving through the explicit Discard button, which silences every guard. */
    private anyTabDirty(): boolean {
        if (this.discarding) {
            return false;
        }
        return this.tabs.some((tab, index) => this.tabDirty(tab, index === this.activeTab));
    }

    // --- Creating a flow link (drag from an output port onto another step) ------------

    /** Whether `target` can receive a link right now (source of the drop feedback messages). */
    private linkTargetError(from: PlacedStep, target: PlacedStep): string | null {
        if (target === from) {
            return null; // silent no-op, like dropping on empty canvas
        }
        if (this.stepMeta[target.type].category === 'trigger') {
            return __('aaxis.ontology.flow_editor.link_target_trigger');
        }
        // The "Start here" arrow counts as the element's one incoming line.
        if (this.links.some(l => l.to === target.id) || this.startId === target.id) {
            return __('aaxis.ontology.flow_editor.link_target_used');
        }
        return '';
    }

    private startLinkDrag(e: PointerEvent, from: PlacedStep, fromPort: number): void {
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('data-role', 'wire-temp');
        this.brandTempWire(path, from, fromPort);
        this.wires.appendChild(path);
        this.linkDrag = {pointerId: e.pointerId, from, fromPort, path, target: null, editing: null};
        this.moveLinkDrag(e);
        this.trackPointer();
        e.preventDefault();
    }

    /**
     * Grabbing an existing link's arrow head re-routes that link. The link is removed from the model
     * up front so the canvas shows what a release would leave behind, and so the "already has an
     * incoming line" rule doesn't count the very link being edited; {@see dropLink} puts it back
     * unless the drop actually changes something.
     */
    private startRelinkDrag(e: PointerEvent, link: FlowLink): void {
        const from = this.stepById(link.from);
        if (!from) {
            return;
        }
        this.links = this.links.filter(l => l !== link);
        this.redrawLinks();

        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('data-role', 'wire-temp');
        this.brandTempWire(path, from, link.fromPort);
        this.wires.appendChild(path);
        this.linkDrag = {
            pointerId: e.pointerId, from, fromPort: link.fromPort, path, target: null, editing: link
        };
        this.moveLinkDrag(e);
        this.trackPointer();
        e.preventDefault();
    }

    private moveLinkDrag(e: PointerEvent): void {
        if (!this.linkDrag) {
            return;
        }
        const bounds = this.canvas().getBoundingClientRect();
        const a = this.outputPos(this.linkDrag.from, this.linkDrag.fromPort);
        this.linkDrag.path.setAttribute(
            'd',
            this.wirePath(a.x, a.y, e.clientX - bounds.left, e.clientY - bounds.top)
        );

        // Live highlight of the step under the pointer when it is a valid target.
        const over = this.stepAt(e.clientX, e.clientY);
        const valid = over !== null && this.linkTargetError(this.linkDrag.from, over) === '';
        if (this.linkDrag.target && this.linkDrag.target !== over) {
            this.linkDrag.target.el.classList.remove('is-link-target');
        }
        this.linkDrag.target = valid ? over : null;
        if (this.linkDrag.target) {
            this.linkDrag.target.el.classList.add('is-link-target');
        }
    }

    private dropLink(e: PointerEvent): void {
        if (!this.linkDrag) {
            return;
        }
        const {from, fromPort, path, target, editing} = this.linkDrag;
        this.linkDrag = null;
        path.remove();
        if (target) {
            target.el.classList.remove('is-link-target');
        }

        const over = this.stepAt(e.clientX, e.clientY);

        if (editing !== null) {
            // Dropped back on the element the line STARTS from → delete it (the line is already out
            // of `links`; leaving it out is the deletion).
            if (over && over.id === editing.from) {
                this.redrawLinks();
                this.syncDirty();
                return;
            }
            // Dropped where it already pointed → the line stays, but the RELEASE POINT may move
            // the target's flow-in anchor: dropping the arrow head on another side of the same
            // tile is the drag way of choosing where lines arrive.
            if (over && over.id === editing.to) {
                this.links.push(editing);
                this.dropAnchor(over, 'in', e);
                this.redrawLinks();
                return;
            }
            // Background, or an element that can't take the line → no change.
            const error = over ? this.linkTargetError(from, over) : null;
            if (!over || error === null || error !== '') {
                if (error) {
                    messenger.notificationFlashMessage('warning', error);
                }
                this.links.push(editing);
                this.redrawLinks();
                return;
            }
            this.links.push({from: editing.from, fromPort: editing.fromPort, to: over.id});
            this.redrawLinks();
            this.syncDirty();
            return;
        }

        if (!over) {
            return;
        }
        // Released on its OWN tile: not a link — the gesture moves this port's flow-out anchor
        // (flow-true/flow-false on a choice) to the side nearest the release point.
        if (over === from) {
            this.dropAnchor(from, fromPort, e);
            return;
        }
        const error = this.linkTargetError(from, over);
        if (error === null) {
            return;
        }
        if (error !== '') {
            messenger.notificationFlashMessage('warning', error);
            return;
        }

        // Each output port drives exactly one link — re-dragging from a used port re-wires it.
        this.links = this.links.filter(l => !(l.from === from.id && l.fromPort === fromPort));
        this.links.push({from: from.id, fromPort, to: over.id});
        this.redrawLinks();
        this.syncDirty();
    }

    /** The tile side nearest to a viewport point — how a dropped line end picks its new anchor. */
    private nearestSide(step: PlacedStep, clientX: number, clientY: number): AnchorSide {
        const rect = step.el.getBoundingClientRect();
        const distances: Array<[AnchorSide, number]> = [
            ['north', Math.abs(clientY - rect.top)],
            ['south', Math.abs(rect.bottom - clientY)],
            ['west', Math.abs(clientX - rect.left)],
            ['east', Math.abs(rect.right - clientX)]
        ];
        distances.sort((a, b) => a[1] - b[1]);
        return distances[0][0];
    }

    /**
     * Re-anchors one side choice from a drag gesture: applies the side nearest the release point
     * when it is free and different — a side another anchor holds just leaves things as they are.
     */
    private dropAnchor(step: PlacedStep, kind: 'in' | number, e: PointerEvent): void {
        const side = this.nearestSide(step, e.clientX, e.clientY);
        const current = kind === 'in' ? this.inSideOf(step) : this.outSideOf(step, kind);
        if (side !== current && !this.usedAnchorSides(step, kind).has(side)) {
            this.setAnchorSide(step, kind, side);
        }
    }

    /** The placed step whose tile contains the given viewport point, if any. */
    private stepAt(clientX: number, clientY: number): PlacedStep | null {
        for (const step of this.steps) {
            const rect = step.el.getBoundingClientRect();
            if (clientX >= rect.left && clientX <= rect.right && clientY >= rect.top && clientY <= rect.bottom) {
                return step;
            }
        }
        return null;
    }

    // --- Selection (click / macOS-style rubber band) -----------------------------------

    private select(steps: PlacedStep[]): void {
        this.selection.forEach(s => s.el.classList.remove('is-selected'));
        this.selection = new Set(steps);
        this.selection.forEach(s => s.el.classList.add('is-selected'));
    }

    private clearSelection(): void {
        this.select([]);
    }

    /** Selection ordered by the horizontal sequence: X position, then Y for exact ties. */
    private orderedSelection(): PlacedStep[] {
        return Array.from(this.selection).sort((a, b) => a.x - b.x || a.y - b.y);
    }

    private startMarquee(e: PointerEvent): void {
        const bounds = this.canvas().getBoundingClientRect();
        const el = document.createElement('div');
        el.className = 'aaxis-flow-editor__marquee';
        this.canvas().appendChild(el);
        this.marqueeDrag = {
            pointerId: e.pointerId,
            x0: e.clientX - bounds.left,
            y0: e.clientY - bounds.top,
            el,
            moved: false
        };
        this.moveMarquee(e);
        this.trackPointer();
        e.preventDefault();
    }

    private moveMarquee(e: PointerEvent): void {
        if (!this.marqueeDrag) {
            return;
        }
        const bounds = this.canvas().getBoundingClientRect();
        const x1 = e.clientX - bounds.left;
        const y1 = e.clientY - bounds.top;
        const {x0, y0, el} = this.marqueeDrag;
        if (Math.abs(x1 - x0) > 3 || Math.abs(y1 - y0) > 3) {
            this.marqueeDrag.moved = true;
        }
        const left = Math.min(x0, x1);
        const top = Math.min(y0, y1);
        const width = Math.abs(x1 - x0);
        const height = Math.abs(y1 - y0);
        el.style.left = `${left}px`;
        el.style.top = `${top}px`;
        el.style.width = `${width}px`;
        el.style.height = `${height}px`;

        // Live selection: every tile intersecting the rubber band.
        this.select(this.steps.filter(s =>
            s.x < left + width && s.x + this.tileSize > left
            && s.y < top + height && s.y + this.tileSize > top
        ));
    }

    private endMarquee(): void {
        if (!this.marqueeDrag) {
            return;
        }
        const {el, moved} = this.marqueeDrag;
        this.marqueeDrag = null;
        el.remove();
        if (!moved) {
            // A plain click on empty canvas: whatever was selected gets unselected.
            this.clearSelection();
        }
    }

    // --- Context menu (right-click on a tile) -------------------------------------------

    private closeContextMenu(): void {
        if (this.menuEl) {
            this.menuEl.remove();
            this.menuEl = null;
            document.removeEventListener('pointerdown', this.onDocPointerDown, true);
        }
    }

    /**
     * Whether the ordered selection can be chained by "Connect": every element after the first
     * must not already receive a line and must not be a trigger, and every element except the
     * last must not be a Choice (its two outputs make the chaining ambiguous).
     */
    private canConnectSelection(ordered: PlacedStep[]): boolean {
        if (ordered.length < 2) {
            return false;
        }
        for (let i = 0; i < ordered.length; i++) {
            const step = ordered[i];
            if (i > 0 && (this.links.some(l => l.to === step.id)
                || this.startId === step.id
                || this.stepMeta[step.type].category === 'trigger')) {
                return false;
            }
            if (i < ordered.length - 1 && step.type === 'choice') {
                return false;
            }
        }
        return true;
    }

    /** Shared context-menu shell: `populate` adds the items, the shell handles placement/closing. */
    private showMenuAt(e: PointerEvent, populate: (addItem: (label: string, icon: string, action: () => void, opts?: {disabled?: boolean; active?: boolean}) => void, addSubmenu?: (label: string, icon: string, children: Array<{label: string; icon: string; action: () => void; disabled?: boolean; active?: boolean}>) => void) => void): void {
        this.closeContextMenu();
        const menu = document.createElement('div');
        menu.className = 'aaxis-flow-editor__menu';
        const buildItem = (label: string, icon: string, action: () => void, opts?: {disabled?: boolean; active?: boolean}): HTMLButtonElement => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'aaxis-flow-editor__menu-item'
                + (opts?.disabled ? ' aaxis-flow-editor__menu-item--disabled' : '')
                + (opts?.active ? ' aaxis-flow-editor__menu-item--active' : '');
            item.innerHTML = `<span class="fa ${icon}" aria-hidden="true"></span>`;
            item.appendChild(document.createTextNode(label));
            if (opts?.disabled) {
                item.disabled = true;
            } else {
                item.addEventListener('click', () => {
                    this.closeContextMenu();
                    action();
                });
            }
            return item;
        };
        const addItem = (label: string, icon: string, action: () => void, opts?: {disabled?: boolean; active?: boolean}): void => {
            menu.appendChild(buildItem(label, icon, action, opts));
        };
        // A nested submenu revealed on HOVER (pure CSS): the parent row carries a ▸ marker and the
        // child list opens flush to its right edge.
        const addSubmenu = (label: string, icon: string, children: Array<{label: string; icon: string; action: () => void; disabled?: boolean; active?: boolean}>): void => {
            const wrap = document.createElement('div');
            wrap.className = 'aaxis-flow-editor__menu-sub';
            const parent = document.createElement('button');
            parent.type = 'button';
            parent.className = 'aaxis-flow-editor__menu-item aaxis-flow-editor__menu-item--parent';
            parent.innerHTML = `<span class="fa ${icon}" aria-hidden="true"></span>`;
            parent.appendChild(document.createTextNode(label));
            const caret = document.createElement('span');
            caret.className = 'fa fa-caret-right aaxis-flow-editor__menu-caret';
            caret.setAttribute('aria-hidden', 'true');
            parent.appendChild(caret);
            const sub = document.createElement('div');
            sub.className = 'aaxis-flow-editor__menu aaxis-flow-editor__menu--nested';
            children.forEach(child => sub.appendChild(
                buildItem(child.label, child.icon, child.action, {disabled: child.disabled, active: child.active})
            ));
            wrap.appendChild(parent);
            wrap.appendChild(sub);
            menu.appendChild(wrap);
        };
        populate(addItem, addSubmenu);

        document.body.appendChild(menu);
        const left = Math.min(e.clientX, window.innerWidth - menu.offsetWidth - 8);
        const top = Math.min(e.clientY, window.innerHeight - menu.offsetHeight - 8);
        menu.style.left = `${Math.max(8, left)}px`;
        menu.style.top = `${Math.max(8, top)}px`;
        this.menuEl = menu;
        document.addEventListener('pointerdown', this.onDocPointerDown, true);
    }

    private openContextMenu(e: PointerEvent, step: PlacedStep): void {
        // Right-clicking outside the current selection re-targets it to the clicked tile.
        if (!this.selection.has(step)) {
            this.select([step]);
        }
        const ordered = this.orderedSelection();

        this.showMenuAt(e, (addItem, addSubmenu) => {
            addItem(__('aaxis.ontology.flow_editor.menu_remove'), 'fa-trash-o', () => this.removeSelection());
            if (ordered.length > 1) {
                addItem(__('aaxis.ontology.flow_editor.menu_align'), 'fa-arrows-h', () => this.alignSelection());
                if (this.canConnectSelection(ordered)) {
                    addItem(__('aaxis.ontology.flow_editor.menu_connect'), 'fa-link', () => this.connectSelection());
                }
            } else if (this.startId === step.id) {
                addItem(__('aaxis.ontology.flow_editor.menu_remove_start'), 'fa-play', () => this.setStart(null));
            } else if (!this.findTrigger() && !this.links.some(l => l.to === step.id)) {
                // Sub-flow entry point: only offered while no trigger exists and nothing arrives here.
                addItem(__('aaxis.ontology.flow_editor.menu_start_here'), 'fa-play', () => this.setStart(step.id));
            }
            if (ordered.length === 1 && (step.type === 'sub_flow' || step.type === 'foreach')) {
                // Navigate to the configured subflow — shown only when it is set AND still a
                // valid subflow in the catalog (sync copy; hidden until the catalog loaded).
                const targetId = Number((step.config || {}).subflow || 0);
                const record = targetId > 0 && this.catalogFlowRecords
                    ? this.catalogFlowRecords.find(f => f.id === targetId && f.type === 'subflow')
                    : undefined;
                if (record) {
                    addItem(__('aaxis.ontology.flow_editor.menu_navigate_subflow'), 'fa-external-link', () => {
                        void this.ensureFlowTab(targetId).then(index => {
                            if (index !== null) {
                                this.switchTab(index);
                            }
                        });
                    });
                }
            }
            if (ordered.length === 1 && step.type === 'subflow' && addSubmenu) {
                // "Called by": every flow whose sub_flow/foreach steps point at THIS subflow —
                // one child per caller, navigating like "Navigate to subflow" does. Computed from
                // the sync catalog copy (fetched at editor load); an unsaved subflow has no id
                // and therefore no callers.
                const selfId = this.flow ? Number((this.flow as any).id || 0) : 0;
                const callers = selfId > 0 && this.catalogFlowRecords
                    ? this.catalogFlowRecords
                        .filter(f => f.id !== selfId && (((f as any).steps || []) as any[]).some(st =>
                            st && (st.type === 'sub_flow' || st.type === 'foreach')
                            && Number(((st.config || {}) as any).subflow || 0) === selfId))
                        .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
                    : [];
                addSubmenu(__('aaxis.ontology.flow_editor.menu_called_by'), 'fa-sitemap', callers.length
                    ? callers.map(f => ({
                        label: f.name || String(f.id),
                        icon: 'fa-external-link',
                        action: () => {
                            void this.ensureFlowTab(f.id).then(index => {
                                if (index !== null) {
                                    this.switchTab(index);
                                }
                            });
                        }
                    }))
                    : [{
                        label: __('aaxis.ontology.flow_editor.menu_called_by_none'),
                        icon: 'fa-ban',
                        action: () => undefined,
                        disabled: true
                    }]);
            }
            if (ordered.length === 1 && addSubmenu) {
                // Anchor sides: where flow lines arrive (flow-in) and leave (flow-out; a choice
                // names its two outputs flow-true/flow-false). Hover reveals the side picker.
                if (this.stepMeta[step.type].category !== 'trigger') {
                    addSubmenu(__('aaxis.ontology.flow_editor.menu_flow_in'), 'fa-sign-in', this.anchorSideChildren(step, 'in'));
                }
                if (step.type === 'choice') {
                    addSubmenu(__('aaxis.ontology.flow_editor.menu_flow_true'), 'fa-sign-out', this.anchorSideChildren(step, 0));
                    addSubmenu(__('aaxis.ontology.flow_editor.menu_flow_false'), 'fa-sign-out', this.anchorSideChildren(step, 1));
                } else {
                    addSubmenu(__('aaxis.ontology.flow_editor.menu_flow_out'), 'fa-sign-out', this.anchorSideChildren(step, 0));
                }
            }
        });
    }

    /**
     * The side-picker entries for one anchor (flow-in / an output port): the four compass sides,
     * the CURRENT one checked, sides used by the tile's OTHER anchors disabled — every anchor of
     * a tile keeps its own side.
     */
    private anchorSideChildren(step: PlacedStep, kind: 'in' | number): Array<{label: string; icon: string; action: () => void; disabled?: boolean; active?: boolean}> {
        const current = kind === 'in' ? this.inSideOf(step) : this.outSideOf(step, kind);
        const used = this.usedAnchorSides(step, kind);
        const icons: Record<AnchorSide, string> = {north: 'fa-arrow-up', south: 'fa-arrow-down', west: 'fa-arrow-left', east: 'fa-arrow-right'};
        return ANCHOR_SIDES.map(side => ({
            label: __(`aaxis.ontology.flow_editor.side_${side}`),
            icon: side === current ? 'fa-check' : icons[side],
            action: () => this.setAnchorSide(step, kind, side),
            disabled: used.has(side),
            active: side === current
        }));
    }

    /** Right-click on a flow line: offers to delete that single connection. */
    private openLinkContextMenu(e: PointerEvent, link: FlowLink): void {
        this.showMenuAt(e, addItem => {
            addItem(__('aaxis.ontology.flow_editor.menu_remove'), 'fa-trash-o', () => {
                this.links = this.links.filter(l => l !== link);
                this.redrawLinks();
                this.syncDirty();
            });
        });
    }

    private removeSelection(): void {
        this.orderedSelection().forEach(step => this.removeStep(step));
        this.clearSelection();
    }

    /**
     * Aligns the selection FOLLOWING THE FLOW LINES' anchor sides: each linked child is placed one
     * tile-gap away from its parent ALONG the parent's flow-out direction for that link (east =
     * to the right, south = below, …), aligned on the perpendicular axis — so a choice with a
     * green-east/red-south pair lays out as an L, and a fully-default flow reproduces the old
     * left-to-right row. Chains are walked breadth-first from their heads (heads by X; a pure
     * cycle enters at its leftmost tile); chain heads and unconnected steps start at the leftmost
     * selected tile\'s spot, sliding east past occupied cells. A spot already claimed keeps
     * advancing along the SAME direction until free (walls slide east so the walk always ends).
     */
    private alignSelection(): void {
        if (this.selection.size < 2) {
            return;
        }
        const byId = new Map(Array.from(this.selection).map(s => [s.id, s]));
        const inner = this.links.filter(l => byId.has(l.from) && byId.has(l.to));
        const linked = new Set<string>();
        inner.forEach(l => {
            linked.add(l.from);
            linked.add(l.to);
        });
        const outgoing = new Map<string, FlowLink[]>();
        [...inner].sort((a, b) => a.fromPort - b.fromPort).forEach(l => {
            const list = outgoing.get(l.from) || [];
            list.push(l);
            outgoing.set(l.from, list);
        });
        const hasIncoming = new Set(inner.map(l => l.to));
        const byPosition = (a: PlacedStep, b: PlacedStep): number => a.x - b.x || a.y - b.y;
        const anchor = Array.from(this.selection).sort(byPosition)[0];
        const anchorPos = this.place(anchor.x, anchor.y);

        const stepGap = 2 * this.tileSize; // one tile + one tile-wide gap
        const occupied = new Set<string>();
        /** Claims the first FREE snapped spot from (x, y) advancing along (dx, dy). */
        const claim = (step: PlacedStep, x: number, y: number, dx: number, dy: number): void => {
            let pos = this.place(x, y);
            let guard = 0;
            while (occupied.has(`${pos.x},${pos.y}`) && guard++ < 128) {
                const next = this.place(pos.x + dx * stepGap, pos.y + dy * stepGap);
                if (next.x === pos.x && next.y === pos.y) {
                    // Clamped into a wall (west/north at the canvas edge): switch PERMANENTLY to
                    // east scanning — flipping back would ping-pong against the wall forever and
                    // exhaust the guard on an occupied cell.
                    dx = 1;
                    dy = 0;
                    pos = this.place(pos.x + stepGap, pos.y);
                } else {
                    pos = next;
                }
            }
            occupied.add(`${pos.x},${pos.y}`);
            step.x = pos.x;
            step.y = pos.y;
            step.el.style.left = `${pos.x}px`;
            step.el.style.top = `${pos.y}px`;
        };

        const visited = new Set<string>();
        const walkFrom = (head: PlacedStep): void => {
            visited.add(head.id);
            claim(head, anchorPos.x, anchorPos.y, 1, 0);
            const queue: PlacedStep[] = [head];
            while (queue.length > 0) {
                const parent = queue.shift() as PlacedStep;
                for (const link of outgoing.get(parent.id) || []) {
                    if (visited.has(link.to)) {
                        continue;
                    }
                    visited.add(link.to);
                    const child = byId.get(link.to) as PlacedStep;
                    // The child sits along the parent\'s flow-out direction for THIS link.
                    const v = SIDE_VECTOR[this.outSideOf(parent, link.fromPort)];
                    claim(child, parent.x + v.dx * stepGap, parent.y + v.dy * stepGap, v.dx, v.dy);
                    queue.push(child);
                }
            }
        };

        // Chain heads: linked steps with no incoming selected link, leftmost chain first.
        Array.from(this.selection).filter(s => linked.has(s.id) && !hasIncoming.has(s.id))
            .sort(byPosition).forEach(s => walkFrom(s));
        // A pure cycle has no head — enter it at its leftmost tile so nothing is dropped.
        Array.from(this.selection).filter(s => linked.has(s.id) && !visited.has(s.id))
            .sort(byPosition).forEach(s => {
                if (!visited.has(s.id)) {
                    walkFrom(s);
                }
            });
        // Then everything without a selected connection — each takes the next free east slot.
        Array.from(this.selection).filter(s => !linked.has(s.id)).sort(byPosition)
            .forEach(s => claim(s, anchorPos.x, anchorPos.y, 1, 0));

        this.redrawLinks();
        this.syncDirty();
    }

    /** Chains the selection in its horizontal sequence: first → second → … → last. */
    private connectSelection(): void {
        const ordered = this.orderedSelection();
        if (!this.canConnectSelection(ordered)) {
            return;
        }
        for (let i = 0; i < ordered.length - 1; i++) {
            // Non-last elements are never Choice here, so port 0 is their single output.
            this.links = this.links.filter(l => !(l.from === ordered[i].id && l.fromPort === 0));
            this.links.push({from: ordered[i].id, fromPort: 0, to: ordered[i + 1].id});
        }
        this.redrawLinks();
        this.syncDirty();
    }

    // --- Moving a placed step (always snapped to the grid) ---------------------------

    private startStepDrag(e: PointerEvent, el: HTMLElement): void {
        const step = this.steps.find(s => s.el === el);
        if (!step) {
            return;
        }
        // Dragging a tile that belongs to a multi-selection moves the whole selection with it,
        // preserving the relative offsets (offsets stay grid-multiples, so members stay snapped).
        const group = this.selection.has(step) && this.selection.size > 1
            ? Array.from(this.selection).filter(s => s !== step)
                .map(s => ({step: s, offX: s.x - step.x, offY: s.y - step.y}))
            : [];
        const rect = el.getBoundingClientRect();
        this.stepDrag = {pointerId: e.pointerId, step, dx: e.clientX - rect.left, dy: e.clientY - rect.top, group};
        el.classList.add('is-moving');
        group.forEach(g => g.step.el.classList.add('is-moving'));
        this.trackPointer();
        e.preventDefault();
    }

    private moveStep(e: PointerEvent): void {
        if (!this.stepDrag) {
            return;
        }
        const {step, dx, dy, group} = this.stepDrag;
        const bounds = this.canvas().getBoundingClientRect();
        const desiredX = e.clientX - bounds.left - dx;
        const desiredY = e.clientY - bounds.top - dy;

        let pos: {x: number; y: number};
        if (!group.length) {
            pos = this.place(desiredX, desiredY);
        } else {
            // Clamp the LEADER so the whole group stays on the canvas (member = leader + offset).
            const maxX = Math.max(0, bounds.width - this.tileSize);
            const maxY = Math.max(0, bounds.height - this.tileSize);
            const offXs = group.map(g => g.offX).concat(0);
            const offYs = group.map(g => g.offY).concat(0);
            const grid = (v: number): number => Math.floor(v / this.spacing) * this.spacing;
            const loX = -Math.min(...offXs);
            const hiX = Math.max(loX, grid(maxX - Math.max(...offXs)));
            const loY = -Math.min(...offYs);
            const hiY = Math.max(loY, grid(maxY - Math.max(...offYs)));
            pos = {
                x: Math.min(Math.max(this.snap(desiredX), loX), hiX),
                y: Math.min(Math.max(this.snap(desiredY), loY), hiY)
            };
        }

        const apply = (s: PlacedStep, x: number, y: number): void => {
            s.x = x;
            s.y = y;
            s.el.style.left = `${x}px`;
            s.el.style.top = `${y}px`;
        };
        apply(step, pos.x, pos.y);
        group.forEach(g => apply(g.step, pos.x + g.offX, pos.y + g.offY));
        // Re-routing runs A* per link — coalesce to one redraw per animation frame while dragging.
        this.scheduleRedraw();
    }

    // --- Debug execution ---------------------------------------------------------

    /**
     * Gathers the trigger input and hands it to `run` — cron/queue triggers execute right away,
     * entity change first asks for its event (system/entity + payload). Shared by Run Now and
     * the step-by-step Debug.
     */
    private collectDebugInput(run: (input: Record<string, any>) => void): void {
        const trigger = this.findTrigger();
        if (!trigger) {
            return;
        }
        if (trigger.type === 'entity_change') {
            this.openDebugInput(trigger, run);
        } else {
            run({});
        }
    }

    /** Entity-change triggers debug with an event: system/entity (prefilled from the trigger) + payload. */
    private openDebugInput(trigger: PlacedStep, run: (input: Record<string, any>) => void): void {
        const dialog = new Dialog({title: __('aaxis.ontology.flow_editor.debug_input_title'), width: '560px'});
        const $content = dialog.open();

        const $form = $('<div/>', {'class': 'aaxis-flow-editor__settings-body'});
        const pair = this.systemEntitySection($form, {
            system: (trigger.config && trigger.config.system) || '',
            entity: (trigger.config && trigger.config.entity) || ''
        }, true);
        const $payload = $('<textarea/>', {
            'class': 'form-control aaxis-flow-editor__settings-textarea',
            rows: 8,
            placeholder: '{ }'
        });
        const $error = $('<p/>', {'class': 'aaxis-flow-editor__settings-error', text: ''});
        $form.append(this.settingsLabel('debug_payload_label'), $payload, $error);
        $content.append($form);

        const $actions = $('<div/>', {'class': 'aaxis-ontology-confirm__actions'});
        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', text: __('aaxis.ontology.flow_editor.cancel')});
        const $run = $('<button/>', {type: 'button', 'class': 'btn btn-primary', text: __('aaxis.ontology.flow_editor.debug_run')});
        $actions.append($cancel, $run);
        $content.append($actions);

        $cancel.on('click', () => dialog.close());
        $run.on('click', () => {
            $error.text('');
            const pairError = pair.error();
            if (pairError !== '') {
                $error.text(pairError);
                return;
            }
            let payload: any = null;
            const raw = String($payload.val() || '').trim();
            if (raw !== '') {
                try {
                    payload = JSON.parse(raw);
                } catch {
                    $error.text(__('aaxis.ontology.flow_editor.debug_payload_invalid'));
                    return;
                }
            }
            const input = pair.merge({payload});
            dialog.close();
            run(input);
        });
    }

    /**
     * Run Now / Debug availability: hidden without a real trigger (synced in updateReachability),
     * DISABLED while the flow's Enabled switch is off or a debug session is already open.
     */
    /** The flow's effective enabled state: the trigger step's `enabled` config flag (see server). */
    private flowEnabled(): boolean {
        const trigger = this.findTrigger();
        return Boolean(trigger && trigger.config && trigger.config.enabled === true);
    }

    private syncDebugButtons(): void {
        const enabled = this.flowEnabled();
        // A flow with red (incomplete/invalid) steps cannot run — the server enforces the same
        // gate on every entry point, this just keeps the buttons honest.
        const invalid = this.steps.some(s => s.invalid === true);
        const $buttons = this.$el.find('[data-role="debug"], [data-role="debug-step"]');
        $buttons.prop('disabled', !enabled || invalid || this.debugSession !== null);
        $buttons.attr('title', invalid ? __('aaxis.ontology.flow_editor.invalid_steps_blocked') : null);
    }

    /** Marks/unmarks one tile as having an incomplete/invalid config (red border). */
    private markStepInvalid(step: PlacedStep, invalid: boolean): void {
        step.invalid = invalid;
        step.el.classList.toggle('is-config-invalid', invalid);
        this.syncDebugButtons();
    }

    /** Applies the server-computed list of invalid step NAMES (page load and every save). */
    private applyInvalidStepNames(names: string[]): void {
        const set = new Set((names || []).map(n => String(n)));
        this.steps.forEach(s => this.markStepInvalid(s, set.has(s.name)));
    }

    /**
     * Opens the debugger sidebar (left of the design area) and starts the session. The canvas
     * definition is SNAPSHOTTED here — later canvas edits don't affect the running session.
     * mode 'step' walks one step per request (Next step / Run all buttons); mode 'run' executes
     * everything in one request and only shows the final result. Cancel/Close just closes —
     * writes already performed by executed writers cannot be undone.
     */
    private startDebugSession(mode: 'step' | 'run', input: Record<string, any>): void {
        this.clearDebugMarks(); // a fresh session starts from an unmarked canvas
        this.debugSession = {
            mode,
            input,
            steps: this.steps.map(s => ({id: s.id, type: s.type, name: s.name, config: s.config || null})),
            links: this.links.map(l => ({from: l.from, fromPort: l.fromPort, to: l.to})),
            context: null,
            contextKey: null,
            index: -1,
            total: 0,
            done: false,
            busy: false,
            busyAction: null,
            error: null,
            statusLabel: '',
            marksByFlow: {},
            failedId: null,
            homeIndex: this.activeTab,
            next: null,
            depth: 0,
            autoClose: null
        };
        this.syncDebugButtons();
        if (mode === 'run') {
            // Run Now: the sidebar only appears when the run FINISHES (see runFullSession).
            this.runFullSession();
        } else {
            this.buildDebugger();
            this.debugAdvance(false, false);
        }
    }

    /** Builds the sidebar skeleton once per session (stable refs keep the evaluator's text). */
    private buildDebugger(): void {
        const session = this.debugSession;
        if (!session) {
            return;
        }
        const $panel = this.$el.find('[data-role="debugger"]');
        $panel.empty().prop('hidden', false);

        // One line: the mode in bold, the step/status right after it in regular weight.
        const $status = $('<span/>', {'class': 'aaxis-flow-editor__debugger-status'});
        const $title = $('<div/>', {'class': 'aaxis-flow-editor__debugger-title'}).append(
            $('<span/>', {
                'class': 'aaxis-flow-editor__debugger-mode',
                text: session.mode === 'step'
                    ? __('aaxis.ontology.flow_editor.debug_step_title')
                    : __('aaxis.ontology.flow_editor.run_now_title')
            }),
            $status
        );
        const $vars = $('<div/>', {'class': 'aaxis-flow-editor__debugger-vars'});

        // The DWL evaluator (just before the buttons): any expression, evaluated server-side
        // against the CURRENT variables; the result opens in a modal.
        const $evalRun = $('<button/>', {
            type: 'button', 'class': 'btn aaxis-flow-editor__debugger-eval-run',
            text: __('aaxis.ontology.flow_editor.debug_eval_run')
        });
        const evalField = createDwlField({
            label: __('aaxis.ontology.flow_editor.debug_eval_label'),
            value: '',
            dwl: true,
            fixed: true,
            editorClass: 'aaxis-flow-editor__debugger-eval-editor',
            $tools: $evalRun
        });
        $evalRun.on('click', () => this.evaluateDebugExpression());

        const $actions = $('<div/>', {'class': 'aaxis-flow-editor__debugger-actions'});

        $panel.append(
            $title,
            $('<div/>', {'class': 'aaxis-flow-editor__debugger-section', text: __('aaxis.ontology.flow_editor.debug_vars_label')}),
            $vars,
            evalField.$el,
            $actions
        );
        this.debugUi = {$status, $vars, $actions, evalField, $evalRun};
        this.updateDebugger();
        // The sidebar just shrank the canvas viewport — pull the toolbox back inside it.
        this.clampToolboxIntoView();
    }

    /** Refreshes the sidebar (status, variables tree, action buttons, evaluator availability). */
    private updateDebugger(): void {
        const session = this.debugSession;
        const ui = this.debugUi;
        if (!session || !ui) {
            return;
        }

        if (session.error !== null) {
            ui.$status.text(` — ${__('aaxis.ontology.flow_editor.debug_failed')}: ${session.error}`).addClass('is-error');
        } else if (session.busy) {
            ui.$status.text(` — ${__('aaxis.ontology.flow_editor.debug_running')}`).removeClass('is-error');
        } else if (session.done) {
            ui.$status.text(` — ${__('aaxis.ontology.flow_editor.debug_finished')}`).removeClass('is-error');
        } else {
            ui.$status.text(session.statusLabel === '' ? '' : ` — ${session.statusLabel}`).removeClass('is-error');
        }

        ui.$vars.empty();
        if (session.context === null) {
            ui.$vars.append($('<div/>', {'class': 'aaxis-flow-editor__debugger-empty', text: __('aaxis.ontology.flow_editor.debug_not_started')}));
        } else {
            // One line per variable; objects/arrays start collapsed (count preview) and expand.
            ui.$vars.append(renderVariablesList(session.context));
        }

        const canEvaluate = session.context !== null && !session.busy;
        ui.$evalRun.prop('disabled', !canEvaluate);

        ui.$actions.empty();
        // VSCode-style debugger controls: uniform SQUARES, centered SVG glyph, label only in the
        // tooltip (title + aria-label). stop = discard/close (red), continue = run all,
        // arc-over-dot = next step (step over), arrow-into-dot = step into, arrow-out = step out.
        const iconButton = (labelKey: string, icon: string, disabled: boolean): any => {
            const label = __('aaxis.ontology.flow_editor.' + labelKey);
            return $('<button/>', {
                type: 'button',
                'class': 'aaxis-flow-editor__debug-btn' + (icon === 'stop' ? ' aaxis-flow-editor__debug-btn--stop' : ''),
                title: label,
                'aria-label': label,
                disabled
            }).append($(DEBUG_ICONS[icon]));
        };
        const finished = session.done || session.error !== null;
        const autoClosing = session.autoClose !== null;
        const autoCloseBar = (): any => {
            const remaining = session.autoClose ? Math.max(0, session.autoClose.deadline - Date.now()) : 0;
            const width = session.autoClose ? (remaining / session.autoClose.duration) * 100 : 0;
            return $('<div/>', {'class': 'aaxis-flow-editor__debug-autoclose'}).append(
                $('<div/>', {'class': 'aaxis-flow-editor__debug-autoclose-bar', 'data-role': 'debug-autoclose-bar'})
                    .css('width', `${width}%`)
            );
        };
        if (session.mode !== 'step') {
            const $stop = iconButton(finished ? 'close' : 'cancel', 'stop', autoClosing);
            $stop.on('click', () => this.closeDebugSession());
            ui.$actions.append($stop);
            if (autoClosing) {
                ui.$actions.append(autoCloseBar());
            }
            return;
        }
        // ALL FIVE buttons stay visible in FIXED positions (VSCode order: continue, step over,
        // step into, step out, stop) — availability is enable/disable only, never show/hide,
        // so a button can never shift under the cursor between clicks.
        const stepping = !session.busy && !finished && !autoClosing;
        // Step into applies only when the NEXT step invokes a subflow (Next step then steps
        // OVER it: the invoker runs atomically); Step out only inside a subflow frame.
        const nextIsInvoker = session.next !== null && (session.next.type === 'sub_flow' || session.next.type === 'foreach');
        const $runAll = iconButton('debug_run_all', 'continue', !stepping);
        const $next = iconButton('debug_next_step', 'over', !stepping);
        const $into = iconButton('debug_step_into', 'into', !stepping || !nextIsInvoker);
        const $out = iconButton('debug_step_out', 'out', !stepping || session.depth === 0);
        const $stop = iconButton(finished ? 'close' : 'cancel', 'stop', autoClosing);
        // The button that started the in-flight request swaps its glyph for a spinner.
        if (session.busy) {
            const $target = session.busyAction === 'runAll'
                ? $runAll
                : (session.busyAction === 'into' ? $into : (session.busyAction === 'out' ? $out : $next));
            $target.empty().append($('<span/>', {'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true'}));
        }
        $runAll.on('click', () => this.debugAdvance(true, false));
        $next.on('click', () => this.debugAdvance(false, false));
        $into.on('click', () => this.debugAdvance(false, true));
        $out.on('click', () => this.debugAdvance(false, false, true));
        $stop.on('click', () => this.closeDebugSession());
        ui.$actions.append($runAll, $next, $into, $out, $stop);
        if (autoClosing) {
            ui.$actions.append(autoCloseBar());
        }
    }

    /**
     * One debugger tick: the next step, the rest of the run (runAll) or a descend into the
     * upcoming subflow (stepInto). The server owns the cursor — a STACK of frames — and answers
     * with the executed step, the frame it belongs to and a `transition`:
     *  'entered'/'reentered' bring the subflow's tab to the front (re-enter WIPES its marks —
     *  a new foreach iteration starts white with only the trigger amber);
     *  'returned' comes home to the caller's tab, whose invoker step is only now marked.
     */
    private debugAdvance(runAll: boolean, stepInto: boolean, stepOut = false): void {
        const session = this.debugSession;
        if (!session || session.busy || session.done) {
            return;
        }
        session.busy = true;
        session.busyAction = runAll ? 'runAll' : (stepOut ? 'out' : (stepInto ? 'into' : 'next'));
        session.error = null;
        this.updateDebugger();

        apiFetch(routing.generate('aaxis_ontology_flow_debug_step'), 'POST', {
            flowId: this.flow && this.flow.id ? this.flow.id : null,
            steps: session.steps,
            links: session.links,
            input: session.input,
            // Only the HANDLE travels — the walk state (context + frame stack) stays server-side.
            contextKey: session.contextKey,
            runAll,
            stepInto,
            stepOut
        }).then(res => {
            if (this.debugSession !== session) {
                return; // the session was cancelled while the request ran
            }
            if (!res.ok || !res.data || !res.data.success) {
                // A failed step paints the canvas too: everything that ran stays amber, the
                // failing tile turns red (the sidebar shows the error text).
                this.markExecutedDebugSteps((res.data && res.data.executedIds) || []);
                if (res.data && res.data.failedStepId) {
                    this.markFailedDebugStep(String(res.data.failedStepId));
                }
                if (res.data && (res.data.subflowTrails || []).length) {
                    this.visitSubflowTrails(res.data.subflowTrails, session);
                }
                throw new Error((res.data && res.data.message) || __('aaxis.ontology.flow_editor.debug_error'));
            }
            session.context = res.data.context ?? {};
            session.contextKey = res.data.contextKey || null;
            session.index = Number(res.data.index);
            session.total = Number(res.data.total);
            session.done = Boolean(res.data.done);
            session.next = res.data.next || null;
            session.depth = Number((res.data.frame && res.data.frame.depth) || 0);
            const frameName = res.data.frame && res.data.frame.flowName && res.data.frame.depth > 0
                ? `${res.data.frame.flowName}: `
                : '';
            const iteration = res.data.iteration !== null && res.data.iteration !== undefined
                ? ` [${__('aaxis.ontology.flow_editor.debug_iteration')} ${Number(res.data.iteration) + 1}]`
                : '';
            session.statusLabel = `${frameName}${res.data.step.name} (${session.index + 1}/${session.total})${iteration}`;
            this.applyDebugTick(session, res.data);
            this.maybeStartDebugAutoClose();
        }).catch((err: Error) => {
            if (this.debugSession === session) {
                session.error = err.message || __('aaxis.ontology.flow_editor.debug_error');
            }
        }).finally(() => {
            // A running subflow visit owns the busy flag; its finish() releases it.
            if (this.debugSession === session && !this.debugVisiting) {
                session.busy = false;
                this.updateDebugger();
            }
        });
    }

    /**
     * A CLEANLY finished session dismisses itself after the configured seconds (0 = never) —
     * a decreasing bar under the buttons counts it down; every button is disabled meanwhile.
     * Failed sessions stay open for inspection.
     */
    private maybeStartDebugAutoClose(): void {
        const session = this.debugSession;
        if (!session || !session.done || session.error !== null || session.autoClose || this.debugAutoCloseSeconds <= 0) {
            return;
        }
        const duration = this.debugAutoCloseSeconds * 1000;
        const deadline = Date.now() + duration;
        session.autoClose = {
            deadline,
            duration,
            timer: window.setInterval(() => {
                if (this.debugSession !== session || !session.autoClose) {
                    return;
                }
                const remaining = session.autoClose.deadline - Date.now();
                if (remaining <= 0) {
                    this.closeDebugSession();
                    return;
                }
                // Only the bar moves — no full re-render per tick.
                this.debugUi?.$actions
                    .find('[data-role="debug-autoclose-bar"]')
                    .css('width', `${(remaining / session.autoClose.duration) * 100}%`);
            }, 100)
        };
        this.updateDebugger();
    }

    /** Applies one tick's canvas effects: tab focus per frame, mark wipes, amber marks. */
    private applyDebugTick(session: NonNullable<typeof this.debugSession>, data: any): void {
        const frame = data.frame || {flowId: null, flowName: null, depth: 0};
        const frameKey = frame.depth > 0 && frame.flowId ? String(frame.flowId) : 'root';
        const ids: string[] = (data.executedIds || [String(data.step.id || '')]).map(String);

        const paint = (): void => {
            if (data.transition === 'reentered') {
                // A fresh iteration: the subflow canvas starts white again.
                this.clearDebugMarks();
                session.marksByFlow[frameKey] = [];
            }
            session.marksByFlow[frameKey] = Array.from(new Set([...(session.marksByFlow[frameKey] || []), ...ids]));
            this.markExecutedDebugSteps(ids);
        };

        const targetTab = frameKey === 'root'
            ? Promise.resolve(session.homeIndex)
            : this.ensureFlowTab(Number(frame.flowId));
        void targetTab.then(index => {
            if (this.debugSession !== session || index === null) {
                paint();
                return;
            }
            if (this.activeTab !== index) {
                // Session-preserving switch; the fresh render then gets this frame's marks back.
                this.debugVisiting = true;
                try {
                    this.switchTab(index);
                } finally {
                    this.debugVisiting = false;
                }
                this.markExecutedDebugSteps(session.marksByFlow[frameKey] || []);
                if (frameKey === 'root' && session.failedId) {
                    this.markFailedDebugStep(session.failedId);
                }
            }
            paint();
            if (data.subflowTrails && data.subflowTrails.length) {
                if (data.transition === null) {
                    // A stepped-OVER invoker: show where the subflow(s) went, then come home.
                    this.visitSubflowTrails(data.subflowTrails, session);
                } else {
                    // Step out ('returned' with trails): remember the subflow marks silently —
                    // revisiting that tab shows everything that ran, no animation.
                    (data.subflowTrails as Array<{flowId: number; executedIds: string[]}>).forEach(t => {
                        const key = String(t.flowId);
                        session.marksByFlow[key] = Array.from(new Set([...(session.marksByFlow[key] || []), ...t.executedIds.map(String)]));
                    });
                }
            }
        });
    }

    /**
     * The debug SUBFLOW VISIT: for each subflow the just-executed step ran, bring its tab to the
     * front (opening it when needed), paint the steps that executed inside it, dwell a moment —
     * then return to the flow being debugged and repaint its session marks (the tab switch
     * re-rendered the canvas). Tab switches keep the session alive via `debugVisiting`.
     */
    private visitSubflowTrails(trails: Array<{flowId: number; flowName?: string; executedIds: string[]}>, session: NonNullable<typeof this.debugSession>): void {
        if (this.debugVisiting || !trails.length) {
            return;
        }
        const homeIndex = session.homeIndex;
        this.debugVisiting = true;
        session.busy = true;
        session.busyAction = 'visit';
        this.updateDebugger();

        const finish = (): void => {
            if (this.tabs[homeIndex] && this.activeTab !== homeIndex) {
                this.switchTab(homeIndex);
            }
            this.debugVisiting = false;
            if (this.debugSession === session) {
                // The re-render dropped the DOM classes — repaint the home canvas's marks.
                this.markExecutedDebugSteps(session.marksByFlow['root'] || []);
                if (session.failedId) {
                    this.markFailedDebugStep(session.failedId);
                }
                session.busy = false;
                session.busyAction = null;
                this.updateDebugger();
            } else {
                this.clearDebugMarks();
            }
        };
        const visitOne = (i: number): void => {
            if (i >= trails.length || this.debugSession !== session) {
                finish();
                return;
            }
            this.ensureFlowTab(trails[i].flowId).then(index => {
                if (this.debugSession !== session || index === null) {
                    if (index === null) {
                        visitOne(i + 1);
                        return;
                    }
                    finish();
                    return;
                }
                if (this.activeTab !== index) {
                    this.switchTab(index);
                }
                this.markExecutedDebugSteps(trails[i].executedIds || []);
                window.setTimeout(() => visitOne(i + 1), 1600);
            }).catch(() => visitOne(i + 1));
        };
        visitOne(0);
    }

    /**
     * The tab index showing the given flow — reusing an open tab, otherwise opening a new one
     * from the flow catalog. Null when the flow is unknown (deleted, or not listed).
     */
    private ensureFlowTab(flowId: number): Promise<number | null> {
        const already = this.tabs.findIndex(t => t.flow !== null && (t.flow as any).id === flowId);
        if (already >= 0) {
            return Promise.resolve(already);
        }
        return this.flowCatalog().then((data: {records?: FlowRecord[]}) => {
            const flow = (data.records || []).find(f => f.id === flowId);
            if (!flow || flow.type === 'native') {
                return null;
            }
            this.tabs.push({flow, design: null, savedState: null, invalidNames: flow.invalidSteps || []});
            this.renderTabs();
            return this.tabs.length - 1;
        }).catch(() => null);
    }

    /**
     * Run Now: the whole flow in ONE request. While it runs only the button spinner shows —
     * the sidebar appears when the run FINISHES, holding the final context (or the failure).
     */
    private runFullSession(): void {
        const session = this.debugSession;
        if (!session) {
            return;
        }
        session.busy = true;
        const $button = this.$el.find('[data-role="debug"]');
        const $spinner = $('<span/>', {'class': 'fa fa-spinner fa-spin aaxis-flow-editor__save-spinner', 'aria-hidden': 'true'});
        $button.prepend($spinner);

        apiFetch(routing.generate('aaxis_ontology_flow_debug'), 'POST', {
            // Writers stamp their upserts with this flow (null = never saved -> Manual fallback).
            flowId: this.flow && this.flow.id ? this.flow.id : null,
            steps: session.steps,
            links: session.links,
            input: session.input
        }).then(res => {
            if (this.debugSession !== session) {
                return;
            }
            if (!res.ok || !res.data || !res.data.success) {
                this.markExecutedDebugSteps((res.data && res.data.executedIds) || []);
                if (res.data && res.data.failedStepId) {
                    this.markFailedDebugStep(String(res.data.failedStepId));
                }
                throw new Error((res.data && res.data.message) || __('aaxis.ontology.flow_editor.debug_error'));
            }
            session.context = res.data.output ?? {};
            session.contextKey = res.data.contextKey || null;
            session.done = true;
            this.markExecutedDebugSteps(res.data.executedIds || []);
        }).catch((err: Error) => {
            if (this.debugSession === session) {
                session.error = err.message || __('aaxis.ontology.flow_editor.debug_error');
            }
        }).finally(() => {
            $spinner.remove();
            if (this.debugSession === session) {
                session.busy = false;
                this.buildDebugger();
                this.maybeStartDebugAutoClose();
            }
        });
    }

    /**
     * Evaluates the sidebar's DWL expression against the session's current variables; the result
     * (or the engine's error) opens in a modal.
     */
    private evaluateDebugExpression(): void {
        const session = this.debugSession;
        const ui = this.debugUi;
        if (!session || !ui || session.context === null) {
            return;
        }
        const expression = ui.evalField.value().trim();
        if (expression === '') {
            return;
        }
        ui.$evalRun.prop('disabled', true);
        apiFetch(routing.generate('aaxis_ontology_flow_debug_eval'), 'POST', {
            expression,
            contextKey: session.contextKey
        }).then(res => {
            const dialog = new Dialog({title: __('aaxis.ontology.flow_editor.debug_eval_label'), width: '640px'});
            const $content = dialog.open();
            if (!res.ok || !res.data || !res.data.success) {
                $content.append($('<p/>', {
                    'class': 'aaxis-debug-eval-error',
                    text: (res.data && res.data.message) || __('aaxis.ontology.flow_editor.debug_error')
                }));
                return;
            }
            $content.append(renderJsonTree(res.data.output ?? null));
        }).catch(() => {
            messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.debug_error'));
        }).finally(() => {
            if (this.debugUi === ui) {
                ui.$evalRun.prop('disabled', !(this.debugSession && this.debugSession.context !== null && !this.debugSession.busy));
            }
        });
    }

    /** Marks tiles as EXECUTED (amber) — marks accumulate over the session, never move away. */
    private markExecutedDebugSteps(stepIds: string[]): void {
        stepIds.forEach(id => {
            const step = this.steps.find(s => s.id === String(id));
            if (step) {
                step.el.classList.add('is-debug-active');
            }
        });
    }

    /** Marks the tile whose step FAILED (reddish) — the sidebar carries the error text itself. */
    private markFailedDebugStep(stepId: string): void {
        const step = this.steps.find(s => s.id === String(stepId));
        if (step) {
            step.el.classList.add('is-debug-failed');
        }
        if (this.debugSession && !this.debugVisiting) {
            this.debugSession.failedId = String(stepId);
        }
    }

    /** Clears every executed/failed mark (session start and close). */
    private clearDebugMarks(): void {
        this.steps.forEach(s => s.el.classList.remove('is-debug-active', 'is-debug-failed'));
    }

    private closeDebugSession(): void {
        if (this.debugSession && this.debugSession.autoClose) {
            window.clearInterval(this.debugSession.autoClose.timer);
        }
        this.clearDebugMarks();
        this.$el.find('[data-role="debugger"]').prop('hidden', true).empty();
        this.debugSession = null;
        this.debugUi = null;
        this.syncDebugButtons();
        // The viewport regained the sidebar's width — the clamp keeps the toolbox consistent.
        this.clampToolboxIntoView();
    }

    // --- Persistence -----------------------------------------------------------

    /**
     * Saves EVERY tab holding unsaved changes, sequentially (parallel saves could race the
     * unique-name check). The active tab is stashed first so all tabs save from their stashed
     * design; each success updates the tab's flow/baseline in place — the canvas never reloads.
     * Failures (missing trigger, name taken, …) are flashed per tab, the rest still save.
     */
    private saveAll(): void {
        if (this.savingAll) {
            return;
        }
        this.stashActiveTab();
        const dirty = this.tabs.filter(tab => tab.design !== null && this.tabDirty(tab, false));
        if (!dirty.length) {
            return;
        }
        this.savingAll = true;
        const $saveAll = this.$el.find('[data-role="save-all"]');
        const $spinner = $('<span/>', {'class': 'fa fa-spinner fa-spin aaxis-flow-editor__save-spinner', 'aria-hidden': 'true'});
        $saveAll.prepend($spinner);
        this.syncDirty();

        let saved = 0;
        const saveOne = (i: number): Promise<void> => {
            if (i >= dirty.length) {
                return Promise.resolve();
            }
            const tab = dirty[i];
            const design = tab.design as any;
            const trigger = (design.steps || []).find((st: any) => st && this.stepMeta[st.type] && this.stepMeta[st.type].category === 'trigger');
            if (!trigger) {
                messenger.notificationFlashMessage('error', `${this.tabTitle(tab, false)}: ${__('aaxis.ontology.flow_editor.trigger_required')}`);
                return saveOne(i + 1);
            }
            const url = tab.flow === null
                ? routing.generate('aaxis_ontology_flow_api_create')
                : routing.generate('aaxis_ontology_flow_api_update', {id: (tab.flow as any).id});
            return apiFetch(url, tab.flow === null ? 'POST' : 'PUT', {
                steps: (design.steps || []).map((st: any) => ({type: st.type, name: st.name, x: st.x, y: st.y, config: st.config || null})),
                design
            }).then(res => {
                if (!res.ok || !res.data || !res.data.success) {
                    throw new Error((res.data && res.data.message) || __('aaxis.ontology.flow_editor.save_error'));
                }
                tab.flow = res.data.flow as FlowRecord;
                tab.savedState = JSON.stringify({design: tab.design});
                tab.invalidNames = (res.data.flow && res.data.flow.invalidSteps) || [];
                saved++;
            }).catch((err: Error) => {
                messenger.notificationFlashMessage('error', `${this.tabTitle(tab, false)}: ${err.message || __('aaxis.ontology.flow_editor.save_error')}`);
            }).then(() => saveOne(i + 1));
        };

        void saveOne(0).finally(() => {
            // Bring the LIVE canvas in line with its (possibly just-saved) tab entry.
            const active = this.tabs[this.activeTab];
            if (active) {
                this.flow = active.flow;
                if (active.savedState !== null) {
                    this.savedState = active.savedState;
                }
                this.applyInvalidStepNames(active.invalidNames);
            }
            this.savingAll = false;
            $spinner.remove();
            if (saved > 0) {
                messenger.notificationFlashMessage('success', __('aaxis.ontology.flow_editor.saved_all', {count: saved}));
            }
            this.renderTabs();
            this.syncTabsUrl();
            this.syncDirty();
        });
    }

    private save(): void {
        // The trigger IS the flow's identity (name + enabled) — nothing to save without one.
        if (!this.findTrigger()) {
            messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.trigger_required'));
            return;
        }
        const url = this.flow === null
            ? routing.generate('aaxis_ontology_flow_api_create')
            : routing.generate('aaxis_ontology_flow_api_update', {id: this.flow.id});

        // Activity indicator: spinner inside the (disabled) Save button until the request settles.
        const $save = this.$el.find('[data-role="save"]');
        $save.prop('disabled', true);
        const $spinner = $('<span/>', {'class': 'fa fa-spinner fa-spin aaxis-flow-editor__save-spinner', 'aria-hidden': 'true'});
        $save.prepend($spinner);
        apiFetch(url, this.flow === null ? 'POST' : 'PUT', {
            steps: this.steps.map(s => ({type: s.type, name: s.name, x: s.x, y: s.y, config: s.config || null})),
            design: this.currentDesign()
        }).then(res => {
            if (!res.ok || !res.data || !res.data.success) {
                throw new Error((res.data && res.data.message) || __('aaxis.ontology.flow_editor.save_error'));
            }
            const created = this.flow === null;
            this.flow = res.data.flow as FlowRecord;
            if (this.tabs[this.activeTab]) {
                this.tabs[this.activeTab].flow = this.flow; // the tab now points at the saved flow
            }
            this.applyInvalidStepNames((res.data.flow && res.data.flow.invalidSteps) || []);
            if (created && this.flow && this.flow.id) {
                // Refreshing after the first save must reopen THIS flow (and the other tabs).
                this.syncTabsUrl();
            }
            messenger.notificationFlashMessage('success', __('aaxis.ontology.flow_editor.saved'));
            // Stay in the editor: the state is clean now (Save disabled, the cancel button
            // reads Close) until the next change.
            this.markSaved();
        }).catch((err: Error) => {
            messenger.notificationFlashMessage('error', err.message || __('aaxis.ontology.flow_editor.save_error'));
            this.syncDirty();
        }).finally(() => {
            $spinner.remove();
        });
    }

    // --- Settings catalogs (fetched once, kept in memory) ------------------------

    /**
     * Systems + entities for the step settings panel. Cached for the editor session: the panel is
     * opened repeatedly and the data barely changes while a flow is being drawn.
     *
     * A FAILED request is not cached — the field is reset so the next open retries, instead of the
     * panel being permanently broken by one hiccup. The caller keeps its own `.catch` for the
     * user-facing message.
     */
    private entityCatalog(): Promise<any> {
        this.catalogEntities ??= fetch(routing.generate('aaxis_ontology_entity_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .catch((err: any) => {
                this.catalogEntities = null;
                throw err;
            });

        return this.catalogEntities;
    }

    /** Connectors for the reader/writer panel. Same caching contract as {@see entityCatalog}. */
    private connectorCatalog(): Promise<any> {
        this.catalogConnectors ??= fetch(routing.generate('aaxis_ontology_connector_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .catch((err: any) => {
                this.catalogConnectors = null;
                throw err;
            });

        return this.catalogConnectors;
    }

    /** Flows for the Call Subflow picker. Same caching contract as the other catalogs. */
    private flowCatalog(): Promise<any> {
        this.catalogFlows ??= fetch(routing.generate('aaxis_ontology_flow_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: any) => {
                // A synchronous copy for consumers that cannot await (context menus).
                this.catalogFlowRecords = (data && data.records) || [];
                return data;
            })
            .catch((err: any) => {
                this.catalogFlows = null;
                throw err;
            });

        return this.catalogFlows;
    }

    /** The "Invoke PHP" class type-ahead: every app-namespace service class (cached). */
    private phpClasses(): Promise<string[]> {
        this.catalogPhpClasses ??= fetch(routing.generate('aaxis_ontology_flow_php_classes'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {classes?: string[]}) => data.classes || [])
            .catch((err: any) => {
                this.catalogPhpClasses = null;
                throw err;
            });

        return this.catalogPhpClasses;
    }

    /** One class's public methods + parameter shapes (cached per class). */
    private phpMethods(className: string): Promise<Array<{name: string; params: Array<{name: string; type: string; required: boolean}>}>> {
        this.phpMethodsCache[className] ??= fetch(
            routing.generate('aaxis_ontology_flow_php_methods') + '?class=' + encodeURIComponent(className),
            {credentials: 'same-origin'}
        )
            .then(r => r.json())
            .then((data: {methods?: any[]}) => data.methods || [])
            .catch((err: any) => {
                delete this.phpMethodsCache[className];
                throw err;
            });

        return this.phpMethodsCache[className];
    }

    /**
     * Warms both catalogs right after the editor loads, so even the FIRST settings panel opens
     * without waiting on the network. Fire and forget: a failure here just leaves the cache empty
     * and the real error surfaces (and retries) when a panel is actually opened.
     */
    private prefetchCatalogs(): void {
        this.entityCatalog().catch(() => undefined);
        this.connectorCatalog().catch(() => undefined);
        this.flowCatalog().catch(() => undefined);
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisFlowEditor');
        if (this.ghostDrag) {
            // el is null while a toolbox drag is armed but has not passed the drag threshold.
            this.ghostDrag.el?.remove();
            this.ghostDrag = null;
        }
        if (this.linkDrag) {
            this.linkDrag.path.remove();
            this.linkDrag = null;
        }
        if (this.marqueeDrag) {
            this.marqueeDrag.el.remove();
            this.marqueeDrag = null;
        }
        this.closeContextMenu();
        this.panelDrag = null;
        this.stepDrag = null;
        $(window).off('resize.aaxisFlowEditor');
        $(window).off('beforeunload.aaxisFlowEditor');
        pageStateChecker.removeChecker(this.dirtyChecker);
        mediator.off('openLink:before', this.onOpenLinkBefore);
        mediator.off('page:beforeRefresh', this.onBeforeRefresh);
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        super.dispose();
    }
}

export default OntologyFlowEditorComponent;

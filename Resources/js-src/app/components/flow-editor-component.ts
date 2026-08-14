import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';

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
            return /^\d+$/.test(token) ? Number(token) : NaN;
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
}

/** Bump when the persisted design shape changes — older designs then load as "corrupted". */
const DESIGN_VERSION = 2;

const SVG_NS = 'http://www.w3.org/2000/svg';

/** How many output ports a step type exposes ("choice" branches, everything else is linear). */
function portCount(type: string): number {
    return type === 'choice' ? 2 : 1;
}

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
    // Sub-flow entry marker: the id of the step the flow "starts at" when there is no trigger,
    // drawn as an origin-less arrow into that step.
    private startId!: string | null;
    private wires!: SVGSVGElement;
    private selection!: Set<PlacedStep>;
    private menuEl!: HTMLElement | null;
    private redrawScheduled!: boolean;
    private savedState!: string;

    private panelDrag!: {pointerId: number; dx: number; dy: number} | null;
    private ghostDrag!: {pointerId: number; type: string; el: HTMLElement} | null;
    private stepDrag!: {
        pointerId: number;
        step: PlacedStep;
        dx: number;
        dy: number;
        // Other selected tiles moving along, as offsets relative to the dragged one.
        group: {step: PlacedStep; offX: number; offY: number}[];
    } | null;
    private linkDrag!: {pointerId: number; from: PlacedStep; fromPort: number; path: SVGPathElement; target: PlacedStep | null} | null;
    private marqueeDrag!: {pointerId: number; x0: number; y0: number; el: HTMLElement; moved: boolean} | null;
    private onPointerMove!: (e: PointerEvent) => void;
    private onPointerUp!: (e: PointerEvent) => void;
    private onDocPointerDown!: (e: PointerEvent) => void;

    initialize(options: FlowEditorOptions): void {
        this.$el = options._sourceElement;
        this.flow = options.flow || null;
        this.listUrl = options.listUrl || routing.generate('aaxis_ontology_flows');
        this.stepMeta = {};
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
        this.tileSize = factor * this.spacing;

        this.$el.find('[data-role="flow-name"]').val(this.flow?.name || this.generateName());
        this.$el.find('[data-role="flow-enabled"]').prop('checked', this.flow ? this.flow.enabled : true);

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

        this.buildWires();
        this.restore();
        // Sync the toggle's active state with however the restore left the toolbox.
        this.setToolboxVisible(!this.toolbox().hidden);
        this.markSaved();

        this.$el.on('input.aaxisFlowEditor', '[data-role="flow-name"]', () => this.syncDirty());
        this.$el.on('change.aaxisFlowEditor', '[data-role="flow-enabled"]', () => this.syncDirty());
        this.$el.on('click.aaxisFlowEditor', '[data-role="cancel"]', (e: any) => {
            e.preventDefault();
            window.location.href = this.listUrl;
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
        this.$el.on('click.aaxisFlowEditor', '[data-role="debug"]', (e: any) => {
            e.preventDefault();
            this.closeContextMenu();
            const trigger = this.findTrigger();
            if (!trigger) {
                return;
            }
            // Cron (and queue) triggers just execute; entity change asks for its event input.
            if (trigger.type === 'entity_change') {
                this.openDebugInput(trigger);
            } else {
                this.runDebug({});
            }
        });
        this.$el.on('pointerdown.aaxisFlowEditor', '[data-role="toolbox"] [data-step-type]', (e: any) => {
            this.closeContextMenu();
            this.clearSelection();
            if (e.originalEvent.button === 0) {
                this.startGhostDrag(e.originalEvent as PointerEvent, String($(e.currentTarget).data('stepType')));
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

    /** new_flow_ + 6 random alphanumerics, e.g. "new_flow_a3k9zq". */
    private generateName(): string {
        const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        let suffix = '';
        for (let i = 0; i < 6; i++) {
            suffix += alphabet[Math.floor(Math.random() * alphabet.length)];
        }
        return `new_flow_${suffix}`;
    }

    private canvas(): HTMLElement {
        return this.$el.find('[data-role="canvas"]')[0];
    }

    private toolbox(): HTMLElement {
        return this.$el.find('[data-role="toolbox"]')[0];
    }

    /** SVG layer for the flow links: first child of the canvas so tiles paint above the wires. */
    private buildWires(): void {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'aaxis-flow-editor__wires');
        const defs = document.createElementNS(SVG_NS, 'defs');
        const marker = document.createElementNS(SVG_NS, 'marker');
        marker.setAttribute('id', 'aaxis-flow-arrow');
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
        svg.appendChild(defs);
        this.canvas().prepend(svg);
        this.wires = svg;
    }

    // --- Link geometry / rendering ------------------------------------------------

    /** Center of an output port, in canvas coordinates. */
    private outputPos(step: PlacedStep, port: number): {x: number; y: number} {
        const ratio = portCount(step.type) === 2 ? (port === 0 ? 1 / 3 : 2 / 3) : 0.5;
        return {x: step.x + this.tileSize, y: step.y + Math.round(this.tileSize * ratio)};
    }

    /** Where links arrive: just off the LEFT edge (2px margin), vertically centered. */
    private inputPos(step: PlacedStep): {x: number; y: number} {
        return {x: step.x - 2, y: step.y + this.tileSize / 2};
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
            // Visible arrow + an invisible wide twin that makes the thin line right-clickable
            // (the svg layer itself keeps pointer-events: none).
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
        const originX = Math.max(2, tip.x - 3 * this.spacing);
        const d = `M ${originX} ${tip.y} L ${tip.x} ${tip.y}`;
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

        const clamp = (v: number, max: number): number => Math.min(Math.max(v, 0), max);
        const sc = clamp(Math.round((from.x + this.tileSize) / cell) + 2, grid.cols - 1);
        const sr = clamp(Math.round(a.y / cell), grid.rows - 1);
        const ec = clamp(Math.round(to.x / cell) - 2, grid.cols - 1);
        const er = clamp(Math.round((to.y + this.tileSize / 2) / cell), grid.rows - 1);

        const cells = this.astar(grid, sc, sr, ec, er);
        const points: {x: number; y: number}[] = [{x: a.x, y: a.y}];
        const push = (x: number, y: number): void => {
            const last = points[points.length - 1];
            if (last.x !== x || last.y !== y) {
                points.push({x, y});
            }
        };

        push(sc * cell, a.y); // stub away from the source tile (at the port's own height)
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
        push(ec * cell, b.y);
        push(b.x, b.y); // stub into the arrow tip (2px off the target's left border)
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

    private setToolboxVisible(visible: boolean): void {
        this.toolbox().hidden = !visible;
        this.$el.find('[data-role="toolbox-toggle"]').toggleClass('is-active', visible);
        // Toolbox visibility is part of the persisted design → counts as a pending change.
        this.syncDirty();
    }

    // --- Persisted editor state (the `design` column) -----------------------------

    // --- Dirty tracking (Save enabled / Cancel↔Close) -----------------------------------

    /** Everything the save payload carries — the unit of "changes pending". */
    private snapshot(): string {
        return JSON.stringify({
            name: String(this.$el.find('[data-role="flow-name"]').val() || '').trim(),
            enabled: this.$el.find('[data-role="flow-enabled"]').is(':checked'),
            design: this.currentDesign()
        });
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
        this.$el.find('[data-role="cancel"]').text(
            dirty ? __('aaxis.ontology.flow_editor.cancel') : __('aaxis.ontology.flow_editor.close')
        );
    }

    /** The full canvas state persisted alongside the logical steps. */
    private currentDesign(): any {
        const toolbox = this.toolbox();
        // Prefer the style-set position: a hidden (display: none) element reports 0/0 offsets,
        // which would teleport the toolbox to the corner on the next restore.
        const styleX = parseInt(toolbox.style.left, 10);
        const styleY = parseInt(toolbox.style.top, 10);
        return {
            version: DESIGN_VERSION,
            steps: this.steps.map(s => ({id: s.id, type: s.type, name: s.name, x: s.x, y: s.y, config: s.config || null})),
            links: this.links.map(l => ({from: l.from, fromPort: l.fromPort, to: l.to})),
            start: this.startId,
            toolbox: {
                x: Number.isFinite(styleX) ? styleX : toolbox.offsetLeft,
                y: Number.isFinite(styleY) ? styleY : toolbox.offsetTop,
                visible: !toolbox.hidden
            }
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
        const toolbox = design.toolbox;
        if (toolbox !== undefined && (typeof toolbox !== 'object' || toolbox === null
            || !Number.isFinite(toolbox.x) || !Number.isFinite(toolbox.y) || typeof toolbox.visible !== 'boolean')
        ) {
            return false;
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

        design.steps.forEach((step: any) => this.addStep(step.type, this.snap(step.x), this.snap(step.y), step.name, step.id, step.config || null));
        this.links = links.map((l: any) => ({from: l.from, fromPort: l.fromPort, to: l.to}));
        this.startId = start;
        this.redrawLinks();
        if (toolbox) {
            this.setToolboxVisible(toolbox.visible);
            // Restore the position only when visible (a hidden toolbox keeps its default CSS spot
            // and reappears there on toggle), clamped into the CURRENT canvas — a spot saved on a
            // wider window would otherwise land outside and be clipped invisible.
            if (toolbox.visible) {
                const el = this.toolbox();
                const canvas = this.canvas();
                const maxLeft = Math.max(0, canvas.clientWidth - el.offsetWidth);
                const maxTop = Math.max(0, canvas.clientHeight - el.offsetHeight);
                el.style.left = `${Math.min(Math.max(0, toolbox.x), maxLeft)}px`;
                el.style.top = `${Math.min(Math.max(0, toolbox.y), maxTop)}px`;
                el.style.right = 'auto';
            }
        }
        return true;
    }

    private snap(value: number): number {
        return Math.round(value / this.spacing) * this.spacing;
    }

    /** Snaps and clamps a canvas-relative tile position so the whole tile stays on the canvas. */
    private place(x: number, y: number): {x: number; y: number} {
        const bounds = this.canvas().getBoundingClientRect();
        const maxX = Math.max(0, bounds.width - this.tileSize);
        const maxY = Math.max(0, bounds.height - this.tileSize);
        return {
            x: Math.min(Math.max(0, this.snap(x)), this.snap(maxX)),
            y: Math.min(Math.max(0, this.snap(y)), this.snap(maxY))
        };
    }

    // --- Step tiles ------------------------------------------------------------

    /** First "<type>-<n>" not used by any step on the canvas (names are unique per flow). */
    private defaultName(type: string): string {
        for (let n = 1; ; n++) {
            const candidate = `${type}-${n}`;
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
        // Output port(s) on the right edge — the drag source for flow links ("×" handle).
        const ports = portCount(type);
        for (let p = 0; p < ports; p++) {
            const port = document.createElement('span');
            port.className = 'aaxis-flow-editor__port'
                + (ports === 2 ? (p === 0 ? ' aaxis-flow-editor__port--a' : ' aaxis-flow-editor__port--b') : '');
            port.setAttribute('data-role', 'port');
            port.setAttribute('data-port', String(p));
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

    private addStep(type: string, x: number, y: number, name?: string, id?: string, config?: Record<string, any> | null): void {
        const stepName = (name || '').trim() || this.defaultName(type);
        const pos = this.place(x, y);
        const el = this.buildTile(type, stepName);
        el.style.left = `${pos.x}px`;
        el.style.top = `${pos.y}px`;
        this.canvas().appendChild(el);
        this.steps.push({id: id || this.newStepId(), type, name: stepName, x: pos.x, y: pos.y, config: config || null, el});
        this.updateReachability();
        this.syncDirty();
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
        $panel.append($('<div/>', {
            'class': 'aaxis-flow-editor__settings-title',
            text: `${meta.label} - ${step.name}`
        }));

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

        // Type-specific configuration blocks; each returns error() + merge() for the submit.
        const sections: {error: () => string; merge: (config: Record<string, any>) => Record<string, any>}[] = [];
        if (step.type === 'reader') {
            // The reader owns the name placement (first fixed row: Name | Reader type | Destination).
            $top.prop('hidden', false);
            sections.push(this.readerSection($top, $body, $side, $panel[0], step.config || {}, $input, reposition));
        } else if (step.type === 'dwl_transform') {
            $top.prop('hidden', false);
            sections.push(this.dwlSection($top, $body, $panel[0], step.config || {}, $input));
        } else {
            $body.append(
                $('<label/>', {'class': 'aaxis-flow-editor__settings-label', text: __('aaxis.ontology.flow_editor.step_name_label')}),
                $input
            );
            if (step.type === 'cron') {
                sections.push(this.cronSection($body, step));
            } else if (step.type === 'entity_change') {
                sections.push(this.systemEntitySection($body, step.config || {}));
            }
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
        $input.trigger('focus').trigger('select');

        const close = (): void => {
            $backdrop.remove();
            $panel.remove();
        };
        const submit = (): void => {
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
            for (const section of sections) {
                const message = section.error();
                if (message !== '') {
                    $error.text(message);
                    return;
                }
            }
            const config = sections.reduce((acc, section) => section.merge(acc), {} as Record<string, any>);
            step.config = Object.keys(config).length ? config : null;
            this.renameStep(step, name);
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

    /** Cron: a required, validated linux cron expression. */
    private cronSection($body: any, step: PlacedStep): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
        const $cron = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 128,
            value: (step.config && step.config.expression) || '',
            placeholder: __('aaxis.ontology.flow_editor.cron_expression_placeholder')
        });
        $body.append(this.settingsLabel('cron_expression_label'), $cron);
        return {
            error: () => isValidCron(String($cron.val() || '')) ? '' : __('aaxis.ontology.flow_editor.cron_expression_invalid'),
            merge: config => ({...config, expression: String($cron.val() || '').trim()})
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
     * selects side by side on one row instead of stacked.
     */
    private systemEntitySection($body: any, initial: Record<string, any>, inline = false): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
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

        let entities: {name: string; displayName?: string; systemName?: string}[] = [];
        const fillEntities = (): void => {
            const system = String($system.val() || '');
            const current = String(initial.entity || '');
            $entity.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
            entities.filter(e => e.systemName === system).forEach(e => {
                $entity.append($('<option/>', {value: e.name, text: e.displayName || e.name, selected: e.name === current}));
            });
        };

        fetch(routing.generate('aaxis_ontology_entity_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {systems?: {name: string}[]; entities?: any[]}) => {
                entities = data.entities || [];
                $system.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
                (data.systems || []).forEach(s => {
                    $system.append($('<option/>', {value: s.name, text: s.name, selected: s.name === String(initial.system || '')}));
                });
                $system.prop('disabled', false);
                $entity.prop('disabled', false);
                fillEntities();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.catalog_load_error')));
        $system.on('change', fillEntities);

        return {
            error: () => (String($system.val() || '') !== '' && String($entity.val() || '') !== '')
                ? '' : __('aaxis.ontology.flow_editor.config_system_entity_required'),
            merge: config => ({...config, system: String($system.val()), entity: String($entity.val())})
        };
    }

    /**
     * Reader: fixed first row (Name | Reader type | Destination), then the variant fields:
     * entity readers pick system+entity (one row) and load "all" or one "by id" (next row);
     * connector readers pick a connector — rest_api ones expose operation | path | body (body
     * content in the panel's right column, below the fixed row), sftp/file_system only the path.
     */
    private readerSection($top: any, $body: any, $side: any, panel: HTMLElement, initial: Record<string, any>, $nameInput: any, reposition: () => void): {error: () => string; merge: (c: Record<string, any>) => Record<string, any>} {
        const $type = $('<select/>', {'class': 'form-control'});
        $type.append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
        $type.append($('<option/>', {value: 'entity', text: __('aaxis.ontology.flow_editor.reader_type_entity'), selected: initial.reader === 'entity'}));
        $type.append($('<option/>', {value: 'connector', text: __('aaxis.ontology.flow_editor.reader_type_connector'), selected: initial.reader === 'connector'}));
        const $destination = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 128,
            value: String(initial.destination || 'payload')
        });
        // Fixed, always-visible first row.
        $top.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'}).append(
            this.settingsCol('step_name_label', $nameInput, 1.2),
            this.settingsCol('reader_type_label', $type),
            this.settingsCol('destination_label', $destination)
        ));

        // Entity variant: system + entity on one row, then load-mode (+ id) on the next.
        const $entityBlock = $('<div/>');
        $body.append($entityBlock);
        const entityPair = this.systemEntitySection($entityBlock, initial.reader === 'entity' ? initial : {}, true);
        const $mode = $('<select/>', {'class': 'form-control'});
        $mode.append($('<option/>', {value: 'all', text: __('aaxis.ontology.flow_editor.reader_mode_all')}));
        $mode.append($('<option/>', {
            value: 'by_id', text: __('aaxis.ontology.flow_editor.reader_mode_by_id'),
            selected: initial.reader === 'entity' && initial.mode === 'by_id'
        }));
        const $recordId = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 255,
            value: initial.reader === 'entity' ? String(initial.record_id || '') : ''
        });
        const $recordIdCol = this.settingsCol('reader_record_id_label', $recordId, 1.4);
        $entityBlock.append($('<div/>', {'class': 'aaxis-flow-editor__settings-row'})
            .append(this.settingsCol('reader_mode_label', $mode), $recordIdCol));

        // Connector variant: the connector, then per-connector-type fields.
        const $connectorBlock = $('<div/>');
        const $connector = $('<select/>', {'class': 'form-control', disabled: true});
        $connector.append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.loading')}));
        const $operation = $('<select/>', {'class': 'form-control'});
        ['get', 'put', 'post', 'patch', 'delete'].forEach(op => {
            $operation.append($('<option/>', {
                value: op, text: op.toUpperCase(),
                selected: initial.reader === 'connector' && initial.operation === op
            }));
        });
        const $bodyType = $('<select/>', {'class': 'form-control'});
        ['empty', 'json', 'text', 'xml'].forEach(b => {
            $bodyType.append($('<option/>', {
                value: b, text: __(`aaxis.ontology.flow_editor.body_${b}`),
                selected: initial.reader === 'connector' && initial.body === b
            }));
        });
        const $path = $('<input/>', {
            type: 'text', 'class': 'form-control', maxlength: 255,
            value: initial.reader === 'connector' ? String(initial.path || '') : ''
        });
        const $operationCol = this.settingsCol('operation_label', $operation);
        const $pathCol = this.settingsCol('path_label', $path, 1.4);
        const $bodyCol = this.settingsCol('body_label', $bodyType);
        const $fieldsRow = $('<div/>', {'class': 'aaxis-flow-editor__settings-row', hidden: true});
        $fieldsRow.append($operationCol, $pathCol, $bodyCol);
        $connectorBlock.append(this.settingsLabel('connector_label'), $connector, $fieldsRow);
        $body.append($connectorBlock);

        // id -> connector type, so the row adapts to the chosen connector (rest_api vs sftp/fs).
        const connectorTypes: Record<string, string> = {};
        fetch(routing.generate('aaxis_ontology_connector_list'), {credentials: 'same-origin'})
            .then(r => r.json())
            .then((data: {records?: {id: number; name: string; type: string; systemName?: string}[]}) => {
                const current = initial.reader === 'connector' ? String(initial.connector || '') : '';
                $connector.empty().append($('<option/>', {value: '', text: __('aaxis.ontology.flow_editor.choose_placeholder')}));
                (data.records || []).forEach(c => {
                    connectorTypes[String(c.id)] = c.type;
                    $connector.append($('<option/>', {
                        value: String(c.id),
                        text: `${c.name} (${c.type}${c.systemName ? ' \u00b7 ' + c.systemName : ''})`,
                        selected: String(c.id) === current
                    }));
                });
                $connector.prop('disabled', false);
                syncBlocks();
            })
            .catch(() => messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.catalog_load_error')));

        // Body content lives in the right column, below the fixed first row. NOTE: a textarea's
        // value must be set via .val() — a `value` attribute in the creation map is ignored.
        const $bodyContent = $('<textarea/>', {'class': 'form-control aaxis-flow-editor__settings-textarea'});
        $bodyContent.val(initial.reader === 'connector' ? String(initial.body_content || '') : '');
        $side.append(this.settingsLabel('body_content_label'), $bodyContent);

        const isRest = (): boolean => connectorTypes[String($connector.val() || '')] === 'rest_api';
        const syncBlocks = (): void => {
            const type = String($type.val() || '');
            $entityBlock.toggle(type === 'entity');
            $connectorBlock.toggle(type === 'connector');
            $recordIdCol.toggle(String($mode.val()) === 'by_id');
            // Connector fields appear only once a connector is chosen; operation/body are
            // rest_api-only (sftp/file_system readers just need the path).
            const hasConnector = String($connector.val() || '') !== '';
            $fieldsRow.prop('hidden', !hasConnector);
            $operationCol.toggle(isRest());
            $bodyCol.toggle(isRest());
            const sideVisible = type === 'connector' && hasConnector && isRest() && String($bodyType.val()) !== 'empty';
            $side.prop('hidden', !sideVisible);
            panel.classList.toggle('is-wide', sideVisible);
            // Any of the toggles changes the panel size — keep the WHOLE popup on-screen.
            reposition();
        };
        $type.on('change', syncBlocks);
        $bodyType.on('change', syncBlocks);
        $connector.on('change', syncBlocks);
        $mode.on('change', syncBlocks);
        syncBlocks();

        return {
            error: () => {
                const type = String($type.val() || '');
                if (type === '') {
                    return __('aaxis.ontology.flow_editor.reader_type_required');
                }
                if (type === 'entity') {
                    const pairError = entityPair.error();
                    if (pairError !== '') {
                        return pairError;
                    }
                    if (String($mode.val()) === 'by_id' && String($recordId.val() || '').trim() === '') {
                        return __('aaxis.ontology.flow_editor.reader_record_id_required');
                    }
                } else {
                    if (String($connector.val() || '') === '') {
                        return __('aaxis.ontology.flow_editor.reader_connector_required');
                    }
                    if (String($path.val() || '').trim() === '') {
                        return __('aaxis.ontology.flow_editor.reader_path_required');
                    }
                }
                if (String($destination.val() || '').trim() === '') {
                    return __('aaxis.ontology.flow_editor.destination_required');
                }
                return '';
            },
            merge: config => {
                const type = String($type.val());
                const base: Record<string, any> = {...config, reader: type, destination: String($destination.val() || '').trim()};
                if (type === 'entity') {
                    const withPair = entityPair.merge(base);
                    withPair.mode = String($mode.val());
                    if (withPair.mode === 'by_id') {
                        withPair.record_id = String($recordId.val() || '').trim();
                    }
                    return withPair;
                }
                base.connector = String($connector.val());
                base.path = String($path.val() || '').trim();
                if (isRest()) {
                    base.operation = String($operation.val());
                    base.body = String($bodyType.val());
                    if (base.body !== 'empty') {
                        base.body_content = String($bodyContent.val() || '');
                    }
                }
                return base;
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

        const $code = $('<textarea/>', {'class': 'form-control aaxis-flow-editor__settings-textarea', spellcheck: 'false'});
        $code.val(String(initial.code || ''));
        $body.append(this.settingsLabel('dwl_code_label'), $code);
        // A code editor deserves the wide panel from the start.
        panel.classList.add('is-wide');

        return {
            error: () => {
                if (String($code.val() || '').trim() === '') {
                    return __('aaxis.ontology.flow_editor.dwl_code_required');
                }
                if (String($destination.val() || '').trim() === '') {
                    return __('aaxis.ontology.flow_editor.destination_required');
                }
                return '';
            },
            merge: config => ({
                ...config,
                code: String($code.val() || ''),
                destination: String($destination.val() || '').trim()
            })
        };
    }

    /** Places the settings panel next to the tile (right side preferred), clamped to the viewport. */
    private positionSettings(panel: HTMLElement, tile: HTMLElement): void {
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
        this.$el.find('[data-role="debug"]').prop('hidden', !this.findTrigger());
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
        const canvas = this.canvas();
        const toolbox = this.$el.find('[data-role="toolbox"]')[0];
        const bounds = canvas.getBoundingClientRect();

        let left = e.clientX - bounds.left - this.panelDrag.dx;
        let top = e.clientY - bounds.top - this.panelDrag.dy;
        left = Math.min(Math.max(0, left), Math.max(0, bounds.width - toolbox.offsetWidth));
        top = Math.min(Math.max(0, top), Math.max(0, bounds.height - toolbox.offsetHeight));

        toolbox.style.left = `${left}px`;
        toolbox.style.top = `${top}px`;
        toolbox.style.right = 'auto';
    }

    // --- Dragging a NEW step out of the toolbox ------------------------------------

    private startGhostDrag(e: PointerEvent, type: string): void {
        if (!this.stepMeta[type]) {
            return;
        }
        // Preview only — the real name is assigned on drop (after a possible trigger swap).
        const el = this.buildTile(type, this.defaultName(type));
        el.classList.add('is-ghost');
        document.body.appendChild(el);
        this.ghostDrag = {pointerId: e.pointerId, type, el};
        this.positionGhost(e);
        this.trackPointer();
        e.preventDefault();
    }

    private positionGhost(e: PointerEvent): void {
        if (!this.ghostDrag) {
            return;
        }
        this.ghostDrag.el.style.left = `${e.clientX - this.tileSize / 2}px`;
        this.ghostDrag.el.style.top = `${e.clientY - this.tileSize / 2}px`;
    }

    private moveGhost(e: PointerEvent): void {
        this.positionGhost(e);
    }

    private dropGhost(e: PointerEvent): void {
        if (!this.ghostDrag) {
            return;
        }
        const {type, el} = this.ghostDrag;
        this.ghostDrag = null;
        el.remove();

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
                        this.removeStep(existingTrigger);
                        this.addStep(type, x, y);
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

        this.addStep(type, x, y);
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
        path.setAttribute('marker-end', 'url(#aaxis-flow-arrow)');
        this.wires.appendChild(path);
        this.linkDrag = {pointerId: e.pointerId, from, fromPort, path, target: null};
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
        const {from, fromPort, path, target} = this.linkDrag;
        this.linkDrag = null;
        path.remove();
        if (target) {
            target.el.classList.remove('is-link-target');
        }

        const over = this.stepAt(e.clientX, e.clientY);
        if (!over) {
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
    private showMenuAt(e: PointerEvent, populate: (addItem: (label: string, icon: string, action: () => void) => void) => void): void {
        this.closeContextMenu();
        const menu = document.createElement('div');
        menu.className = 'aaxis-flow-editor__menu';
        const addItem = (label: string, icon: string, action: () => void): void => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'aaxis-flow-editor__menu-item';
            item.innerHTML = `<span class="fa ${icon}" aria-hidden="true"></span>`;
            item.appendChild(document.createTextNode(label));
            item.addEventListener('click', () => {
                this.closeContextMenu();
                action();
            });
            menu.appendChild(item);
        };
        populate(addItem);

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

        this.showMenuAt(e, addItem => {
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
        });
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
     * Aligns the selection on the Y of its first element (the leftmost one) and spreads the tiles
     * with a constant ONE-tile gap between them, keeping the horizontal sequence.
     */
    private alignSelection(): void {
        const ordered = this.orderedSelection();
        if (ordered.length < 2) {
            return;
        }
        const first = ordered[0];
        ordered.forEach((step, i) => {
            const pos = this.place(first.x + i * 2 * this.tileSize, first.y);
            step.x = pos.x;
            step.y = pos.y;
            step.el.style.left = `${pos.x}px`;
            step.el.style.top = `${pos.y}px`;
        });
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

    /** Entity-change triggers debug with an event: system/entity (prefilled from the trigger) + payload. */
    private openDebugInput(trigger: PlacedStep): void {
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
            this.runDebug(input);
        });
    }

    /** POSTs the CURRENT canvas definition (unsaved edits included) for a debug run. */
    private runDebug(input: Record<string, any>): void {
        const $debug = this.$el.find('[data-role="debug"]');
        $debug.prop('disabled', true);
        const $spinner = $('<span/>', {'class': 'fa fa-spinner fa-spin aaxis-flow-editor__save-spinner', 'aria-hidden': 'true'});
        $debug.prepend($spinner);

        this.apiFetch(routing.generate('aaxis_ontology_flow_debug'), 'POST', {
            steps: this.steps.map(s => ({id: s.id, type: s.type, name: s.name, config: s.config || null})),
            links: this.links.map(l => ({from: l.from, fromPort: l.fromPort, to: l.to})),
            input
        }).then(res => {
            if (!res.ok || !res.data || !res.data.success) {
                throw new Error((res.data && res.data.message) || __('aaxis.ontology.flow_editor.debug_error'));
            }
            this.showDebugResult(res.data.output);
        }).catch((err: Error) => {
            messenger.notificationFlashMessage('error', err.message || __('aaxis.ontology.flow_editor.debug_error'));
        }).finally(() => {
            $spinner.remove();
            $debug.prop('disabled', false);
        });
    }

    /** The accumulated output context, pretty-printed as JSON. */
    private showDebugResult(output: any): void {
        const dialog = new Dialog({title: __('aaxis.ontology.flow_editor.debug_result_title'), width: '640px'});
        const $content = dialog.open();
        $content.append($('<pre/>', {
            'class': 'aaxis-json-view',
            text: JSON.stringify(output ?? {}, null, 2)
        }));
    }

    // --- Persistence -----------------------------------------------------------

    private save(): void {
        const name = String(this.$el.find('[data-role="flow-name"]').val() || '').trim();
        if (name === '') {
            messenger.notificationFlashMessage('error', __('aaxis.ontology.flow_editor.name_required'));
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
        this.apiFetch(url, this.flow === null ? 'POST' : 'PUT', {
            name,
            enabled: this.$el.find('[data-role="flow-enabled"]').is(':checked'),
            steps: this.steps.map(s => ({type: s.type, name: s.name, x: s.x, y: s.y, config: s.config || null})),
            design: this.currentDesign()
        }).then(res => {
            if (!res.ok || !res.data || !res.data.success) {
                throw new Error((res.data && res.data.message) || __('aaxis.ontology.flow_editor.save_error'));
            }
            const created = this.flow === null;
            this.flow = res.data.flow as FlowRecord;
            if (created && this.flow && this.flow.id) {
                // Refreshing after the first save must reopen THIS flow, not a fresh editor.
                window.history.replaceState(null, '', routing.generate('aaxis_ontology_flow_editor', {id: this.flow.id}));
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

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisFlowEditor');
        if (this.ghostDrag) {
            this.ghostDrag.el.remove();
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
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        super.dispose();
    }
}

export default OntologyFlowEditorComponent;

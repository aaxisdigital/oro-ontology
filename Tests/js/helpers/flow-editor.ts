import $ from 'jquery';
import {afterEach, beforeEach, vi} from 'vitest';

/**
 * Fixtures for the flow editor tests. The editor measures its DOM constantly (tile rects, the
 * canvas plane, the toolbox), which jsdom does not lay out — `useFlowEditorEnv()` installs a
 * small deterministic geometry: the canvas plane sits at CANVAS in viewport coordinates and every
 * tile's rect follows its inline left/top, so client coordinates are simply canvas + position.
 *
 * Test files mock the Dialog widget with the recording fake below and hand the component class
 * to `useFlowEditorEnv()` (this module must not import the component itself: the Dialog mock
 * factory imports it while the component is still loading):
 *
 *   vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());
 *   useFlowEditorEnv(FlowEditorComponent);
 */

/** Where the canvas plane sits in viewport coordinates (and its size). */
export const CANVAS = {left: 100, top: 50, width: 1000, height: 600};
/** The floating toolbox's rect while visible (over the canvas' top-right corner). */
export const TOOLBOX_RECT = {left: 900, top: 60, width: 180, height: 300};
/** Default grid: 10px dots, 8x tiles. */
export const TILE = 80;

// --- Dialog fake -----------------------------------------------------------------------

export class FlowDialog {
    static instances: FlowDialog[] = [];

    $content: any = $('<div class="fe-dialog-body"></div>');
    closed = false;

    constructor(public options: any) {
        FlowDialog.instances.push(this);
    }

    open(): any {
        $(document.body).append(this.$content);
        return this.$content;
    }

    close(): void {
        this.closed = true;
        this.$content.remove();
    }

    /** The most recently constructed dialog. */
    static last(): FlowDialog {
        return FlowDialog.instances[FlowDialog.instances.length - 1];
    }
}

export function dialogModule(): any {
    return {default: FlowDialog};
}

// --- Toolbox / DOM -----------------------------------------------------------------------

/** [category, [type, icon, label]...] — the palette the twig renders (labels as the UI shows them). */
export const PALETTE: Array<[string, Array<[string, string, string]>]> = [
    ['trigger', [['cron', 'fa-clock-o', 'Schedule'], ['endpoint', 'fa-plug', 'Endpoint'],
        ['entity_change', 'fa-database', 'Entity Change'], ['subflow', 'fa-sign-in', 'Subflow']]],
    ['action', [['choice', 'fa-code-fork', 'Choice'], ['sub_flow', 'fa-sitemap', 'Call Subflow'],
        ['foreach', 'fa-repeat', 'Foreach Loop']]],
    ['operation', [['dwl_transform', 'fa-random', 'DWL Transform'], ['entity_read', 'fa-download', 'Entity Read'],
        ['entity_write', 'fa-upload', 'Entity Write'], ['invoke', 'fa-bolt', 'HTTP Request'],
        ['sql_query', 'fa-table', 'SQL Query'], ['invoke_php', 'fa-code', 'Invoke PHP']]],
    ['operation', [['file_read', 'fa-file-text-o', 'Read File'], ['file_write', 'fa-pencil-square-o', 'Write File'],
        ['file_list', 'fa-folder-open-o', 'List Folder'], ['file_delete', 'fa-trash-o', 'Delete'],
        ['file_rename', 'fa-i-cursor', 'Rename']]],
    ['operation', [['logger', 'fa-list-alt', 'Logger'], ['event', 'fa-flag-o', 'Event'],
        ['email', 'fa-envelope-o', 'Email'], ['ms_teams', 'fa-windows', 'MS Teams']]]
];

/** The editor markup of Flow/editor.html.twig (tab bar with its action groups, debugger, canvas, toolbox). */
export function editorDom(): any {
    const button = (role: string, extra = ''): string =>
        `<button type="button" class="aaxis-flow-editor__tab aaxis-flow-editor__tab--action" data-role="${role}" title="${role}"${extra}><span class="fa fa-x"></span></button>`;
    // Run Now as a split button: the caret opens a menu holding Debug (debug-step).
    const runSplit = `
        <div class="aaxis-flow-editor__run" data-role="run-split" hidden>
            ${button('debug')}
            <button type="button" class="aaxis-flow-editor__tab" data-role="run-menu-toggle" aria-expanded="false" title="run-options"></button>
            <div class="aaxis-flow-editor__menu" data-role="run-menu" hidden>
                <button type="button" class="aaxis-flow-editor__menu-item" data-role="debug-step" title="debug-step">debug-step</button>
            </div>
        </div>`;
    const canvasActions = [button('toolbox-toggle'), runSplit, button('organize', ' hidden')].join('');
    const documentActions = [
        button('tab-add'), button('tab-open'), button('save', ' disabled'), button('save-all', ' disabled'),
        button('view-source'), button('flow-history', ' disabled'), button('tab-close-all')
    ].join('');
    const sections = PALETTE.map(([category, items]) => `
        <div class="aaxis-flow-editor__toolbox-section">
            <div class="aaxis-flow-editor__toolbox-section-title">
                <button type="button" data-role="toolbox-section-toggle"><span class="fa fa-minus"></span></button>
            </div>
            ${items.map(([type, icon, label]) => `<div class="aaxis-flow-editor__toolbox-item" data-step-type="${type}"
                data-step-category="${category}" data-step-icon="${icon}" data-step-label="${label}">${label}</div>`).join('')}
        </div>`).join('');

    return $(`
        <div class="content">
            <div class="aaxis-flow-editor" data-role="editor">
                <div class="aaxis-flow-editor__tabs" data-role="tabs">
                    <div class="aaxis-flow-editor__tab-list" data-role="tab-list"></div>
                    <div class="aaxis-flow-editor__tab-actions" data-role="tab-actions">
                        <div class="aaxis-flow-editor__tab-group" data-role="canvas-actions">${canvasActions}</div>
                        <div class="aaxis-flow-editor__tab-group" data-role="document-actions">${documentActions}</div>
                    </div>
                </div>
                <div class="aaxis-flow-editor__canvas-wrap">
                    <aside class="aaxis-flow-editor__debugger" data-role="debugger" hidden></aside>
                    <div class="aaxis-flow-editor__canvas" data-role="canvas-viewport">
                        <div class="aaxis-flow-editor__canvas-inner" data-role="canvas"></div>
                    </div>
                    <div class="aaxis-flow-editor__toolbox" data-role="toolbox">
                        <div class="aaxis-flow-editor__toolbox-handle" data-role="toolbox-handle">
                            Toolbox
                            <button type="button" data-role="toolbox-expand-all"><span class="fa fa-plus-square-o"></span></button>
                            <button type="button" data-role="toolbox-collapse-all"><span class="fa fa-minus-square-o"></span></button>
                            <button type="button" data-role="toolbox-hide"><span class="fa fa-times"></span></button>
                        </div>
                        <div class="aaxis-flow-editor__toolbox-body" data-role="toolbox-body">${sections}</div>
                    </div>
                </div>
            </div>
        </div>`).appendTo(document.body);
}

// --- Geometry ----------------------------------------------------------------------------

function rect(left: number, top: number, width: number, height: number): DOMRect {
    return {left, top, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON: () => ({})} as DOMRect;
}

/** Overridable per test: the rect a tab button reports (drag-reorder math). */
export const geometry = {
    tabWidth: 100,
    viewportOffsetLeft: 0
};

function installGeometry(): void {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
        const role = this.dataset.role;
        if (role === 'step') {
            return rect(
                CANVAS.left + (Number.parseFloat(this.style.left) || 0),
                CANVAS.top + (Number.parseFloat(this.style.top) || 0),
                Number.parseFloat(this.style.width) || TILE,
                Number.parseFloat(this.style.height) || TILE
            );
        }
        if (role === 'canvas') {
            return rect(CANVAS.left, CANVAS.top, CANVAS.width, CANVAS.height);
        }
        if (role === 'toolbox') {
            return this.hidden ? rect(0, 0, 0, 0) : rect(TOOLBOX_RECT.left, TOOLBOX_RECT.top, TOOLBOX_RECT.width, TOOLBOX_RECT.height);
        }
        if (role === 'tab') {
            return rect(Number(this.dataset.tabIndex) * geometry.tabWidth, 0, geometry.tabWidth, 30);
        }
        if (this.classList.contains('aaxis-flow-editor')) {
            return rect(0, 40, 1200, 700);
        }
        return rect(0, 0, 0, 0);
    });
    const size = (el: Element, horizontal: boolean): number => {
        const role = (el as HTMLElement).dataset?.role;
        if (role === 'canvas' || role === 'canvas-viewport') {
            return horizontal ? CANVAS.width : CANVAS.height;
        }
        if (role === 'toolbox') {
            return horizontal ? TOOLBOX_RECT.width : TOOLBOX_RECT.height;
        }
        return 0;
    };
    vi.spyOn(Element.prototype, 'clientWidth', 'get').mockImplementation(function (this: Element) {
        return size(this, true);
    });
    vi.spyOn(Element.prototype, 'clientHeight', 'get').mockImplementation(function (this: Element) {
        return size(this, false);
    });
    vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(function (this: HTMLElement) {
        return size(this, true);
    });
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function (this: HTMLElement) {
        return size(this, false);
    });
    vi.spyOn(HTMLElement.prototype, 'offsetLeft', 'get').mockImplementation(function (this: HTMLElement) {
        return this.dataset.role === 'canvas-viewport' ? geometry.viewportOffsetLeft : 0;
    });
    vi.spyOn(HTMLElement.prototype, 'offsetTop', 'get').mockImplementation(() => 0);
    // jsdom has no Element#scrollTo (Organize scrolls the viewport home).
    (HTMLElement.prototype as any).scrollTo = vi.fn();
}

// --- Network -----------------------------------------------------------------------------

export type Reply = {status?: number; body: any} | Error;
export type Route = Reply | Promise<Reply> | ((url: string, init?: any) => Reply | Promise<Reply>);

export const ENTITY_CATALOG = {
    systems: [{name: 'crm'}, {name: 'erp'}],
    entities: [
        {name: 'customer', displayName: 'Customer', systemName: 'crm', attributes: [{name: 'email'}], readerAttributes: ['id', 'email', 'name']},
        {name: 'order', systemName: 'crm', attributes: [{name: 'total'}]},
        {name: 'item', systemName: 'erp'}
    ]
};

export const CONNECTOR_CATALOG = {
    records: [
        {id: 1, name: 'api', type: 'rest_api', systemName: 'crm'},
        {id: 2, name: 'pg', type: 'database'},
        {id: 3, name: 'files', type: 'file_system', systemName: 'erp'},
        {id: 4, name: 'box', type: 'sftp'}
    ]
};

/** The fetch stub's current routes (keyed by route name = the stub router's path). */
export let routes: Record<string, Route> = {};

/**
 * Replaces global fetch with a router over the stub routing's `/<route>?<query>` URLs. Unknown
 * routes reject (the editor's background prefetches swallow that). Returns the spy.
 */
export function mockFetch(extra: Record<string, Route> = {}): any {
    routes = {
        aaxis_ontology_entity_list: {body: ENTITY_CATALOG},
        aaxis_ontology_connector_list: {body: CONNECTOR_CATALOG},
        aaxis_ontology_flow_list: {body: {records: []}},
        ...extra
    };
    const spy = vi.fn((input: any, init?: any) => {
        const url = String(input);
        const name = url.split('?')[0].replace(/^\//, '');
        const route = routes[name];
        if (route === undefined) {
            return Promise.reject(new Error(`unexpected fetch ${url}`));
        }
        return Promise.resolve(typeof route === 'function' ? route(url, init) : route).then(reply => {
            if (reply instanceof Error) {
                throw reply;
            }
            const status = reply.status ?? 200;
            return {ok: status < 400, status, json: () => Promise.resolve(reply.body)};
        });
    });
    vi.stubGlobal('fetch', spy);
    return spy;
}

/** A promise settled by hand — for replies whose timing matters. */
export function deferred<T = Reply>(): {promise: Promise<T>; resolve: (v: T) => void; reject: (e: any) => void} {
    let resolve!: (v: T) => void;
    let reject!: (e: any) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return {promise, resolve, reject};
}

/** The parsed JSON bodies sent to one route. */
export function sentTo(spy: any, name: string): any[] {
    return spy.mock.calls
        .filter((c: any[]) => String(c[0]).split('?')[0] === `/${name}`)
        .map((c: any[]) => (c[1] && c[1].body ? JSON.parse(c[1].body) : null));
}

/** Lets every pending promise chain settle (fetch → json → then …). */
export async function flush(): Promise<void> {
    for (let i = 0; i < 30; i++) {
        await Promise.resolve();
    }
}

// --- Builders ----------------------------------------------------------------------------

export function st(id: string, type: string, name: string, x: number, y: number, config: Record<string, any> | null = null, extra: Record<string, any> = {}): any {
    return {id, type, name, x, y, config, ...extra};
}

export function design(steps: any[], links: any[] = [], start: string | null = null): any {
    return {version: 2, steps, links, start};
}

export function flowRecord(id: number | null, name: string, steps: any[], links: any[] = [], extra: Record<string, any> = {}): any {
    return {
        id,
        name,
        enabled: true,
        type: 'flow',
        steps: steps.map(s => ({type: s.type, name: s.name, x: s.x, y: s.y})),
        design: design(steps, links),
        invalidSteps: [],
        ...extra
    };
}

/** A flow with a cron trigger linked to one DWL transform — the usual "valid, runnable" flow. */
export function simpleFlow(id: number | null = 7, extra: Record<string, any> = {}): any {
    return flowRecord(id, 'Nightly', [
        st('t1', 'cron', 'nightly', 0, 0, {enabled: true, mode: 'interval', value: 1, unit: 'day'}),
        st('d1', 'dwl_transform', 'shape', 200, 0, {code: 'payload', destination: 'payload'})
    ], [{from: 't1', fromPort: 0, to: 'd1'}], extra);
}

// --- Editor lifecycle --------------------------------------------------------------------

const editors: any[] = [];
let EditorClass: any = null;

export function createEditor(options: Record<string, any> = {}): any {
    const $el = editorDom();
    const editor = new EditorClass({_sourceElement: $el, listUrl: '#flows', ...options});
    editors.push(editor);
    return editor;
}

/** Registers the per-test environment: geometry, storage/URL reset, fetch routes, cleanup. */
export function useFlowEditorEnv(component: any): void {
    EditorClass = component;
    beforeEach(() => {
        installGeometry();
        FlowDialog.instances = [];
        window.localStorage.clear();
        window.history.replaceState(null, '', '/');
        mockFetch();
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });
    afterEach(() => {
        editors.splice(0).forEach(editor => editor.dispose());
        $(document).off('.aaxisFlowSettings').off('.aaxisFlowSettingsDrag');
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });
}

// --- DOM probes / gestures ---------------------------------------------------------------

/** The editor's root jQuery element. */
export function root(editor: any): any {
    return editor.$el;
}

/** The tile of the step with this name. */
export function tile(name: string): HTMLElement {
    const el = $('[data-role="step"]').filter((_i: number, node: HTMLElement) =>
        $(node).find('.aaxis-flow-editor__step-name').text() === name)[0];
    if (!el) {
        throw new Error(`no tile named ${name}`);
    }
    return el;
}

export function tileNames(): string[] {
    return $('[data-role="step"] .aaxis-flow-editor__step-name').map((_i: number, el: HTMLElement) => el.textContent).get();
}

/** The live canvas state as it would be saved (the editor's own serialization). */
export function designOf(editor: any): any {
    return editor.currentDesign();
}

export function configOf(editor: any, name: string): any {
    const step = designOf(editor).steps.find((s: any) => s.name === name);
    return step ? step.config : undefined;
}

export function linksOf(editor: any): string[] {
    const byId: Record<string, string> = {};
    designOf(editor).steps.forEach((s: any) => {
        byId[s.id] = s.name;
    });
    return designOf(editor).links.map((l: any) => `${byId[l.from]}:${l.fromPort}->${byId[l.to]}`);
}

/** Dispatches a pointer-ish event (jsdom has no PointerEvent: a MouseEvent carrying pointerId). */
export function pointer(target: EventTarget, type: string, clientX: number, clientY: number, opts: {button?: number; pointerId?: number} = {}): MouseEvent {
    const ev = new MouseEvent(type, {bubbles: true, cancelable: true, clientX, clientY, button: opts.button ?? 0});
    Object.defineProperty(ev, 'pointerId', {value: opts.pointerId ?? 1});
    target.dispatchEvent(ev);
    return ev;
}

/** Viewport coordinates of a canvas point. */
export function client(x: number, y: number): [number, number] {
    return [CANVAS.left + x, CANVAS.top + y];
}

/** Drags a toolbox item onto the canvas point (x, y) — canvas coordinates of the RELEASE. */
export function dropFromToolbox(type: string, x: number, y: number): void {
    const item = document.querySelector(`[data-role="toolbox"] [data-step-type="${type}"]`) as HTMLElement;
    pointer(item, 'pointerdown', TOOLBOX_RECT.left + 10, TOOLBOX_RECT.top + 10);
    pointer(window, 'pointermove', ...client(x, y));
    pointer(window, 'pointerup', ...client(x, y));
}

/** Drags from a step's output port to the canvas point (x, y). */
export function dragPort(name: string, port: number, x: number, y: number): void {
    const el = tile(name).querySelector(`[data-role="port"][data-port="${port}"]`) as HTMLElement;
    pointer(el, 'pointerdown', ...client(0, 0));
    pointer(window, 'pointermove', ...client(x, y));
    pointer(window, 'pointerup', ...client(x, y));
}

/** Center of a tile in viewport coordinates. */
export function centerOf(name: string): [number, number] {
    const r = tile(name).getBoundingClientRect();
    return [r.left + r.width / 2, r.top + r.height / 2];
}

export function contextMenu(target: Element, clientX = 300, clientY = 300): void {
    const ev = new MouseEvent('contextmenu', {bubbles: true, cancelable: true, clientX, clientY});
    target.dispatchEvent(ev);
}

/** The labels of the open context menu's top-level items (submenu parents included). */
export function menuLabels(): string[] {
    return $('body > .aaxis-flow-editor__menu').first().children().map((_i: number, el: HTMLElement) => {
        const item = el.classList.contains('aaxis-flow-editor__menu-sub') ? el.firstElementChild as HTMLElement : el;
        return item.textContent;
    }).get();
}

/** Clicks the context-menu item (top-level or nested) with this label. */
export function clickMenu(label: string): void {
    const item = $('.aaxis-flow-editor__menu-item').filter((_i: number, el: HTMLElement) => el.textContent === label)[0];
    if (!item) {
        throw new Error(`no menu item ${label}`);
    }
    item.click();
}

/** Clicks `child` inside the hover submenu whose parent row reads `parent`. */
export function clickSubmenu(parent: string, child: string): void {
    const $wrap = $('.aaxis-flow-editor__menu-sub').filter((_i: number, el: HTMLElement) =>
        (el.firstElementChild as HTMLElement).textContent === parent);
    const item = $wrap.find('.aaxis-flow-editor__menu--nested .aaxis-flow-editor__menu-item')
        .filter((_i: number, el: HTMLElement) => el.textContent === child)[0] as HTMLButtonElement | undefined;
    if (!item) {
        throw new Error(`no submenu item ${parent} > ${child}`);
    }
    item.click();
}

/** The children of one hover submenu: label + disabled/active flags. */
export function submenu(parent: string): Array<{label: string; disabled: boolean; active: boolean}> {
    return $('.aaxis-flow-editor__menu-sub').filter((_i: number, el: HTMLElement) =>
        (el.firstElementChild as HTMLElement).textContent === parent)
        .find('.aaxis-flow-editor__menu--nested .aaxis-flow-editor__menu-item')
        .map((_i: number, el: HTMLButtonElement) => ({
            label: el.textContent,
            disabled: el.disabled,
            active: el.classList.contains('aaxis-flow-editor__menu-item--active')
        })).get();
}

/** Rubber-band selection on the bare canvas between two canvas points. */
export function marquee(x0: number, y0: number, x1: number, y1: number): void {
    const canvas = document.querySelector('[data-role="canvas"]') as HTMLElement;
    pointer(canvas, 'pointerdown', ...client(x0, y0));
    pointer(window, 'pointermove', ...client(x1, y1));
    pointer(window, 'pointerup', ...client(x1, y1));
}

/** Drags a placed tile by `(dx, dy)` pixels (grabbed at its top-left + 10px). */
export function dragTile(name: string, dx: number, dy: number): void {
    const r = tile(name).getBoundingClientRect();
    pointer(tile(name), 'pointerdown', r.left + 10, r.top + 10);
    pointer(window, 'pointermove', r.left + 10 + dx, r.top + 10 + dy);
    pointer(window, 'pointerup', r.left + 10 + dx, r.top + 10 + dy);
}

export function selectedNames(): string[] {
    return $('[data-role="step"].is-selected .aaxis-flow-editor__step-name').map((_i: number, el: HTMLElement) => el.textContent).get();
}

export function positionOf(name: string): [number, number] {
    return [Number.parseFloat(tile(name).style.left), Number.parseFloat(tile(name).style.top)];
}

/** Opens a tile's settings popup (double-click) and returns the panel. */
export function openSettings(name: string): any {
    tile(name).dispatchEvent(new MouseEvent('dblclick', {bubbles: true}));
    return $('.aaxis-flow-editor__settings').last();
}

export function confirmSettings(): void {
    $('.aaxis-flow-editor__settings .btn-primary').last().trigger('click');
}

/** The control right after the label with this key suffix (…flow_editor.<key>). */
export function field(key: string): any {
    const $next = $('label').filter((_i: number, el: HTMLElement) =>
        el.textContent === `aaxis.ontology.flow_editor.${key}`).first().next();

    // The connector select sits in a wrapper next to its refresh button.
    return $next.is('.aaxis-flow-editor__connector-picker') ? $next.find('select') : $next;
}

/** A DWL field's textarea by its label key suffix. */
export function dwlTextarea(key: string): any {
    return $('.aaxis-dwl-field').filter((_i: number, el: HTMLElement) =>
        $(el).find('.aaxis-dwl-field__label').text() === `aaxis.ontology.flow_editor.${key}`).find('textarea');
}

export function tabTitles(): string[] {
    return $('[data-role="tab"] .aaxis-flow-editor__tab-title').map((_i: number, el: HTMLElement) => el.textContent).get();
}

export function activeTabIndex(): number {
    return Number($('[data-role="tab"].is-active').data('tabIndex'));
}

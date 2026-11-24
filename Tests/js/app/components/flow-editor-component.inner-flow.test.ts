import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    centerOf, client, clickMenu, clickSubmenu, confirmSettings, configOf, contextMenu, createEditor, designOf,
    dragPort, FlowDialog, dragTile, dropFromToolbox, flowRecord, flush, linksOf, menuLabels, mockFetch, openSettings, pointer,
    positionOf, root, selectedNames, sentTo, st, submenu, tile, tileNames, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());
// The inner-flow picker label is a formatted message ("<name> (inner flow)"): render it readable.
vi.mock('orotranslation/js/translator', () => ({
    default: (key: string, params?: Record<string, unknown>): string =>
        (key.endsWith('.inner_flow_option') ? `${String(params?.name)} (inner flow)` : key)
}));

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;
const CRON = {enabled: true, mode: 'interval', value: 1, unit: 'day'};
const DWL = {code: 'payload', destination: 'payload'};

/**
 * Geometry with the default grid (10px dots, 80px tiles): an inner flow is 160px tall, its
 * elements sit 40px in from its left/top edges and 120px apart (a tile + a half-tile gap), and it
 * is max(160, 80 + 120n - 40) wide.
 */

/** The names of an inner flow's elements, in order (from the design the editor would save). */
function children(editor: any, box: string): string[] {
    const steps = designOf(editor).steps;
    const byId: Record<string, string> = {};
    steps.forEach((s: any) => {
        byId[s.id] = s.name;
    });
    return (steps.find((s: any) => s.name === box)?.config?.children || []).map((id: string) => byId[id]);
}

function idOf(editor: any, name: string): string {
    return designOf(editor).steps.find((s: any) => s.name === name).id;
}

function sizeOf(name: string): [number, number] {
    return [Number.parseFloat(tile(name).style.width), Number.parseFloat(tile(name).style.height)];
}

/** The automatic arrows drawn inside a box: [x1, x2] per arrow, plus the branch tint. */
function innerWires(name: string): Array<{d: string; branch: string | undefined}> {
    return $(tile(name)).find('[data-role="inner-wire"]').map((_i: number, el: SVGPathElement) =>
        ({d: el.getAttribute('d') as string, branch: el.dataset.branch})).get();
}

/** Drags a placed tile so that the POINTER is released at canvas (x, y) (grabbed at its top-left + 10). */
function dragTo(name: string, x: number, y: number): void {
    const r = tile(name).getBoundingClientRect();
    pointer(tile(name), 'pointerdown', r.left + 10, r.top + 10);
    pointer(window, 'pointermove', ...client(x, y));
    pointer(window, 'pointerup', ...client(x, y));
}

/** A flow: cron → Call Subflow "call" (→ inner flow "box" holding "shape" then "log"). */
function innerFlowRecord(extra: {callConfig?: any; boxChildren?: string[]; more?: any[]; links?: any[]} = {}): any {
    return flowRecord(7, 'Main', [
        st('t1', 'cron', 'nightly', 0, 0, CRON),
        st('c1', 'sub_flow', 'call', 200, 0, extra.callConfig ?? {inner: 'b1'}),
        st('b1', 'inner_flow', 'box', 100, 300, {children: extra.boxChildren ?? ['d1', 'l1']}),
        st('d1', 'dwl_transform', 'shape', 0, 0, DWL),
        st('l1', 'logger', 'log', 0, 0, {message: 'hi'}),
        ...(extra.more || [])
    ], extra.links ?? [{from: 't1', fromPort: 0, to: 'c1'}]);
}

describe('OntologyFlowEditorComponent — Inner Flow on the canvas', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('drops an inner flow as a two-tile-tall empty box without ports', () => {
        const editor = createEditor();

        dropFromToolbox('inner_flow', 140, 140);

        expect(tileNames()).toEqual(['inner_flow-1']);
        expect(positionOf('inner_flow-1')).toEqual([100, 100]);
        expect(sizeOf('inner_flow-1')).toEqual([160, 160]);
        expect(tile('inner_flow-1').classList.contains('aaxis-flow-editor__step--inner')).toBe(true);
        expect(tile('inner_flow-1').classList.contains('is-empty')).toBe(true);
        expect(tile('inner_flow-1').querySelectorAll('[data-role="port"]')).toHaveLength(0);
        // Valid empty — no red tile, and it sits BELOW every tile (right after the wires layer).
        expect(tile('inner_flow-1').classList.contains('is-config-invalid')).toBe(false);
        expect(tile('inner_flow-1').previousElementSibling?.classList.contains('aaxis-flow-editor__wires')).toBe(true);
        expect(configOf(editor, 'inner_flow-1')).toBeNull();
    });

    it('places dropped elements in a row, before or after the elements they land next to, chained automatically', () => {
        const editor = createEditor();
        dropFromToolbox('inner_flow', 140, 140); // box at (100, 100)

        dropFromToolbox('dwl_transform', 150, 180);
        expect(children(editor, 'inner_flow-1')).toEqual(['dwl_transform-1']);
        expect(positionOf('dwl_transform-1')).toEqual([140, 140]);
        expect(sizeOf('inner_flow-1')).toEqual([160, 160]);
        expect(tile('inner_flow-1').classList.contains('is-empty')).toBe(false);

        // Right of the first element's center → after it; the box grows to hold the row.
        dropFromToolbox('logger', 250, 180);
        expect(children(editor, 'inner_flow-1')).toEqual(['dwl_transform-1', 'logger-1']);
        expect(sizeOf('inner_flow-1')).toEqual([280, 160]);
        expect(positionOf('logger-1')).toEqual([260, 140]);

        // Left of the first element's center → before it.
        dropFromToolbox('choice', 120, 230);
        expect(children(editor, 'inner_flow-1')).toEqual(['choice-1', 'dwl_transform-1', 'logger-1']);
        expect(positionOf('choice-1')).toEqual([140, 140]);
        expect(positionOf('dwl_transform-1')).toEqual([260, 140]);
        expect(positionOf('logger-1')).toEqual([380, 140]);
        // The Choice keeps a slot for its "false" lane on a row below: the box grows a row.
        expect(sizeOf('inner_flow-1')).toEqual([400, 280]);

        // Entry arrow + one arrow per pair (green after the Choice: its match), then the Choice's
        // red line down into its (empty) false slot.
        expect(innerWires('inner_flow-1')).toEqual([
            {d: 'M 6 80 L 38 80', branch: undefined},
            {d: 'M 120 80 L 158 80', branch: 'matched'},
            {d: 'M 240 80 L 278 80', branch: undefined},
            {d: 'M 80 120 L 80 158', branch: 'not-matched'}
        ]);
        expect($(tile('inner_flow-1')).find('[data-role="inner-slot"]')).toHaveLength(1);
        // Elements are wired automatically: no flow lines, ports hidden, new configs still red.
        expect(linksOf(editor)).toEqual([]);
        expect(tile('logger-1').classList.contains('is-inner-child')).toBe(true);
        expect(tile('choice-1').classList.contains('is-config-invalid')).toBe(true);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
    });

    it('keeps triggers and inner flows out of a box', () => {
        const editor = createEditor();
        dropFromToolbox('inner_flow', 140, 140);

        dropFromToolbox('cron', 150, 180);
        dropFromToolbox('inner_flow', 150, 180);

        expect(tileNames()).toEqual(['inner_flow-1']);
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('warning', KEY('inner_flow_not_allowed'));

        // Dragging a placed trigger over the box sends it back where it started.
        dropFromToolbox('cron', 600, 140);
        dragTo('schedule-1', 150, 180);
        expect(positionOf('schedule-1')).toEqual([560, 100]);
        expect(children(editor, 'inner_flow-1')).toEqual([]);
    });

    it('takes a dragged step in at the drop spot, dropping its own flow lines', () => {
        const editor = createEditor({flow: flowRecord(7, 'Main', [
            st('t1', 'cron', 'nightly', 0, 0, CRON),
            st('d1', 'dwl_transform', 'shape', 500, 0, DWL),
            st('b1', 'inner_flow', 'box', 100, 300, {children: ['l1']}),
            st('l1', 'logger', 'log', 0, 0, {message: 'hi'})
        ], [{from: 't1', fromPort: 0, to: 'd1'}])});
        expect(positionOf('log')).toEqual([140, 340]);

        // Highlighted as the target while dragged over, then inserted before "log".
        const r = tile('shape').getBoundingClientRect();
        pointer(tile('shape'), 'pointerdown', r.left + 10, r.top + 10);
        pointer(window, 'pointermove', ...client(120, 380));
        expect(tile('box').classList.contains('is-drop-target')).toBe(true);
        pointer(window, 'pointerup', ...client(120, 380));

        expect(tile('box').classList.contains('is-drop-target')).toBe(false);
        expect(children(editor, 'box')).toEqual(['shape', 'log']);
        expect(positionOf('shape')).toEqual([140, 340]);
        expect(positionOf('log')).toEqual([260, 340]);
        expect(linksOf(editor)).toEqual([]);
    });

    it('re-orders an element dragged within its box and ignores a plain click', () => {
        const editor = createEditor({flow: innerFlowRecord({
            boxChildren: ['d1', 'l1', 'e1'],
            more: [st('e1', 'event', 'evt', 0, 0, {value: 'x'})]
        })});
        expect(children(editor, 'box')).toEqual(['shape', 'log', 'evt']);

        const r = tile('shape').getBoundingClientRect();
        pointer(tile('shape'), 'pointerdown', r.left + 10, r.top + 10);
        pointer(window, 'pointerup', r.left + 10, r.top + 10);
        expect(children(editor, 'box')).toEqual(['shape', 'log', 'evt']);

        // Released between "log" (center 300) and "evt" (center 420): after "log".
        dragTo('shape', 330, 380);
        expect(children(editor, 'box')).toEqual(['log', 'shape', 'evt']);
        expect(positionOf('log')).toEqual([140, 340]);
        expect(positionOf('shape')).toEqual([260, 340]);
        expect(selectedNames()).toEqual(['shape']);
    });

    it('drops an element dragged out of its box onto the canvas, unconnected', () => {
        const editor = createEditor({flow: innerFlowRecord()});
        expect(sizeOf('box')).toEqual([280, 160]);

        dragTo('log', 700, 100);

        expect(children(editor, 'box')).toEqual(['shape']);
        expect(sizeOf('box')).toEqual([160, 160]);
        expect(positionOf('log')).toEqual([690, 90]);
        expect(tile('log').classList.contains('is-inner-child')).toBe(false);
        expect(linksOf(editor)).toEqual(['nightly:0->call']);
    });

    it('moves its elements along when the box is dragged', () => {
        createEditor({flow: innerFlowRecord()});

        dragTile('box', 200, 100);

        expect(positionOf('box')).toEqual([300, 400]);
        expect(positionOf('shape')).toEqual([340, 440]);
        expect(positionOf('log')).toEqual([460, 440]);
    });

    it('refuses flow lines into a box or its elements', () => {
        const editor = createEditor({flow: innerFlowRecord({links: []})});

        dragPort('nightly', 0, ...centerOf('box').map((v, i) => v - (i === 0 ? 100 : 50)) as [number, number]);
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('warning', KEY('link_target_inner_flow'));
        const [sx, sy] = centerOf('shape');
        dragPort('nightly', 0, sx - 100, sy - 50);
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('warning', KEY('link_target_inner_child'));
        expect(linksOf(editor)).toEqual([]);
    });

    it('asks before removing a box with elements, then removes everything inside it and turns its callers red', () => {
        const editor = createEditor({flow: innerFlowRecord()});
        expect(tile('call').classList.contains('is-config-invalid')).toBe(false);
        const all = tileNames();

        // Cancel keeps everything.
        contextMenu(tile('box'));
        clickMenu(KEY('menu_remove'));
        const asked = FlowDialog.last();
        expect(asked.options.title).toBe(KEY('inner_flow_remove_title'));
        expect(asked.$content.find('.aaxis-ontology-confirm__q').text()).toBe(KEY('inner_flow_remove_confirm'));
        expect(tileNames()).toEqual(all);
        asked.$content.find('.btn:not(.btn-primary)').trigger('click');
        expect(asked.closed).toBe(true);
        expect(tileNames()).toEqual(all);

        contextMenu(tile('box'));
        clickMenu(KEY('menu_remove'));
        FlowDialog.last().$content.find('.btn-primary').trigger('click');

        expect(tileNames()).toEqual(['nightly', 'call']);
        expect(tile('call').classList.contains('is-config-invalid')).toBe(true);
        expect(configOf(editor, 'call')).toEqual({inner: 'b1'});
    });

    it('removes an empty box, or several boxes after one question naming them all', () => {
        const editor = createEditor({flow: innerFlowRecord({more: [
            st('b2', 'inner_flow', 'empty', 600, 300, {children: []}),
            st('b3', 'inner_flow', 'other', 600, 600, {children: ['x1']}),
            st('x1', 'logger', 'quiet', 0, 0, {message: 'x'})
        ]})});
        const dialogs = FlowDialog.instances.length;

        contextMenu(tile('empty'));
        clickMenu(KEY('menu_remove'));
        expect(FlowDialog.instances.length).toBe(dialogs);
        expect(tileNames()).not.toContain('empty');

        editor.select([tile('box'), tile('other')].map(el => editor.steps.find((s: any) => s.el === el)));
        contextMenu(tile('other'));
        clickMenu(KEY('menu_remove'));
        expect(FlowDialog.last().$content.find('.aaxis-ontology-confirm__q').text()).toBe(KEY('inner_flows_remove_confirm'));
        FlowDialog.last().$content.find('.btn-primary').trigger('click');
        expect(tileNames()).toEqual(['nightly', 'call']);
    });

    it('takes a removed element out of its box', () => {
        const editor = createEditor({flow: innerFlowRecord()});

        contextMenu(tile('shape'));
        clickMenu(KEY('menu_remove'));

        expect(children(editor, 'box')).toEqual(['log']);
        expect(positionOf('log')).toEqual([140, 340]);
    });

    it('grays a box out until a reachable Call Subflow / Foreach Loop invokes it', () => {
        createEditor({flow: innerFlowRecord({
            more: [
                st('b2', 'inner_flow', 'nested', 600, 300, {children: ['f1']}),
                st('f1', 'foreach', 'each', 0, 0, {inner: 'b1', array: 'payload', item: 'row'}),
                st('b3', 'inner_flow', 'orphan', 600, 600, {children: ['x1']}),
                st('x1', 'logger', 'quiet', 0, 0, {message: 'x'})
            ],
            callConfig: {inner: 'b2'}
        })});
        const gray = (name: string): boolean => tile(name).classList.contains('is-unreachable');

        // call → nested → each → box: reachability follows the invocations.
        expect(['call', 'nested', 'each', 'box', 'shape', 'log'].map(gray)).toEqual([false, false, false, false, false, false]);
        expect(['orphan', 'quiet'].map(gray)).toEqual([true, true]);
    });

    it('rebuilds boxes from a stored design, dropping what an inner flow cannot hold', () => {
        const editor = createEditor({flow: flowRecord(7, 'Main', [
            st('t1', 'cron', 'nightly', 0, 0, CRON),
            st('b1', 'inner_flow', 'box', 100, 300, {children: ['d1', 'gone', 't1', 'd1', 'b2']}),
            st('b2', 'inner_flow', 'other', 600, 300, {children: ['d1', 'l1']}),
            st('d1', 'dwl_transform', 'shape', 0, 0, DWL),
            st('l1', 'logger', 'log', 900, 900, {message: 'hi'})
        ], [{from: 't1', fromPort: 0, to: 'd1'}, {from: 'd1', fromPort: 0, to: 'l1'}])});

        expect(children(editor, 'box')).toEqual(['shape']);
        expect(children(editor, 'other')).toEqual(['log']);
        expect(positionOf('shape')).toEqual([140, 340]);
        expect(positionOf('log')).toEqual([640, 340]);
        expect(linksOf(editor)).toEqual([]);
    });

    it('lays boxes out by their real width on Organize, elements inside', () => {
        createEditor({flow: innerFlowRecord({links: []})});

        $('[data-role="organize"]').trigger('click');

        // Reading order: nightly, box (x 100), call (x 200) — the box is 280 wide.
        expect(positionOf('nightly')).toEqual([40, 40]);
        expect(positionOf('box')).toEqual([200, 40]);
        expect(positionOf('shape')).toEqual([240, 80]);
        expect(positionOf('call')).toEqual([560, 40]);
    });

    it('offers navigation between a box and its callers instead of anchors and Start here', () => {
        createEditor({flow: innerFlowRecord({links: []})});

        contextMenu(tile('call'));
        expect(menuLabels()).toContain(KEY('menu_navigate_inner_flow'));
        clickMenu(KEY('menu_navigate_inner_flow'));
        expect(selectedNames()).toEqual(['box']);

        contextMenu(tile('box'));
        expect(menuLabels()).toEqual([KEY('menu_remove'), KEY('menu_copy'), KEY('menu_called_by')]);
        expect(submenu(KEY('menu_called_by')).map(c => c.label)).toEqual(['call']);
        clickSubmenu(KEY('menu_called_by'), 'call');
        expect(selectedNames()).toEqual(['call']);

        contextMenu(tile('shape'));
        expect(menuLabels()).toEqual([KEY('menu_remove'), KEY('menu_copy'), KEY('menu_breakpoint')]);
    });

    it('says when nothing calls a box', () => {
        createEditor({flow: innerFlowRecord({callConfig: {subflow: 3}})});

        contextMenu(tile('box'));

        expect(submenu(KEY('menu_called_by'))).toEqual([{label: KEY('menu_inner_called_by_none'), disabled: true, active: false}]);
    });
});

describe('OntologyFlowEditorComponent — a Choice\'s false lane inside an inner flow', () => {
    useFlowEditorEnv(FlowEditorComponent);

    /** Box "box" at (100, 300) holding `children` (+ `branches`) over the given elements. */
    function boxFlow(children: string[], branches: Record<string, string[]> | null, elements: any[]): any {
        return flowRecord(7, 'Main', [
            st('t1', 'cron', 'nightly', 0, 0, CRON),
            st('b1', 'inner_flow', 'box', 100, 300, {children, ...(branches ? {branches} : {})}),
            ...elements
        ]);
    }

    const gate = (id: string, name: string): any => st(id, 'choice', name, 0, 0, {expression: 'payload'});
    const log = (id: string, name: string): any => st(id, 'logger', name, 0, 0, {message: 'x'});
    const branchesOf = (editor: any): Record<string, string[]> => {
        const steps = designOf(editor).steps;
        const byId: Record<string, string> = {};
        steps.forEach((s: any) => {
            byId[s.id] = s.name;
        });
        const branches = steps.find((s: any) => s.name === 'box').config.branches || {};
        const named: Record<string, string[]> = {};
        Object.entries(branches).forEach(([k, ids]) => {
            named[byId[k]] = (ids as string[]).map(id => byId[id]);
        });
        return named;
    };

    it('lays a false lane out on the row below its Choice, with the red line into it', () => {
        createEditor({flow: boxFlow(['g1', 'l1'], {g1: ['x1', 'x2']}, [gate('g1', 'gate'), log('l1', 'ok'), log('x1', 'no'), log('x2', 'no more')])});

        // Row 0: gate, ok — row 1 (one tile + pad lower), from the gate's column: no, no more.
        expect(positionOf('gate')).toEqual([140, 340]);
        expect(positionOf('ok')).toEqual([260, 340]);
        expect(positionOf('no')).toEqual([140, 460]);
        expect(positionOf('no more')).toEqual([260, 460]);
        expect(sizeOf('box')).toEqual([280, 280]);
        expect(innerWires('box')).toEqual([
            {d: 'M 6 80 L 38 80', branch: undefined},
            {d: 'M 120 80 L 158 80', branch: 'matched'},
            {d: 'M 80 120 L 80 158', branch: 'not-matched'},
            {d: 'M 120 200 L 158 200', branch: undefined}
        ]);
        expect($(tile('box')).find('[data-role="inner-slot"]')).toHaveLength(0);
    });

    it('drops into the false slot, then before/after the lane\'s elements', () => {
        const editor = createEditor({flow: boxFlow(['g1'], null, [gate('g1', 'gate')])});

        dropFromToolbox('logger', 150, 470); // the empty slot below the gate
        expect(children(editor, 'box')).toEqual(['gate']);
        expect(branchesOf(editor)).toEqual({gate: ['logger-1']});
        expect(positionOf('logger-1')).toEqual([140, 460]);

        dropFromToolbox('event', 250, 470); // right of the lane's last element → appended
        dropFromToolbox('email', 130, 470); // before its first element
        expect(branchesOf(editor)).toEqual({gate: ['email-1', 'logger-1', 'event-1']});
        // The main row is unaffected; the box spans the longer lane.
        expect(sizeOf('box')).toEqual([400, 280]);
    });

    it('nests: a Choice in a false lane gets its own lane below', () => {
        createEditor({flow: boxFlow(['g1'], {g1: ['g2'], g2: ['x1']}, [gate('g1', 'gate'), gate('g2', 'inner gate'), log('x1', 'deep')])});

        expect(positionOf('inner gate')).toEqual([140, 460]);
        expect(positionOf('deep')).toEqual([140, 580]);
        expect(sizeOf('box')).toEqual([160, 400]);
    });

    it('keeps lanes apart: a lane to the left drops below the ones to its right', () => {
        createEditor({flow: boxFlow(['g1', 'g2'], {g1: ['a1', 'a2'], g2: ['r1', 'r2']},
            [gate('g1', 'left'), gate('g2', 'right'), log('a1', 'a1'), log('a2', 'a2'), log('r1', 'b1'), log('r2', 'b2')])});

        expect(positionOf('b1')).toEqual([260, 460]); // right's lane: row 1, from its column
        expect(positionOf('a1')).toEqual([140, 580]); // left's lane would overlap row 1 → row 2
        expect(positionOf('a2')).toEqual([260, 580]);
        expect(sizeOf('box')).toEqual([400, 400]);
    });

    it('moves an element between the main row and a false lane', () => {
        const editor = createEditor({flow: boxFlow(['g1', 'l1'], null, [gate('g1', 'gate'), log('l1', 'ok')])});

        dragTo('ok', 150, 470);
        expect(children(editor, 'box')).toEqual(['gate']);
        expect(branchesOf(editor)).toEqual({gate: ['ok']});

        dragTo('ok', 250, 350);
        expect(children(editor, 'box')).toEqual(['gate', 'ok']);
        expect(designOf(editor).steps.find((s: any) => s.name === 'box').config).toEqual({children: ['g1', 'l1']});
    });

    it('moves a Choice with its false lane within the box, but never into that lane', () => {
        const editor = createEditor({flow: boxFlow(['l1', 'g1'], {g1: ['x1']}, [log('l1', 'first'), gate('g1', 'gate'), log('x1', 'no')])});

        dragTo('gate', 130, 350); // before "first"
        expect(children(editor, 'box')).toEqual(['gate', 'first']);
        expect(branchesOf(editor)).toEqual({gate: ['no']});
        expect(positionOf('no')).toEqual([140, 460]);

        dragTo('gate', 300, 470); // after "no" — its own false lane
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('warning', KEY('inner_flow_branch_cycle'));
        expect(children(editor, 'box')).toEqual(['gate', 'first']);
        expect(positionOf('gate')).toEqual([140, 340]);
    });

    it('lets a removed or departing Choice\'s false lane take its place', () => {
        const editor = createEditor({flow: boxFlow(['l1', 'g1', 'l2'], {g1: ['x1', 'x2']},
            [log('l1', 'a'), gate('g1', 'gate'), log('l2', 'z'), log('x1', 'no'), log('x2', 'no more')])});

        dragTo('gate', 800, 100); // out of the box
        expect(children(editor, 'box')).toEqual(['a', 'no', 'no more', 'z']);
        expect(branchesOf(editor)).toEqual({});
        expect(tile('gate').classList.contains('is-inner-child')).toBe(false);
    });

    it('keeps the false lanes through the box\'s settings and a copy / paste', () => {
        const editor = createEditor({flow: boxFlow(['g1'], {g1: ['x1']}, [gate('g1', 'gate'), log('x1', 'no')])});

        openSettings('box');
        confirmSettings();
        expect(branchesOf(editor)).toEqual({gate: ['no']});

        const doc = {
            format: 'aaxis-ontology-flow-steps', version: 1, links: [],
            steps: [
                {id: 'b1', type: 'inner_flow', name: 'box', x: 0, y: 0, config: {children: ['g1'], branches: {g1: ['x1'], gone: ['x1']}}},
                {id: 'g1', type: 'choice', name: 'gate', x: 0, y: 0, config: {expression: 'payload'}},
                {id: 'x1', type: 'logger', name: 'no', x: 0, y: 0, config: {message: 'x'}}
            ]
        };
        const ev = new Event('paste', {bubbles: true, cancelable: true}) as any;
        ev.clipboardData = {getData: () => JSON.stringify(doc)};
        pointer(document.querySelector('[data-role="canvas-viewport"]') as HTMLElement, 'pointermove', ...client(600, 100));
        document.dispatchEvent(ev);

        const pasted = designOf(editor).steps.find((s: any) => s.name === 'box (copy)');
        const byName = (name: string): string => designOf(editor).steps.find((s: any) => s.name === name).id;
        expect(pasted.config).toEqual({children: [byName('gate (copy)')], branches: {[byName('gate (copy)')]: [byName('no (copy)')]}});
    });

    it('drops false lanes a stored design cannot hold', () => {
        const editor = createEditor({flow: boxFlow(['l1'], {l1: ['x1'], nope: ['x2']}, [log('l1', 'not a choice'), log('x1', 'a'), log('x2', 'b')])});

        expect(designOf(editor).steps.find((s: any) => s.name === 'box').config).toEqual({children: ['l1']});
        expect(tile('a').classList.contains('is-inner-child')).toBe(false);
    });
});

describe('OntologyFlowEditorComponent — Inner Flow settings and pickers', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const FLOWS = {records: [
        {id: 20, name: 'Zeta', type: 'subflow'},
        {id: 7, name: 'Main', type: 'flow'}
    ]};

    function optionTexts(): string[] {
        return $('.aaxis-flow-editor__combo-option').map((_i: number, el: HTMLElement) => el.textContent).get();
    }

    function pick(text: string): void {
        const option = $('.aaxis-flow-editor__combo-option').filter((_i: number, el: HTMLElement) => el.textContent === text)[0];
        option.dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));
    }

    it('edits only the name of a box, keeping its elements (no Log event switch)', async () => {
        const editor = createEditor({flow: innerFlowRecord()});
        await flush();

        const $panel = openSettings('box');
        expect($panel.find('.aaxis-flow-editor__settings-hint').text()).toBe(KEY('inner_flow_hint'));
        expect($panel.find('.aaxis-flow-editor__settings-actions input[type="checkbox"]')).toHaveLength(0);
        $panel.find('input.form-control').first().val('renamed');
        confirmSettings();

        expect(children(editor, 'renamed')).toEqual(['shape', 'log']);
        expect(configOf(editor, 'renamed')).toEqual({children: ['d1', 'l1']});
    });

    it('lists the inner flows first in Call Subflow and stores the pick as `inner`', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        const editor = createEditor({flow: innerFlowRecord({
            callConfig: {subflow: 20},
            more: [
                st('b2', 'inner_flow', 'another', 600, 300, null),
                st('c2', 'sub_flow', 'inside', 0, 0, null)
            ],
            boxChildren: ['d1', 'l1', 'c2']
        })});
        await flush();

        openSettings('call');
        await flush();
        $('.aaxis-flow-editor__combo input').trigger('focus').val('').trigger('input');
        expect(optionTexts()).toEqual([
            'another (inner flow)',
            'box (inner flow)',
            'Zeta'
        ]);
        pick('box (inner flow)');
        confirmSettings();
        expect(configOf(editor, 'call')).toEqual({inner: 'b1'});

        // A step INSIDE a box is never offered its own box (it would call itself).
        openSettings('inside');
        await flush();
        $('.aaxis-flow-editor__combo input').last().trigger('focus');
        expect(optionTexts()).toEqual(['another (inner flow)', 'Zeta']);
    });

    it('shows a saved inner-flow pick and switches back to a subflow', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        const editor = createEditor({flow: innerFlowRecord()});
        await flush();

        openSettings('call');
        await flush();
        const $filter = $('.aaxis-flow-editor__combo input');
        expect($filter.val()).toBe('box (inner flow)');
        $filter.trigger('focus').val('').trigger('input');
        pick('Zeta');
        confirmSettings();

        expect(configOf(editor, 'call')).toEqual({subflow: '20'});
    });

    it('offers inner flows to Foreach Loop too', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        const editor = createEditor({flow: innerFlowRecord({
            more: [st('f1', 'foreach', 'each', 400, 0, {subflow: 20, array: 'payload', item: 'row'})]
        })});
        await flush();

        openSettings('each');
        await flush();
        const $filter = $('.aaxis-flow-editor__combo input');
        expect($filter.val()).toBe('Zeta');
        $filter.trigger('focus').val('').trigger('input');
        expect(optionTexts()).toEqual(['box (inner flow)', 'Zeta']);
        pick('box (inner flow)');
        confirmSettings();

        expect(configOf(editor, 'each')).toEqual({inner: 'b1', array: 'payload', item: 'row'});
    });

    it('still offers the inner flows when the flow catalog fails', async () => {
        mockFetch({aaxis_ontology_flow_list: new Error('down')});
        createEditor({flow: innerFlowRecord({callConfig: null})});
        await flush();

        openSettings('call');
        await flush();
        const $filter = $('.aaxis-flow-editor__combo input');

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', KEY('catalog_load_error'));
        expect($filter.prop('disabled')).toBe(false);
        $filter.trigger('focus');
        expect(optionTexts()).toEqual(['box (inner flow)']);
    });

    it('paints a Call Subflow pointing at a vanished inner flow red on load', () => {
        createEditor({flow: innerFlowRecord({callConfig: {inner: 'nope'}})});

        expect(tile('call').classList.contains('is-config-invalid')).toBe(true);
    });
});

describe('OntologyFlowEditorComponent — Inner Flow debugging', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const tick = (extra: Record<string, any> = {}): any => ({body: {
        success: true, context: {}, contextKey: 'k1', index: 0, total: 3, done: false,
        step: {id: 't1', name: 'nightly'}, executedIds: ['t1'], next: null,
        frame: {flowId: 7, flowName: 'Main', depth: 0, canvasDepth: 0, innerId: null}, transition: null, ...extra
    }});
    const marked = (cls: string): string[] =>
        $(`[data-role="step"].${cls} .aaxis-flow-editor__step-name`).map((_i: number, el: HTMLElement) => el.textContent).get();

    it('paints an inner frame on the debugged canvas and wipes only the box on a new iteration', async () => {
        let n = 0;
        const replies = [
            tick({next: {id: 'c1', name: 'call', type: 'sub_flow'}}),
            tick({step: {id: 'b1', name: 'box'}, executedIds: ['b1'], transition: 'entered',
                frame: {flowId: 7, flowName: 'box', depth: 1, canvasDepth: 0, innerId: 'b1'},
                next: {id: 'd1', name: 'shape', type: 'dwl_transform'}}),
            tick({step: {id: 'd1', name: 'shape'}, executedIds: ['d1'],
                frame: {flowId: 7, flowName: 'box', depth: 1, canvasDepth: 0, innerId: 'b1'}}),
            tick({step: {id: 'b1', name: 'box'}, executedIds: ['b1'], transition: 'reentered', iteration: 1,
                frame: {flowId: 7, flowName: 'box', depth: 1, canvasDepth: 0, innerId: 'b1'}})
        ];
        const spy = mockFetch({aaxis_ontology_flow_debug_step: () => replies[Math.min(n++, replies.length - 1)]});
        const editor = createEditor({flow: innerFlowRecord()});
        await flush();

        root(editor).find('[data-role="debug-step"]').trigger('click');
        await flush();
        expect(marked('is-debug-executed')).toEqual(['nightly']);

        const step = (): void => {
            $(`.aaxis-flow-editor__debug-btn[aria-label="${KEY('debug_step_into')}"]`).prop('disabled', false).trigger('click');
        };
        step();
        await flush();
        step();
        await flush();
        // Still on the one tab: the inner frame paints the debugged canvas.
        expect($('[data-role="tab"]')).toHaveLength(1);
        expect(marked('is-debug-executed').sort()).toEqual(['box', 'nightly', 'shape']);
        expect($('.aaxis-flow-editor__debugger-status').text()).toContain('box: shape');

        step();
        await flush();
        // A new iteration clears the box only — the caller's marks stay.
        expect(marked('is-debug-executed').sort()).toEqual(['box', 'nightly']);
        expect(sentTo(spy, 'aaxis_ontology_flow_debug_step')[0].steps.find((s: any) => s.id === 'b1').config)
            .toEqual({children: ['d1', 'l1']});
    });
});

describe('OntologyFlowEditorComponent — Inner Flow copy / paste', () => {
    useFlowEditorEnv(FlowEditorComponent);

    function paste(text: string, x: number, y: number): void {
        const ev = new Event('paste', {bubbles: true, cancelable: true}) as any;
        ev.clipboardData = {getData: () => text};
        pointer(document.querySelector('[data-role="canvas-viewport"]') as HTMLElement, 'pointermove', ...client(x, y));
        document.dispatchEvent(ev);
    }

    function copy(): string {
        let text = '';
        const ev = new Event('copy', {bubbles: true, cancelable: true}) as any;
        ev.clipboardData = {setData: (_type: string, value: string) => {
            text = value;
        }};
        document.dispatchEvent(ev);
        return text;
    }

    it('copies a selected box with its elements and pastes a re-pointed copy', () => {
        const editor = createEditor({flow: innerFlowRecord({links: []})});

        pointer(tile('box'), 'pointerdown', ...client(110, 310));
        pointer(window, 'pointerup', ...client(110, 310));
        const doc = JSON.parse(copy());
        expect(doc.steps.map((s: any) => s.name)).toEqual(['box', 'shape', 'log']);

        paste(JSON.stringify(doc), 100, 600);

        expect(children(editor, 'box (copy)')).toEqual(['shape (copy)', 'log (copy)']);
        expect(positionOf('box (copy)')).toEqual([100, 600]);
        expect(positionOf('shape (copy)')).toEqual([140, 640]);
        expect(children(editor, 'box')).toEqual(['shape', 'log']);
        expect(idOf(editor, 'box (copy)')).not.toBe('b1');
        expect(selectedNames().sort()).toEqual(['box (copy)', 'log (copy)', 'shape (copy)']);
    });

    it('re-points a pasted caller at the pasted box, keeps it on the original, or clears it', () => {
        const editor = createEditor({flow: innerFlowRecord({links: []})});
        const boxAndCaller = {
            format: 'aaxis-ontology-flow-steps', version: 1, links: [],
            steps: [
                {id: 'c1', type: 'sub_flow', name: 'call', x: 0, y: 0, config: {inner: 'b1'}},
                {id: 'b1', type: 'inner_flow', name: 'box', x: 0, y: 200, config: {children: []}}
            ]
        };
        paste(JSON.stringify(boxAndCaller), 100, 600);
        expect(configOf(editor, 'call (copy)')).toEqual({inner: idOf(editor, 'box (copy)')});

        // Only the caller: its target is the original box on this canvas.
        paste(JSON.stringify({...boxAndCaller, steps: [boxAndCaller.steps[0]]}), 600, 600);
        expect(configOf(editor, 'call (copy 2)')).toEqual({inner: 'b1'});

        // A target that does not exist here: cleared, red.
        paste(JSON.stringify({...boxAndCaller, steps: [{...boxAndCaller.steps[0], config: {inner: 'elsewhere'}}]}), 600, 800);
        expect(configOf(editor, 'call (copy 3)')).toEqual({});
        expect(tile('call (copy 3)').classList.contains('is-config-invalid')).toBe(true);
    });
});


import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    client, createEditor, design, designOf, dragPort, flowRecord, linksOf, pointer, root, st, tile, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

/** The rendered wire groups: from/port/to plus the visible path. */
function wires(): Array<{from: string; port: string; to: string; branch: string | null; d: string; marker: string | null}> {
    return Array.from(document.querySelectorAll<SVGGElement>('[data-role="wire-group"]')).map(g => {
        const path = g.querySelector('[data-role="wire"]')!;
        return {
            from: g.getAttribute('data-from')!,
            port: g.getAttribute('data-from-port')!,
            to: g.getAttribute('data-to')!,
            branch: g.getAttribute('data-branch'),
            d: path.getAttribute('d')!,
            marker: path.getAttribute('marker-end')
        };
    });
}

/** The polyline corners of an SVG path made of M/L commands (arcs skipped). */
function corners(d: string): Array<[number, number]> {
    return Array.from(d.matchAll(/[ML] (-?[\d.]+) (-?[\d.]+)/g)).map(m => [Number(m[1]), Number(m[2])] as [number, number]);
}

/** The arrow-head grip of the wire leaving the step with this id. */
function grip(fromId: string): Element {
    return document.querySelector(`[data-role="wire-group"][data-from="${fromId}"] [data-role="wire-end"]`)!;
}

/** Grabs a wire's arrow head and releases it at the viewport point. */
function relink(fromId: string, clientX: number, clientY: number, opts: {button?: number} = {}): void {
    const grip = document.querySelector(`[data-role="wire-group"][data-from="${fromId}"] [data-role="wire-end"]`)!;
    pointer(grip, 'pointerdown', clientX, clientY, opts);
    pointer(window, 'pointermove', clientX, clientY);
    pointer(window, 'pointerup', clientX, clientY);
}

describe('OntologyFlowEditorComponent — drawing links', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const base = (): any => flowRecord(1, 'F', [
        st('t', 'cron', 'tick', 0, 0, {enabled: true}),
        st('a', 'logger', 'a', 300, 0),
        st('b', 'logger', 'b', 300, 300),
        st('c', 'choice', 'pick', 0, 300, {expression: 'x'})
    ]);

    it('drags a live wire from a port and links it on release over a valid step', () => {
        const editor = createEditor({flow: base()});
        const port = tile('tick').querySelector('[data-role="port"]')!;

        pointer(port, 'pointerdown', ...client(80, 40));
        pointer(window, 'pointermove', ...client(340, 40));
        const temp = document.querySelector('[data-role="wire-temp"]')!;
        expect(temp.getAttribute('marker-end')).toBe('url(#aaxis-flow-arrow)');
        expect(temp.getAttribute('d')).toBe('M 80 40 C 210 40, 210 40, 340 40');
        expect(tile('a').classList.contains('is-link-target')).toBe(true);
        pointer(window, 'pointermove', ...client(340, 340));
        expect(tile('a').classList.contains('is-link-target')).toBe(false);
        expect(tile('b').classList.contains('is-link-target')).toBe(true);
        pointer(window, 'pointerup', ...client(340, 340));

        expect(document.querySelector('[data-role="wire-temp"]')).toBeNull();
        expect(tile('b').classList.contains('is-link-target')).toBe(false);
        expect(linksOf(editor)).toEqual(['tick:0->b']);
        expect(tile('b').classList.contains('is-unreachable')).toBe(false);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
    });

    it('re-wires a used port instead of adding a second line', () => {
        const editor = createEditor({flow: base()});

        dragPort('tick', 0, 340, 40);
        dragPort('tick', 0, 340, 340);

        expect(linksOf(editor)).toEqual(['tick:0->b']);
    });

    it('refuses a trigger or an already-fed step as target, with a warning', () => {
        const editor = createEditor({flow: base()});
        dragPort('tick', 0, 340, 40);

        dragPort('pick', 0, 40, 40);
        expect(messenger.notificationFlashMessage).toHaveBeenLastCalledWith('warning', 'aaxis.ontology.flow_editor.link_target_trigger');
        dragPort('pick', 0, 340, 40);
        expect(messenger.notificationFlashMessage).toHaveBeenLastCalledWith('warning', 'aaxis.ontology.flow_editor.link_target_used');

        expect(linksOf(editor)).toEqual(['tick:0->a']);
    });

    it('does nothing when released on the empty canvas', () => {
        const editor = createEditor({flow: base()});

        dragPort('tick', 0, 700, 500);

        expect(linksOf(editor)).toEqual([]);
        expect(messenger.notificationFlashMessage).not.toHaveBeenCalled();
    });

    it('treats the start marker as the step\'s one incoming line', () => {
        const editor = createEditor({flow: {id: 2, name: 'S', enabled: true, type: 'subflow', steps: [],
            design: design([st('a', 'logger', 'a', 0, 0), st('b', 'logger', 'b', 300, 0)], [], 'b')}});

        dragPort('a', 0, 340, 40);

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('warning', 'aaxis.ontology.flow_editor.link_target_used');
        expect(linksOf(editor)).toEqual([]);
    });

    it('tints the choice branches: green for "matched", red for "not matched"', () => {
        createEditor({flow: base()});
        const falsePort = tile('pick').querySelector('[data-port="1"]')!;
        pointer(falsePort, 'pointerdown', ...client(40, 380));
        pointer(window, 'pointermove', ...client(340, 340));
        const temp = document.querySelector('[data-role="wire-temp"]')!;
        expect(temp.getAttribute('data-branch')).toBe('not-matched');
        expect(temp.getAttribute('marker-end')).toBe('url(#aaxis-flow-arrow-red)');
        pointer(window, 'pointerup', ...client(340, 340));
        dragPort('pick', 0, 340, 40);

        const drawn = wires();
        expect(drawn.map(w => [w.port, w.branch, w.marker])).toEqual([
            ['1', 'not-matched', 'url(#aaxis-flow-arrow-red)'],
            ['0', 'matched', 'url(#aaxis-flow-arrow-green)']
        ]);
        expect(tile('pick').querySelector('[data-port="0"]')!.getAttribute('title')).toBe('aaxis.ontology.flow_editor.choice_port_matched');
        expect(tile('pick').querySelector('[data-port="0"]')!.className).toBe('aaxis-flow-editor__port aaxis-flow-editor__port--a');
        expect(tile('pick').querySelector('[data-port="1"]')!.className).toBe('aaxis-flow-editor__port aaxis-flow-editor__port--b');
        expect(tile('pick').querySelector('[data-port="1"]')!.getAttribute('title')).toBe('aaxis.ontology.flow_editor.choice_port_not_matched');
        expect(tile('tick').querySelector('[data-port="0"]')!.className).toBe('aaxis-flow-editor__port');
        expect(tile('tick').querySelector('[data-port="0"]')!.hasAttribute('title')).toBe(false);
        expect(document.querySelectorAll('marker[data-branch]')).toHaveLength(2);
    });

    it('moves a port\'s out side when the wire is released on its own tile', () => {
        const editor = createEditor({flow: base()});

        dragPort('tick', 0, 40, 78); // near the bottom edge
        expect(designOf(editor).steps[0].outSides).toEqual(['south']);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);

        dragPort('pick', 0, 40, 378); // south is the false port's side → unchanged
        expect(designOf(editor).steps[3].outSides).toBeUndefined();
        dragPort('pick', 0, 79, 340); // already east → unchanged
        expect(designOf(editor).steps[3].outSides).toBeUndefined();
        dragPort('pick', 1, 40, 302); // north is free
        expect(designOf(editor).steps[3].outSides).toEqual([undefined, 'north']);
    });

    it('ignores a secondary-button press on a port', () => {
        createEditor({flow: base()});
        const port = tile('tick').querySelector('[data-role="port"]')!;

        pointer(port, 'pointerdown', ...client(80, 40), {button: 2});

        expect(document.querySelector('[data-role="wire-temp"]')).toBeNull();
    });
});

describe('OntologyFlowEditorComponent — re-routing an existing link by its arrow head', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const linked = (): any => flowRecord(1, 'F', [
        st('t', 'cron', 'tick', 0, 0, {enabled: true}),
        st('a', 'logger', 'a', 300, 0),
        st('b', 'logger', 'b', 300, 300),
        st('x', 'logger', 'x', 600, 300)
    ], [{from: 't', fromPort: 0, to: 'a'}, {from: 'x', fromPort: 0, to: 'b'}]);

    it('pulls the link out while dragging and retargets it on a free step', () => {
        const editor = createEditor({flow: linked()});
        pointer(grip('t'), 'pointerdown', ...client(298, 40));
        expect(wires()).toHaveLength(1);
        expect(document.querySelector('[data-role="wire-temp"]')).not.toBeNull();
        pointer(window, 'pointermove', ...client(640, 340));
        pointer(window, 'pointerup', ...client(640, 340));

        expect(linksOf(editor)).toEqual(['x:0->b', 'tick:0->x']);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
    });

    it('deletes the link when dropped back on the step it starts from', () => {
        const editor = createEditor({flow: linked()});

        relink('t', ...client(40, 40));

        expect(linksOf(editor)).toEqual(['x:0->b']);
        expect(tile('a').classList.contains('is-unreachable')).toBe(true);
    });

    it('keeps the link and moves the target\'s in side when dropped on its current target', () => {
        const editor = createEditor({flow: linked()});

        relink('t', ...client(340, 2));

        expect(linksOf(editor)).toEqual(['x:0->b', 'tick:0->a']);
        expect(designOf(editor).steps[1].inSide).toBe('north');
        expect([grip('t').getAttribute('cx'), grip('t').getAttribute('cy')]).toEqual(['340', '-2']);
    });

    it('puts the link back unchanged on the background or on a step that cannot take it', () => {
        const editor = createEditor({flow: linked()});

        relink('t', ...client(800, 100));
        expect(linksOf(editor)).toEqual(['x:0->b', 'tick:0->a']);
        expect(messenger.notificationFlashMessage).not.toHaveBeenCalled();

        relink('t', ...client(340, 340)); // b already receives x's line
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('warning', 'aaxis.ontology.flow_editor.link_target_used');
        expect(linksOf(editor)).toEqual(['x:0->b', 'tick:0->a']);

        relink('x', ...client(40, 40)); // onto the trigger
        expect(messenger.notificationFlashMessage).toHaveBeenLastCalledWith('warning', 'aaxis.ontology.flow_editor.link_target_trigger');
        expect(linksOf(editor)).toEqual(['tick:0->a', 'x:0->b']);
    });

    it('ignores secondary buttons and grips without a matching link', () => {
        const editor = createEditor({flow: linked()});

        relink('t', ...client(40, 40), {button: 2});
        expect(linksOf(editor)).toHaveLength(2);

        const stale = grip('t');
        stale.parentElement!.setAttribute('data-to', 'nowhere');
        pointer(stale, 'pointerdown', ...client(40, 40));
        expect(document.querySelector('[data-role="wire-temp"]')).toBeNull();
    });
});

describe('OntologyFlowEditorComponent — wire routing', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const orthogonal = (points: Array<[number, number]>): boolean =>
        points.every((p, i) => i === 0 || p[0] === points[i - 1][0] || p[1] === points[i - 1][1]);

    it('routes a clear line straight from the port to the arrow tip', () => {
        createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 0, 200, {enabled: true}), st('a', 'logger', 'a', 400, 200)
        ], [{from: 't', fromPort: 0, to: 'a'}])});

        expect(wires()[0].d).toBe('M 80 240 L 398 240');
        const grip = document.querySelector('[data-role="wire-end"]')!;
        expect([grip.getAttribute('cx'), grip.getAttribute('cy'), grip.getAttribute('r')]).toEqual(['398', '240', '9']);
    });

    it('detours around a tile in the way with an orthogonal polyline', () => {
        createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 0, 200, {enabled: true}),
            st('m', 'logger', 'middle', 200, 200),
            st('a', 'logger', 'a', 400, 200)
        ], [{from: 't', fromPort: 0, to: 'a'}])});

        const d = wires()[0].d;
        const points = corners(d);
        expect(points[0]).toEqual([80, 240]);
        expect(points.at(-1)).toEqual([398, 240]);
        expect(orthogonal(points)).toBe(true);
        // No segment crosses the middle tile (200..280 × 200..280).
        const crosses = points.slice(1).some((p, i) => {
            const q = points[i];
            const [x1, x2] = [Math.min(p[0], q[0]), Math.max(p[0], q[0])];
            const [y1, y2] = [Math.min(p[1], q[1]), Math.max(p[1], q[1])];
            return x2 >= 200 && x1 <= 280 && y2 >= 200 && y1 <= 280;
        });
        expect(crosses).toBe(false);
        expect(d).toBe(ROUTE_DETOUR);
    });

    it('follows custom anchor sides on both ends', () => {
        createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 200, 0, {enabled: true}, {outSides: ['south']}),
            st('a', 'logger', 'a', 200, 300, null, {inSide: 'north'})
        ], [{from: 't', fromPort: 0, to: 'a'}])});

        expect(wires()[0].d).toBe('M 240 80 L 240 298');
    });

    it('jumps a horizontal run over another line\'s vertical run', () => {
        createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 0, 200, {enabled: true}),
            st('a', 'logger', 'a', 400, 200),
            st('c', 'logger', 'c', 200, 0, null, {outSides: ['south']}),
            st('d', 'logger', 'd', 200, 400, null, {inSide: 'north'})
        ], [{from: 't', fromPort: 0, to: 'a'}, {from: 'c', fromPort: 0, to: 'd'}])});

        const [horizontal, vertical] = wires();
        expect(horizontal.d).toBe('M 80 240 L 235 240 A 5 5 0 0 1 245 240 L 398 240');
        expect(vertical.d).toBe('M 240 80 L 240 398');
    });

    it('draws the jumps of a right-to-left run mirrored', () => {
        createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 400, 200, {enabled: true}, {outSides: ['west']}),
            st('a', 'logger', 'a', 0, 200, null, {inSide: 'east', outSides: ['west']}),
            st('c', 'logger', 'c', 200, 0, null, {outSides: ['south']}),
            st('d', 'logger', 'd', 200, 400, null, {inSide: 'north'})
        ], [{from: 't', fromPort: 0, to: 'a'}, {from: 'c', fromPort: 0, to: 'd'}])});

        expect(wires()[0].d).toBe('M 400 240 L 245 240 A 5 5 0 0 0 235 240 L 82 240');
    });

    it('falls back to a plain three-segment line when the canvas is jammed', () => {
        createEditor({flow: flowRecord(1, 'F', [
            st('s', 'cron', 'src', 300, 300, {enabled: true}),
            st('b', 'logger', 'blocker', 400, 300),
            st('t', 'logger', 'target', 700, 500)
        ], [{from: 's', fromPort: 0, to: 't'}])});

        expect(wires()[0].d).toBe('M 380 340 L 540 340 L 540 540 L 698 540');
    });

    it('draws the start marker as a short arrow into the step\'s in side', () => {
        createEditor({flow: {id: 2, name: 'S', enabled: true, type: 'subflow', steps: [],
            design: design([st('a', 'logger', 'a', 300, 300, null, {inSide: 'north'})], [], 'a')}});

        const arrow = document.querySelector('[data-role="start-group"] [data-role="wire"]')!;
        expect(arrow.getAttribute('d')).toBe('M 340 268 L 340 298');
        expect(arrow.getAttribute('marker-end')).toBe('url(#aaxis-flow-arrow)');
    });

    it('keeps the start arrow inside the canvas near its edge', () => {
        createEditor({flow: {id: 2, name: 'S', enabled: true, type: 'subflow', steps: [],
            design: design([st('a', 'logger', 'a', 0, 0)], [], 'a')}});

        expect(document.querySelector('[data-role="start-group"] [data-role="wire"]')!.getAttribute('d')).toBe('M 2 40 L -2 40');
    });
});

/** Pinned output of the A* router for the detour scenario (see the test above). */
const ROUTE_DETOUR = 'M 80 240 L 180 240 L 180 300 L 380 300 L 380 240 L 398 240';

describe('OntologyFlowEditorComponent — routing geometry helpers', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('A* finds the path with the fewest turns around a wall', () => {
        const editor = createEditor();
        const cols = 5;
        const rows = 3;
        const blocked = new Uint8Array(cols * rows);
        blocked[0 * cols + 2] = 1;
        blocked[1 * cols + 2] = 1;

        const path = editor.astar({cols, rows, blocked}, 0, 0, 4, 0);

        expect(path).toEqual([
            {c: 0, r: 0}, {c: 0, r: 1}, {c: 0, r: 2}, {c: 1, r: 2}, {c: 2, r: 2},
            {c: 3, r: 2}, {c: 4, r: 2}, {c: 4, r: 1}, {c: 4, r: 0}
        ]);
        expect(editor.astar({cols, rows, blocked}, 1, 1, 1, 1)).toEqual([{c: 1, r: 1}]);
    });

    it('A* gives up on an enclosed goal, but may start and end on blocked cells', () => {
        const editor = createEditor();
        const cols = 5;
        const rows = 5;
        const blocked = new Uint8Array(cols * rows);
        [[2, 1], [1, 2], [3, 2], [2, 3]].forEach(([c, r]) => {
            blocked[r * cols + c] = 1;
        });

        expect(editor.astar({cols, rows, blocked}, 0, 0, 2, 2)).toBeNull();
        blocked[0] = 1;
        blocked[4] = 1;
        expect(editor.astar({cols, rows, blocked}, 0, 0, 4, 0)).toEqual([
            {c: 0, r: 0}, {c: 1, r: 0}, {c: 2, r: 0}, {c: 3, r: 0}, {c: 4, r: 0}
        ]);
    });

    it('computes the proper crossings of horizontal runs with other routes\' vertical runs', () => {
        const editor = createEditor();

        const hops = editor.computeHops([
            [{x: 0, y: 50}, {x: 200, y: 50}, {x: 200, y: 150}],
            [{x: 100, y: 0}, {x: 100, y: 100}],
            [{x: 150, y: 0}, {x: 150, y: 50}],
            [{x: 300, y: 100}, {x: 50, y: 100}],
            [{x: 60, y: 0}, {x: 60, y: 80}],
            [{x: 20, y: 50}, {x: 20, y: 50}]
        ]);

        expect(hops).toEqual([{0: [100, 60]}, {}, {}, {0: [200]}, {}, {}]);
    });

    it('draws jumps along the run direction and skips those too close to a corner', () => {
        const editor = createEditor();

        expect(editor.pathWithHops([{x: 0, y: 50}, {x: 200, y: 50}, {x: 200, y: 150}], {0: [100, 60, 195], 1: [200]}))
            .toBe('M 0 50 L 55 50 A 5 5 0 0 1 65 50 L 95 50 A 5 5 0 0 1 105 50 L 200 50 L 200 150');
        expect(editor.pathWithHops([{x: 300, y: 100}, {x: 50, y: 100}], {0: [200, 55, 250, 296]}))
            .toBe('M 300 100 L 255 100 A 5 5 0 0 0 245 100 L 205 100 A 5 5 0 0 0 195 100 L 50 100');
        expect(editor.pathWithHops([{x: 0, y: 0}, {x: 10, y: 0}], {})).toBe('M 0 0 L 10 0');
    });

    it('sizes the jump radius from the grid spacing (4..6px)', () => {
        const small = createEditor({gridSpacing: 4});
        const large = createEditor({gridSpacing: 40});

        expect(small.pathWithHops([{x: 0, y: 0}, {x: 100, y: 0}], {0: [50]})).toBe('M 0 0 L 46 0 A 4 4 0 0 1 54 0 L 100 0');
        expect(large.pathWithHops([{x: 0, y: 0}, {x: 100, y: 0}], {0: [50]})).toBe('M 0 0 L 44 0 A 6 6 0 0 1 56 0 L 100 0');
    });
});

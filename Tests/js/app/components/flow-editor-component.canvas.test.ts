import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    client, clickMenu, clickSubmenu, configOf, contextMenu, createEditor, design, designOf, dragTile, dropFromToolbox,
    FlowDialog, flowRecord, flush, linksOf, marquee, menuLabels, mockFetch, pointer, positionOf, root, selectedNames,
    simpleFlow, st, submenu, tabTitles, tile, tileNames, TOOLBOX_RECT, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const MENU = (key: string): string => `aaxis.ontology.flow_editor.${key}`;

/** Confirms (or cancels) the newest confirm dialog. */
function answerConfirm(confirm: boolean): void {
    FlowDialog.last().$content.find(confirm ? '.btn-primary' : '.btn:not(.btn-primary)').trigger('click');
}

describe('OntologyFlowEditorComponent — dropping steps from the toolbox', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('adds nothing for a plain click on a toolbox item', () => {
        createEditor();
        const item = document.querySelector('[data-step-type="logger"]') as HTMLElement;

        pointer(item, 'pointerdown', 950, 100);
        pointer(window, 'pointermove', 952, 101);
        pointer(window, 'pointerup', 952, 101);

        expect(tileNames()).toEqual([]);
        expect(document.querySelector('.is-ghost')).toBeNull();
    });

    it('shows a ghost tile while dragging and drops a snapped, trigger-armed step', () => {
        const editor = createEditor();
        const item = document.querySelector('[data-step-type="cron"]') as HTMLElement;

        pointer(item, 'pointerdown', 950, 100);
        pointer(window, 'pointermove', ...client(143, 138));
        const ghost = document.querySelector<HTMLElement>('.is-ghost')!;
        expect(ghost.querySelector('.aaxis-flow-editor__step-name')!.textContent).toBe('schedule-1');
        expect(ghost.style.left).toBe(`${100 + 143 - 40}px`);
        pointer(window, 'pointerup', ...client(143, 138));

        expect(document.querySelector('.is-ghost')).toBeNull();
        expect(tileNames()).toEqual(['schedule-1']);
        expect(positionOf('schedule-1')).toEqual([100, 100]);
        expect(configOf(editor, 'schedule-1')).toEqual({enabled: true});
        // The new trigger names the tab, and the change is pending.
        expect(tabTitles()).toEqual(['schedule-1']);
        expect($('[data-role="tab"] .aaxis-flow-editor__tab-dirty')).toHaveLength(1);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
    });

    it('seeds a dropped endpoint with the response example and derives names from the labels', () => {
        const editor = createEditor();

        dropFromToolbox('endpoint', 100, 100);
        dropFromToolbox('invoke', 300, 100);
        dropFromToolbox('invoke', 300, 300);
        dropFromToolbox('ms_teams', 500, 100);

        expect(tileNames()).toEqual(['endpoint-1', 'http_request-1', 'http_request-2', 'ms_teams-1']);
        expect(configOf(editor, 'endpoint-1')).toEqual({enabled: true, response: '{\n    statusCode: 200,\n    body: payload\n}'});
        expect(configOf(editor, 'http_request-1')).toBeNull();
    });

    it('marks fresh drops that need configuration red and keeps the others clean', () => {
        const editor = createEditor();
        dropFromToolbox('cron', 100, 100);
        expect(root(editor).find('[data-role="debug"]').prop('disabled')).toBe(false);

        dropFromToolbox('email', 300, 300);
        dropFromToolbox('dwl_transform', 500, 300);

        expect(tile('email-1').classList.contains('is-config-invalid')).toBe(false);
        expect(tile('dwl_transform-1').classList.contains('is-config-invalid')).toBe(true);
        expect(root(editor).find('[data-role="debug"]').prop('disabled')).toBe(true);
    });

    it('ignores a release over the toolbox or outside the canvas', () => {
        createEditor();
        const item = document.querySelector('[data-step-type="logger"]') as HTMLElement;

        pointer(item, 'pointerdown', 950, 100);
        pointer(window, 'pointermove', 400, 400);
        pointer(window, 'pointerup', TOOLBOX_RECT.left + 5, TOOLBOX_RECT.top + 5);
        pointer(item, 'pointerdown', 950, 100);
        pointer(window, 'pointermove', 400, 400);
        pointer(window, 'pointerup', 5, 5);

        expect(tileNames()).toEqual([]);
    });

    it('drops over the toolbox area once the toolbox is hidden', () => {
        const editor = createEditor();
        root(editor).find('[data-role="toolbox-hide"]').trigger('click');

        dropFromToolbox('logger', TOOLBOX_RECT.left - 100 + 5, TOOLBOX_RECT.top - 50 + 5);

        expect(tileNames()).toEqual(['logger-1']);
    });

    it('ignores non-primary buttons and unknown toolbox types', () => {
        const editor = createEditor();
        const item = document.querySelector('[data-step-type="logger"]') as HTMLElement;
        pointer(item, 'pointerdown', 950, 100, {button: 2});
        pointer(window, 'pointermove', 400, 400);
        pointer(window, 'pointerup', 400, 400);
        // An item the editor did not harvest at load (unknown type) arms nothing either.
        const unknown = $('<div data-step-type="mystery">?</div>').appendTo(root(editor).find('[data-role="toolbox-body"]'))[0];
        pointer(unknown, 'pointerdown', 950, 100);
        pointer(window, 'pointermove', 400, 400);
        pointer(window, 'pointerup', 400, 400);

        expect(tileNames()).toEqual([]);
    });

    it('asks before replacing the trigger; the replacement takes its spot, sides and links', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 100, 200, {enabled: true}, {outSides: ['south']}),
            st('l', 'logger', 'log', 100, 400)
        ], [{from: 't', fromPort: 0, to: 'l'}])});

        dropFromToolbox('endpoint', 600, 100);
        expect(FlowDialog.last().options.title).toBe(MENU('replace_trigger_title'));
        answerConfirm(false);
        expect(tileNames()).toEqual(['tick', 'log']);

        dropFromToolbox('endpoint', 600, 100);
        answerConfirm(true);
        expect(tileNames()).toEqual(['log', 'endpoint-1']);
        expect(positionOf('endpoint-1')).toEqual([100, 200]);
        expect(designOf(editor).steps[1].outSides).toEqual(['south']);
        expect(linksOf(editor)).toEqual(['endpoint-1:0->log']);
    });

    it('replaces a trigger without custom sides or links', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 100, 200, {enabled: true})])});

        dropFromToolbox('subflow', 600, 100);
        answerConfirm(true);

        expect(tileNames()).toEqual(['subflow-1']);
        expect(designOf(editor).steps[0].outSides).toBeUndefined();
    });

    it('asks before a trigger displaces the sub-flow start marker', () => {
        const editor = createEditor({flow: {id: 2, name: 'S', enabled: true, type: 'subflow', steps: [],
            design: design([st('a', 'logger', 'first', 0, 0)], [], 'a')}});

        dropFromToolbox('cron', 540, 340);
        expect(FlowDialog.last().options.title).toBe(MENU('start_override_title'));
        expect(FlowDialog.last().$content.text()).toContain(MENU('start_override_question'));
        answerConfirm(true);

        expect(designOf(editor).start).toBeNull();
        expect(positionOf('schedule-1')).toEqual([500, 300]);
        expect(document.querySelectorAll('[data-role="start-group"]')).toHaveLength(0);
    });

    it('chains a drop onto a step with a free port one gap along its out side', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('t', 'cron', 'tick', 100, 100, {enabled: true})])});

        dropFromToolbox('logger', 140, 140); // released on the trigger tile

        expect(positionOf('logger-1')).toEqual([260, 100]);
        expect(linksOf(editor)).toEqual(['tick:0->logger-1']);
        expect(designOf(editor).steps[1].inSide).toBeUndefined();
        expect(tile('logger-1').classList.contains('is-unreachable')).toBe(false);
    });

    it('faces the chained step towards its parent and skips occupied cells', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 100, 100, {enabled: true}, {outSides: ['south']}),
            st('x', 'logger', 'blocker', 100, 260)
        ])});

        dropFromToolbox('logger', 140, 140);

        expect(positionOf('logger-1')).toEqual([100, 420]);
        expect(designOf(editor).steps[2].inSide).toBe('north');
        expect(linksOf(editor)).toEqual(['tick:0->logger-1']);
    });

    it('keeps the default in-side when facing the parent would collide with a choice output', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 200, 400, {enabled: true}, {outSides: ['north']})
        ])});

        dropFromToolbox('choice', 240, 440);

        expect(positionOf('choice-1')).toEqual([200, 240]);
        expect(designOf(editor).steps[1].inSide).toBeUndefined();
        expect(tile('choice-1').classList.contains('is-config-invalid')).toBe(true);
    });

    it('scans east once the chain direction runs into the canvas wall', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('t', 'cron', 'tick', 0, 200, {enabled: true}, {outSides: ['west']})
        ])});

        dropFromToolbox('logger', 40, 240);

        expect(positionOf('logger-1')).toEqual([160, 200]);
        expect(linksOf(editor)).toEqual(['tick:0->logger-1']);
    });

    it('uses the choice\'s next free port, and drops loose when every port is taken', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('c', 'choice', 'pick', 100, 100, {expression: 'true'}),
            st('a', 'logger', 'yes', 400, 400)
        ], [{from: 'c', fromPort: 0, to: 'a'}])});

        dropFromToolbox('logger', 140, 140);
        expect(linksOf(editor)).toEqual(['pick:0->yes', 'pick:1->logger-1']);
        expect(positionOf('logger-1')).toEqual([100, 260]);

        dropFromToolbox('logger', 140, 140);
        expect(positionOf('logger-2')).toEqual([100, 100]);
        expect(linksOf(editor)).toHaveLength(2);
    });

    it('never chains a trigger onto a step', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('l', 'logger', 'log', 100, 100)])});

        dropFromToolbox('cron', 140, 140);

        expect(linksOf(editor)).toEqual([]);
        expect(positionOf('schedule-1')).toEqual([100, 100]);
    });
});

describe('OntologyFlowEditorComponent — moving and selecting tiles', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const three = (): any => flowRecord(1, 'F', [
        st('t', 'cron', 'tick', 0, 0, {enabled: true}),
        st('a', 'logger', 'a', 200, 0),
        st('b', 'logger', 'b', 200, 200)
    ], [{from: 't', fromPort: 0, to: 'a'}]);

    it('moves a tile snapped to the grid and marks the flow dirty', async () => {
        const editor = createEditor({flow: three()});

        const r = tile('b').getBoundingClientRect();
        pointer(tile('b'), 'pointerdown', r.left + 10, r.top + 10);
        expect(tile('b').classList.contains('is-moving')).toBe(true);
        expect(selectedNames()).toEqual(['b']);
        pointer(window, 'pointermove', r.left + 10 + 33, r.top + 10 + 47);
        pointer(window, 'pointerup', r.left + 43, r.top + 57);

        expect(positionOf('b')).toEqual([230, 250]);
        expect(tile('b').classList.contains('is-moving')).toBe(false);
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
        // The wires are re-routed on the next animation frame.
        await new Promise(resolve => requestAnimationFrame(resolve));
        await new Promise(resolve => requestAnimationFrame(resolve));
        expect((root(editor).find('[data-role="canvas"]')[0] as HTMLElement).style.width).toBe(`${230 + 80 + 80}px`);
    });

    it('never moves a tile above/left of the canvas origin', () => {
        createEditor({flow: three()});

        dragTile('a', -500, -500);

        expect(positionOf('a')).toEqual([0, 0]);
    });

    it('rubber-bands a selection and moves the whole group, clamped to the canvas', () => {
        createEditor({flow: three()});

        marquee(150, -10, 400, 250);
        expect(selectedNames()).toEqual(['a', 'b']);

        dragTile('a', 1000, 1000);
        // Leader clamped so the member 200px below it still fits (600 - 80 - 200 = 320).
        expect(positionOf('a')).toEqual([920, 320]);
        expect(positionOf('b')).toEqual([920, 520]);
        expect(positionOf('tick')).toEqual([0, 0]);

        dragTile('a', -2000, -2000);
        expect(positionOf('a')).toEqual([0, 0]);
        expect(positionOf('b')).toEqual([0, 200]);
    });

    it('clears the selection on a plain click on the empty canvas', () => {
        createEditor({flow: three()});
        marquee(150, -10, 400, 250);

        marquee(700, 500, 701, 501);

        expect(selectedNames()).toEqual([]);
    });

    it('keeps a multi-selection when pressing one of its tiles', () => {
        createEditor({flow: three()});
        marquee(150, -10, 400, 250);

        const r = tile('b').getBoundingClientRect();
        pointer(tile('b'), 'pointerdown', r.left + 1, r.top + 1, {button: 2});
        pointer(window, 'pointerup', r.left + 1, r.top + 1, {button: 2});

        expect(selectedNames()).toEqual(['a', 'b']);
    });

    it('lays everything out in execution order with Organize, wrapping at the viewport width', () => {
        const steps = [st('t', 'cron', 'tick', 500, 500, {enabled: true})];
        for (let i = 0; i < 6; i++) {
            steps.push(st(`s${i}`, 'logger', `step${i}`, 900 - i * 100, 10));
        }
        steps.push(st('u', 'logger', 'loose', 0, 300));
        const links = [{from: 't', fromPort: 0, to: 's3'}, {from: 's3', fromPort: 0, to: 's1'}];
        const editor = createEditor({flow: flowRecord(1, 'F', steps, links)});

        root(editor).find('[data-role="organize"]').trigger('click');

        const order = ['tick', 'step3', 'step1', 'loose', 'step5', 'step4', 'step2', 'step0'];
        expect(order.map(positionOf)).toEqual([
            [40, 40], [200, 40], [360, 40], [520, 40], [680, 40], [840, 40],
            [40, 200], [200, 200]
        ]);
        expect((HTMLElement.prototype as any).scrollTo).toHaveBeenCalledWith({left: 0, top: 0});
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
    });

    it('does nothing on Organize with an empty canvas', () => {
        const editor = createEditor();

        root(editor).find('[data-role="organize"]').trigger('click');

        expect((HTMLElement.prototype as any).scrollTo).not.toHaveBeenCalled();
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(true);
    });

    it('organizes a sub-flow from its start marker', () => {
        createEditor({flow: {id: 2, name: 'S', enabled: true, type: 'subflow', steps: [],
            design: design([st('a', 'logger', 'later', 0, 0), st('b', 'logger', 'entry', 300, 0)], [{from: 'b', fromPort: 0, to: 'a'}], 'b')}});

        $('[data-role="organize"]').trigger('click');

        expect(positionOf('entry')).toEqual([40, 40]);
        expect(positionOf('later')).toEqual([200, 40]);
    });
});

describe('OntologyFlowEditorComponent — context menus', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('offers Remove plus the anchor submenus for one step, and removes it with its links', () => {
        const editor = createEditor({flow: simpleFlow()});

        contextMenu(tile('shape'));
        expect(menuLabels()).toEqual([MENU('menu_remove'), MENU('menu_copy'), MENU('menu_flow_in'), MENU('menu_flow_out')]);
        expect(selectedNames()).toEqual(['shape']);
        clickMenu(MENU('menu_remove'));

        expect(tileNames()).toEqual(['nightly']);
        expect(linksOf(editor)).toEqual([]);
        expect(document.querySelector('body > .aaxis-flow-editor__menu')).toBeNull();
    });

    it('lists the anchor sides with the current one checked and the used ones disabled', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [st('c', 'choice', 'pick', 200, 200, {expression: 'x'})])});

        contextMenu(tile('pick'));
        expect(menuLabels()).toEqual([MENU('menu_remove'), MENU('menu_copy'), MENU('menu_start_here'), MENU('menu_flow_in'), MENU('menu_flow_true'), MENU('menu_flow_false')]);
        expect(submenu(MENU('menu_flow_in'))).toEqual([
            {label: MENU('side_north'), disabled: false, active: false},
            {label: MENU('side_south'), disabled: true, active: false},
            {label: MENU('side_east'), disabled: true, active: false},
            {label: MENU('side_west'), disabled: false, active: true}
        ]);
        expect(submenu(MENU('menu_flow_false')).find(c => c.active)!.label).toBe(MENU('side_south'));

        clickSubmenu(MENU('menu_flow_in'), MENU('side_north'));
        expect(designOf(editor).steps[0].inSide).toBe('north');
        contextMenu(tile('pick'));
        clickSubmenu(MENU('menu_flow_true'), MENU('side_west'));
        expect(designOf(editor).steps[0].outSides).toEqual(['west']);
        expect(tile('pick').querySelector<HTMLElement>('[data-port="0"]')!.style.left).toBe('-9px');
        expect(root(editor).find('[data-role="save"]').prop('disabled')).toBe(false);
    });

    it('hides flow-in on triggers and cannot pick an occupied side', () => {
        const editor = createEditor({flow: simpleFlow()});

        contextMenu(tile('nightly'));
        expect(menuLabels()).toEqual([MENU('menu_remove'), MENU('menu_copy'), MENU('menu_flow_out')]);
        expect(submenu(MENU('menu_flow_out')).every(c => !c.disabled)).toBe(true);
        clickSubmenu(MENU('menu_flow_out'), MENU('side_north'));
        expect(designOf(editor).steps[0].outSides).toEqual(['north']);
    });

    it('closes the menu on any press outside it', () => {
        createEditor({flow: simpleFlow()});
        contextMenu(tile('shape'), 5000, 5000);
        const menu = document.querySelector<HTMLElement>('body > .aaxis-flow-editor__menu')!;
        expect(menu.style.left).toBe(`${window.innerWidth - 8}px`);

        pointer(menu, 'pointerdown', 0, 0);
        expect(document.querySelector('body > .aaxis-flow-editor__menu')).not.toBeNull();
        pointer(document.body, 'pointerdown', 0, 0);
        expect(document.querySelector('body > .aaxis-flow-editor__menu')).toBeNull();
    });

    it('sets and clears the sub-flow "Start here" marker', () => {
        const editor = createEditor({flow: flowRecord(2, 'S', [
            st('a', 'logger', 'a', 0, 0), st('b', 'logger', 'b', 300, 0)
        ], [{from: 'a', fromPort: 0, to: 'b'}], {type: 'subflow'})});

        contextMenu(tile('b'));
        expect(menuLabels()).not.toContain(MENU('menu_start_here'));
        contextMenu(tile('a'));
        clickMenu(MENU('menu_start_here'));
        expect(designOf(editor).start).toBe('a');
        expect(tile('b').classList.contains('is-unreachable')).toBe(false);
        expect(document.querySelectorAll('[data-role="start-group"]')).toHaveLength(1);

        contextMenu(tile('a'));
        clickMenu(MENU('menu_remove_start'));
        expect(designOf(editor).start).toBeNull();

        contextMenu(tile('a'));
        clickMenu(MENU('menu_start_here'));
        contextMenu(document.querySelector('[data-role="start-group"]')!);
        clickMenu(MENU('menu_remove'));
        expect(designOf(editor).start).toBeNull();
        expect(tile('a').classList.contains('is-unreachable')).toBe(true);
    });

    it('drops the start marker together with its step', () => {
        const editor = createEditor({flow: {id: 2, name: 'S', enabled: true, type: 'subflow', steps: [],
            design: design([st('a', 'logger', 'a', 0, 0)], [], 'a')}});

        contextMenu(tile('a'));
        clickMenu(MENU('menu_remove'));

        expect(designOf(editor).start).toBeNull();
    });

    it('removes a single wire from its own context menu', () => {
        const editor = createEditor({flow: simpleFlow()});

        contextMenu(document.querySelector('[data-role="wire-group"] [data-role="wire-hit"]')!);
        expect(menuLabels()).toEqual([MENU('menu_remove')]);
        clickMenu(MENU('menu_remove'));

        expect(linksOf(editor)).toEqual([]);
        expect(tileNames()).toEqual(['nightly', 'shape']);
        expect(tile('shape').classList.contains('is-unreachable')).toBe(true);
    });

    it('offers Align and Connect for a connectable multi-selection', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('a', 'logger', 'a', 300, 0), st('b', 'logger', 'b', 0, 100), st('c', 'choice', 'c', 600, 50)
        ])});
        marquee(-10, -10, 900, 300);

        contextMenu(tile('a'));
        expect(menuLabels()).toEqual([MENU('menu_remove'), MENU('menu_copy'), MENU('menu_align'), MENU('menu_connect')]);
        clickMenu(MENU('menu_connect'));

        expect(linksOf(editor)).toEqual(['b:0->a', 'a:0->c']);
    });

    it.each([
        ['a later element already receives a line', [st('a', 'logger', 'a', 0, 0), st('b', 'logger', 'b', 300, 0), st('x', 'logger', 'x', 300, 400)], [{from: 'x', fromPort: 0, to: 'b'}]],
        ['a later element is a trigger', [st('a', 'logger', 'a', 0, 0), st('b', 'cron', 'b', 300, 0)], []],
        ['a non-last element is a choice', [st('a', 'choice', 'a', 0, 0), st('b', 'logger', 'b', 300, 0)], []]
    ])('hides Connect when %s', (_label, steps, links) => {
        createEditor({flow: flowRecord(1, 'F', steps, links)});
        marquee(-10, -10, 400, 90);

        contextMenu(tile('a'));

        expect(menuLabels()).toEqual([MENU('menu_remove'), MENU('menu_copy'), MENU('menu_align')]);
    });

    it('hides Connect when a later element carries the start marker', () => {
        createEditor({flow: {id: 2, name: 'S', enabled: true, type: 'subflow', steps: [],
            design: design([st('a', 'logger', 'a', 0, 0), st('b', 'logger', 'b', 300, 0)], [], 'b')}});
        marquee(-10, -10, 400, 90);

        contextMenu(tile('a'));

        expect(menuLabels()).toEqual([MENU('menu_remove'), MENU('menu_copy'), MENU('menu_align')]);
    });

    it('re-targets the selection to a right-clicked tile outside it', () => {
        createEditor({flow: simpleFlow()});
        marquee(-10, -10, 90, 90);
        expect(selectedNames()).toEqual(['nightly']);

        contextMenu(tile('shape'));

        expect(selectedNames()).toEqual(['shape']);
    });

    it('removes the whole selection', () => {
        const editor = createEditor({flow: simpleFlow()});
        marquee(-10, -10, 400, 90);

        contextMenu(tile('shape'));
        clickMenu(MENU('menu_remove'));

        expect(tileNames()).toEqual([]);
        expect(designOf(editor).links).toEqual([]);
        expect(root(editor).find('[data-role="organize"]').prop('disabled')).toBe(true);
    });

    it('aligns chains along each parent\'s out side, then the loose steps to the east', () => {
        const editor = createEditor({flow: flowRecord(1, 'F', [
            st('a', 'cron', 'a', 300, 300, {enabled: true}),
            st('b', 'logger', 'b', 50, 400),
            st('c', 'choice', 'c', 500, 100, {expression: 'x'}),
            st('d', 'logger', 'd', 700, 0),
            st('e', 'logger', 'e', 600, 500),
            st('f', 'logger', 'f', 900, 500)
        ], [
            {from: 'a', fromPort: 0, to: 'b'}, {from: 'b', fromPort: 0, to: 'c'},
            {from: 'c', fromPort: 0, to: 'd'}, {from: 'c', fromPort: 1, to: 'e'}
        ])});
        marquee(-10, -10, 999, 599);

        contextMenu(tile('a'));
        clickMenu(MENU('menu_align'));

        expect(['a', 'b', 'c', 'd', 'e', 'f'].map(positionOf)).toEqual([
            [50, 400], [210, 400], [370, 400], [530, 400], [370, 560], [690, 400]
        ]);
        expect(designOf(editor).links).toHaveLength(4);
    });

    it('enters a pure cycle at its leftmost tile and slides west-bound chains east at the wall', () => {
        createEditor({flow: flowRecord(1, 'F', [
            st('a', 'logger', 'a', 0, 200, null, {inSide: 'north', outSides: ['west']}),
            st('b', 'logger', 'b', 300, 200),
            st('c', 'logger', 'c', 600, 200)
        ], [{from: 'a', fromPort: 0, to: 'b'}, {from: 'b', fromPort: 0, to: 'c'}, {from: 'c', fromPort: 0, to: 'a'}])});
        marquee(-10, 150, 999, 290);

        contextMenu(tile('a'));
        clickMenu(MENU('menu_align'));

        // a claims the anchor; b wants the spot west of a (clamped onto a) → east instead.
        expect(['a', 'b', 'c'].map(positionOf)).toEqual([[0, 200], [160, 200], [320, 200]]);
    });

    it('navigates to a Call Subflow\'s target and lists a subflow\'s callers', async () => {
        const child = flowRecord(20, 'Child', [st('e', 'subflow', 'entry', 0, 0, {enabled: true})], [], {type: 'subflow'});
        const caller = {...flowRecord(30, 'Caller', [st('c', 'cron', 'go', 0, 0, {enabled: true})]),
            steps: [{type: 'sub_flow', name: 'call', x: 0, y: 0, config: {subflow: 20}}]};
        const looper = {...flowRecord(31, 'A looper', []), steps: [null, {type: 'foreach', name: 'loop', config: {subflow: '20'}}]};
        mockFetch({aaxis_ontology_flow_list: {body: {records: [child, caller, looper]}}});
        createEditor({flow: flowRecord(10, 'Main', [
            st('t', 'cron', 'go', 0, 0, {enabled: true}),
            st('s', 'sub_flow', 'call', 200, 0, {subflow: 20}),
            st('x', 'sub_flow', 'broken', 400, 0, {subflow: 404}),
            st('y', 'foreach', 'unset', 600, 0)
        ])});
        await flush();

        contextMenu(tile('broken'));
        expect(menuLabels()).not.toContain(MENU('menu_navigate_subflow'));
        contextMenu(tile('unset'));
        expect(menuLabels()).not.toContain(MENU('menu_navigate_subflow'));
        contextMenu(tile('call'));
        clickMenu(MENU('menu_navigate_subflow'));
        await flush();

        expect(tabTitles()).toEqual(['go', 'entry']);
        expect($('[data-role="tab"].is-active .aaxis-flow-editor__tab-title').text()).toBe('entry');
        contextMenu(tile('entry'));
        expect(submenu(MENU('menu_called_by')).map(c => c.label)).toEqual(['A looper', 'Caller']);
        clickSubmenu(MENU('menu_called_by'), 'Caller');
        await flush();
        expect(tabTitles()).toEqual(['go', 'entry', 'go']);
        contextMenu(tile('go'));
        expect(menuLabels()).not.toContain(MENU('menu_called_by'));
    });

    it('shows a disabled "no callers" entry for an unsaved subflow', async () => {
        createEditor({flow: flowRecord(null, 'New', [st('e', 'subflow', 'entry', 0, 0, {enabled: true})], [], {type: 'subflow'})});
        await flush();

        contextMenu(tile('entry'));

        expect(submenu(MENU('menu_called_by'))).toEqual([{label: MENU('menu_called_by_none'), disabled: true, active: false}]);
    });
});

describe('OntologyFlowEditorComponent — toolbox panel', () => {
    useFlowEditorEnv(FlowEditorComponent);

    const stored = (): any => JSON.parse(window.localStorage.getItem('aaxis.ontology.flowEditor.toolbox') || 'null');

    it('toggles the toolbox and remembers the choice as a workspace preference', () => {
        const editor = createEditor();
        const toolbox = root(editor).find('[data-role="toolbox"]')[0];
        expect(root(editor).find('[data-role="toolbox-toggle"]').hasClass('is-active')).toBe(true);
        expect(stored()).toBeNull(); // the init sync never persists

        root(editor).find('[data-role="toolbox-toggle"]').trigger('click');
        expect(toolbox.hidden).toBe(true);
        expect(root(editor).find('[data-role="toolbox-toggle"]').hasClass('is-active')).toBe(false);
        expect(stored()).toEqual({visible: false});

        root(editor).find('[data-role="toolbox-toggle"]').trigger('click');
        expect(toolbox.hidden).toBe(false);
        expect(toolbox.style.maxHeight).toBe(`${600 - 48}px`);
        expect(stored()).toEqual({visible: true});
    });

    it('collapses and expands a toolbox section', () => {
        createEditor();
        const $toggle = $('[data-role="toolbox-section-toggle"]').first();

        $toggle.trigger('click');
        expect($toggle.closest('.aaxis-flow-editor__toolbox-section').hasClass('is-collapsed')).toBe(true);
        expect($toggle.find('.fa').hasClass('fa-plus')).toBe(true);
        $toggle.trigger('click');
        expect($toggle.find('.fa').hasClass('fa-minus')).toBe(true);
    });

    it('drags the toolbox by its handle, clamped into the viewport, and stores the spot', () => {
        const editor = createEditor();
        const handle = root(editor).find('[data-role="toolbox-handle"]')[0];
        const toolbox = root(editor).find('[data-role="toolbox"]')[0];

        pointer(handle, 'pointerdown', TOOLBOX_RECT.left + 20, TOOLBOX_RECT.top + 5);
        pointer(window, 'pointermove', 300, 200);
        expect([toolbox.style.left, toolbox.style.top, toolbox.style.right]).toEqual(['280px', '195px', 'auto']);
        pointer(window, 'pointermove', 5000, 5000);
        expect([toolbox.style.left, toolbox.style.top]).toEqual(['820px', '300px']);
        pointer(window, 'pointerup', 5000, 5000);

        expect(stored()).toEqual({visible: true, x: 820, y: 300});
    });

    it('does not start a drag from the handle\'s hide button or a secondary button', () => {
        const editor = createEditor();
        const toolbox = root(editor).find('[data-role="toolbox"]')[0];

        pointer(root(editor).find('[data-role="toolbox-hide"]')[0], 'pointerdown', 10, 10);
        pointer(root(editor).find('[data-role="toolbox-handle"]')[0], 'pointerdown', 10, 10, {button: 1});
        pointer(window, 'pointermove', 300, 200);

        expect(toolbox.style.left).toBe('');
    });

    it('restores a stored spot that fits, and ignores one that does not', () => {
        window.localStorage.setItem('aaxis.ontology.flowEditor.toolbox', JSON.stringify({visible: true, x: 100, y: 40}));
        const editor = createEditor();
        const toolbox = root(editor).find('[data-role="toolbox"]')[0];
        expect([toolbox.style.left, toolbox.style.top, toolbox.style.right]).toEqual(['100px', '40px', 'auto']);

        $(document.body).empty();
        window.localStorage.setItem('aaxis.ontology.flowEditor.toolbox', JSON.stringify({visible: true, x: 5000, y: 40}));
        const other = createEditor();
        expect(root(other).find('[data-role="toolbox"]')[0].style.left).toBe('');
    });

    it.each([
        ['hidden', {visible: false}, true],
        ['without a spot', {visible: true}, false],
        ['unreadable', '{not json', false],
        ['not an object', '42', false]
    ])('restores a stored preference that is %s', (_label, value, hidden) => {
        window.localStorage.setItem('aaxis.ontology.flowEditor.toolbox', typeof value === 'string' ? value : JSON.stringify(value));
        const editor = createEditor();

        expect(root(editor).find('[data-role="toolbox"]')[0].hidden).toBe(hidden);
        expect(root(editor).find('[data-role="toolbox-toggle"]').hasClass('is-active')).toBe(!hidden);
    });

    it('re-clamps the toolbox and refits the editor on window resize', () => {
        const editor = createEditor();
        const toolbox = root(editor).find('[data-role="toolbox"]')[0];
        toolbox.style.left = '4000px';
        toolbox.style.top = '-50px';

        $(window).trigger('resize');

        expect([toolbox.style.left, toolbox.style.top]).toEqual(['820px', '0px']);
    });

    it('survives a storage that throws', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('quota');
        });
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('denied');
        });
        const editor = createEditor();

        expect(() => root(editor).find('[data-role="toolbox-hide"]').trigger('click')).not.toThrow();
        expect(root(editor).find('[data-role="toolbox"]')[0].hidden).toBe(true);
    });
});

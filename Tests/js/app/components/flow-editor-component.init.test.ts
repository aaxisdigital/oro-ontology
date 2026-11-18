import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import mediator from 'oroui/js/mediator';
import pageStateChecker from 'oronavigation/js/app/services/page-state-checker';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    createEditor, design, designOf, flowRecord, flush, mockFetch, root, simpleFlow, st, tabTitles, tile, tileNames,
    useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

describe('OntologyFlowEditorComponent — loading a flow', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('renders a stored design: snapped tiles, wires, the trigger-named tab and a clean state', () => {
        const flow = flowRecord(7, 'Nightly', [
            st('t1', 'cron', 'nightly', 3, 4, {enabled: true}),
            st('d1', 'dwl_transform', 'shape', 204, 0, {code: 'x', destination: 'payload'}),
            st('lost', 'logger', 'orphan', 400, 200)
        ], [{from: 't1', fromPort: 0, to: 'd1'}]);
        const editor = createEditor({flow});

        expect(tileNames()).toEqual(['nightly', 'shape', 'orphan']);
        expect(tile('nightly').style.left).toBe('0px');
        expect(tile('shape').style.left).toBe('200px');
        expect(tile('nightly').style.width).toBe('80px');
        expect(tile('nightly').querySelector('.fa-clock-o')).not.toBeNull();
        expect(tile('nightly').title).toBe('nightly (Schedule)');
        expect(document.querySelectorAll('[data-role="wire-group"]')).toHaveLength(1);
        // Reachability: the logger hangs off nothing, so it would never run.
        expect(tile('orphan').classList.contains('is-unreachable')).toBe(true);
        expect(tile('shape').classList.contains('is-unreachable')).toBe(false);
        expect(tabTitles()).toEqual(['nightly']);
        const $root = root(editor);
        expect($root.find('[data-role="save"]').prop('disabled')).toBe(true);
        expect($root.find('[data-role="cancel-label"]').text()).toBe('aaxis.ontology.flow_editor.close');
        expect($root.find('[data-role="run-split"]').prop('hidden')).toBe(false);
        expect($root.find('[data-role="debug"]').prop('disabled')).toBe(false);
        expect($root.find('[data-role="flow-history"]').prop('disabled')).toBe(false);
        expect($root.find('[data-role="organize"]').prop('hidden')).toBe(false);
        expect($root.find('[data-role="canvas"]')[0].style.getPropertyValue('--aaxis-flow-grid')).toBe('10px');
    });

    it('registers every unsaved-changes guard and warms the catalogs', () => {
        const fetchSpy = mockFetch();
        const editor = createEditor({flow: simpleFlow()});

        expect(pageStateChecker.registerChecker).toHaveBeenCalledTimes(1);
        expect(mediator.on).toHaveBeenCalledWith('openLink:before', expect.any(Function));
        expect(mediator.on).toHaveBeenCalledWith('page:beforeRefresh', expect.any(Function));
        const fetched = fetchSpy.mock.calls.map((c: any[]) => c[0]);
        expect(fetched).toEqual(['/aaxis_ontology_entity_list', '/aaxis_ontology_connector_list', '/aaxis_ontology_flow_list']);
        // Clean at load: the checker reports nothing to lose.
        const checker = (pageStateChecker.registerChecker as any).mock.calls[0][0];
        expect(checker()).toBe(false);
        editor.dispose();
        expect(pageStateChecker.removeChecker).toHaveBeenCalledWith(checker);
        expect(mediator.off).toHaveBeenCalledWith('openLink:before', expect.any(Function));
        expect(mediator.off).toHaveBeenCalledWith('page:beforeRefresh', expect.any(Function));
    });

    it('rebuilds the canvas from the logical steps when the flow has no design (unknown types skipped)', () => {
        createEditor({flow: {id: 3, name: 'Legacy', enabled: true, type: 'flow', design: null, steps: [
            {type: 'cron', name: 'tick', x: 12, y: 17},
            {type: 'removed_type', name: 'gone', x: 0, y: 0},
            {type: 'logger', name: 'log', x: 100, y: 0}
        ]}});

        expect(tileNames()).toEqual(['tick', 'log']);
        expect(tile('tick').style.left).toBe('10px');
        expect(tile('tick').style.top).toBe('20px');
    });

    it('starts empty on a brand-new flow, the tab reading "unnamed"', () => {
        const editor = createEditor();

        expect(tileNames()).toEqual([]);
        expect(tabTitles()).toEqual(['aaxis.ontology.flow_editor.name_unset']);
        expect(root(editor).find('[data-role="run-split"]').prop('hidden')).toBe(true);
        expect(root(editor).find('[data-role="organize"]').prop('hidden')).toBe(true);
        expect(root(editor).find('[data-role="flow-history"]').prop('disabled')).toBe(true);
    });

    const trigger = st('t', 'cron', 'tick', 0, 0);
    const logger = st('l', 'logger', 'log', 100, 0);
    it.each([
        ['a non-object', 'nope'],
        ['an array', []],
        ['an outdated version', {...design([trigger]), version: 1}],
        ['steps that are not a list', {version: 2, steps: {}}],
        ['an unknown step type', design([st('x', 'mystery', 'x', 0, 0)])],
        ['a null step', design([null])],
        ['an empty id', design([st('', 'logger', 'x', 0, 0)])],
        ['duplicate ids', design([logger, {...logger, name: 'other'}])],
        ['a blank name', design([st('a', 'logger', '  ', 0, 0)])],
        ['duplicate names (case-insensitive)', design([logger, st('m', 'logger', 'LOG', 0, 0)])],
        ['a non-finite position', design([st('a', 'logger', 'a', Number.NaN, 0)])],
        ['an array config', design([st('a', 'logger', 'a', 0, 0, [] as any)])],
        ['links that are not a list', {...design([logger]), links: {}}],
        ['a link to an unknown step', design([trigger, logger], [{from: 't', fromPort: 0, to: 'zz'}])],
        ['a self link', design([logger], [{from: 'l', fromPort: 0, to: 'l'}])],
        ['a port the type lacks', design([trigger, logger], [{from: 't', fromPort: 1, to: 'l'}])],
        ['a fractional port', design([trigger, logger], [{from: 't', fromPort: 0.5, to: 'l'}])],
        ['a link into a trigger', design([trigger, logger], [{from: 'l', fromPort: 0, to: 't'}])],
        ['a port used twice', design([trigger, logger, st('m', 'logger', 'm', 0, 100)],
            [{from: 't', fromPort: 0, to: 'l'}, {from: 't', fromPort: 0, to: 'm'}])],
        ['two lines into one step', design([trigger, st('c', 'choice', 'c', 0, 100), logger],
            [{from: 't', fromPort: 0, to: 'l'}, {from: 'c', fromPort: 0, to: 'l'}])],
        ['a start marker while a trigger exists', design([trigger, logger], [], 'l')],
        ['a start marker on an unknown step', design([logger], [], 'zz')],
        ['a start marker on a step with an incoming line', design([logger, st('m', 'logger', 'm', 0, 100)], [{from: 'm', fromPort: 0, to: 'l'}], 'l')],
        ['a non-string start marker', design([logger], [], 5 as any)]
    ])('reports %s as a corrupted design and opens an empty canvas', (_label, stored) => {
        createEditor({flow: {id: 1, name: 'x', enabled: true, type: 'flow', steps: [], design: stored}});

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('warning', 'aaxis.ontology.flow_editor.design_corrupted');
        expect(tileNames()).toEqual([]);
    });

    it('accepts a design without links and restores the sub-flow start marker', () => {
        const editor = createEditor({flow: {id: 2, name: 'Sub', enabled: true, type: 'subflow', steps: [],
            design: {version: 2, steps: [st('a', 'logger', 'first', 0, 0), st('b', 'logger', 'second', 200, 0)], start: 'a'}}});

        expect(messenger.notificationFlashMessage).not.toHaveBeenCalled();
        expect(document.querySelectorAll('[data-role="start-group"]')).toHaveLength(1);
        expect(designOf(editor).start).toBe('a');
        expect(tile('first').classList.contains('is-unreachable')).toBe(false);
        expect(tile('second').classList.contains('is-unreachable')).toBe(true);
    });

    it('restores custom anchor sides and positions the port handles on them', () => {
        const editor = createEditor({flow: flowRecord(4, 'A', [
            st('t', 'cron', 'tick', 0, 0, {enabled: true}, {outSides: ['south']}),
            st('c', 'choice', 'pick', 0, 200, null, {inSide: 'north', outSides: ['east', 'west']})
        ], [{from: 't', fromPort: 0, to: 'c'}])});

        const saved = designOf(editor).steps;
        expect(saved[0].outSides).toEqual(['south']);
        expect(saved[1]).toMatchObject({inSide: 'north', outSides: ['east', 'west']});
        const ports = tile('pick').querySelectorAll<HTMLElement>('[data-role="port"]');
        expect([ports[0].style.right, ports[0].style.top]).toEqual(['-9px', '50%']);
        expect([ports[1].style.left, ports[1].style.transform]).toEqual(['-9px', 'translateY(-50%)']);
        const south = tile('tick').querySelector<HTMLElement>('[data-role="port"]')!;
        expect([south.style.left, south.style.bottom, south.style.transform]).toEqual(['50%', '-9px', 'translateX(-50%)']);
    });

    it.each([
        ['an unknown side', {inSide: 'up'}],
        ['too many out sides', {outSides: ['east', 'south']}],
        ['an invalid out side', {outSides: ['sideways']}],
        ['colliding sides', {inSide: 'east'}]
    ])('falls back to the default anchors for %s (never "corrupted")', (_label, anchors) => {
        const editor = createEditor({flow: flowRecord(4, 'A', [st('l', 'logger', 'log', 0, 0, null, anchors)])});

        expect(messenger.notificationFlashMessage).not.toHaveBeenCalled();
        const saved = designOf(editor).steps[0];
        expect(saved.inSide).toBeUndefined();
        expect(saved.outSides).toBeUndefined();
    });

    it('keeps a partially-valid out-side list (null entries fall back per port)', () => {
        const editor = createEditor({flow: flowRecord(4, 'A', [st('c', 'choice', 'pick', 0, 0, null, {outSides: [null, 'north']})])});

        expect(designOf(editor).steps[0].outSides).toEqual([undefined, 'north']);
        const falsePort = tile('pick').querySelectorAll<HTMLElement>('[data-role="port"]')[1];
        expect(falsePort.style.top).toBe('-9px');
    });

    it('paints the server-reported invalid steps red and blocks running', () => {
        const editor = createEditor({flow: simpleFlow(7, {invalidSteps: ['shape']})});

        expect(tile('shape').classList.contains('is-config-invalid')).toBe(true);
        expect(tile('nightly').classList.contains('is-config-invalid')).toBe(false);
        const $run = root(editor).find('[data-role="debug"]');
        expect($run.prop('disabled')).toBe(true);
        expect($run.attr('title')).toBe('aaxis.ontology.flow_editor.invalid_steps_blocked');
    });

    it('opens Debug from the caret next to Run Now, closing on an outside click or Escape', () => {
        const editor = createEditor({flow: simpleFlow()});
        const $root = root(editor);
        const $toggle = $root.find('[data-role="run-menu-toggle"]');
        const $menu = $root.find('[data-role="run-menu"]');

        expect($menu.prop('hidden')).toBe(true);
        $toggle.trigger('click');
        expect($menu.prop('hidden')).toBe(false);
        expect($toggle.attr('aria-expanded')).toBe('true');
        $toggle.trigger('click');
        expect($menu.prop('hidden')).toBe(true);

        $toggle.trigger('click');
        $menu[0].dispatchEvent(new MouseEvent('pointerdown', {bubbles: true}));
        expect($menu.prop('hidden')).toBe(false);
        document.body.dispatchEvent(new MouseEvent('pointerdown', {bubbles: true}));
        expect($menu.prop('hidden')).toBe(true);
        expect($toggle.attr('aria-expanded')).toBe('false');

        $toggle.trigger('click');
        document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        expect($menu.prop('hidden')).toBe(true);
    });

    it('keeps the run buttons\' own titles unless red steps block them', () => {
        const editor = createEditor({flow: simpleFlow(7, {invalidSteps: ['shape']})});
        const $run = root(editor).find('[data-role="debug"]');
        expect($run.attr('title')).toBe('aaxis.ontology.flow_editor.invalid_steps_blocked');

        editor.markStepInvalid(editor.steps.find((s: any) => s.name === 'shape'), false);

        expect($run.prop('disabled')).toBe(false);
        expect($run.attr('title')).toBe('debug');
        expect(root(editor).find('[data-role="run-menu-toggle"]').attr('title')).toBe('run-options');
    });

    it('still runs a flow manually while its trigger is switched off', () => {
        const flow = simpleFlow();
        flow.design.steps[0].config.enabled = false;
        const editor = createEditor({flow});

        expect(root(editor).find('[data-role="run-split"]').prop('hidden')).toBe(false);
        expect(root(editor).find('[data-role="debug"]').prop('disabled')).toBe(false);
        expect(root(editor).find('[data-role="run-menu-toggle"]').prop('disabled')).toBe(false);
        expect(root(editor).find('[data-role="debug-step"]').prop('disabled')).toBe(false);
    });

    it('clamps the grid options and sizes the tiles from them', () => {
        createEditor({flow: simpleFlow(), gridSpacing: 1, stepSizeFactor: 40});
        expect(tile('nightly').style.width).toBe(`${16 * 4}px`);

        $(document.body).empty();
        createEditor({flow: simpleFlow(), gridSpacing: 'junk', stepSizeFactor: 3});
        expect(tile('nightly').style.width).toBe('30px');
    });

    it('fits the editor when mounted directly on the editor root', () => {
        const first = createEditor();
        const $root = first.$el.find('.aaxis-flow-editor');
        first.dispose();
        $root[0].style.minHeight = '';
        const editor = new (FlowEditorComponent as any)({_sourceElement: $root, listUrl: '#flows'});

        expect($root[0].style.minHeight).toBe(`${window.innerHeight - 40 - 16}px`);
        expect(tabTitles()).toContain('aaxis.ontology.flow_editor.name_unset');
        editor.dispose();
    });

    it('fits the editor into the viewport below the page chrome', () => {
        const editor = createEditor();

        const el = root(editor).find('.aaxis-flow-editor')[0];
        expect(el.style.minHeight).toBe(`${window.innerHeight - 40 - 16}px`);
    });

    it('reopens the tabs listed in ?tabs=, in order, skipping unknown and native flows', async () => {
        const other = flowRecord(8, 'Other', [st('o', 'endpoint', 'hook', 0, 0, {enabled: true})]);
        const sub = flowRecord(9, 'Sub', [st('s', 'subflow', 'entry', 0, 0, {enabled: true})], [], {type: 'subflow'});
        mockFetch({aaxis_ontology_flow_list: {body: {records: [other, sub, {id: 5, name: 'Native', type: 'native'}]}}});
        window.history.replaceState(null, '', '/editor?tabs=9,7,5,x,8,9,404');
        createEditor({flow: simpleFlow(7)});
        await flush();

        expect(tabTitles()).toEqual(['entry', 'nightly', 'hook']);
        expect($('[data-role="tab"].is-active .aaxis-flow-editor__tab-title').text()).toBe('nightly');
    });

    it('puts the current flow first when ?tabs= does not list it', async () => {
        const other = flowRecord(8, 'Other', [st('o', 'endpoint', 'hook', 0, 0, {enabled: true})]);
        mockFetch({aaxis_ontology_flow_list: {body: {records: [other]}}});
        window.history.replaceState(null, '', '/editor?tabs=8');
        createEditor();
        await flush();

        expect(tabTitles()).toEqual(['aaxis.ontology.flow_editor.name_unset', 'hook']);
    });

    it('ignores ?tabs= naming only the current flow, and survives a failing catalog', async () => {
        const spy = mockFetch({aaxis_ontology_flow_list: new Error('down')});
        window.history.replaceState(null, '', '/editor?tabs=7');
        createEditor({flow: simpleFlow(7)});
        await flush();
        expect(tabTitles()).toEqual(['nightly']);

        $(document.body).empty();
        window.history.replaceState(null, '', '/editor?tabs=8');
        createEditor({flow: simpleFlow(7)});
        await flush();
        expect(tabTitles()).toEqual(['nightly']);
        expect(spy).toHaveBeenCalled();
    });
});

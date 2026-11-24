import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import FlowEditorComponent from '../../../../Resources/js-src/app/components/flow-editor-component';
import {
    client, configOf, createEditor, dropFromToolbox, flowRecord, flush, linksOf, mockFetch, openSettings, pointer,
    positionOf, selectedNames, st, tabTitles, tile, TOOLBOX_RECT, useFlowEditorEnv
} from '../../helpers/flow-editor';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/flow-editor')).dialogModule());

const KEY = (key: string): string => `aaxis.ontology.flow_editor.${key}`;
const CRON = {enabled: true, mode: 'interval', value: 1, unit: 'day'};
const FLOWS = {records: [
    {id: 7, name: 'Main', type: 'flow', steps: []},
    {id: 20, name: 'Enrich', type: 'subflow', steps: [], design: {version: 2, steps: [st('s', 'subflow', 'enrich', 0, 0, {enabled: true})], links: []}}
]};

/** Lets the catalog load and the frame-coalesced redraw run. */
async function settle(): Promise<void> {
    await flush();
    await new Promise(resolve => setTimeout(resolve, 40));
}

/** The name glyphs: [step name, its icon class, tooltip]. */
const glyphs = (): Array<[string, string, string]> => $('[data-role="step-link"]').map((_i: number, el: HTMLElement) => {
    const step = el.closest('[data-role="step"]') as HTMLElement;
    const icon = Array.from(el.classList).find(c => c.startsWith('fa-') && c !== 'fa') as string;
    return [[step.querySelector('.aaxis-flow-editor__step-name')!.textContent, icon, el.title]];
}).get();

const glyphOf = (name: string): HTMLElement => tile(name).querySelector('[data-role="step-link"]') as HTMLElement;

function click(el: Element, mods: {metaKey?: boolean; altKey?: boolean; ctrlKey?: boolean} = {}): void {
    el.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true, ...mods}));
}

/** trigger → call (→ "after"), plus an inner flow box the call may point at. */
function callFlow(callConfig: Record<string, any>): any {
    return flowRecord(7, 'Main', [
        st('t1', 'cron', 'nightly', 0, 0, CRON),
        st('c1', 'sub_flow', 'call', 200, 0, callConfig),
        st('a1', 'logger', 'after', 400, 0, {message: 'x'}),
        st('b1', 'inner_flow', 'box', 0, 300, {children: []})
    ], [{from: 't1', fromPort: 0, to: 'c1'}, {from: 'c1', fromPort: 0, to: 'a1'}]);
}

describe('OntologyFlowEditorComponent — the glyph after a Call Subflow / Foreach name', () => {
    useFlowEditorEnv(FlowEditorComponent);

    it('marks a Call Subflow with the subflow glyph, named in its tooltip, once the catalog is in', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        createEditor({flow: callFlow({subflow: '20'})});
        await settle();

        expect(glyphs()).toEqual([['call', 'fa-sitemap', KEY('step_link_title')]]);
        // Text-less: the label still reads the name (and the tile is found by it).
        expect(tile('call').querySelector('.aaxis-flow-editor__step-name')!.textContent).toBe('call');
    });

    it('opens the subflow on Cmd/Alt+click only', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        createEditor({flow: callFlow({subflow: '20'})});
        await settle();

        click(glyphOf('call'));
        await flush();
        expect(tabTitles()).toHaveLength(1);

        click(glyphOf('call'), {metaKey: true});
        await flush();
        expect(tabTitles()).toEqual(['nightly', 'enrich']);
    });

    it('marks an inner-flow target with the inner-flow glyph, and brings its box into view on Alt+click', async () => {
        createEditor({flow: callFlow({inner: 'b1'})});
        await settle();

        expect(glyphs()).toEqual([['call', 'fa-object-group', KEY('step_link_title')]]);
        click(glyphOf('call'), {altKey: true});
        expect(selectedNames()).toEqual(['box']);
    });

    it('marks a Foreach Loop too — even without an outgoing line — but not a missing target', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        createEditor({flow: flowRecord(7, 'Main', [
            st('t1', 'cron', 'nightly', 0, 0, CRON),
            st('c1', 'sub_flow', 'call', 200, 0, {subflow: '404'}),
            st('c2', 'foreach', 'each', 400, 0, {subflow: '20', array: 'rows', item: 'row'})
        ], [{from: 't1', fromPort: 0, to: 'c1'}, {from: 'c1', fromPort: 0, to: 'c2'}])});
        await settle();

        expect(glyphs().map(g => g[0])).toEqual(['each']);
    });

    it('follows a new target picked in the settings, and survives a rename', async () => {
        mockFetch({aaxis_ontology_flow_list: {body: FLOWS}});
        const editor = createEditor({flow: callFlow({subflow: '20'})});
        await settle();

        openSettings('call');
        await flush();
        $('.aaxis-flow-editor__settings input.form-control').first().val('call renamed');
        $('.aaxis-flow-editor__combo input').trigger('focus').val('').trigger('input');
        const option = $('.aaxis-flow-editor__combo-option').filter((_i: number, el: HTMLElement) => el.textContent === KEY('inner_flow_option'))[0];
        option.dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));
        $('.aaxis-flow-editor__settings .btn-primary').trigger('click');

        expect(configOf(editor, 'call renamed')).toEqual({inner: 'b1'});
        expect(glyphs()).toEqual([['call renamed', 'fa-object-group', KEY('step_link_title')]]);
    });
});

describe('OntologyFlowEditorComponent — dropping a new element on a line', () => {
    useFlowEditorEnv(FlowEditorComponent);

    function twoSteps(sourceType = 'dwl_transform', fromPort = 0): any {
        return flowRecord(7, 'Main', [
            st('t1', 'cron', 'nightly', 0, 200, CRON),
            st('a1', sourceType, 'first', 0, 0, sourceType === 'choice' ? {expression: 'x'} : {code: 'x', destination: 'x'}),
            st('b1', 'logger', 'second', 400, 0, {message: 'x'})
        ], [{from: 'a1', fromPort, to: 'b1'}]);
    }

    it('splices the new element in between the two, where it was dropped', () => {
        const editor = createEditor({flow: twoSteps()});

        dropFromToolbox('event', 240, 41);

        expect(linksOf(editor)).toEqual(['first:0->event-1', 'event-1:0->second']);
        expect(positionOf('event-1')).toEqual([200, 0]);
        expect(tile('event-1').classList.contains('is-config-invalid')).toBe(true);
    });

    it('keeps a Choice\'s branch: the new element takes the line\'s port', () => {
        const editor = createEditor({flow: flowRecord(7, 'Main', [
            st('a1', 'choice', 'gate', 0, 0, {expression: 'x'}),
            st('b1', 'logger', 'second', 400, 0, {message: 'x'})
        ], [{from: 'a1', fromPort: 0, to: 'b1'}])});

        dropFromToolbox('choice', 240, 41);

        // gate's green line now goes to the new Choice, which continues on ITS green line.
        expect(linksOf(editor)).toEqual(['gate:0->choice-1', 'choice-1:0->second']);
    });

    it('highlights the line while the new element hovers it', () => {
        createEditor({flow: twoSteps()});
        const item = document.querySelector('[data-role="toolbox"] [data-step-type="event"]') as HTMLElement;

        pointer(item, 'pointerdown', TOOLBOX_RECT.left + 10, TOOLBOX_RECT.top + 10);
        pointer(window, 'pointermove', ...client(240, 41));
        expect(document.querySelectorAll('[data-role="wire-group"].is-splice-target')).toHaveLength(1);
        pointer(window, 'pointermove', ...client(240, 140));
        expect(document.querySelectorAll('[data-role="wire-group"].is-splice-target')).toHaveLength(0);
        pointer(window, 'pointermove', ...client(240, 41));
        pointer(window, 'pointerup', ...client(240, 41));
        expect(document.querySelectorAll('[data-role="wire-group"].is-splice-target')).toHaveLength(0);
    });

    it('leaves the line alone for a drop beside it, a trigger or an inner flow', () => {
        const editor = createEditor({flow: twoSteps()});

        dropFromToolbox('logger', 240, 90);
        expect(linksOf(editor)).toEqual(['first:0->second']);

        dropFromToolbox('inner_flow', 240, 41);
        expect(linksOf(editor)).toEqual(['first:0->second']);
    });
});

import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import FlowRunsIndexComponent from '../../../../Resources/js-src/app/components/flow-runs-index-component';
import {flush, stubFetch} from '../../helpers/page-mocks';

const REBUILD_URL = '/aaxis_ontology_config_flow_runs_rebuild';

function answer(status: number, body: unknown): any {
    return {ok: status < 400, status, json: () => Promise.resolve(body)};
}

describe('FlowRunsIndexComponent', () => {
    let $el: any;
    let component: any;

    beforeEach(() => {
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
        $el = $(
            '<div><button data-role="flow-runs-rebuild">Rebuild</button>'
            + '<div data-role="flow-runs-rebuild-result" hidden></div></div>'
        ).appendTo(document.body);
    });

    afterEach(() => {
        component?.dispose();
        $el.remove();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    const create = () => {
        component = new (FlowRunsIndexComponent as any)({_sourceElement: $el});
        return component;
    };
    const $button = () => $el.find('[data-role="flow-runs-rebuild"]');
    const $result = () => $el.find('[data-role="flow-runs-rebuild-result"]');

    it('queues the rebuild with the CSRF header and says it runs in the background', async () => {
        const fetchSpy = stubFetch({[REBUILD_URL]: answer(200, {success: true})});
        create();

        $button().trigger('click');
        $button().trigger('click'); // ignored while the request is in flight
        expect($button().prop('disabled')).toBe(true);
        await flush();

        expect(fetchSpy).toHaveBeenCalledTimes(1);
        const [url, init] = fetchSpy.mock.calls[0];
        expect(url).toBe(REBUILD_URL);
        expect(init).toMatchObject({method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Header': 'tok'}});
        expect($result().prop('hidden')).toBe(false);
        expect($result().find('.is-ok').text()).toContain('aaxis.ontology.config.flow_runs_index.queued');
        // Queued: no reason to press it again.
        expect($button().prop('disabled')).toBe(true);
    });

    it.each([
        ['a server error', () => answer(500, {success: false})],
        ['an unexpected answer', () => answer(200, {})],
        ['a network failure', () => Promise.reject(new Error('offline'))]
    ])('reports %s and lets the user try again', async (_label, reply) => {
        stubFetch({[REBUILD_URL]: reply});
        create();

        $button().trigger('click');
        await flush();

        expect($result().find('.is-fail').text()).toContain('aaxis.ontology.config.flow_runs_index.queue_failed');
        expect($button().prop('disabled')).toBe(false);
    });

    it('unbinds the button on dispose; a second dispose is a no-op', () => {
        const fetchSpy = stubFetch({[REBUILD_URL]: answer(200, {success: true})});
        create().dispose();
        component.dispose();

        $button().trigger('click');

        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

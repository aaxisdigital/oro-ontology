import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import FlowHistoryMaintenanceComponent from '../../../../Resources/js-src/app/components/flow-history-maintenance-component';
import {FakeDialog, flush, stubFetch} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());

const REBUILD_URL = '/aaxis_ontology_config_flow_runs_rebuild';
const PURGE_URL = '/aaxis_ontology_config_flow_history_purge';

const REBUILD = {
    route: 'aaxis_ontology_config_flow_runs_rebuild',
    queued: 'aaxis.ontology.config.flow_runs_index.queued',
    failed: 'aaxis.ontology.config.flow_runs_index.queue_failed'
};

const PURGE = {
    route: 'aaxis_ontology_config_flow_history_purge',
    queued: 'aaxis.ontology.config.flow_history_purge.queued',
    failed: 'aaxis.ontology.config.flow_history_purge.queue_failed',
    confirm: {
        title: 'aaxis.ontology.config.flow_history_purge.confirm_title',
        question: 'aaxis.ontology.config.flow_history_purge.confirm_question',
        button: 'aaxis.ontology.config.flow_history_purge.confirm_button',
        params: {days: 30}
    }
};

function answer(status: number, body: unknown): any {
    return {ok: status < 400, status, json: () => Promise.resolve(body)};
}

describe('FlowHistoryMaintenanceComponent', () => {
    let $el: any;
    let component: any;

    beforeEach(() => {
        FakeDialog.instances = [];
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
        $el = $(
            '<div><button data-role="maintenance-run">Run</button>'
            + '<div data-role="maintenance-result" hidden></div></div>'
        ).appendTo(document.body);
    });

    afterEach(() => {
        component?.dispose();
        $el.remove();
        $('.fake-dialog-body').remove();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    const create = (options: Record<string, unknown> = REBUILD) => {
        component = new (FlowHistoryMaintenanceComponent as any)({_sourceElement: $el, ...options});
        return component;
    };
    const $button = () => $el.find('[data-role="maintenance-run"]');
    const $result = () => $el.find('[data-role="maintenance-result"]');
    const dialogButton = (label: string) => FakeDialog.instances.at(-1)!.$content.find('button')
        .filter((_i: number, el: HTMLElement) => el.textContent === label);

    it('queues the job on its route with the CSRF header and says it runs in the background', async () => {
        const fetchSpy = stubFetch({[REBUILD_URL]: answer(200, {success: true})});
        create();

        $button().trigger('click');
        $button().trigger('click'); // ignored while the request is in flight
        expect($button().prop('disabled')).toBe(true);
        await flush();

        expect(FakeDialog.instances).toHaveLength(0); // no confirmation configured
        expect(fetchSpy).toHaveBeenCalledTimes(1);
        const [url, init] = fetchSpy.mock.calls[0];
        expect(url).toBe(REBUILD_URL);
        expect(init).toMatchObject({method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Header': 'tok'}});
        expect($result().prop('hidden')).toBe(false);
        expect($result().find('.is-ok').text()).toContain(REBUILD.queued);
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

        expect($result().find('.is-fail').text()).toContain(REBUILD.failed);
        expect($button().prop('disabled')).toBe(false);
    });

    it('asks first when the job is destructive: Cancel queues nothing', async () => {
        const fetchSpy = stubFetch({[PURGE_URL]: answer(200, {success: true})});
        create(PURGE);

        $button().trigger('click');
        const dialog = FakeDialog.instances.at(-1)!;
        expect(dialog.options.title).toBe(PURGE.confirm.title);
        expect(dialog.$content.find('.aaxis-ontology-confirm__q').text()).toBe(PURGE.confirm.question);
        dialogButton('Cancel').trigger('click');
        await flush();

        expect(dialog.close).toHaveBeenCalled();
        expect(fetchSpy).not.toHaveBeenCalled();
        expect($button().prop('disabled')).toBe(false);
    });

    it('queues the confirmed cleanup', async () => {
        const fetchSpy = stubFetch({[PURGE_URL]: answer(200, {success: true})});
        create(PURGE);

        $button().trigger('click');
        dialogButton(PURGE.confirm.button).trigger('click');
        await flush();

        expect(fetchSpy).toHaveBeenCalledTimes(1);
        expect(fetchSpy.mock.calls[0][0]).toBe(PURGE_URL);
        expect($result().find('.is-ok').text()).toContain(PURGE.queued);
    });

    it('unbinds the button on dispose; a second dispose is a no-op', () => {
        const fetchSpy = stubFetch({[REBUILD_URL]: answer(200, {success: true})});
        create().dispose();
        component.dispose();

        $button().trigger('click');

        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

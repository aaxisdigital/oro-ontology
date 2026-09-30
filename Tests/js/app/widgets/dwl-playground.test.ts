import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import DwlPlayground from '../../../../Resources/js-src/app/widgets/dwl-playground';
import {FakeDialog, flush, jsonResponse, resetFakes, stubFetch} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());
// Show translation parameters, which the shared stub drops when the key has no placeholders.
vi.mock('orotranslation/js/translator', () => ({
    default: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key
}));

/** jsdom's Blob cannot be read back synchronously, so remember what it was built from. */
class CapturingBlob extends Blob {
    parts: BlobPart[];

    constructor(parts: BlobPart[] = [], options?: BlobPropertyBag) {
        super(parts, options);
        this.parts = parts;
    }
}

const COUNT_URL = '/aaxis_ontology_entity_dwl_count?id=7';
const RUN_URL = '/aaxis_ontology_entity_dwl?id=7';

describe('DwlPlayground', () => {
    let clicked: HTMLAnchorElement[];
    let createObjectURL: ReturnType<typeof vi.fn>;
    let revokeObjectURL: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        resetFakes();
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 4, 12, 9, 5, 7));
        vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
            cb(0);
            return 1;
        });
        clicked = [];
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            clicked.push(this);
        });
        createObjectURL = vi.fn(() => 'blob:fake');
        revokeObjectURL = vi.fn();
        vi.stubGlobal('Blob', CapturingBlob);
        (window.URL as any).createObjectURL = createObjectURL;
        (window.URL as any).revokeObjectURL = revokeObjectURL;
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    function playground(routes: Record<string, any> = {}, label = 'Order Lines') {
        const fetchSpy = stubFetch({[COUNT_URL]: jsonResponse({success: true, total: 1916}), ...routes});
        const dwl = new DwlPlayground({entityId: 7, entityLabel: label});
        dwl.open();

        return {dwl, fetchSpy, dialog: FakeDialog.instances[0]};
    }

    const $script = () => $('.aaxis-dwl__editor').not('.aaxis-dwl__editor--result');
    const $result = () => $('.aaxis-dwl__editor--result');
    const $run = () => $('.aaxis-dwl__run');
    const $export = () => $('.aaxis-dwl__export');
    const $status = () => $('.aaxis-dwl__status');
    const $limitToggle = () => $('.aaxis-dwl__limit-check');
    const $limitValue = () => $('.aaxis-dwl__limit-value');
    const $total = () => $('.aaxis-dwl__total');

    describe('opening', () => {
        it('opens a movable, sized dialog titled after the entity and hosts the two panes', async () => {
            const {dialog} = playground();
            await flush();

            expect(dialog.options).toMatchObject({
                title: 'aaxis.ontology.dwl.title:{"entity":"Order Lines"}',
                subtitle: 'aaxis.ontology.dwl.subtitle',
                width: '920px',
                height: '640px',
                movable: true,
                bodyClass: 'aaxis-dwl'
            });
            expect(dialog.$content.find('.aaxis-dwl__pane')).toHaveLength(2);
        });

        it('starts with the pass-through script, the default limit and an empty, disabled result', async () => {
            playground();
            await flush();

            expect($script().val()).toBe('output application/json\n---\npayload');
            expect($limitToggle().prop('checked')).toBe(true);
            expect($limitValue().val()).toBe('100');
            expect($result().val()).toBe('');
            expect($export().prop('disabled')).toBe(true);
        });

        it('focuses the script editor on the next frame', async () => {
            playground();
            const focused = vi.fn();
            await flush();
            $script().on('focus', focused);
            // requestAnimationFrame already ran synchronously in open(); re-trigger to prove the wiring target
            $script().trigger('focus');

            expect(focused).toHaveBeenCalled();
        });

        it('greys the result out before the first run, with the stale hint as its tooltip', async () => {
            playground();
            await flush();

            expect($result().hasClass('is-stale')).toBe(true);
            expect($result().attr('title')).toBe('aaxis.ontology.dwl.stale_hint');
        });

        it('shows "loading" until the record count arrives, then the formatted total', async () => {
            const {fetchSpy} = playground();
            expect($total().text()).toBe('aaxis.ontology.dwl.total_loading');

            await flush();

            expect(fetchSpy).toHaveBeenCalledWith(COUNT_URL, {credentials: 'same-origin'});
            expect($total().text()).toBe(
                `aaxis.ontology.dwl.total_records:${JSON.stringify({total: (1916).toLocaleString()})}`
            );
        });

        it.each([
            ['an unsuccessful answer', jsonResponse({success: false})],
            ['a non-numeric total', jsonResponse({success: true, total: 'many'})],
            ['an empty body', jsonResponse(null)]
        ])('blanks the total label on %s', async (_name, response) => {
            playground({[COUNT_URL]: response});
            await flush();

            expect($total().text()).toBe('');
        });

        it('blanks the total label when the count request fails and stays usable', async () => {
            playground({[COUNT_URL]: Promise.reject(new Error('down'))});
            await flush();

            expect($total().text()).toBe('');
            expect($run().prop('disabled')).toBe(false);
        });

        it('forgets the dialog when it closes, so a late response is ignored', async () => {
            let resolveRun: (value: any) => void = () => undefined;
            const {dialog} = playground({[RUN_URL]: new Promise(resolve => {
                resolveRun = resolve;
            })});
            await flush();

            const $held = $result();
            $run().trigger('click');
            dialog.close();
            resolveRun(jsonResponse({success: true, result: 'late'}));
            await flush();

            expect($held.val()).toBe('');
        });
    });

    describe('row limit', () => {
        it('disables the number when the switch is turned off and runs without a cap', async () => {
            const {fetchSpy} = playground({[RUN_URL]: jsonResponse({success: true, result: '[]', rows: 0})});
            await flush();

            $limitToggle().prop('checked', false).trigger('change');
            expect($limitValue().prop('disabled')).toBe(true);
            $run().trigger('click');
            await flush();

            const body = JSON.parse(fetchSpy.mock.calls.find(c => c[0] === RUN_URL)![1].body);
            expect(body.limit).toBeNull();
        });

        it.each([
            ['250', 250],
            ['0', 100],
            ['-5', 100],
            ['abc', 100],
            ['', 100],
            ['12.9', 12]
        ])('limit box "%s" is sent as %s', async (typed, expected) => {
            const {fetchSpy} = playground({[RUN_URL]: jsonResponse({success: true, result: '[]', rows: 0})});
            await flush();

            $limitValue().val(typed).trigger('input');
            $run().trigger('click');
            await flush();

            const body = JSON.parse(fetchSpy.mock.calls.find(c => c[0] === RUN_URL)![1].body);
            expect(body.limit).toBe(expected);
        });
    });

    describe('running', () => {
        it('posts the script and limit with the CSRF header and shows the result and row count', async () => {
            const {fetchSpy} = playground({
                [RUN_URL]: jsonResponse({success: true, result: '{"a":1}', rows: 1234, total: 2000, mime: 'application/json'})
            });
            await flush();

            $script().val('output application/json\n---\npayload.a').trigger('input');
            $run().trigger('click');

            expect($run().prop('disabled')).toBe(true);
            expect($run().find('.fa').hasClass('fa-spin')).toBe(true);
            expect($status().text()).toBe('aaxis.ontology.dwl.running');
            await flush();

            const [, init] = fetchSpy.mock.calls.find(c => c[0] === RUN_URL)!;
            expect(init.method).toBe('POST');
            expect(init.credentials).toBe('same-origin');
            expect(init.headers).toEqual({'Content-Type': 'application/json', 'X-CSRF-Header': 'tok'});
            expect(JSON.parse(init.body)).toEqual({script: 'output application/json\n---\npayload.a', limit: 100});
            expect($result().val()).toBe('{"a":1}');
            expect($result().hasClass('is-error')).toBe(false);
            expect($status().text()).toBe(
                `aaxis.ontology.dwl.status_rows:${JSON.stringify({rows: (1234).toLocaleString()})}`
            );
            expect($total().text()).toContain(JSON.stringify({total: (2000).toLocaleString()}));
            expect($export().prop('disabled')).toBe(false);
            expect($run().prop('disabled')).toBe(false);
            expect($run().find('.fa').hasClass('fa-play')).toBe(true);
            expect($run().find('.fa').hasClass('fa-spin')).toBe(false);
        });

        it('reports a truncated run as limited', async () => {
            playground({[RUN_URL]: jsonResponse({success: true, result: 'x', rows: 100, truncated: true})});
            await flush();

            $run().trigger('click');
            await flush();

            expect($status().text()).toBe(`aaxis.ontology.dwl.status_limited:${JSON.stringify({rows: '100'})}`);
        });

        it('counts a missing row figure as zero', async () => {
            playground({[RUN_URL]: jsonResponse({success: true, result: 'x'})});
            await flush();

            $run().trigger('click');
            await flush();

            expect($status().text()).toBe(`aaxis.ontology.dwl.status_rows:${JSON.stringify({rows: '0'})}`);
        });

        it('un-greys the result after a run and greys it again when the script or limit drifts', async () => {
            playground({[RUN_URL]: jsonResponse({success: true, result: 'x', rows: 1})});
            await flush();

            $run().trigger('click');
            await flush();
            expect($result().hasClass('is-stale')).toBe(false);
            expect($result().attr('title')).toBe('');

            $script().val('changed').trigger('input');
            expect($result().hasClass('is-stale')).toBe(true);

            $script().val('output application/json\n---\npayload').trigger('input');
            expect($result().hasClass('is-stale')).toBe(false);

            $limitValue().val('5').trigger('input');
            expect($result().hasClass('is-stale')).toBe(true);
            $limitValue().val('100').trigger('input');
            expect($result().hasClass('is-stale')).toBe(false);

            $limitToggle().prop('checked', false).trigger('change');
            expect($result().hasClass('is-stale')).toBe(true);
        });

        it('refuses to run an empty script and says so in the result pane', async () => {
            const {fetchSpy} = playground();
            await flush();
            fetchSpy.mockClear();

            $script().val('   ').trigger('input');
            $run().trigger('click');

            expect(fetchSpy).not.toHaveBeenCalled();
            expect($result().val()).toBe('aaxis.ontology.dwl.script_required');
            expect($result().hasClass('is-error')).toBe(true);
            expect($result().hasClass('is-stale')).toBe(false); // the message belongs to these inputs
            expect($export().prop('disabled')).toBe(true);
        });

        it('shows a script error from the server in the result pane, not as stale', async () => {
            playground({[RUN_URL]: jsonResponse({success: false, error: 'Parse error at 1:1'})});
            await flush();

            $run().trigger('click');
            await flush();

            expect($result().val()).toBe('Parse error at 1:1');
            expect($result().hasClass('is-error')).toBe(true);
            expect($result().hasClass('is-stale')).toBe(false);
            expect($status().text()).toBe('');
            expect($export().prop('disabled')).toBe(true);
            expect($run().prop('disabled')).toBe(false);
        });

        it('falls back to a generic message when a failure carries no error text', async () => {
            playground({[RUN_URL]: jsonResponse({success: false})});
            await flush();

            $run().trigger('click');
            await flush();

            expect($result().val()).toBe('aaxis.ontology.dwl.run_error');
        });

        it('falls back to a generic message when the server answers with an empty body', async () => {
            playground({[RUN_URL]: jsonResponse(null)});
            await flush();

            $run().trigger('click');
            await flush();

            expect($result().val()).toBe('aaxis.ontology.dwl.run_error');
        });

        it('falls back to a generic message when the request itself fails', async () => {
            playground({[RUN_URL]: Promise.reject(new Error('network'))});
            await flush();

            $run().trigger('click');
            await flush();

            expect($result().val()).toBe('aaxis.ontology.dwl.run_error');
            expect($result().hasClass('is-error')).toBe(true);
            expect($run().prop('disabled')).toBe(false);
        });

        it('ignores a second click while a run is in flight', async () => {
            let resolveRun: (value: any) => void = () => undefined;
            const {fetchSpy} = playground({[RUN_URL]: new Promise(resolve => {
                resolveRun = resolve;
            })});
            await flush();

            $run().trigger('click');
            $run().trigger('click');
            resolveRun(jsonResponse({success: true, result: 'x', rows: 1}));
            await flush();

            expect(fetchSpy.mock.calls.filter(c => c[0] === RUN_URL)).toHaveLength(1);
        });

        it('keeps Export disabled when the run produced no output', async () => {
            playground({[RUN_URL]: jsonResponse({success: true, result: '', rows: 0})});
            await flush();

            $run().trigger('click');
            await flush();

            expect($export().prop('disabled')).toBe(true);
        });

        it('does not touch the closed dialog when a run settles afterwards', async () => {
            let resolveRun: (value: any) => void = () => undefined;
            const {dialog} = playground({[RUN_URL]: new Promise(resolve => {
                resolveRun = resolve;
            })});
            await flush();

            const $held = $run();
            $held.trigger('click');
            dialog.close();
            resolveRun(jsonResponse({success: true, result: 'x', rows: 1}));
            await flush();

            expect($held.prop('disabled')).toBe(true); // the spinner state was left alone
        });
    });

    describe('export', () => {
        async function runAndExport(response: any, label?: string) {
            playground({[RUN_URL]: jsonResponse(response)}, label);
            await flush();
            $run().trigger('click');
            await flush();
            $export().trigger('click');
        }

        it('downloads the last output verbatim with the resolved mime type and extension', async () => {
            await runAndExport({success: true, result: '<a/>', rows: 1, mime: 'application/xml', extension: 'xml'});

            expect(createObjectURL).toHaveBeenCalledTimes(1);
            const blob: CapturingBlob = createObjectURL.mock.calls[0][0];
            expect(blob.type).toBe('application/xml;charset=utf-8');
            expect(blob.parts).toEqual(['<a/>']);
            expect(clicked).toHaveLength(1);
            expect(clicked[0].href).toBe('blob:fake');
            expect(clicked[0].download).toBe('order-lines-dwl-20260512-090507.xml');
            expect(document.body.contains(clicked[0])).toBe(false);
        });

        it('defaults to json when the server names no format, and revokes the URL on the next tick', async () => {
            await runAndExport({success: true, result: '{}', rows: 1});

            expect(createObjectURL.mock.calls[0][0].type).toBe('application/json;charset=utf-8');
            expect(clicked[0].download.endsWith('.json')).toBe(true);
            expect(revokeObjectURL).not.toHaveBeenCalled();
            vi.advanceTimersByTime(1);
            expect(revokeObjectURL).toHaveBeenCalledWith('blob:fake');
        });

        it.each([
            ['Sales  Order / Header!!', 'sales-order-header-dwl-'],
            ['', 'entity-dwl-'],
            ['***', 'entity-dwl-']
        ])('slugs the entity label "%s" into the file name', async (label, prefix) => {
            await runAndExport({success: true, result: 'x', rows: 1}, label);

            expect(clicked[0].download.startsWith(prefix)).toBe(true);
        });

        it('does nothing when there is no successful run to export', async () => {
            playground({[RUN_URL]: jsonResponse({success: false, error: 'bad'})});
            await flush();
            $run().trigger('click');
            await flush();

            $export().prop('disabled', false).trigger('click');

            expect(createObjectURL).not.toHaveBeenCalled();
        });
    });

    describe('transport', () => {
        it('sends an empty CSRF header when the cookie is missing', async () => {
            vi.spyOn(document, 'cookie', 'get').mockReturnValue('');
            const {fetchSpy} = playground({[RUN_URL]: jsonResponse({success: true, result: 'x', rows: 1})});
            await flush();

            $run().trigger('click');
            await flush();

            const [, init] = fetchSpy.mock.calls.find(c => c[0] === RUN_URL)!;
            expect(init.headers['X-CSRF-Header']).toBe('');
        });

        it('uses the https- prefixed cookie on https pages', async () => {
            vi.spyOn(window, 'location', 'get').mockReturnValue({protocol: 'https:', search: ''} as any);
            vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=plain; https-_csrf=secure%20value');
            const {fetchSpy} = playground({[RUN_URL]: jsonResponse({success: true, result: 'x', rows: 1})});
            await flush();

            $run().trigger('click');
            await flush();

            const [, init] = fetchSpy.mock.calls.find(c => c[0] === RUN_URL)!;
            expect(init.headers['X-CSRF-Header']).toBe('secure value');
        });
    });
});

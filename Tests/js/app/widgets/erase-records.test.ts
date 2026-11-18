import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import EraseRecordsDialog, {ERASE_OPERATORS} from '../../../../Resources/js-src/app/widgets/erase-records';
import {FakeDialog, flush, jsonResponse, resetFakes, stubFetch} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());

const PREVIEW = '/aaxis_ontology_entity_erase_preview';
const ERASE = '/aaxis_ontology_entity_erase_records';
const T = (key: string): string => `aaxis.ontology.erase.${key}`;

function deferred(): {promise: Promise<any>; resolve: (v: any) => void} {
    let resolve!: (v: any) => void;
    const promise = new Promise(r => {
        resolve = r;
    });

    return {promise, resolve};
}

describe('EraseRecordsDialog', () => {
    let onErased: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        resetFakes();
        vi.mocked(messenger.notificationFlashMessage).mockClear();
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
        onErased = vi.fn();
    });

    afterEach(() => {
        $('.fake-dialog-body').remove();
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    function open(routes: Record<string, any>, attributes = ['id', 'status']): {dialog: any; fetchSpy: any; $: (role: string) => any} {
        const fetchSpy = stubFetch(routes);
        new EraseRecordsDialog({entityId: 4, entityLabel: 'Orders', attributes, onErased}).open();
        const dialog = FakeDialog.instances.at(-1)!;

        return {dialog, fetchSpy, $: (role: string) => dialog.$content.find(`[data-role="${role}"]`)};
    }

    const urls = (spy: any): string[] => spy.mock.calls.map((c: any[]) => c[0]);
    const pick = (q: (r: string) => any, role: string, value: string) => q(role).val(value).trigger('change');
    const filterMode = (q: (r: string) => any) => q('erase-mode').filter('[value="filter"]').prop('checked', true).trigger('change');

    it('opens on all records, counts them and names the count on the Erase button', async () => {
        const {dialog, fetchSpy, $: q} = open({[PREVIEW]: jsonResponse({success: true, total: 7})});

        expect(dialog.options).toMatchObject({title: T('title'), bodyClass: 'aaxis-erase-host'});
        expect(dialog.$content.find('.aaxis-erase__question').text()).toBe(T('question').replace('{{ name }}', 'Orders'));
        expect(q('erase-attribute').prop('disabled')).toBe(true);
        expect(q('erase-filter').hasClass('is-disabled')).toBe(true);
        expect(q('erase-count').text()).toBe(T('counting'));
        expect(q('erase-confirm').prop('disabled')).toBe(true);
        await flush();

        expect(urls(fetchSpy)).toEqual([`${PREVIEW}?id=4&mode=all`]);
        expect(q('erase-count').text()).toBe(T('selected').replace('{{ count }}', '7'));
        expect(q('erase-confirm').prop('disabled')).toBe(false);
        expect(q('erase-confirm').text()).toBe(T('confirm').replace('{{ count }}', '7'));
        expect(q('erase-preview-toggle').prop('disabled')).toBe(false);
    });

    it('lists the attributes given and every comparison', () => {
        const {$: q} = open({[PREVIEW]: jsonResponse({success: true, total: 0})});

        expect(q('erase-attribute').find('option').map((_i: number, el: HTMLOptionElement) => el.value).get()).toEqual(['id', 'status']);
        expect(q('erase-operator').find('option').map((_i: number, el: HTMLOptionElement) => el.value).get()).toEqual([...ERASE_OPERATORS]);
        expect(q('erase-operator').find('option').first().text()).toBe(T('op.eq'));
    });

    it('does not ask the server while the filter misses its value, and counts a typed one after a pause', async () => {
        vi.useFakeTimers();
        const {fetchSpy, $: q} = open({[PREVIEW]: jsonResponse({success: true, total: 2})});
        await flush();
        fetchSpy.mockClear();

        filterMode(q);
        await flush();
        expect(fetchSpy).not.toHaveBeenCalled();
        expect(q('erase-count').text()).toBe(T('value_required'));
        expect(q('erase-confirm').prop('disabled')).toBe(true);
        expect(q('erase-attribute').prop('disabled')).toBe(false);

        pick(q, 'erase-attribute', 'status');
        q('erase-value').val('op').trigger('input');
        q('erase-value').val('open').trigger('input');
        expect(q('erase-count').text()).toBe(T('counting'));
        vi.advanceTimersByTime(349);
        expect(fetchSpy).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        await flush();

        expect(urls(fetchSpy)).toEqual([`${PREVIEW}?id=4&mode=filter&attribute=status&operator=eq&value=open`]);
        expect(q('erase-count').text()).toBe(T('selected').replace('{{ count }}', '2'));
    });

    it('takes no value for empty / not empty, and explains the wildcards for matches', async () => {
        const {fetchSpy, $: q} = open({[PREVIEW]: jsonResponse({success: true, total: 1})});
        filterMode(q);
        expect(q('erase-hint').prop('hidden')).toBe(true);

        pick(q, 'erase-operator', 'matches');
        expect(q('erase-hint').prop('hidden')).toBe(false);
        expect(q('erase-value').prop('hidden')).toBe(false);

        q('erase-value').val('stale');
        pick(q, 'erase-operator', 'not_empty');
        expect(q('erase-value').prop('hidden')).toBe(true);
        expect(q('erase-value').prop('disabled')).toBe(true);
        expect(q('erase-hint').prop('hidden')).toBe(true);
        await flush();

        expect(urls(fetchSpy).at(-1)).toBe(`${PREVIEW}?id=4&mode=filter&attribute=id&operator=not_empty&value=`);
    });

    it('says when the entity has no attribute to filter on', () => {
        const {fetchSpy, $: q} = open({[PREVIEW]: jsonResponse({success: true, total: 1})}, []);
        fetchSpy.mockClear();

        filterMode(q);

        expect(q('erase-count').text()).toBe(T('no_attributes'));
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it.each([
        ['the server reason', () => jsonResponse({success: false, message: 'Unsupported comparison "x".'}, false), 'Unsupported comparison "x".'],
        ['the generic message', () => Promise.reject(new Error('offline')), T('count_error')]
    ])('shows %s when the selection cannot be counted', async (_label, answer, message) => {
        const {$: q} = open({[PREVIEW]: answer});
        await flush();

        expect(q('erase-count').text()).toBe(message);
        expect(q('erase-count').hasClass('is-error')).toBe(true);
        expect(q('erase-confirm').prop('disabled')).toBe(true);
    });

    it('drops an answer that arrives after the selection changed', async () => {
        const slow = deferred();
        const answers = [slow.promise, Promise.resolve(jsonResponse({success: true, total: 3}))];
        const {$: q} = open({[PREVIEW]: () => answers.shift()});
        filterMode(q);
        pick(q, 'erase-operator', 'empty');
        await flush();
        expect(q('erase-count').text()).toBe(T('selected').replace('{{ count }}', '3'));

        slow.resolve(jsonResponse({success: true, total: 99}));
        await flush();

        expect(q('erase-count').text()).toBe(T('selected').replace('{{ count }}', '3'));
    });

    it('previews the selection page by page, counting through the same request', async () => {
        const page = (n: number) => jsonResponse({
            success: true, total: 12, page: n,
            records: [{uniqueId: `R${n}`, version: 2, updatedAt: null, payload: {status: 'open', n}}]
        });
        const {fetchSpy, $: q} = open({[PREVIEW]: (url: string) => (url.includes('rows=1') ? page(url.includes('page=2') ? 2 : 1) : jsonResponse({success: true, total: 12}))});
        await flush();
        fetchSpy.mockClear();

        q('erase-preview-toggle').trigger('click');
        await flush();

        expect(urls(fetchSpy)).toEqual([`${PREVIEW}?id=4&mode=all&rows=1&page=1&pageSize=10`]);
        expect(q('erase-preview-toggle').text()).toBe(T('preview_hide'));
        const $rows = q('erase-preview').find('tbody tr');
        expect($rows).toHaveLength(1);
        expect($rows.find('td').map((_i: number, el: HTMLElement) => el.textContent).get()).toEqual(['R1', '2', '', '{"status":"open","n":1}']);
        expect($rows.find('code').attr('title')).toContain('"status": "open"');
        expect(q('erase-page-info').text()).toBe(T('page_of').replace('{{ page }}', '1').replace('{{ pages }}', '2'));
        expect(q('erase-page').first().prop('disabled')).toBe(true);

        q('erase-page').last().trigger('click');
        await flush();
        expect(q('erase-preview').find('tbody td').first().text()).toBe('R2');
        expect(q('erase-page').last().prop('disabled')).toBe(true);

        // A changed selection restarts at page 1, still through the preview request alone.
        fetchSpy.mockClear();
        filterMode(q);
        pick(q, 'erase-operator', 'not_empty');
        await flush();
        expect(urls(fetchSpy).every(u => u.includes('rows=1&page=1'))).toBe(true);

        q('erase-preview-toggle').trigger('click');
        expect(q('erase-preview').prop('hidden')).toBe(true);
        expect(q('erase-preview').children()).toHaveLength(0);
        expect(q('erase-preview-toggle').text()).toBe(T('preview_show'));
    });

    it('says when a preview page is empty, and clears the preview on a failed or incomplete selection', async () => {
        const {$: q} = open({
            [PREVIEW]: (url: string) => (url.includes('value=bad')
                ? jsonResponse({success: false, message: 'nope'}, false)
                : jsonResponse({success: true, total: 1, records: []}))
        });
        await flush();
        q('erase-preview-toggle').trigger('click');
        await flush();
        expect(q('erase-preview').find('.aaxis-erase__empty').text()).toBe(T('preview_empty'));

        filterMode(q);
        await flush();
        expect(q('erase-preview').children()).toHaveLength(0);
        expect(q('erase-count').text()).toBe(T('value_required'));

        q('erase-value').val('bad');
        pick(q, 'erase-operator', 'eq');
        await flush();
        expect(q('erase-count').text()).toBe('nope');
        expect(q('erase-preview').children()).toHaveLength(0);
    });

    it('erases exactly the counted selection, flashes the result and hands over', async () => {
        const {dialog, fetchSpy, $: q} = open({
            [PREVIEW]: jsonResponse({success: true, total: 2}),
            [ERASE]: jsonResponse({success: true, deleted: 2})
        });
        filterMode(q);
        pick(q, 'erase-attribute', 'status');
        q('erase-value').val('closed');
        pick(q, 'erase-operator', 'ne');
        await flush();

        q('erase-confirm').trigger('click');
        q('erase-confirm').trigger('click'); // ignored while erasing
        expect(q('erase-confirm').find('.fa-spinner')).toHaveLength(1);
        await flush();

        const calls = fetchSpy.mock.calls.filter((c: any[]) => c[0].startsWith(ERASE));
        expect(calls).toHaveLength(1);
        expect(calls[0][0]).toBe(`${ERASE}?id=4`);
        expect(calls[0][1]).toMatchObject({method: 'POST', headers: {'X-CSRF-Header': 'tok'}});
        expect(JSON.parse(calls[0][1].body)).toEqual({mode: 'filter', attribute: 'status', operator: 'ne', value: 'closed'});
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', 'aaxis.ontology.entity_manager.purged');
        expect(dialog.close).toHaveBeenCalled();
        expect(onErased).toHaveBeenCalledWith(2);
    });

    it.each([
        ['with the server reason', () => jsonResponse({success: false, message: 'Locked'}, false), 'Locked'],
        ['with the generic message', () => Promise.reject(new Error('net')), 'aaxis.ontology.entity_manager.purge_error']
    ])('keeps the popup open on a failed erase, %s', async (_label, answer, message) => {
        const {dialog, $: q} = open({[PREVIEW]: jsonResponse({success: true, total: 5}), [ERASE]: answer});
        await flush();

        q('erase-confirm').trigger('click');
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', message);
        expect(q('erase-count').text()).toBe(message);
        expect(q('erase-confirm').prop('disabled')).toBe(false);
        expect(q('erase-confirm').find('.fa-spinner')).toHaveLength(0);
        expect(dialog.close).not.toHaveBeenCalled();
        expect(onErased).not.toHaveBeenCalled();
    });

    it('closes on Cancel, dropping a pending recount', async () => {
        vi.useFakeTimers();
        const {dialog, fetchSpy, $: q} = open({[PREVIEW]: jsonResponse({success: true, total: 1})});
        await flush();
        fetchSpy.mockClear();
        filterMode(q);
        q('erase-value').val('x').trigger('input');

        q('erase-cancel').trigger('click');
        vi.advanceTimersByTime(1000);

        expect(dialog.close).toHaveBeenCalled();
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import mediator from 'oroui/js/mediator';
import DataPlaygroundComponent, {inputNameFor, isInputName} from '../../../../Resources/js-src/app/components/data-playground-component';
import {FakeDialog, flush, jsonResponse, pageElement, resetFakes, stubFetch} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());

const T = (key: string): string => `aaxis.ontology.data_playground.${key}`;
const LIST = 'aaxis_ontology_dwl_script_list';
const RUN = 'aaxis_ontology_dwl_script_run';
const CREATE = 'aaxis_ontology_dwl_script_create';
const UPDATE = 'aaxis_ontology_dwl_script_update';
const DELETE = 'aaxis_ontology_dwl_script_delete';
const COUNT = 'aaxis_ontology_entity_dwl_count';

function script(id: number, name: string, extra: Record<string, unknown> = {}): any {
    return {id, name, script: `"${name}"`, public: false, mine: true, owner: 'Me', inputs: [], limit: 100, ...extra};
}

const SCRIPTS = [
    script(1, 'alpha', {inputs: [{entityId: 10, name: 'orders'}, {entityId: 99, name: 'gone'}], limit: 5}),
    script(2, 'beta', {public: true}),
    script(3, 'shared report', {public: true, mine: false, owner: 'Ann Lee', inputs: [{entityId: 12, name: 'leads'}]})
];
const ENTITIES = [{id: 10, name: 'Order', system: 'ERP'}, {id: 11, name: 'order-line', system: 'ERP'}, {id: 12, name: 'Lead', system: 'CRM'}];

describe('isInputName / inputNameFor', () => {
    it('accepts DWL variables only', () => {
        expect(['payload', '_x1', 'order_lines', 'a'.repeat(64)].every(isInputName)).toBe(true);
        expect(['1st', 'order-lines', 'a b', '', 'match', 'Default', 'a'.repeat(65)].some(isInputName)).toBe(false);
    });

    it('derives a free variable name from an entity name', () => {
        expect(inputNameFor('classification-of-product', [])).toBe('classification_of_product');
        expect(inputNameFor('Order', ['Order'])).toBe('Order_2');
        expect(inputNameFor('Order', ['Order', 'Order_2'])).toBe('Order_3');
        expect(inputNameFor('2024 sales', [])).toBe('_2024_sales');
        expect(inputNameFor('match', [])).toBe('match_');
        expect(inputNameFor('---', [])).toBe('records');
        expect(inputNameFor('Oro\\Bundle\\ProductBundle\\Entity\\Product', [])).toBe('Product');
    });
});

describe('DataPlaygroundComponent', () => {
    let $el: any;
    let component: any;

    beforeEach(() => {
        resetFakes();
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
        window.history.replaceState(null, '', '/data-playground');
    });

    afterEach(() => {
        component?.dispose();
        component = null;
        $el?.remove();
        vi.unstubAllGlobals();
    });

    async function mount(routes: Record<string, any> = {}, options: Record<string, unknown> = {}): Promise<ReturnType<typeof stubFetch>> {
        $el = pageElement('<div data-role="playground"></div>');
        const fetchSpy = stubFetch({
            [LIST]: jsonResponse({scripts: SCRIPTS.map(s => ({...s})), entities: ENTITIES}),
            [COUNT]: (url: string) => jsonResponse({success: true, total: url.includes('id=10') ? 1695 : 20}),
            ...routes
        });
        component = new (DataPlaygroundComponent as any)({_sourceElement: $el, canCreate: true, canEdit: true, canDelete: true, ...options});
        await flush();

        return fetchSpy;
    }

    const q = (role: string): any => $el.find(`[data-role="${role}"]`);
    const items = (): string[] => q('script-list').find('.aaxis-dwl-pg__item-name').map((_i: number, el: HTMLElement) => el.textContent).get();
    const item = (name: string): any => q('script-list').find('.aaxis-dwl-pg__item').filter((_i: number, el: HTMLElement) => $(el).find('.aaxis-dwl-pg__item-name').text() === name);
    const sent = (spy: any, route: string): any[] => spy.mock.calls.filter((c: any[]) => String(c[0]).includes(route)).map((c: any[]) => (c[1]?.body ? JSON.parse(c[1].body) : null));
    const typeCode = (text: string): void => {
        $el.find('.aaxis-dwl-pg__script').val(text).trigger('input');
    };
    const shown = (): boolean[] => ['script-save', 'script-copy'].map(role => !q(role).prop('hidden'));
    const lock = (name: string): string => {
        const $lock = item(name).find('[data-role="script-lock"]');
        return `${$lock.hasClass('fa-lock') ? 'private' : ''}${$lock.hasClass('fa-unlock-alt') && $lock.hasClass('is-public') ? 'public' : ''}`;
    };
    const trash = (name: string): any => item(name).find('[data-role="script-item-delete"]');
    /** Each chip as "name ← entity · count" (count only once known). */
    const chips = (): string[] => q('input-chip').map((_i: number, el: HTMLElement) => {
        const $chip = $(el);
        const count = $chip.find('.aaxis-dwl-pg__input-count').text();
        return `${$chip.find('.aaxis-dwl-pg__input-name').text()} ← ${$chip.find('.aaxis-dwl-pg__input-entity').text()}${count ? ` · ${count}` : ''}`;
    }).get();
    const options = (): string[] => q('input-entities').children().map((_i: number, el: HTMLElement) => el.textContent).get();
    const pick = (name: string): void => {
        const option = q('input-entity').filter((_i: number, el: HTMLElement) => el.textContent === name)[0];
        option.dispatchEvent(new MouseEvent('pointerdown', {bubbles: true, cancelable: true}));
    };
    const rename = (name: string): void => {
        q('input-name').val(name).trigger('input');
    };

    it('lays out the list, the top row (name · inputs · public · actions), the code and the result row', async () => {
        await mount();

        expect(items()).toEqual([T('untitled'), 'alpha', 'beta', 'shared report']);
        expect(q('script-draft').hasClass('is-active')).toBe(true);
        // The API collection's padlock before each name: red lock = private, green unlocked = public.
        expect([lock('alpha'), lock('beta'), lock('shared report')]).toEqual(['private', 'public', 'public']);
        expect(item('shared report').find('.aaxis-dwl-pg__item-owner').text()).toBe('Ann Lee');
        // A trash on the user's own saved scripts only.
        expect([trash('alpha').length, trash('beta').length, trash('shared report').length]).toEqual([1, 1, 0]);
        expect(q('script-draft').find('[data-role="script-item-delete"]')).toHaveLength(0);
        expect(q('script-delete')).toHaveLength(0);

        // Public first, then the name and the inputs, the actions last.
        expect($el.find('.aaxis-dwl-pg__head').children().map((_i: number, el: HTMLElement) => el.className.split(' ').pop()).get())
            .toEqual(['aaxis-dwl-pg__public', 'aaxis-dwl-pg__name', 'aaxis-dwl-pg__inputs', 'aaxis-dwl-pg__head-actions']);
        // Result, its status, max rows, Run and Save as… share one row; no format selector.
        expect($el.find('.aaxis-dwl-pg__result-head').children().map((_i: number, el: HTMLElement) => el.dataset.role || el.className.split(' ').pop()).get())
            .toEqual(['aaxis-dwl-pg__result-label', 'run-status', 'aaxis-dwl-pg__limit', 'run-limit', 'run', 'save-as']);
        expect(q('render')).toHaveLength(0);

        expect(chips()).toEqual([]);
        expect(q('input-add').text()).toContain(T('input_add'));
        expect($el.find('.aaxis-dwl-pg__script').val()).toBe('output application/json\n---\npayload');
        expect([q('run-limit-on').prop('checked'), q('run-limit').val()]).toEqual([true, '100']);
        expect(shown()).toEqual([true, false]);
        expect(q('script-save').prop('disabled')).toBe(true);
        expect(q('save-as').prop('disabled')).toBe(true);
    });

    it('opens a script with its inputs and max rows, dropping inputs whose entity is gone', async () => {
        const spy = await mount();

        item('alpha').trigger('click');
        await flush();

        expect(chips()).toEqual(['orders ← Order · 1,695']);
        expect([q('run-limit-on').prop('checked'), q('run-limit').val()]).toEqual([true, '5']);
        expect(sent(spy, COUNT)).toHaveLength(1);
        expect(window.location.search).toBe('?script=1');
        expect(shown()).toEqual([true, false]);

        // The open script's lock follows the Public switch before it is saved.
        q('script-public').prop('checked', true).trigger('change');
        expect(lock('alpha')).toBe('public');
    });

    it('opens a script from the keyboard', async () => {
        await mount();

        item('beta').trigger($.Event('keydown', {key: 'Enter'}));
        await flush();

        expect(item('beta').hasClass('is-active')).toBe(true);
    });

    it('reopens the script named in the URL', async () => {
        window.history.replaceState(null, '', '/data-playground?script=2');
        await mount();

        expect(item('beta').hasClass('is-active')).toBe(true);
        expect(q('script-public').prop('checked')).toBe(true);
    });

    it('filters the list by name', async () => {
        await mount();

        q('script-filter').val('ar').trigger('input');
        expect(items()).toEqual([T('untitled'), 'shared report']);
        q('script-filter').val('zzz').trigger('input');
        expect($el.find('.aaxis-dwl-pg__empty').text()).toBe(T('no_match'));
    });

    it('adds inputs through the dropdown, named after their entity', async () => {
        await mount();

        q('input-add').trigger('click');
        expect(q('input-menu')).toHaveLength(1);
        expect(document.activeElement).toBe(q('input-filter')[0]);
        expect(options()).toEqual(['ERP', 'Order', 'order-line', 'CRM', 'Lead']);
        expect(q('input-name').val()).toBe('');
        expect(q('input-hint').text()).toBe(T('input_hint_entity'));
        expect(q('input-name').hasClass('is-invalid')).toBe(false);
        expect(q('run').prop('disabled')).toBe(true); // no entity yet

        q('input-filter').val('crm').trigger('input');
        expect(options()).toEqual(['CRM', 'Lead']);
        q('input-filter').val('zzz').trigger('input');
        expect(q('input-entities').find('.aaxis-dwl-pg__empty').text()).toBe(T('input_none'));
        q('input-filter').val('order').trigger('input');
        pick('order-line');
        await flush();
        expect(chips()).toEqual(['order_line ← order-line · 20']);
        expect(q('input-name').val()).toBe('order_line');
        expect(q('input-entity').filter('.is-selected').text()).toBe('order-line');
        expect(q('input-hint').text()).toBe(T('input_hint'));
        // The untouched pass-through script reads the first input.
        expect($el.find('.aaxis-dwl-pg__script').val()).toBe('output application/json\n---\norder_line');

        // Picking another entity keeps a name the page chose in step — and the script with it.
        pick('Order');
        await flush();
        expect(chips()).toEqual(['Order ← Order · 1,695']);
        expect($el.find('.aaxis-dwl-pg__script').val()).toBe('output application/json\n---\nOrder');
        rename('orders');
        expect($el.find('.aaxis-dwl-pg__script').val()).toBe('output application/json\n---\norders');
        q('input-menu-done').trigger('click');
        expect(q('input-menu')).toHaveLength(0);

        q('input-add').trigger('click');
        q('input-filter').val('line').trigger('input');
        q('input-filter').trigger($.Event('keydown', {key: 'Enter'}));
        await flush();
        expect(chips()).toEqual(['orders ← Order · 1,695', 'order_line ← order-line · 20']);
        // Two inputs: the script is the user's business now.
        expect($el.find('.aaxis-dwl-pg__script').val()).toBe('output application/json\n---\norders');
        expect(q('run').prop('disabled')).toBe(false);
    });

    it('never rewrites a script the user changed', async () => {
        await mount();
        typeCode('my code');

        q('input-add').trigger('click');
        pick('Order');

        expect($el.find('.aaxis-dwl-pg__script').val()).toBe('my code');
    });

    it('marks an input whose name cannot be a variable or is taken, and blocks Run and Save until fixed', async () => {
        await mount();
        q('input-add').trigger('click');
        pick('Order');
        q('script-name').val('x').trigger('input');

        rename('order lines');
        expect(q('input-chip').hasClass('is-invalid')).toBe(true);
        expect(q('input-name').hasClass('is-invalid')).toBe(true);
        expect(q('input-hint').text()).toBe(T('input_name_invalid'));
        expect([q('run').prop('disabled'), q('script-save').prop('disabled')]).toEqual([true, true]);

        rename('orders');
        q('input-menu-done').trigger('click');
        q('input-add').trigger('click');
        pick('Lead');
        rename('orders');
        expect(q('input-hint').text()).toBe(T('input_name_duplicate'));
        expect(q('input-chip').eq(1).hasClass('is-invalid')).toBe(true);

        rename('leads');
        await flush();
        expect(q('input-chip').filter('.is-invalid')).toHaveLength(0);
        expect(chips()).toEqual(['orders ← Order · 1,695', 'leads ← Lead · 20']);
        expect([q('run').prop('disabled'), q('script-save').prop('disabled')]).toEqual([false, false]);
    });

    it('drops an input left without an entity, and removes inputs from the chip or the dropdown', async () => {
        await mount();
        q('input-add').trigger('click');
        $el.find('.aaxis-dwl-pg__script')[0].dispatchEvent(new MouseEvent('pointerdown', {bubbles: true}));
        expect(q('input-menu')).toHaveLength(0);
        expect(chips()).toEqual([]);

        q('input-add').trigger('click');
        pick('Order');
        q('input-add').trigger('click');
        pick('Lead');
        await flush();
        expect(chips()).toEqual(['Order ← Order · 1,695', 'Lead ← Lead · 20']);
        q('input-menu-remove').trigger('click');
        expect(chips()).toEqual(['Order ← Order · 1,695']);

        q('input-chip').trigger('click');
        expect(q('input-menu')).toHaveLength(1);
        q('input-chip').trigger('click'); // the open chip toggles it shut
        expect(q('input-menu')).toHaveLength(0);
        q('input-chip').trigger('click');
        q('input-name').trigger($.Event('keydown', {key: 'Escape'}));
        expect(q('input-menu')).toHaveLength(0);

        q('input-remove').trigger('click');
        expect(chips()).toEqual([]);
    });

    it('runs the current text against the named inputs and shows the output with the rows shown and each input\'s records', async () => {
        const spy = await mount({[RUN]: jsonResponse({
            success: true, result: 'id,name\n1,a\n', format: 'csv', mime: 'application/csv', extension: 'csv',
            inputs: [{name: 'orders', records: 1695}, {name: 'leads', records: 2}], output: {rows: 1697, shown: 2, truncated: true}
        })});
        item('alpha').trigger('click');
        await flush();
        q('input-add').trigger('click');
        pick('Lead');
        rename('leads');
        q('input-menu-done').trigger('click');
        typeCode('output application/csv\n---\norders');
        q('run-limit').val('2').trigger('input');

        q('run').trigger('click');
        expect(q('run').prop('disabled')).toBe(true);
        await flush();

        expect(sent(spy, RUN)).toEqual([{
            script: 'output application/csv\n---\norders', inputs: [{entityId: 10, name: 'orders'}, {entityId: 12, name: 'leads'}], limit: 2
        }]);
        expect(q('result').val()).toBe('id,name\n1,a\n');
        // Max rows cut the RESULT; the inputs were read in full.
        expect(q('run-status').text()).toBe(`${T('status_rows_limited')} · ${T('status_input')} · ${T('status_input')}`);
        expect(q('save-as').prop('disabled')).toBe(false);

        // The result greys out once the script, the inputs or max rows drift from what ran.
        expect(q('result').hasClass('is-stale')).toBe(false);
        q('run-limit-on').prop('checked', false).trigger('change');
        expect(q('result').hasClass('is-stale')).toBe(true);
        expect(q('run-limit').prop('disabled')).toBe(true);
    });

    it('names every row when max rows let all through, and only the inputs for a result that is no list', async () => {
        await mount({[RUN]: (url: string, init: any) => jsonResponse({
            success: true, result: 'x', mime: 'text/plain', extension: 'txt', inputs: [{name: 'orders', records: 3}],
            output: JSON.parse(init.body).limit === null ? null : {rows: 3, shown: 3, truncated: false}
        })});

        q('run').trigger('click');
        await flush();
        expect(q('run-status').text()).toBe(`${T('status_rows')} · ${T('status_input')}`);

        q('run-limit-on').prop('checked', false).trigger('change');
        q('run').trigger('click');
        await flush();
        expect(q('run-status').text()).toBe(T('status_input'));
    });

    it('shows a failed run in the result pane', async () => {
        await mount({[RUN]: jsonResponse({success: false, error: 'Unexpected token', inputs: []})});

        q('run').trigger('click');
        await flush();

        expect(q('result').val()).toBe('Unexpected token');
        expect(q('result').hasClass('is-error')).toBe(true);
        expect(q('run-status').text()).toBe(T('run_failed'));
        expect(q('save-as').prop('disabled')).toBe(true);
    });

    it('falls back to a generic message when the run request itself fails', async () => {
        await mount({[RUN]: () => Promise.reject(new Error('offline'))});

        q('run').trigger('click');
        await flush();

        expect(q('result').val()).toBe(T('run_error'));
    });

    describe('Save as…', () => {
        const RESULT = (): any => jsonResponse({success: true, result: 'a,b\n', format: 'csv', mime: 'application/csv', extension: 'csv', inputs: []});

        it('asks the browser where to save the last result, named after the script', async () => {
            await mount({[RUN]: RESULT()});
            const write = vi.fn(() => Promise.resolve());
            const close = vi.fn(() => Promise.resolve());
            const picker = vi.fn(() => Promise.resolve({createWritable: () => Promise.resolve({write, close})}));
            vi.stubGlobal('showSaveFilePicker', picker);
            q('script-name').val('my: report').trigger('input');
            q('run').trigger('click');
            await flush();

            q('save-as').trigger('click');
            await flush();

            expect(picker).toHaveBeenCalledWith({suggestedName: 'my_ report.csv', types: [{description: 'application/csv', accept: {'application/csv': ['.csv']}}]});
            expect(write).toHaveBeenCalledWith('a,b\n');
            expect(close).toHaveBeenCalled();
        });

        it('says nothing when the dialog is cancelled, and flashes a real failure', async () => {
            await mount({[RUN]: RESULT()});
            const picker = vi.fn()
                .mockReturnValueOnce(Promise.reject(Object.assign(new Error('cancelled'), {name: 'AbortError'})))
                .mockReturnValueOnce(Promise.reject(new Error('disk full')));
            vi.stubGlobal('showSaveFilePicker', picker);
            q('run').trigger('click');
            await flush();

            q('save-as').trigger('click');
            await flush();
            expect(messenger.notificationFlashMessage).not.toHaveBeenCalled();
            q('save-as').trigger('click');
            await flush();
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', T('save_as_error'));
        });

        it('downloads the file where the browser has no save dialog', async () => {
            await mount({[RUN]: RESULT()});
            vi.stubGlobal('showSaveFilePicker', undefined);
            const createObjectURL = vi.fn(() => 'blob:x');
            const revokeObjectURL = vi.fn();
            vi.stubGlobal('URL', Object.assign(function () {
                return undefined;
            }, {createObjectURL, revokeObjectURL}));
            const clicked: string[] = [];
            const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
                clicked.push(this.download);
            });
            q('run').trigger('click');
            await flush();

            q('save-as').trigger('click');

            expect(clicked).toEqual(['result.csv']);
            expect(createObjectURL).toHaveBeenCalledTimes(1);
            click.mockRestore();
        });
    });

    it('saves a new script with its inputs and max rows, showing the request under way, then selects it', async () => {
        const saved = script(9, 'gamma', {script: 'x', inputs: [{entityId: 11, name: 'order_line'}]});
        let answer!: (reply: any) => void;
        const spy = await mount({[CREATE]: () => new Promise(resolve => {
            answer = resolve;
        })});
        q('script-name').val(' gamma ').trigger('input');
        q('input-add').trigger('click');
        pick('order-line');

        q('script-save').trigger('click');
        await flush();
        expect(q('script-save').find('[data-role="saving"].fa-spinner')).toHaveLength(1);
        expect(q('script-save').attr('aria-busy')).toBe('true');
        expect(q('script-save').prop('disabled')).toBe(true);
        answer(jsonResponse({success: true, script: saved}));
        await flush();
        expect(q('script-save').find('[data-role="saving"]')).toHaveLength(0);
        expect(q('script-save').attr('aria-busy')).toBeUndefined();

        expect(sent(spy, CREATE)).toEqual([{
            name: 'gamma', script: 'output application/json\n---\norder_line', public: false, inputs: [{entityId: 11, name: 'order_line'}], limit: 100
        }]);
        expect(items()).toEqual(['alpha', 'beta', 'gamma', 'shared report']);
        expect(item('gamma').hasClass('is-active')).toBe(true);
        expect(chips()).toEqual(['order_line ← order-line · 20']);
        expect(window.location.search).toBe('?script=9');
        expect(q('script-save').prop('disabled')).toBe(true);
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', T('saved'));
    });

    it('updates an own script with Ctrl/⌘+S and runs with Ctrl/⌘+Enter', async () => {
        const spy = await mount({
            [UPDATE]: jsonResponse({success: true, script: script(2, 'beta', {script: 'x', public: false})}),
            [RUN]: jsonResponse({success: true, result: '1', mime: 'application/json', extension: 'json', inputs: []})
        });
        item('beta').trigger('click');
        await flush();
        q('script-public').prop('checked', false).trigger('change');
        typeCode('x');
        expect(item('beta').find('.aaxis-dwl-pg__item-dirty')).toHaveLength(1);

        $el.find('.aaxis-dwl-pg__script').trigger($.Event('keydown', {key: 'Enter', ctrlKey: true}));
        $el.find('.aaxis-dwl-pg__script').trigger($.Event('keydown', {key: 's', metaKey: true}));
        await flush();

        expect(sent(spy, RUN)).toHaveLength(1);
        expect(String(spy.mock.calls.find((c: any[]) => String(c[0]).includes(UPDATE))[0])).toContain('id=2');
        expect(sent(spy, UPDATE)).toEqual([{name: 'beta', script: 'x', public: false, inputs: [], limit: 100}]);
        expect(item('beta').find('.aaxis-dwl-pg__item-dirty')).toHaveLength(0);
        // The run on screen survives the save.
        expect(q('result').val()).toBe('1');
    });

    it('flashes why a save was refused', async () => {
        await mount({[CREATE]: jsonResponse({success: false, message: 'You already have a script with this name.'}, false)});
        q('script-name').val('alpha').trigger('input');

        q('script-save').trigger('click');
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'You already have a script with this name.');
        expect(q('script-save').prop('disabled')).toBe(false);
    });

    it('shows someone else\'s public script read-only, with "Save a copy" as a private script of the user\'s own', async () => {
        const spy = await mount({[CREATE]: jsonResponse({success: true, script: script(10, 'shared report (copy)')})});
        item('shared report').trigger('click');
        await flush();

        expect(q('script-name').prop('readonly')).toBe(true);
        expect(q('script-public').prop('disabled')).toBe(true);
        expect(q('script-owner').text()).toBe(T('owner'));
        expect(q('script-owner').prop('hidden')).toBe(false);
        expect(shown()).toEqual([false, true]);
        expect(chips()).toEqual(['leads ← Lead · 20']);

        typeCode('changed');
        q('script-copy').trigger('click');
        expect(q('script-copy').find('[data-role="saving"]')).toHaveLength(1);
        await flush();
        expect(q('script-copy').find('[data-role="saving"]')).toHaveLength(0);

        expect(sent(spy, CREATE)).toEqual([{
            name: `shared report ${T('copy_suffix')}`, script: 'changed', public: false, inputs: [{entityId: 12, name: 'leads'}], limit: 100
        }]);
        expect(item('shared report (copy)').hasClass('is-active')).toBe(true);
    });

    it('numbers a copy whose name the user already has', async () => {
        const spy = await mount({[CREATE]: jsonResponse({success: true, script: script(11, 'x')})});
        component.scripts = [...component.scripts, script(20, `shared report ${T('copy_suffix')}`)];
        item('shared report').trigger('click');
        await flush();

        q('script-copy').trigger('click');
        await flush();

        expect(sent(spy, CREATE)[0].name).toBe(`shared report ${T('copy_suffix').slice(0, -1)} 2)`);
    });

    it('deletes an own script from its row after confirming — the open one gives way to a new draft', async () => {
        const spy = await mount({[DELETE]: jsonResponse({success: true})});
        item('alpha').trigger('click');
        await flush();

        trash('alpha').trigger('click');
        expect(item('alpha').hasClass('is-active')).toBe(true); // the trash does not select the row
        const dialog = FakeDialog.instances.at(-1)!;
        expect(dialog.options.title).toBe(T('delete_title'));
        dialog.$content.find('.btn-primary').trigger('click');
        await flush();

        expect(String(spy.mock.calls.find((c: any[]) => String(c[0]).includes(DELETE))[0])).toContain('id=1');
        expect(items()).toEqual([T('untitled'), 'beta', 'shared report']);
        expect(window.location.search).toBe('');
        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', T('deleted'));
    });

    it('deletes another own script without leaving the open one', async () => {
        const spy = await mount({[DELETE]: jsonResponse({success: true})});
        item('alpha').trigger('click');
        await flush();
        typeCode('work in progress');

        trash('beta').trigger('click');
        FakeDialog.instances.at(-1)!.$content.find('.btn-primary').trigger('click');
        await flush();

        expect(String(spy.mock.calls.find((c: any[]) => String(c[0]).includes(DELETE))[0])).toContain('id=2');
        expect(items()).toEqual(['alpha', 'shared report']);
        expect(item('alpha').hasClass('is-active')).toBe(true);
        expect($el.find('.aaxis-dwl-pg__script').val()).toBe('work in progress');
    });

    it('flashes a delete that fails', async () => {
        await mount({[DELETE]: jsonResponse({success: false, message: 'Only the owner of this script can change or delete it.'}, false)});

        trash('beta').trigger('click');
        FakeDialog.instances.at(-1)!.$content.find('.btn-primary').trigger('click');
        await flush();

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'Only the owner of this script can change or delete it.');
        expect(items()).toContain('beta');
    });

    it('asks before discarding unsaved changes', async () => {
        await mount();
        typeCode('unsaved work');

        item('beta').trigger('click');
        const dialog = FakeDialog.instances.at(-1)!;
        expect(dialog.options.title).toBe(T('discard_title'));
        dialog.$content.find('.btn:not(.btn-primary)').trigger('click');
        expect(q('script-draft').hasClass('is-active')).toBe(true);
        expect($el.find('.aaxis-dwl-pg__script').val()).toBe('unsaved work');

        item('beta').trigger('click');
        FakeDialog.instances.at(-1)!.$content.find('.btn-primary').trigger('click');
        await flush();
        expect(item('beta').hasClass('is-active')).toBe(true);

        // A clean script switches at once; New starts a fresh draft.
        const dialogs = FakeDialog.instances.length;
        q('script-new').trigger('click');
        expect(FakeDialog.instances.length).toBe(dialogs);
        expect(q('script-draft').hasClass('is-active')).toBe(true);
    });

    it('guards leaving the page while something is unsaved', async () => {
        await mount();
        const [, onOpenLink] = (mediator.on as any).mock.calls.find((c: any[]) => c[0] === 'openLink:before');
        const leaving = (): any => {
            const e = $.Event('beforeunload');
            $(window).trigger(e);
            return e;
        };

        expect(leaving().isDefaultPrevented()).toBe(false);
        typeCode('work');
        expect(leaving().isDefaultPrevented()).toBe(true);
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        const link: any = {prevented: false};
        onOpenLink(link);
        expect(link.prevented).toBe(true);

        component.dispose();
        expect(mediator.off).toHaveBeenCalledWith('openLink:before', onOpenLink);
        expect(leaving().isDefaultPrevented()).toBe(false);
        component = null;
    });

    it('hides what the user may not do', async () => {
        await mount({}, {canCreate: false, canEdit: false, canDelete: false});

        expect(q('script-new')).toHaveLength(0);
        expect(shown()).toEqual([false, false]);
        expect(q('script-item-delete')).toHaveLength(0);
        item('alpha').trigger('click');
        await flush();
        expect(shown()).toEqual([false, false]);
    });

    it('flashes a list that cannot be loaded', async () => {
        await mount({[LIST]: jsonResponse({message: 'Access denied.'}, false)});

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'Access denied.');
        expect(items()).toEqual([T('untitled')]);
    });
});

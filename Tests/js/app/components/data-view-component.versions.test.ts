import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import {mountDataView} from '../../helpers/data-view';
import {flush, resetFakes} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/data-grid', async () => (await import('../../helpers/page-mocks')).dataGridModule());
vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());
vi.mock('oroform/js/app/views/select2-view', async () => (await import('../../helpers/page-mocks')).select2Module());

const STAMP = '2026-05-12T10:00:00Z';

const V3 = {version: 3, uuid: 'aaaa-3333', updatedAt: STAMP, current: true, payload: {a: 3, b: {c: 1}}};
const V2 = {version: 2, uuid: 'bbbb-2222', updatedAt: STAMP, current: false, payload: {a: 2, b: {c: 1}}};
const V1 = {version: 1, uuid: 'aaab-1111', updatedAt: null, current: false, payload: {a: 1}};

describe('OntologyDataViewComponent — version viewer', () => {
    let component: any;
    let $content: any;

    beforeEach(async () => {
        resetFakes();
        window.localStorage.clear();
        component = mountDataView().component;
        await flush();
        $content = $('<div/>').appendTo(document.body);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        delete (Element.prototype as any).scrollIntoView;
    });

    const render = (versions: any[]) => component.renderVersions($content, versions);
    const $box = () => $content.find('[data-role="version"]');
    const $slider = () => $content.find('input[type="range"]');
    const $pre = () => $content.find('.aaxis-json-view');
    const $error = () => $content.find('.aaxis-json-version__error');
    const $search = () => $content.find('.aaxis-json-search__input');
    const $count = () => $content.find('.aaxis-json-search__count');
    const $diff = () => $content.find('[data-role="diff-only"]');
    const $wrap = () => $content.find('[data-role="wrap-lines"]');
    const label = (v: any, current = false) =>
        ['v' + v.version, v.updatedAt ? new Date(v.updatedAt).toLocaleString() : '', v.uuid]
            .filter(part => part !== '').join(' — ')
        + (current ? '  (aaxis.ontology.data_view.version_current)' : '');

    function type(value: string, key = 'Enter'): void {
        $box().val(value);
        $box().trigger($.Event('keydown', {key}));
    }

    describe('layout', () => {
        it('shows the picker, the total, a slider with one stop per version and the oldest/newest ends', () => {
            render([V3, V2, V1]);

            expect($content.hasClass('aaxis-json-dialog')).toBe(true);
            expect($box().attr('autocomplete')).toBe('off');
            expect($box().attr('title')).toBe('aaxis.ontology.data_view.version_search_hint');
            expect($content.find('.aaxis-json-version__count').text()).toBe('/ 3');
            expect($error().attr('role')).toBe('alert');
            const slider = $slider();
            expect(slider.attr('min')).toBe('0');
            expect(slider.attr('max')).toBe('2');
            expect(slider.attr('step')).toBe('1');
            expect(slider.attr('aria-label')).toBe('aaxis.ontology.data_view.version_slider');
            expect((slider[0] as HTMLInputElement).style.getPropertyValue('--tick-gap')).toBe('50%');
            expect($content.find('datalist option')).toHaveLength(3);
            expect($content.find('datalist').attr('id')).toBe(slider.attr('list'));
            expect($content.find('.aaxis-json-slider__end').map((_i: number, el: any) => el.textContent).get())
                .toEqual(['v1', 'v3']);
        });

        it('gives every opened dialog its own datalist id', () => {
            render([V3, V2]);
            const first = $content.find('datalist').attr('id');
            const $other = $('<div/>').appendTo(document.body);

            component.renderVersions($other, [V3, V2]);

            expect($other.find('datalist').attr('id')).not.toBe(first);
        });

        it('has no slider for a single version', () => {
            render([V1]);

            expect($slider()).toHaveLength(0);
            expect($content.find('.aaxis-json-version__count').text()).toBe('/ 1');
            expect($content.find('.aaxis-json-slider')).toHaveLength(0);
        });

        it('leaves out the tick gap when there is nothing to tick between', () => {
            render([V1]);

            expect(($content.find('.aaxis-json-version')[0] as HTMLElement).style.getPropertyValue('--tick-gap')).toBe('');
        });

        it('uses bare v labels for versions without a number', () => {
            render([{...V2, version: null}, {...V1, version: null}]);

            expect($content.find('.aaxis-json-slider__end').map((_i: number, el: any) => el.textContent).get()).toEqual(['v', 'v']);
        });

        it('starts on the newest version, labelled as current, with the slider at its right end', () => {
            render([V3, V2, V1]);

            expect($box().val()).toBe(label(V3, true));
            expect($slider().val()).toBe('2');
        });

        it('omits the current marker on a non-current newest version and skips blank parts', () => {
            render([{...V1, uuid: null}]);

            expect($box().val()).toBe('v1');
        });
    });

    describe('rendering the selected version', () => {
        it('highlights only what changed against the previous (older) version', () => {
            render([V3, V2, V1]);

            const $changed = $pre().find('.aaxis-json-changed');
            expect($changed).toHaveLength(1);
            expect($changed.text()).toBe('3');
        });

        it('shows the oldest version in full, without change markers', () => {
            render([V3, V2, V1]);

            $slider().val('0').trigger('input');

            expect($box().val()).toBe(label(V1));
            expect($pre().find('.aaxis-json-changed')).toHaveLength(0);
            expect($pre().text()).toBe(JSON.stringify(V1.payload, null, 2));
        });

        it('mirrors slider positions onto versions: right is newest', () => {
            render([V3, V2, V1]);

            $slider().val('1').trigger('input');
            expect($box().val()).toBe(label(V2));

            $slider().val('2').trigger('input');
            expect($box().val()).toBe(label(V3, true));
        });

        it('treats a null payload as an empty object', () => {
            render([{...V2, payload: null}, {...V1, payload: null}]);

            expect($pre().text()).toBe('{}');
        });

        it('diff-only trims the view to the changed parts, and Copy follows what is on screen', () => {
            const writeText = vi.fn(() => Promise.resolve());
            vi.stubGlobal('navigator', {clipboard: {writeText}});
            render([V3, V2, V1]);

            $diff().prop('checked', true).trigger('change');

            expect($pre().text()).toBe(JSON.stringify({a: 3}, null, 2));
            $content.find('.aaxis-json-copy').trigger('click');
            expect(writeText).toHaveBeenCalledWith(JSON.stringify({a: 3}, null, 2));
        });

        it('diff-only shows a muted note (and copies nothing) when versions are identical', () => {
            render([{...V2, payload: {a: 1}}, {...V1, payload: {a: 1}}]);

            $diff().prop('checked', true).trigger('change');

            expect($pre().find('.aaxis-json-note').text()).toBe('aaxis.ontology.data_view.diff_no_changes');
            const writeText = vi.fn(() => Promise.resolve());
            vi.stubGlobal('navigator', {clipboard: {writeText}});
            $content.find('.aaxis-json-copy').trigger('click');
            expect(writeText).toHaveBeenCalledWith('');
        });

        it('diff-only still shows the complete oldest version', () => {
            render([V3, V2, V1]);
            $slider().val('0').trigger('input');

            $diff().prop('checked', true).trigger('change');

            expect($pre().text()).toBe(JSON.stringify(V1.payload, null, 2));
        });

        it('switching back from diff-only restores the full view', () => {
            render([V3, V2, V1]);
            $diff().prop('checked', true).trigger('change');

            $diff().prop('checked', false).trigger('change');

            expect($pre().text()).toBe(JSON.stringify(V3.payload, null, 2));
        });
    });

    describe('typing a version', () => {
        beforeEach(() => {
            render([V3, V2, V1]);
        });

        it.each([
            ['2', V2, 1],
            ['v1', V1, 2],
            [' V3 ', V3, 0],
            ['bbbb-2222', V2, 1],
            ['BBBB-2222', V2, 1],
            ['aaab', V1, 2]
        ])('jumps to %j on Enter', (typed, expected, index) => {
            type(typed);

            expect($box().val()).toBe(label(expected, expected === V3));
            expect($slider().val()).toBe(String(2 - index));
            expect($error().text()).toBe('');
        });

        it.each([['99'], ['zzz'], ['aaa'], ['']])('flags %j as not found and stays on the current version', typed => {
            $slider().val('1').trigger('input');

            type(typed);

            expect($error().text()).toBe('aaxis.ontology.data_view.version_not_found');
            expect($slider().val()).toBe('1');
        });

        it('a numeric query that matches no version number falls back to the uuid lookup', () => {
            $content.empty();
            render([{...V2, version: 2, uuid: '12345678'}, {...V1, uuid: 'abcdefgh'}]);

            type('123');

            expect($box().val()).toBe(label({...V2, uuid: '12345678'}));

            expect($error().text()).toBe('');
        });

        it('does not treat unchanged text as a search', () => {
            type(String($box().val()));

            expect($error().text()).toBe('');
        });

        it('ignores keys other than Enter and swallows the Enter default action', () => {
            type('2', 'a');
            expect($box().val()).toBe('2');

            const event = $.Event('keydown', {key: 'Enter'});
            $box().trigger(event);
            expect(event.isDefaultPrevented()).toBe(true);
        });

        it('clears the error on the next successful jump', () => {
            type('zzz');
            type('2');

            expect($error().text()).toBe('');
        });

        it('selects the whole text on focus', () => {
            const selected = vi.fn();
            $box().on('select', selected);

            $box().trigger('focus');

            expect(selected).toHaveBeenCalled();
        });
    });

    describe('leaving the box', () => {
        beforeEach(() => {
            render([V3, V2, V1]);
        });

        it('restores the current label when the half-typed query matches nothing', () => {
            $slider().val('1').trigger('input');
            $box().val('garbage');

            $box().trigger('blur');

            expect($box().val()).toBe(label(V2));
            expect($error().text()).toBe('aaxis.ontology.data_view.version_not_found');
        });

        it('jumps to a valid query typed before blurring', () => {
            $box().val('1');

            $box().trigger('blur');

            expect($box().val()).toBe(label(V1));
            expect($error().text()).toBe('');
        });

        it('does nothing when the text is untouched', () => {
            $box().trigger('blur');

            expect($box().val()).toBe(label(V3, true));
            expect($error().text()).toBe('');
        });
    });

    describe('search', () => {
        beforeEach(() => {
            // "c" occurs in the key "c" of the nested object; "a" in key "a" and nowhere else
            render([V3, V2, V1]);
        });

        it('wraps each case-insensitive match in a mark and counts them', () => {
            $search().val('"A"').trigger('input');

            expect($pre().find('mark.aaxis-json-mark')).toHaveLength(1);
            expect($pre().find('mark').text()).toBe('"a"');
            expect($count().text()).toBe('1 / 1');
            expect($pre().find('mark').hasClass('is-current')).toBe(true);
            expect($pre().text()).toBe(JSON.stringify(V3.payload, null, 2));
        });

        it('cycles through matches with Enter, Shift+Enter and the arrow buttons, wrapping around', () => {
            $search().val('"').trigger('input');
            const total = $pre().find('mark').length;
            expect(total).toBeGreaterThan(2);
            expect($count().text()).toBe(`1 / ${total}`);

            $search().trigger($.Event('keydown', {key: 'Enter'}));
            expect($count().text()).toBe(`2 / ${total}`);

            $search().trigger($.Event('keydown', {key: 'Enter', shiftKey: true}));
            $search().trigger($.Event('keydown', {key: 'Enter', shiftKey: true}));
            expect($count().text()).toBe(`${total} / ${total}`);

            $content.find('.aaxis-json-search__nav').last().trigger('click');
            expect($count().text()).toBe(`1 / ${total}`);
            $content.find('.aaxis-json-search__nav').first().trigger('click');
            expect($count().text()).toBe(`${total} / ${total}`);
            expect($pre().find('mark.is-current')).toHaveLength(1);
        });

        it('ignores other keys in the search box', () => {
            $search().val('"').trigger('input');

            $search().trigger($.Event('keydown', {key: 'a'}));

            expect($count().text()).toContain('1 /');
        });

        it('reports 0 / 0 when nothing matches and blank when the query is empty', () => {
            $search().val('zzz').trigger('input');
            expect($count().text()).toBe('0 / 0');
            expect($pre().find('mark')).toHaveLength(0);

            $search().val('').trigger('input');
            expect($count().text()).toBe('');
        });

        it('does nothing on prev/next with no matches', () => {
            $content.find('.aaxis-json-search__nav').first().trigger('click');

            expect($count().text()).toBe('');
        });

        it('keeps the search applied when another version is selected', () => {
            $search().val('"a"').trigger('input');

            $slider().val('0').trigger('input');

            expect($pre().find('mark')).toHaveLength(1);
            expect($count().text()).toBe('1 / 1');
        });

        it('scrolls the current match into view where the browser can', () => {
            const scrollIntoView = vi.fn();
            (Element.prototype as any).scrollIntoView = scrollIntoView;

            $search().val('"a"').trigger('input');

            expect(scrollIntoView).toHaveBeenCalledWith({block: 'nearest'});
        });

        it('matches text split across syntax-colouring spans separately, never across them', () => {
            $search().val('a').trigger('input');

            // every match sits inside one text node; the count equals the number of "a" characters in text nodes
            const text = $pre().text().toLowerCase();
            expect($pre().find('mark')).toHaveLength(text.split('a').length - 1);
        });

        it('highlights several matches inside one text node', () => {
            render([{...V2, payload: {word: 'banana'}}, {...V1, payload: {word: 'banana'}}]);

            $search().val('an').trigger('input');

            expect($pre().find('mark')).toHaveLength(2);
            expect($pre().text()).toContain('banana');
        });
    });

    describe('wrap lines', () => {
        it('sits after Copy, unwrapped by default, and wraps the JSON while on', () => {
            render([V3, V2, V1]);

            const footer = $content.find('.aaxis-json-footer--tools').children().get();
            expect(footer.map((el: HTMLElement) => el.className)).toEqual([
                'aaxis-json-search', 'btn btn-sm aaxis-json-copy', 'aaxis-json-mode aaxis-json-wrap'
            ]);
            expect($content.find('.aaxis-json-wrap').attr('title')).toBe('aaxis.ontology.data_view.wrap_lines_hint');
            expect($content.find('.aaxis-json-wrap .aaxis-json-mode__label').text()).toBe('aaxis.ontology.data_view.wrap_lines');
            expect($wrap().prop('checked')).toBe(false);
            expect($pre().hasClass('is-wrapped')).toBe(false);

            $wrap().prop('checked', true).trigger('change');
            expect($pre().hasClass('is-wrapped')).toBe(true);
            type('1');
            expect($pre().hasClass('is-wrapped')).toBe(true);

            $wrap().prop('checked', false).trigger('change');
            expect($pre().hasClass('is-wrapped')).toBe(false);
        });

        it('is remembered for the next dialog', () => {
            render([V3, V2]);
            $wrap().prop('checked', true).trigger('change');
            expect(window.localStorage.getItem('aaxis.ontology.dataView.wrapLines')).toBe('1');

            const $other = $('<div/>').appendTo(document.body);
            component.renderVersions($other, [V3, V2]);
            expect($other.find('[data-role="wrap-lines"]').prop('checked')).toBe(true);
            expect($other.find('.aaxis-json-view').hasClass('is-wrapped')).toBe(true);

            $wrap().prop('checked', false).trigger('change');
            expect(window.localStorage.getItem('aaxis.ontology.dataView.wrapLines')).toBe('0');
        });

        it('falls back to unwrapped when the storage refuses, and still toggles', () => {
            vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
                throw new Error('denied');
            });
            vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
                throw new Error('denied');
            });
            render([V3]);
            expect($wrap().prop('checked')).toBe(false);

            $wrap().prop('checked', true).trigger('change');
            expect($pre().hasClass('is-wrapped')).toBe(true);
            vi.restoreAllMocks();
        });

        it('keeps the current search match in view after the lines move', () => {
            const scrollIntoView = vi.fn();
            (Element.prototype as any).scrollIntoView = scrollIntoView;
            render([V3, V2, V1]);
            $search().val('a').trigger('input');
            $search().trigger($.Event('keydown', {key: 'Enter'}));
            const count = $count().text();
            scrollIntoView.mockClear();

            $wrap().prop('checked', true).trigger('change');

            expect(scrollIntoView).toHaveBeenCalledTimes(1);
            expect($count().text()).toBe(count);
        });

        it('leaves a search with no matches alone', () => {
            render([V3]);
            $search().val('zzz').trigger('input');

            $wrap().prop('checked', true).trigger('change');

            expect($count().text()).toBe('0 / 0');
        });
    });

    describe('copy', () => {
        it('copies the shown JSON and flashes success', async () => {
            const writeText = vi.fn(() => Promise.resolve());
            vi.stubGlobal('navigator', {clipboard: {writeText}});
            render([V3, V2, V1]);

            $content.find('.aaxis-json-copy').trigger('click');
            await flush();

            expect(writeText).toHaveBeenCalledWith(JSON.stringify(V3.payload, null, 2));
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', 'aaxis.ontology.data_view.copied');
        });

        it('flashes an error when the clipboard rejects', async () => {
            vi.stubGlobal('navigator', {clipboard: {writeText: vi.fn(() => Promise.reject(new Error('denied')))}});
            render([V3]);

            $content.find('.aaxis-json-copy').trigger('click');
            await flush();

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.data_view.copy_error');
        });

        it('falls back to a hidden textarea and execCommand without the async clipboard API', () => {
            vi.stubGlobal('navigator', {});
            const execCommand = vi.fn(() => true);
            (document as any).execCommand = execCommand;
            let copied = '';
            execCommand.mockImplementation(() => {
                copied = (document.body.querySelector('textarea[style*="fixed"]') as HTMLTextAreaElement).value;
                return true;
            });
            render([V3]);

            $content.find('.aaxis-json-copy').trigger('click');

            expect(execCommand).toHaveBeenCalledWith('copy');
            expect(copied).toBe(JSON.stringify(V3.payload, null, 2));
            expect(document.body.querySelector('textarea[style*="fixed"]')).toBeNull();
            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('success', 'aaxis.ontology.data_view.copied');
            delete (document as any).execCommand;
        });

        it('flashes an error when the fallback throws', () => {
            vi.stubGlobal('navigator', {});
            (document as any).execCommand = () => {
                throw new Error('unsupported');
            };
            render([V3]);

            $content.find('.aaxis-json-copy').trigger('click');

            expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'aaxis.ontology.data_view.copy_error');
            delete (document as any).execCommand;
        });
    });
});

import $ from 'jquery';
import {afterEach, describe, expect, it, vi} from 'vitest';
import {
    apiFetch, bindGridToolbar, csrfToken, formatDateTime, setRefreshBusy
} from '../../../../Resources/js-src/app/components/component-support';

function setCookie(cookie: string): void {
    vi.spyOn(document, 'cookie', 'get').mockReturnValue(cookie);
}

describe('csrfToken', () => {
    it('reads the _csrf cookie on http and url-decodes it', () => {
        setCookie('a=1; _csrf=tok%2Fen%3D; b=2');

        expect(csrfToken()).toBe('tok/en=');
    });

    it('does not mistake a longer cookie name for the CSRF one', () => {
        setCookie('x_csrf=nope');

        expect(csrfToken()).toBe('');
    });

    it('uses the https- prefixed cookie on https pages', () => {
        vi.spyOn(window, 'location', 'get').mockReturnValue({protocol: 'https:'} as any);
        setCookie('_csrf=plain; https-_csrf=secure');

        expect(csrfToken()).toBe('secure');
    });

    it('is empty when the cookie is absent', () => {
        setCookie('other=1');

        expect(csrfToken()).toBe('');
    });
});

describe('apiFetch', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('sends a JSON request with the CSRF header and resolves to ok + parsed body', async () => {
        setCookie('_csrf=abc');
        const fetchSpy = vi.fn().mockResolvedValue({ok: true, json: () => Promise.resolve({success: true})});
        vi.stubGlobal('fetch', fetchSpy);

        const result = await apiFetch('/x', 'POST', {a: 1});

        expect(result).toEqual({ok: true, data: {success: true}});
        expect(fetchSpy).toHaveBeenCalledWith('/x', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': 'abc'},
            body: '{"a":1}'
        });
    });

    it('omits the body when none is given and reports a failed status without rejecting', async () => {
        setCookie('');
        const fetchSpy = vi.fn().mockResolvedValue({ok: false, json: () => Promise.resolve({message: 'nope'})});
        vi.stubGlobal('fetch', fetchSpy);

        const result = await apiFetch('/x', 'DELETE');

        expect(result).toEqual({ok: false, data: {message: 'nope'}});
        expect(fetchSpy.mock.calls[0][1]).not.toHaveProperty('body');
    });

    it('sends a falsy body such as null or 0 (only undefined means "no body")', async () => {
        setCookie('');
        const fetchSpy = vi.fn().mockResolvedValue({ok: true, json: () => Promise.resolve({})});
        vi.stubGlobal('fetch', fetchSpy);

        await apiFetch('/x', 'POST', null);

        expect(fetchSpy.mock.calls[0][1].body).toBe('null');
    });
});

describe('setRefreshBusy', () => {
    it('disables the refresh button and spins its icon, then undoes both', () => {
        const $el = $('<div><button data-role="refresh"><span class="fa"></span></button></div>');
        const $button = $el.find('button');

        setRefreshBusy($el, true);
        expect($button.prop('disabled')).toBe(true);
        expect($el.find('.fa').hasClass('fa-spin')).toBe(true);

        setRefreshBusy($el, false);
        expect($button.prop('disabled')).toBe(false);
        expect($el.find('.fa').hasClass('fa-spin')).toBe(false);
    });
});

describe('formatDateTime', () => {
    it('returns an empty string for null or empty values', () => {
        expect(formatDateTime(null)).toBe('');
        expect(formatDateTime('')).toBe('');
    });

    it('formats a parseable date with the locale formatter', () => {
        const value = '2026-05-12T14:22:00Z';

        expect(formatDateTime(value)).toBe(new Date(value).toLocaleString());
    });

    it('falls back to the raw text when the date does not parse', () => {
        expect(formatDateTime('not a date')).toBe('not a date');
    });
});

describe('bindGridToolbar', () => {
    function setup() {
        const $el = $(
            '<div><button data-role="refresh">r</button><button data-role="columns-settings">c</button></div>'
        );
        const onRefresh = vi.fn();
        const grid = {toggleColumnSettings: vi.fn()};
        bindGridToolbar($el, 'ns', onRefresh, () => grid);

        return {$el, onRefresh, grid};
    }

    it('refreshes on the refresh button and swallows the default action', () => {
        const {$el, onRefresh} = setup();
        const event = $.Event('click');

        $el.find('[data-role="refresh"]').trigger(event);

        expect(onRefresh).toHaveBeenCalledTimes(1);
        expect(event.isDefaultPrevented()).toBe(true);
    });

    it('opens the column settings anchored on the clicked button', () => {
        const {$el, grid} = setup();
        const button = $el.find('[data-role="columns-settings"]')[0];

        $el.find('[data-role="columns-settings"]').trigger('click');

        expect(grid.toggleColumnSettings).toHaveBeenCalledWith(button);
    });

    it('resolves the grid lazily, so a rebuilt grid is the one toggled', () => {
        const $el = $('<div><button data-role="columns-settings"></button></div>');
        const first = {toggleColumnSettings: vi.fn()};
        const second = {toggleColumnSettings: vi.fn()};
        let current = first;
        bindGridToolbar($el, 'ns', vi.fn(), () => current);

        current = second;
        $el.find('button').trigger('click');

        expect(first.toggleColumnSettings).not.toHaveBeenCalled();
        expect(second.toggleColumnSettings).toHaveBeenCalledTimes(1);
    });

    it('unbinds through its event namespace', () => {
        const {$el, onRefresh} = setup();

        $el.off('.ns');
        $el.find('[data-role="refresh"]').trigger('click');

        expect(onRefresh).not.toHaveBeenCalled();
    });
});

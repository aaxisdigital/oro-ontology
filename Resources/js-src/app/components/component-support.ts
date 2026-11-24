/**
 * Request plumbing and small presentation helpers shared by the ontology page components.
 *
 * These live in a module rather than a base class because the components extend Oro's
 * BaseComponent directly and have no common ancestor of their own.
 */

/** Oro's CSRF cookie, whose name depends on the scheme. Empty string when the cookie is absent. */
export function csrfToken(): string {
    const name = window.location.protocol === 'https:' ? 'https-_csrf' : '_csrf';
    const match = new RegExp('(?:^|; )' + name + '=([^;]*)').exec(document.cookie);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * JSON request carrying Oro's CSRF header and the session cookie. Resolves to the parsed body plus
 * the HTTP ok flag, so callers can branch on failure without a separate rejection path.
 */
export function apiFetch(url: string, method: string, body?: any, keepalive = false): Promise<{ok: boolean; data: any}> {
    const opts: any = {
        method,
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Header': csrfToken()}
    };
    if (keepalive) {
        // Outlives the page: a request sent while the tab closes or reloads still reaches the server.
        opts.keepalive = true;
    }
    if (body !== undefined) {
        opts.body = JSON.stringify(body);
    }

    return fetch(url, opts).then(r => r.json().then(d => ({ok: r.ok, data: d})));
}

/**
 * Reads a ONE-SHOT deep-link parameter (`?system=`, `?entity=`, `?uuid=` — other pages link here
 * pre-filtered) and drops it from the URL: once applied, the grid's own URL state (`f.<column>`,
 * DataGrid `urlState`) carries the filter, so a refresh keeps it and a filter the user cleared stays
 * cleared. Returns the trimmed value, or null when absent or blank.
 */
export function takeQueryParam(name: string): string | null {
    const params = new URLSearchParams(window.location.search);
    const value = params.get(name);
    if (value === null) {
        return null;
    }
    params.delete(name);
    const query = params.toString();
    window.history.replaceState(window.history.state, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash);
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

/** Spins and disables the page's refresh button while a request is in flight. */
export function setRefreshBusy($el: any, busy: boolean): void {
    $el.find('[data-role="refresh"]').prop('disabled', busy)
        .find('.fa').toggleClass('fa-spin', busy);
}

/** Locale-formatted timestamp; falls back to the raw text when the value will not parse. */
export function formatDateTime(value: string | null): string {
    if (!value) {
        return '';
    }
    const d = new Date(value);

    return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString();
}

/**
 * Binds the two toolbar controls every ontology list page shares: the refresh button and the
 * column-settings popover. `grid` is a thunk so the binding still resolves the current grid if the
 * component rebuilds it.
 */
export function bindGridToolbar($el: any, ns: string, onRefresh: () => void, grid: () => any): void {
    $el.on('click.' + ns, '[data-role="refresh"]', (e: any) => {
        e.preventDefault();
        onRefresh();
    });
    $el.on('click.' + ns, '[data-role="columns-settings"]', (e: any) => {
        e.preventDefault();
        grid().toggleColumnSettings(e.currentTarget);
    });
}

import $ from 'jquery';
import {vi} from 'vitest';

/**
 * Shared fakes for the ontology page components. They only need to show how a component DRIVES
 * CommonBundle's DataGrid / Dialog / RecordFormModal, so each fake records its constructor options and
 * exposes spies. Use from a test file with, e.g.:
 *
 *   vi.mock('aaxiscommon/js/app/widgets/data-grid', async () => (await import('../../helpers/page-mocks')).dataGridModule());
 */

export class FakeGrid {
    static instances: FakeGrid[] = [];

    mount = vi.fn();
    setRows = vi.fn();
    setFilter = vi.fn();
    toggleColumnSettings = vi.fn();
    dispose = vi.fn();

    constructor(public options: any) {
        FakeGrid.instances.push(this);
    }

    /** The column definition with the given key. */
    column(key: string): any {
        return this.options.columns.find((c: any) => c.key === key);
    }

    /** The action definition with the given key. */
    action(key: string): any {
        return this.options.actions.find((a: any) => a.key === key);
    }
}

export const navigateTo = vi.fn();

export function dataGridModule(): any {
    return {default: FakeGrid, navigateTo};
}

export class FakeDialog {
    static instances: FakeDialog[] = [];

    $content = $('<div class="fake-dialog-body"></div>');
    opened = false;

    constructor(public options: any) {
        FakeDialog.instances.push(this);
    }

    open(): any {
        this.opened = true;
        $(document.body).append(this.$content);
        return this.$content;
    }

    close = vi.fn(() => {
        this.$content.remove();
        this.options.onClose?.();
    });
}

export function dialogModule(): any {
    return {default: FakeDialog};
}

export class FakeRecordForm {
    static instances: FakeRecordForm[] = [];

    open = vi.fn();
    close = vi.fn();
    showError = vi.fn();

    constructor(public options: any) {
        FakeRecordForm.instances.push(this);
    }

    /** Field definition by key (top level). */
    field(key: string): any {
        return this.options.fields.find((f: any) => f.key === key);
    }
}

export function recordFormModule(): any {
    return {default: FakeRecordForm};
}

/** Stand-in for oroform's Select2View: records what it enhanced, and can be told to fail. */
export class FakeSelect2 {
    static instances: FakeSelect2[] = [];
    static failNext = false;

    dispose = vi.fn();

    constructor(public options: any) {
        if (FakeSelect2.failNext) {
            throw new Error('select2 unavailable');
        }
        FakeSelect2.instances.push(this);
    }
}

export function select2Module(): any {
    return {default: FakeSelect2};
}

/** Forget every recorded widget instance — call from beforeEach. */
export function resetFakes(): void {
    FakeGrid.instances = [];
    FakeDialog.instances = [];
    FakeRecordForm.instances = [];
    FakeSelect2.instances = [];
    FakeSelect2.failNext = false;
    navigateTo.mockReset();
}

/** Lets pending promise chains (fetch().then().then().finally()) settle. */
export async function flush(times = 25): Promise<void> {
    for (let i = 0; i < times; i++) {
        await Promise.resolve();
    }
}

/** A `fetch` Response stand-in: `{ok, json()}`. */
export function jsonResponse(data: any, ok = true): any {
    return {ok, json: () => Promise.resolve(data)};
}

/** Installs a fetch mock answering by URL substring; returns the spy. Unmatched URLs reject. */
export function stubFetch(routes: Record<string, any | ((url: string, init?: any) => any)>): ReturnType<typeof vi.fn> {
    const spy = vi.fn((url: string, init?: any) => {
        const key = Object.keys(routes).find(k => url.includes(k));
        if (key === undefined) {
            return Promise.reject(new Error('unexpected fetch ' + url));
        }
        const answer = routes[key];
        const value = typeof answer === 'function' ? answer(url, init) : answer;

        return value instanceof Promise ? value : Promise.resolve(value);
    });
    vi.stubGlobal('fetch', spy);

    return spy;
}

/** Builds the `_sourceElement` a page component is mounted on: a list slot + the toolbar hooks. */
export function pageElement(extra = ''): any {
    return $(
        '<div>'
        + '<button data-role="refresh"><span class="fa"></span></button>'
        + '<button data-role="columns-settings"></button>'
        + '<button data-role="add"></button>'
        + '<button data-role="import"></button>'
        + '<div data-role="list"></div>' + extra + '</div>'
    ).appendTo(document.body);
}

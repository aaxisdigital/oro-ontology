import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import ConnectorViewTestComponent from '../../../../Resources/js-src/app/components/connector-view-test-component';
import {flush, jsonResponse, stubFetch} from '../../helpers/page-mocks';

const TEST_URL = '/aaxis_ontology_connector_test_stored?id=12';

describe('ConnectorViewTestComponent', () => {
    let $el: any;
    let $button: any;

    beforeEach(() => {
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
        // The button lives in the page title bar, OUTSIDE the component's own element.
        $button = $('<button data-role="connector-test-stored">Test</button>').appendTo(document.body);
        $el = $('<div><div data-role="connector-test-result" hidden></div></div>').appendTo(document.body);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        $(document).off('.aaxisConnectorViewTest');
    });

    const create = (options: Record<string, unknown> = {}) =>
        new (ConnectorViewTestComponent as any)({_sourceElement: $el, connectorId: 12, ...options});
    const $result = () => $el.find('[data-role="connector-test-result"]');

    it('probes the stored connector by id — a bodyless POST carrying only the CSRF header', async () => {
        const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: true})});
        create();

        $button.trigger('click');

        expect(fetchSpy).toHaveBeenCalledWith(TEST_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': 'tok'}
        });
        await flush();
    });

    it('coerces a string connector id to a number', async () => {
        const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: true})});
        create({connectorId: '12'});

        $button.trigger('click');

        expect(fetchSpy.mock.calls[0][0]).toBe(TEST_URL);
        await flush();
    });

    it('shows a spinner line, reveals the result block and disables the clicked button while testing', async () => {
        stubFetch({[TEST_URL]: new Promise(() => undefined)});
        create();
        const event = $.Event('click');

        $button.trigger(event);

        expect(event.isDefaultPrevented()).toBe(true);
        expect($button.prop('disabled')).toBe(true);
        expect($result().attr('hidden')).toBeUndefined();
        expect($result().find('.is-progress .fa-spin')).toHaveLength(1);
        expect($result().text().trim()).toBe('aaxis.common.grid.testing');
        await flush();
    });

    it('renders the overall line and the per-step lines, then re-enables the button', async () => {
        stubFetch({
            [TEST_URL]: jsonResponse({
                success: false,
                message: 'Could not connect',
                steps: [{label: 'TCP', success: true, message: 'banner SSH-2.0'}, {label: 'Auth', success: false}]
            })
        });
        create();

        $button.trigger('click');
        await flush();

        const $lines = $result().children();
        expect($lines).toHaveLength(3);
        expect($lines.eq(0).is('.aaxis-connector-test__line.is-fail')).toBe(true);
        expect($lines.eq(0).find('.fa-times-circle')).toHaveLength(1);
        expect($lines.eq(0).text().trim()).toBe('Could not connect');
        expect($lines.eq(1).is('.aaxis-connector-test__step.is-ok')).toBe(true);
        expect($lines.eq(1).find('.fa-check-circle')).toHaveLength(1);
        expect($lines.eq(1).text().trim()).toBe('TCP — banner SSH-2.0');
        expect($lines.eq(2).is('.aaxis-connector-test__step.is-fail')).toBe(true);
        expect($lines.eq(2).text().trim()).toBe('Auth');
        expect($button.prop('disabled')).toBe(false);
    });

    it.each([
        [true, 'is-ok', 'aaxis.common.grid.test_success'],
        [false, 'is-fail', 'aaxis.common.grid.test_error']
    ])('uses the generic line when success=%s comes without a message', async (success, cls, text) => {
        stubFetch({[TEST_URL]: jsonResponse({success})});
        create();

        $button.trigger('click');
        await flush();

        expect($result().children()).toHaveLength(1);
        expect($result().children().first().hasClass(cls)).toBe(true);
        expect($result().text().trim()).toBe(text);
    });

    it('reports the generic error when the request fails and re-enables the button', async () => {
        stubFetch({[TEST_URL]: Promise.reject(new Error('down'))});
        create();

        $button.trigger('click');
        await flush();

        expect($result().children().first().hasClass('is-fail')).toBe(true);
        expect($result().text().trim()).toBe('aaxis.common.grid.test_error');
        expect($button.prop('disabled')).toBe(false);
    });

    it('ignores a click while a test is already in flight', async () => {
        const fetchSpy = stubFetch({[TEST_URL]: new Promise(() => undefined)});
        create();

        $button.trigger('click');
        $button.prop('disabled', false).trigger('click');

        expect(fetchSpy).toHaveBeenCalledTimes(1);
        await flush();
    });

    it('unbinds the document listener on dispose, and disposing twice is harmless', () => {
        const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: true})});
        const component = create();

        component.dispose();
        component.dispose();
        $button.trigger('click');

        expect(component.disposed).toBe(true);
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

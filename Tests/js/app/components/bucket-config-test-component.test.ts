import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import BucketConfigTestComponent from '../../../../Resources/js-src/app/components/bucket-config-test-component';
import {flush, jsonResponse, stubFetch} from '../../helpers/page-mocks';

const TEST_URL = '/aaxis_ontology_config_bucket_test';

/** The five config-form inputs the component reads, named exactly like Oro's system-config fields. */
function configField(field: string, value: string): void {
    $(`<input name="oro_config_configuration_form[aaxis_ontology___bucket_${field}][value]">`)
        .val(value)
        .appendTo(document.body);
}

describe('BucketConfigTestComponent', () => {
    let $el: any;

    beforeEach(() => {
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
        $el = $(
            '<div><button data-role="bucket-config-test">Test</button>'
            + '<div data-role="bucket-config-test-result" hidden></div></div>'
        ).appendTo(document.body);
        configField('endpoint_url', 'https://s3.example.com:9000');
        configField('access_key', 'AK');
        configField('secret_key', '*****');
        configField('name', 'my-bucket');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    const create = () => new (BucketConfigTestComponent as any)({_sourceElement: $el});
    const click = () => $el.find('[data-role="bucket-config-test"]').trigger('click');
    const $result = () => $el.find('[data-role="bucket-config-test-result"]');

    it('posts the CURRENT form values, keyed the way the server expects, with the CSRF header', async () => {
        const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: true})});
        create();

        click();

        expect(fetchSpy).toHaveBeenCalledTimes(1);
        const [url, init] = fetchSpy.mock.calls[0];
        expect(url).toBe(TEST_URL);
        expect(init).toMatchObject({
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': 'tok'}
        });
        expect(JSON.parse(init.body)).toEqual({
            config: {
                endpoint_url: 'https://s3.example.com:9000',
                access_key: 'AK',
                secret_key: '*****',
                bucket_name: 'my-bucket'
            }
        });
        await flush();
    });

    it('sends an empty string for a field that is not on the page', async () => {
        $('[name*="bucket_access_key"]').remove();
        const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: true})});
        create();

        click();

        expect(JSON.parse(fetchSpy.mock.calls[0][1].body).config.access_key).toBe('');
        await flush();
    });

    it('shows a spinner line and disables the button while testing', async () => {
        stubFetch({[TEST_URL]: new Promise(() => undefined)});
        create();

        click();

        const $button = $el.find('[data-role="bucket-config-test"]');
        expect($button.prop('disabled')).toBe(true);
        expect($result().attr('hidden')).toBeUndefined();
        expect($result().find('.aaxis-connector-test__line.is-progress .fa-circle-o-notch.fa-spin')).toHaveLength(1);
        expect($result().text().trim()).toBe('aaxis.common.grid.testing');
        await flush();
    });

    it('swallows the default action of the click', () => {
        stubFetch({[TEST_URL]: new Promise(() => undefined)});
        create();
        const event = $.Event('click');

        $el.find('[data-role="bucket-config-test"]').trigger(event);

        expect(event.isDefaultPrevented()).toBe(true);
    });

    it('renders the overall success line and every step, then re-enables the button', async () => {
        stubFetch({
            [TEST_URL]: jsonResponse({
                success: true,
                message: 'Connected',
                steps: [
                    {label: 'Socket', success: true},
                    {label: 'List', success: false, message: '403 Forbidden'}
                ]
            })
        });
        create();

        click();
        await flush();

        const $lines = $result().children();
        expect($lines).toHaveLength(3);
        expect($lines.eq(0).is('.aaxis-connector-test__line.is-ok')).toBe(true);
        expect($lines.eq(0).find('.fa-check-circle')).toHaveLength(1);
        expect($lines.eq(0).text().trim()).toBe('Connected');
        expect($lines.eq(1).is('.aaxis-connector-test__step.is-ok')).toBe(true);
        expect($lines.eq(1).text().trim()).toBe('Socket');
        expect($lines.eq(2).is('.aaxis-connector-test__step.is-fail')).toBe(true);
        expect($lines.eq(2).find('.fa-times-circle')).toHaveLength(1);
        expect($lines.eq(2).text().trim()).toBe('List — 403 Forbidden');
        expect($el.find('[data-role="bucket-config-test"]').prop('disabled')).toBe(false);
    });

    it('renders a failure with the server message', async () => {
        stubFetch({[TEST_URL]: jsonResponse({success: false, message: 'Refused'})});
        create();

        click();
        await flush();

        const $line = $result().children().first();
        expect($line.is('.is-fail')).toBe(true);
        expect($line.find('.fa-times-circle')).toHaveLength(1);
        expect($line.text().trim()).toBe('Refused');
    });

    it.each([
        [true, 'aaxis.common.grid.test_success'],
        [false, 'aaxis.common.grid.test_error']
    ])('falls back to a generic line when success=%s carries no message', async (success, text) => {
        stubFetch({[TEST_URL]: jsonResponse({success})});
        create();

        click();
        await flush();

        expect($result().children()).toHaveLength(1);
        expect($result().text().trim()).toBe(text);
    });

    it('shows the generic error when the request fails and still re-enables the button', async () => {
        stubFetch({[TEST_URL]: Promise.reject(new Error('network'))});
        create();

        click();
        await flush();

        expect($result().children().first().is('.is-fail')).toBe(true);
        expect($result().text().trim()).toBe('aaxis.common.grid.test_error');
        expect($el.find('[data-role="bucket-config-test"]').prop('disabled')).toBe(false);
    });

    it('ignores clicks while a test is already running', async () => {
        const fetchSpy = stubFetch({[TEST_URL]: new Promise(() => undefined)});
        create();

        click();
        $el.find('[data-role="bucket-config-test"]').prop('disabled', false);
        click();

        expect(fetchSpy).toHaveBeenCalledTimes(1);
        await flush();
    });

    it('stops listening once disposed; disposing twice is harmless', () => {
        const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: true})});
        const component = create();

        component.dispose();
        component.dispose();
        click();

        expect(component.disposed).toBe(true);
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import ConnectorConfigComponent from '../../../../Resources/js-src/app/components/connector-config-component';
import {FakeDialog, FakeRecordForm, flush, jsonResponse, resetFakes, stubFetch} from '../../helpers/page-mocks';

vi.mock('aaxiscommon/js/app/widgets/dialog', async () => (await import('../../helpers/page-mocks')).dialogModule());
vi.mock('aaxiscommon/js/app/widgets/record-form-modal', async () => (await import('../../helpers/page-mocks')).recordFormModule());

const SENTINEL = '********';
const TEST_URL = '/aaxis_ontology_connector_test';

describe('ConnectorConfigComponent', () => {
    let $type: any;
    let $config: any;

    beforeEach(() => {
        resetFakes();
        vi.spyOn(document, 'cookie', 'get').mockReturnValue('_csrf=tok');
        $type = $(
            '<select id="type"><option value="sftp">sftp</option><option value="rest_api">rest</option>'
            + '<option value="file_system">fs</option><option value="bucket">bucket</option>'
            + '<option value="database">db</option><option value="weird">weird</option></select>'
        ).appendTo(document.body);
        $config = $('<textarea id="config" class="form-control" rows="6"></textarea>').appendTo(document.body);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    function mount(type: string, config: unknown = '', options: Record<string, unknown> = {}) {
        $type.val(type);
        $config.val(typeof config === 'string' ? config : JSON.stringify(config));

        return new (ConnectorConfigComponent as any)({
            _sourceElement: $(document.body), typeFieldId: 'type', configFieldId: 'config', connectorId: 42, ...options
        });
    }

    const $display = () => $config.next('textarea');
    const configure = () => $('.aaxis-ontology-configure-btn').trigger('click');
    const lastModal = () => FakeRecordForm.instances.at(-1)!;
    const written = () => JSON.parse(String($config.val()));

    describe('setup', () => {
        it('hides the mapped textarea (readonly) and shows a masked, readonly display twin after it', () => {
            mount('sftp', {server: 's', password: 'secret'});

            expect($config.prop('readonly')).toBe(true);
            expect($config.css('display')).toBe('none');
            const $twin = $display();
            expect($twin.prop('readonly')).toBe(true);
            expect($twin.attr('class')).toBe('form-control');
            expect($twin.attr('rows')).toBe('6');
            expect($twin.attr('spellcheck')).toBe('false');
            expect($twin.attr('aria-label')).toBe('aaxis.ontology.connector_config.display_label');
            expect(JSON.parse(String($twin.val()))).toEqual({server: 's', password: SENTINEL});
        });

        it('falls back to 8 rows and no class when the textarea has none', () => {
            $config.removeAttr('rows').removeAttr('class');
            mount('sftp');

            expect($display().attr('rows')).toBe('8');
            expect($display().attr('class')).toBe('');
        });

        it('adds a Configure button on its own row below the display', () => {
            mount('sftp');

            const $row = $display().next('.aaxis-ontology-configure');
            expect($row).toHaveLength(1);
            const $button = $row.find('button.aaxis-ontology-configure-btn');
            expect($button.attr('type')).toBe('button');
            expect($button.find('.fa-cog')).toHaveLength(1);
            expect($button.text().trim()).toBe('aaxis.ontology.connector_config.configure');
        });

        it('does nothing when either field is missing from the page', () => {
            $config.remove();

            const component = mount('sftp');

            expect($('.aaxis-ontology-configure-btn')).toHaveLength(0);
            expect(() => component.dispose()).not.toThrow();
        });

        it('leaves the display empty for an empty config', () => {
            mount('sftp', '');

            expect($display().val()).toBe('');
        });

        it('shows an empty object for a config that is not JSON', () => {
            mount('sftp', 'not json');

            expect($display().val()).toBe('{}');
        });

        it('swallows the default action of the Configure button', () => {
            mount('sftp');
            const event = $.Event('click');

            $('.aaxis-ontology-configure-btn').trigger(event);

            expect(event.isDefaultPrevented()).toBe(true);
        });
    });

    describe('secret masking in the display', () => {
        function displayed(config: unknown): any {
            mount('sftp', config);

            return JSON.parse(String($display().val()));
        }

        it.each([
            'password', 'passphrase', 'key', 'private_key', 'secret', 'client_secret', 'api_key', 'apikey',
            'token', 'access_token', 'refresh_token', 'authorization', 'PASSWORD', 'Authorization'
        ])('masks a known secret key: %s', key => {
            expect(displayed({[key]: 'clear'})[key]).toBe(SENTINEL);
        });

        it.each(['access_key', 'secret_key', 'my_token', 'db_secret', 'admin_password', 'X-Api-Key', 'X Auth Token'])(
            'masks a key ending in a secret suffix after normalisation: %s', key => {
                expect(displayed({[key]: 'clear'})[key]).toBe(SENTINEL);
            }
        );

        it('leaves ordinary keys, empty secrets and non-string secrets alone', () => {
            expect(displayed({server: 'host', password: '', token: 123, key: null, port: 22})).toEqual({
                server: 'host', password: '', token: 123, key: null, port: 22
            });
        });

        it('masks nested objects, and header values inside arrays', () => {
            expect(displayed({headers: {Authorization: 'Bearer x', Accept: 'json'}, rows: [{secret: 's'}, 'plain']})).toEqual({
                headers: {Authorization: SENTINEL, Accept: 'json'},
                rows: [{secret: SENTINEL}, 'plain']
            });
        });

        it('does not treat array elements as keyed values', () => {
            expect(displayed({list: ['a', 'b']})).toEqual({list: ['a', 'b']});
        });
    });

    describe('type switch', () => {
        it('lets the type change freely when there is no configuration', () => {
            mount('sftp', '');

            $type.val('bucket').trigger('change');

            expect(FakeDialog.instances).toHaveLength(0);
        });

        it.each([['{}'], ['null'], ['""'], ['  ']])('treats config %s as empty', config => {
            mount('sftp', config);

            $type.val('bucket').trigger('change');

            expect(FakeDialog.instances).toHaveLength(0);
        });

        it('does not ask when the selected value did not actually change', () => {
            mount('sftp', {server: 's'});

            $type.trigger('change');

            expect(FakeDialog.instances).toHaveLength(0);
        });

        it('asks for confirmation when a configuration exists', () => {
            mount('sftp', {server: 's'});

            $type.val('bucket').trigger('change');

            const dialog = FakeDialog.instances[0];
            expect(dialog.options).toMatchObject({title: 'aaxis.ontology.connector_config.type_switch_title', width: '520px'});
            expect(dialog.$content.find('.aaxis-ontology-confirm__q').text())
                .toBe('aaxis.ontology.connector_config.type_switch_question');
            expect(dialog.$content.find('.aaxis-ontology-confirm__danger').text())
                .toBe('aaxis.ontology.connector_config.type_switch_warning');
        });

        it('treats an unparseable config as existing (better to ask than to silently lose it)', () => {
            mount('sftp', '{broken');

            $type.val('bucket').trigger('change');

            expect(FakeDialog.instances).toHaveLength(1);
        });

        it('wipes the configuration and the display on Confirm, and keeps the new type', () => {
            mount('sftp', {server: 's'});
            $type.val('bucket').trigger('change');
            const dialog = FakeDialog.instances[0];

            dialog.$content.find('.aaxis-ontology-confirm__delete').trigger('click');

            expect($config.val()).toBe('');
            expect($display().val()).toBe('');
            expect($type.val()).toBe('bucket');
            expect(dialog.close).toHaveBeenCalled();
            // the new type is now the baseline: switching back with no config no longer asks
            $type.val('sftp').trigger('change');
            expect(FakeDialog.instances).toHaveLength(1);
        });

        it.each([
            ['Cancel', (dialog: FakeDialog) => dialog.$content.find('button.btn').first().trigger('click')],
            ['closing the dialog', (dialog: FakeDialog) => dialog.close()]
        ])('reverts the select on %s without re-triggering the handler', (_name, dismiss) => {
            mount('sftp', {server: 's'});
            $type.val('bucket').trigger('change');
            const dialog = FakeDialog.instances[0];

            dismiss(dialog);

            expect($type.val()).toBe('sftp');
            expect(FakeDialog.instances).toHaveLength(1); // the revert's own change event was muted
            expect($config.val()).not.toBe('');
        });

        it('lets a later switch through the guard again after a revert', () => {
            mount('sftp', {server: 's'});
            $type.val('bucket').trigger('change');
            FakeDialog.instances[0].close();

            $type.val('database').trigger('change');

            expect(FakeDialog.instances).toHaveLength(2);
        });
    });

    describe('configure dispatch', () => {
        it('opens the unsupported-type notice for a type without a form', () => {
            mount('weird', {a: 1});

            configure();

            const dialog = FakeDialog.instances[0];
            expect(dialog.options).toMatchObject({title: 'aaxis.ontology.connector_config.not_available_title', width: '480px'});
            expect(dialog.$content.find('p').text()).toBe('aaxis.ontology.connector_config.not_available_text');
            dialog.$content.find('button').trigger('click');
            expect(dialog.close).toHaveBeenCalled();
            expect(FakeRecordForm.instances).toHaveLength(0);
        });

        it.each([
            ['file_system', 'title_file_system'],
            ['sftp', 'title_sftp'],
            ['rest_api', 'title_rest_api'],
            ['bucket', 'title_bucket'],
            ['database', 'title_database']
        ])('opens the %s popup', (type, titleKey) => {
            mount(type, {});

            configure();

            expect(lastModal().open).toHaveBeenCalled();
            expect(lastModal().options.title).toBe('aaxis.ontology.connector_config.' + titleKey);
            expect(lastModal().options.resizable).toBe(false);
        });

        it.each(['[1,2]', '"text"', 'nope', ''])('opens the popup with empty defaults when the config is %s', config => {
            mount('file_system', config);

            configure();

            expect(lastModal().options.values).toEqual({base_path: ''});
        });
    });

    describe('file_system popup', () => {
        it('has a single required base path prefilled from the config', () => {
            mount('file_system', {base_path: '/data'});

            configure();

            expect(lastModal().options.fields).toHaveLength(1);
            expect(lastModal().field('base_path')).toMatchObject({type: 'text', required: true});
            expect(lastModal().options.values).toEqual({base_path: '/data'});
        });

        it('ignores a non-string base path', () => {
            mount('file_system', {base_path: 5});

            configure();

            expect(lastModal().options.values).toEqual({base_path: ''});
        });

        it('writes the JSON back (4-space indent) and refreshes the display on submit', () => {
            mount('file_system', {base_path: '/old'});
            configure();

            lastModal().options.onSubmit({base_path: '/new'});

            expect($config.val()).toBe(JSON.stringify({base_path: '/new'}, null, 4));
            expect(JSON.parse(String($display().val()))).toEqual({base_path: '/new'});
        });

        it('coerces an empty base path to an empty string', () => {
            mount('file_system', {});
            configure();

            lastModal().options.onSubmit({base_path: undefined});

            expect(written()).toEqual({base_path: ''});
        });
    });

    describe('sftp popup', () => {
        it('prefills server/port/user/auth and never the stored secrets', () => {
            mount('sftp', {server: 'h', port: 2222, user: 'u', auth: 'password', password: SENTINEL, key: 'KEY'});

            configure();

            expect(lastModal().options.values).toEqual({server: 'h', port: 2222, user: 'u', auth: 'password'});
            expect(lastModal().options.width).toBe('620px');
        });

        it('defaults port 22 and auth none for an empty or invalid config', () => {
            mount('sftp', {auth: 'weird', port: '22'});

            configure();

            expect(lastModal().options.values).toEqual({server: '', port: 22, user: '', auth: 'none'});
        });

        it('lays the fields out in the srv/cred rows with the auth switch gating password and key', () => {
            mount('sftp', {auth: 'password'});
            configure();

            const fields = lastModal().options.fields;
            expect(fields.map((f: any) => [f.key, f.row ?? null, f.width ?? null])).toEqual([
                ['server', 'srv', '75%'], ['port', 'srv', '25%'], ['auth', 'cred', '24%'],
                ['user', 'cred', '38%'], ['password', 'cred', '38%'], ['key', null, null]
            ]);
            expect(lastModal().field('port')).toMatchObject({min: 1, max: 65535, required: true});
            expect(lastModal().field('password')).toMatchObject({disabled: false, required: true, hint: undefined});
            expect(lastModal().field('key')).toMatchObject({disabled: true, required: false});
            expect(lastModal().field('key').hint).toBe('aaxis.ontology.connector_config.key_not_defined');
        });

        it('does not require a password that is already stored, and says so', () => {
            mount('sftp', {auth: 'password', password: SENTINEL, key: SENTINEL});
            configure();

            expect(lastModal().field('password')).toMatchObject({required: false});
            expect(lastModal().field('password').hint).toBe('aaxis.ontology.connector_config.password_defined');
            expect(lastModal().field('key').hint).toBe('aaxis.ontology.connector_config.key_defined');
        });

        it('rebuilds the fields when the auth choice changes, ignoring changes inside collections', () => {
            mount('sftp', {auth: 'none'});
            configure();
            const api = {rebuildFields: vi.fn()};

            lastModal().options.onFieldChange({key: 'auth', value: 'key', api});
            lastModal().options.onFieldChange({key: 'auth', value: 'key', api, collectionKey: 'x'});
            lastModal().options.onFieldChange({key: 'user', value: 'u', api});

            expect(api.rebuildFields).toHaveBeenCalledTimes(1);
            const rebuilt = api.rebuildFields.mock.calls[0][0];
            expect(rebuilt.find((f: any) => f.key === 'key')).toMatchObject({disabled: false, required: true});
            expect(rebuilt.find((f: any) => f.key === 'password')).toMatchObject({disabled: true});
        });

        it('writes a no-auth config without any secret', () => {
            mount('sftp', {});
            configure();

            lastModal().options.onSubmit({server: 'h', port: 22.4, user: 'u', auth: 'none', password: 'ignored'});

            expect(written()).toEqual({server: 'h', port: 22, user: 'u', auth: 'none'});
        });

        it('writes a typed password, replacing a stored one', () => {
            mount('sftp', {password: SENTINEL});
            configure();

            lastModal().options.onSubmit({server: 'h', port: 22, user: 'u', auth: 'password', password: 'new'});

            expect(written().password).toBe('new');
        });

        it('re-emits the stored password/key when the input was left empty', () => {
            mount('sftp', {password: SENTINEL, key: 'PEM'});
            configure();
            const modal = lastModal();

            modal.options.onSubmit({server: 'h', port: 22, user: 'u', auth: 'password', password: ''});
            expect(written().password).toBe(SENTINEL);

            modal.options.onSubmit({server: 'h', port: 22, user: 'u', auth: 'key', key: ''});
            expect(written()).toMatchObject({auth: 'key', key: 'PEM'});
            expect(written()).not.toHaveProperty('password');
        });

        it('writes an empty secret when none is typed and none is stored', () => {
            mount('sftp', {});
            configure();

            lastModal().options.onSubmit({server: 'h', port: 22, user: 'u', auth: 'key'});

            expect(written().key).toBe('');
        });

        it('defaults port and auth for missing values', () => {
            mount('sftp', {});
            configure();

            lastModal().options.onSubmit({});

            expect(written()).toEqual({server: '', port: 22, user: '', auth: 'none'});
        });
    });

    describe('rest_api popup', () => {
        const REST = {
            server: 'api.example.com', port: 8443, headers: {Accept: 'json', Empty: null},
            auth: 'oauth', oauth: {path: '/token', body: {grant_type: 'client'}, headers: {'X-A': 'b'}}
        };

        it('prefills values, turning header objects into name/value rows', () => {
            mount('rest_api', REST);

            configure();

            expect(lastModal().options.values).toEqual({
                server: 'api.example.com', port: 8443,
                headers: [{name: 'Accept', value: 'json'}, {name: 'Empty', value: ''}],
                auth: 'oauth', auth_headers: [], oauth_path: '/token',
                oauth_body: [{name: 'grant_type', value: 'client'}],
                oauth_headers: [{name: 'X-A', value: 'b'}]
            });
            expect(lastModal().options.width).toBe('680px');
        });

        it('defaults everything for an empty config', () => {
            mount('rest_api', {});

            configure();

            expect(lastModal().options.values).toEqual({
                server: '', port: null, headers: [], auth: 'none', auth_headers: [],
                oauth_path: '', oauth_body: [], oauth_headers: []
            });
        });

        it('shows the secrets note as the subtitle only when a sentinel is present', () => {
            mount('rest_api', {headers: {Authorization: SENTINEL}});
            configure();
            expect(lastModal().options.subtitle).toBe('aaxis.ontology.connector_config.secrets_note');

            mount('rest_api', {headers: {Accept: 'json'}});
            configure();
            expect(lastModal().options.subtitle).toBe('aaxis.ontology.connector_config.subtitle');
        });

        it('offers only server/port/headers/auth for auth none', () => {
            mount('rest_api', {});
            configure();

            expect(lastModal().options.fields.map((f: any) => f.key)).toEqual(['server', 'port', 'headers', 'auth']);
            expect(lastModal().field('headers').fields.map((f: any) => f.key)).toEqual(['name', 'value']);
            expect(lastModal().field('auth').options.map((o: any) => o.value)).toEqual(['none', 'headers', 'oauth']);
        });

        it('adds the auth-header collection for auth headers', () => {
            mount('rest_api', {auth: 'headers'});
            configure();

            expect(lastModal().options.fields.map((f: any) => f.key)).toEqual(
                ['server', 'port', 'headers', 'auth', 'auth_headers']
            );
        });

        it('adds the token path and the body/headers tab pair for oauth', () => {
            mount('rest_api', {auth: 'oauth'});
            configure();

            expect(lastModal().options.fields.map((f: any) => f.key)).toEqual(
                ['server', 'port', 'headers', 'auth', 'oauth_path', 'oauth_body', 'oauth_headers']
            );
            expect(lastModal().field('oauth_path')).toMatchObject({required: true});
            expect(lastModal().field('oauth_body')).toMatchObject({tabGroup: 'oauth', type: 'collection'});
            expect(lastModal().field('oauth_headers')).toMatchObject({tabGroup: 'oauth', type: 'collection'});
        });

        it('rebuilds the fields when the auth choice changes', () => {
            mount('rest_api', {});
            configure();
            const api = {rebuildFields: vi.fn()};

            lastModal().options.onFieldChange({key: 'auth', value: 'headers', api});
            lastModal().options.onFieldChange({key: 'auth', value: 'headers', api, collectionKey: 'headers'});

            expect(api.rebuildFields).toHaveBeenCalledTimes(1);
            expect(api.rebuildFields.mock.calls[0][0].map((f: any) => f.key)).toContain('auth_headers');
        });

        it('writes headers with auth none, dropping nameless rows and trimming names', () => {
            mount('rest_api', {});
            configure();

            lastModal().options.onSubmit({
                server: 's', port: 443.6, auth: 'none',
                headers: [{name: ' A ', value: 'x'}, {name: '  ', value: 'dropped'}, {name: 'B'}, null],
                auth_headers: [{name: 'ignored', value: '1'}]
            });

            expect(written()).toEqual({server: 's', headers: {A: 'x', B: ''}, auth: 'none', port: 444});
        });

        it('omits the port when none is given', () => {
            mount('rest_api', {});
            configure();

            lastModal().options.onSubmit({server: 's', auth: 'none', headers: 'not an array'});

            expect(written()).toEqual({server: 's', headers: {}, auth: 'none'});
        });

        it('writes auth headers for auth "headers"', () => {
            mount('rest_api', {});
            configure();

            lastModal().options.onSubmit({
                server: 's', auth: 'headers', headers: [], auth_headers: [{name: 'Authorization', value: 'Bearer t'}]
            });

            expect(written().auth_headers).toEqual({Authorization: 'Bearer t'});
            expect(written()).not.toHaveProperty('oauth');
        });

        it('writes the oauth block for auth "oauth"', () => {
            mount('rest_api', {});
            configure();

            lastModal().options.onSubmit({
                server: 's', auth: 'oauth', headers: [], oauth_path: '/t',
                oauth_body: [{name: 'grant_type', value: 'client'}], oauth_headers: [{name: 'X', value: 'y'}]
            });

            expect(written().oauth).toEqual({path: '/t', body: {grant_type: 'client'}, headers: {X: 'y'}});
            expect(written()).not.toHaveProperty('auth_headers');
        });

        it('defaults auth to none when the form supplies none', () => {
            mount('rest_api', {});
            configure();

            lastModal().options.onSubmit({server: 's', headers: []});

            expect(written().auth).toBe('none');
        });
    });

    describe('bucket popup', () => {
        it('prefills server/port/bucket and never the keys', () => {
            mount('bucket', {server: 's3.example.com', port: 9000, access_key: SENTINEL, secret_key: SENTINEL, bucket_name: 'b'});

            configure();

            expect(lastModal().options.values).toEqual({server: 's3.example.com', port: 9000, bucket_name: 'b'});
            expect(lastModal().options.width).toBe('620px');
        });

        it('defaults for an empty config and requires both keys', () => {
            mount('bucket', {});
            configure();

            expect(lastModal().options.values).toEqual({server: '', port: null, bucket_name: ''});
            expect(lastModal().options.subtitle).toBe('aaxis.ontology.connector_config.subtitle');
            expect(lastModal().field('access_key')).toMatchObject({type: 'password', required: true, row: 'keys', width: '50%'});
            expect(lastModal().field('secret_key')).toMatchObject({type: 'password', required: true});
            expect(lastModal().field('access_key').hint).toBeUndefined();
            expect(lastModal().field('bucket_name')).toMatchObject({required: true});
        });

        it.each([
            [{access_key: SENTINEL}],
            [{secret_key: SENTINEL}]
        ])('shows the secrets note and stops requiring the stored key for %j', config => {
            mount('bucket', config);
            configure();

            expect(lastModal().options.subtitle).toBe('aaxis.ontology.connector_config.secrets_note');
            const stored = Object.keys(config)[0];
            expect(lastModal().field(stored).required).toBe(false);
            expect(lastModal().field(stored).hint).toBe(`aaxis.ontology.connector_config.${stored}_defined`);
        });

        it('writes keys in form order with the optional port right after the server', () => {
            mount('bucket', {});
            configure();

            lastModal().options.onSubmit({
                server: 's', port: 9000, access_key: 'AK', secret_key: 'SK', bucket_name: 'b'
            });

            expect(Object.keys(written())).toEqual(['server', 'port', 'access_key', 'secret_key', 'bucket_name']);
            expect(written()).toEqual({server: 's', port: 9000, access_key: 'AK', secret_key: 'SK', bucket_name: 'b'});
        });

        it('omits the port and re-emits stored keys left empty', () => {
            mount('bucket', {access_key: SENTINEL, secret_key: 'typed-earlier'});
            configure();

            lastModal().options.onSubmit({server: 's', access_key: '', secret_key: '', bucket_name: 'b'});

            expect(written()).toEqual({server: 's', access_key: SENTINEL, secret_key: 'typed-earlier', bucket_name: 'b'});
        });

        it('writes empty keys when none typed and none stored', () => {
            mount('bucket', {});
            configure();

            lastModal().options.onSubmit({});

            expect(written()).toEqual({server: '', access_key: '', secret_key: '', bucket_name: ''});
        });
    });

    describe('database popup', () => {
        it('prefills the values, defaulting port 5432 and the postgresql engine', () => {
            mount('database', {server: 'db', database: 'app', schema: 'public', user: 'u', password: SENTINEL});

            configure();

            expect(lastModal().options.values).toEqual({
                server: 'db', port: 5432, engine: 'postgresql', database: 'app', schema: 'public', user: 'u'
            });
            expect(lastModal().options.width).toBe('640px');
            expect(lastModal().options.subtitle).toBe('aaxis.ontology.connector_config.secrets_note');
        });

        it('defaults everything for an empty config', () => {
            mount('database', {});
            configure();

            expect(lastModal().options.values).toEqual({
                server: '', port: 5432, engine: 'postgresql', database: '', schema: '', user: ''
            });
            expect(lastModal().options.subtitle).toBe('aaxis.ontology.connector_config.subtitle');
            expect(lastModal().field('password')).toMatchObject({required: true, type: 'password'});
            expect(lastModal().field('engine').options).toHaveLength(1);
            expect(lastModal().field('schema')).toMatchObject({row: 'db', width: '38%'});
        });

        it('does not require a stored password', () => {
            mount('database', {password: SENTINEL});
            configure();

            expect(lastModal().field('password')).toMatchObject({required: false});
            expect(lastModal().field('password').hint).toBe('aaxis.ontology.connector_config.password_defined');
        });

        it('writes the connection, omitting a blank schema and keeping a stored password', () => {
            mount('database', {password: SENTINEL});
            configure();

            lastModal().options.onSubmit({
                engine: 'postgresql', server: 'db', port: 5433, database: 'app', schema: '   ', user: 'u', password: ''
            });

            expect(written()).toEqual({
                engine: 'postgresql', server: 'db', port: 5433, database: 'app', user: 'u', password: SENTINEL
            });
        });

        it('writes a trimmed schema and a typed password', () => {
            mount('database', {});
            configure();

            lastModal().options.onSubmit({
                server: 'db', port: 5432, database: 'app', schema: ' sales ', user: 'u', password: 'pw'
            });

            expect(written()).toMatchObject({engine: 'postgresql', schema: 'sales', password: 'pw'});
        });

        it('defaults the port when the form supplies none', () => {
            mount('database', {});
            configure();

            lastModal().options.onSubmit({});

            expect(written()).toMatchObject({port: 5432, engine: 'postgresql', password: ''});
        });
    });

    describe('Test action', () => {
        function testAction(type: string, config: unknown = {}) {
            mount(type, config);
            configure();

            return lastModal().options.testAction;
        }

        it.each(['file_system', 'sftp', 'rest_api', 'bucket', 'database'])('%s popup carries a Test action', type => {
            expect(testAction(type).label).toBe('aaxis.ontology.connector_config.test');
        });

        it('posts the CURRENT popup values with the type and connector id, and maps the outcome', async () => {
            const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({
                success: true, message: 'ok', steps: [{label: 'Socket', success: true}], extra: 'ignored'
            })});
            const action = testAction('file_system', {});

            const outcome = await action.onTest({base_path: '/tmp'});

            const [, init] = fetchSpy.mock.calls[0];
            expect(init.method).toBe('POST');
            expect(init.headers['X-CSRF-Header']).toBe('tok');
            expect(JSON.parse(init.body)).toEqual({type: 'file_system', config: {base_path: '/tmp'}, id: 42});
            expect(outcome).toEqual({success: true, message: 'ok', steps: [{label: 'Socket', success: true}]});
        });

        it('sends a null connector id on create and drops a non-string message / non-array steps', async () => {
            const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: false, message: 7, steps: 'nope'})});
            mount('database', {}, {connectorId: null});
            configure();

            const outcome = await lastModal().options.testAction.onTest({server: 'db'});

            expect(JSON.parse(fetchSpy.mock.calls[0][1].body)).toMatchObject({type: 'database', id: null});
            expect(outcome).toEqual({success: false, message: undefined, steps: undefined});
        });

        it('treats a non-numeric connector id as none', async () => {
            const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: true})});
            mount('database', {}, {connectorId: '42'});
            configure();

            await lastModal().options.testAction.onTest({server: 'db'});

            expect(JSON.parse(fetchSpy.mock.calls[0][1].body).id).toBeNull();
        });

        it.each([
            ['a body without a boolean success flag', jsonResponse({message: 'x'})],
            ['an empty body', jsonResponse(null)]
        ])('rejects with the generic test error on %s', async (_name, response) => {
            stubFetch({[TEST_URL]: response});
            const action = testAction('sftp', {});

            await expect(action.onTest({server: 'h', port: 22, user: 'u', auth: 'none'}))
                .rejects.toThrow('aaxis.ontology.connector_config.test_error');
        });

        it('tests each type with the config it would save (sftp keeps a stored secret)', async () => {
            const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: true})});
            const action = testAction('sftp', {password: SENTINEL});

            await action.onTest({server: 'h', port: 22, user: 'u', auth: 'password', password: ''});
            await flush();

            expect(JSON.parse(fetchSpy.mock.calls[0][1].body).config).toEqual({
                server: 'h', port: 22, user: 'u', auth: 'password', password: SENTINEL
            });
        });

        it.each([
            ['rest_api', {server: 's', auth: 'none', headers: []}, {server: 's', headers: {}, auth: 'none'}],
            ['bucket', {server: 's', access_key: 'a', secret_key: 'b', bucket_name: 'n'},
                {server: 's', access_key: 'a', secret_key: 'b', bucket_name: 'n'}],
            ['database', {server: 's', port: 1, database: 'd', user: 'u', password: 'p'},
                {engine: 'postgresql', server: 's', port: 1, database: 'd', user: 'u', password: 'p'}]
        ])('%s tests exactly what it would save', async (type, values, expected) => {
            const fetchSpy = stubFetch({[TEST_URL]: jsonResponse({success: true})});
            const action = testAction(type, {});

            await action.onTest(values);

            expect(JSON.parse(fetchSpy.mock.calls[0][1].body)).toEqual({type, config: expected, id: 42});
        });
    });

    describe('dispose', () => {
        it('removes the display, button and handlers, and shows the mapped textarea again', () => {
            const component = mount('sftp', {server: 's'});

            component.dispose();
            component.dispose();
            $type.val('bucket').trigger('change');

            expect(component.disposed).toBe(true);
            expect($display()).toHaveLength(0);
            expect($('.aaxis-ontology-configure')).toHaveLength(0);
            expect($config.css('display')).not.toBe('none');
            expect(FakeDialog.instances).toHaveLength(0);
        });
    });
});

import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import BaseComponent from 'oroui/js/app/components/base/component';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import RecordFormModal, {FormField, TestOutcome} from 'aaxiscommon/js/app/widgets/record-form-modal';
import {apiFetch} from './component-support';

interface ConnectorConfigOptions {
    _sourceElement: any;
    /** DOM id of the connector "type" select. */
    typeFieldId: string;
    /** DOM id of the read-only config JSON textarea. */
    configFieldId: string;
    /** Id of the connector being edited (null on create) — lets the test endpoint resolve stored secrets. */
    connectorId?: number | null;
}

/**
 * Server-side sentinel for a stored secret (see PHP ConnectorConfigSecrets): the config JSON the
 * page receives holds this value in place of every password/key/Authorization secret, and sending
 * it back unchanged means "keep the stored value".
 */
const SENTINEL = '********';

const TYPE_SFTP = 'sftp';
const TYPE_REST_API = 'rest_api';
const TYPE_FILE_SYSTEM = 'file_system';
const TYPE_BUCKET = 'bucket';
const TYPE_DATABASE = 'database';

/** The only database engine supported so far — mirrors ConnectorTester::ENGINE_POSTGRESQL. */
const ENGINE_POSTGRESQL = 'postgresql';
const POSTGRES_DEFAULT_PORT = 5432;

/**
 * Mirror of the server-side secret-key rules (PHP ConnectorConfigSecrets::isSecretKey) used to
 * mask the DISPLAYED JSON only — keep the two lists in sync. The server stays authoritative for
 * what is persisted; this mirror only prevents a just-typed secret from showing up in the
 * read-only JSON preview before the form is saved.
 */
const SECRET_KEYS = new Set([
    'password', 'passphrase', 'key', 'private_key', 'secret', 'client_secret',
    'api_key', 'apikey', 'token', 'access_token', 'refresh_token', 'authorization'
]);
const SECRET_SUFFIXES = ['_key', '_token', '_secret', '_password'];

/**
 * Companion component of the Connector create/update form (Connector/update.html.twig).
 *
 * The mapped config JSON textarea is hidden and read-only — the configuration is authored through
 * a type-specific "Configure" popup (File System / SFTP / REST API / Bucket / Database) rendered
 * with the reusable RecordFormModal widget. What the user sees instead is a display-only copy of the JSON with every
 * secret masked, so neither stored secrets (already masked server-side as ********) nor secrets
 * typed in the popup during this session are ever readable on the page.
 *
 * Stored secrets never enter the popups either: the JSON only carries the ******** sentinel,
 * secret inputs start empty with a "value stored" hint, and leaving them untouched re-emits the
 * previous JSON value so the server keeps the stored secret.
 *
 * Switching the Type select while a configuration exists asks for confirmation first (the
 * configuration is type-specific), then wipes the JSON; cancelling reverts the select.
 */
class ConnectorConfigComponent extends BaseComponent {
    private $type!: any;
    private $config!: any;
    private $display!: any;
    private $button!: any;
    private $buttonRow!: any;
    private lastType!: string;
    private suppressTypeChange!: boolean;
    private connectorId!: number | null;

    initialize(options: ConnectorConfigOptions): void {
        this.$type = $('#' + options.typeFieldId);
        this.$config = $('#' + options.configFieldId);
        this.suppressTypeChange = false;
        this.connectorId = typeof options.connectorId === 'number' ? options.connectorId : null;
        if (this.$type.length === 0 || this.$config.length === 0) {
            return;
        }
        this.lastType = String(this.$type.val() || '');

        // The mapped textarea keeps the submitted value but is never shown (belt and suspenders —
        // the form type already renders it read-only); the visible copy below is always masked.
        this.$config.prop('readonly', true);
        this.$display = $('<textarea/>', {
            'class': this.$config.attr('class') || '',
            rows: this.$config.attr('rows') || 8,
            readonly: 'readonly',
            spellcheck: false,
            'aria-label': __('aaxis.ontology.connector_config.display_label')
        });
        this.$config.hide().after(this.$display);
        this.refreshDisplay();

        this.$button = $('<button/>', {
            type: 'button',
            'class': 'btn aaxis-ontology-configure-btn'
        }).append(
            $('<span/>', {'class': 'fa fa-cog', 'aria-hidden': 'true'}),
            $('<span/>', {text: ' ' + __('aaxis.ontology.connector_config.configure')})
        );
        // Block-level wrapper: the textarea is inline-block, so a bare (inline-flex) button
        // would sit beside it — the wrapper forces the button onto its own line below.
        this.$buttonRow = $('<div/>', {'class': 'aaxis-ontology-configure'}).append(this.$button);
        this.$display.after(this.$buttonRow);
        this.$button.on('click.aaxisConnectorCfg', (e: any) => {
            e.preventDefault();
            this.openConfigPopup();
        });

        this.$type.on('change.aaxisConnectorCfg', () => this.onTypeChange());
    }

    // --- Type switch confirmation ---------------------------------------------

    private onTypeChange(): void {
        if (this.suppressTypeChange) {
            return;
        }
        const newType = String(this.$type.val() || '');
        if (newType === this.lastType) {
            return;
        }
        if (!this.hasConfig()) {
            this.lastType = newType;
            return;
        }
        this.confirmTypeSwitch(newType);
    }

    private hasConfig(): boolean {
        const raw = String(this.$config.val() || '').trim();
        if (raw === '') {
            return false;
        }
        try {
            const parsed = JSON.parse(raw);
            return !!parsed && typeof parsed === 'object' && Object.keys(parsed).length > 0;
        } catch (e) {
            return true;
        }
    }

    private confirmTypeSwitch(newType: string): void {
        let confirmed = false;
        const dialog = new Dialog({
            title: __('aaxis.ontology.connector_config.type_switch_title'),
            width: '520px',
            // Closing without confirming (Cancel, ✕, Esc, backdrop) reverts the select.
            onClose: () => {
                if (!confirmed) {
                    this.revertType();
                }
            }
        });
        const $content = dialog.open();

        const $body = $('<div/>', {'class': 'aaxis-ontology-confirm'});
        $body.append($('<p/>', {
            'class': 'aaxis-ontology-confirm__q',
            text: __('aaxis.ontology.connector_config.type_switch_question')
        }));
        $body.append($('<p/>', {
            'class': 'aaxis-ontology-confirm__danger',
            text: __('aaxis.ontology.connector_config.type_switch_warning')
        }));

        const $actions = $('<div/>', {'class': 'aaxis-ontology-confirm__actions'});
        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', text: __('Cancel')});
        const $confirm = $('<button/>', {
            type: 'button', 'class': 'btn aaxis-ontology-confirm__delete',
            text: __('aaxis.ontology.connector_config.type_switch_confirm')
        });
        $actions.append($cancel, $confirm);
        $body.append($actions);
        $content.append($body);

        $cancel.on('click', () => dialog.close());
        $confirm.on('click', () => {
            confirmed = true;
            this.$config.val('');
            this.refreshDisplay();
            this.lastType = newType;
            dialog.close();
        });
    }

    private revertType(): void {
        this.suppressTypeChange = true;
        try {
            // trigger('change') keeps the uniform/select2 wrapper in sync with the reverted value.
            this.$type.val(this.lastType).trigger('change');
        } finally {
            this.suppressTypeChange = false;
        }
    }

    // --- Configure popups -------------------------------------------------------

    private openConfigPopup(): void {
        const type = String(this.$type.val() || '');
        const config = this.parseConfig();
        if (type === TYPE_FILE_SYSTEM) {
            this.openFileSystemPopup(config);
        } else if (type === TYPE_SFTP) {
            this.openSftpPopup(config);
        } else if (type === TYPE_REST_API) {
            this.openRestApiPopup(config);
        } else if (type === TYPE_BUCKET) {
            this.openBucketPopup(config);
        } else if (type === TYPE_DATABASE) {
            this.openDatabasePopup(config);
        } else {
            // Safety net for a type added to OntologyConnector::TYPES before its Configure form
            // exists — says so rather than letting the button do nothing.
            this.openUnsupportedPopup();
        }
    }

    /** Placeholder for connector types whose per-type form has not been defined yet. */
    private openUnsupportedPopup(): void {
        const dialog = new Dialog({
            title: __('aaxis.ontology.connector_config.not_available_title'),
            width: '480px'
        });
        const $content = dialog.open();

        const $body = $('<div/>', {'class': 'aaxis-ontology-confirm'});
        $body.append($('<p/>', {text: __('aaxis.ontology.connector_config.not_available_text')}));
        const $actions = $('<div/>', {'class': 'aaxis-ontology-confirm__actions'});
        const $close = $('<button/>', {type: 'button', 'class': 'btn', text: __('Cancel')});
        $actions.append($close);
        $body.append($actions);
        $content.append($body);

        $close.on('click', () => dialog.close());
    }

    private parseConfig(): Record<string, any> {
        try {
            const parsed = JSON.parse(String(this.$config.val() || ''));
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (e) {
            return {}; // not valid JSON (e.g. empty) — treat as no configuration
        }
    }

    private writeConfig(config: Record<string, any>): void {
        // 4-space indent matches the server's JSON_PRETTY_PRINT rendering.
        this.$config.val(JSON.stringify(config, null, 4));
        this.refreshDisplay();
    }

    /** Re-renders the visible JSON copy from the mapped field, masking every secret value. */
    private refreshDisplay(): void {
        const raw = String(this.$config.val() || '').trim();
        if (raw === '') {
            this.$display.val('');
            return;
        }
        const config = this.parseConfig();
        this.$display.val(JSON.stringify(this.maskForDisplay(config), null, 4));
    }

    /** Deep-copies a config, replacing secret values with the sentinel (display only). */
    private maskForDisplay(value: any, key?: string): any {
        if (Array.isArray(value)) {
            return value.map(item => this.maskForDisplay(item));
        }
        if (value && typeof value === 'object') {
            const out: Record<string, any> = {};
            Object.keys(value).forEach(k => {
                out[k] = this.maskForDisplay(value[k], k);
            });
            return out;
        }
        if (typeof value === 'string' && value !== '' && key !== undefined && this.isSecretKey(key)) {
            return SENTINEL;
        }
        return value;
    }

    private isSecretKey(key: string): boolean {
        const normalized = key.toLowerCase().replace(/[- ]/g, '_');
        return SECRET_KEYS.has(normalized) || SECRET_SUFFIXES.some(suffix => normalized.endsWith(suffix));
    }

    // File System: only a base path.
    private openFileSystemPopup(config: Record<string, any>): void {
        new RecordFormModal({
            title: __('aaxis.ontology.connector_config.title_file_system'),
            subtitle: __('aaxis.ontology.connector_config.subtitle'),
            resizable: false,
            fields: [{
                key: 'base_path', label: __('aaxis.ontology.connector_config.base_path'), type: 'text',
                required: true, placeholder: __('aaxis.ontology.connector_config.base_path_placeholder')
            }],
            values: {base_path: typeof config.base_path === 'string' ? config.base_path : ''},
            testAction: {
                label: __('aaxis.ontology.connector_config.test'),
                onTest: values => this.runConfigTest(TYPE_FILE_SYSTEM, this.buildFileSystemConfig(values))
            },
            onSubmit: values => {
                this.writeConfig(this.buildFileSystemConfig(values));
            }
        }).open();
    }

    private buildFileSystemConfig(values: Record<string, any>): Record<string, any> {
        return {base_path: String(values.base_path || '')};
    }

    // SFTP: server/port + auth/user/password on one row, key textarea below. The password/key
    // controls are always rendered; the auth choice only toggles their enabled state. Secrets
    // never show in the popup — the password/key inputs start empty, and an empty submit re-emits
    // the previous JSON value (the ******** sentinel for a saved secret, the cleartext for one
    // typed this session).
    private openSftpPopup(config: Record<string, any>): void {
        const hasStoredPassword = typeof config.password === 'string' && config.password !== '';
        const hasStoredKey = typeof config.key === 'string' && config.key !== '';
        const auth = ['none', 'password', 'key'].includes(String(config.auth)) ? String(config.auth) : 'none';

        new RecordFormModal({
            title: __('aaxis.ontology.connector_config.title_sftp'),
            subtitle: __('aaxis.ontology.connector_config.subtitle'),
            width: '620px',
            resizable: false,
            fields: this.sftpFields(auth, hasStoredPassword, hasStoredKey),
            values: {
                server: typeof config.server === 'string' ? config.server : '',
                port: typeof config.port === 'number' ? config.port : 22,
                user: typeof config.user === 'string' ? config.user : '',
                auth
                // password/key intentionally not prefilled — stored values never reach the form
            },
            onFieldChange: ctx => {
                if (ctx.key === 'auth' && !ctx.collectionKey) {
                    // Re-render with the password/key controls enabled/disabled per the new auth.
                    ctx.api.rebuildFields(this.sftpFields(String(ctx.value), hasStoredPassword, hasStoredKey));
                }
            },
            testAction: {
                label: __('aaxis.ontology.connector_config.test'),
                onTest: values => this.runConfigTest(
                    TYPE_SFTP,
                    this.buildSftpConfig(values, config, hasStoredPassword, hasStoredKey)
                )
            },
            onSubmit: values => {
                this.writeConfig(this.buildSftpConfig(values, config, hasStoredPassword, hasStoredKey));
            }
        }).open();
    }

    private buildSftpConfig(
        values: Record<string, any>,
        config: Record<string, any>,
        hasStoredPassword: boolean,
        hasStoredKey: boolean
    ): Record<string, any> {
        const out: Record<string, any> = {
            server: String(values.server || ''),
            port: values.port == null ? 22 : Math.round(Number(values.port)),
            user: String(values.user || ''),
            auth: String(values.auth || 'none')
        };
        // An empty secret input means "keep what the JSON already holds" (sentinel or a
        // value typed earlier this session); a filled one replaces it.
        if (out.auth === 'password') {
            let password = values.password ? String(values.password) : '';
            if (password === '' && hasStoredPassword) {
                password = String(config.password);
            }
            out.password = password;
        } else if (out.auth === 'key') {
            let key = values.key ? String(values.key) : '';
            if (key === '' && hasStoredKey) {
                key = String(config.key);
            }
            out.key = key;
        }
        return out;
    }

    private sftpFields(auth: string, hasStoredPassword: boolean, hasStoredKey: boolean): FormField[] {
        const t = (key: string): string => __('aaxis.ontology.connector_config.' + key);
        return [
            {key: 'server', label: t('server'), type: 'text', required: true, row: 'srv', width: '75%'},
            {
                key: 'port', label: t('port'), type: 'number', required: true,
                min: 1, max: 65535, row: 'srv', width: '25%'
            },
            {
                key: 'auth', label: t('auth'), type: 'select', row: 'cred', width: '24%',
                options: [
                    {value: 'none', label: t('auth_none')},
                    {value: 'password', label: t('auth_password')},
                    {value: 'key', label: t('auth_key')}
                ]
            },
            {key: 'user', label: t('user'), type: 'text', required: true, row: 'cred', width: '38%'},
            {
                key: 'password', label: t('password'), type: 'password', row: 'cred', width: '38%',
                disabled: auth !== 'password',
                required: auth === 'password' && !hasStoredPassword,
                hint: hasStoredPassword ? t('password_defined') : undefined
            },
            {
                key: 'key', label: t('key'), type: 'textarea',
                disabled: auth !== 'key',
                required: auth === 'key' && !hasStoredKey,
                placeholder: t('key_placeholder'),
                hint: hasStoredKey ? t('key_defined') : t('key_not_defined')
            }
        ];
    }

    // REST API: server/port + free headers + none|headers|oauth auth. Secret header values
    // (e.g. Authorization) arrive masked as ******** — leaving them unchanged keeps the stored
    // value; typing over them replaces it.
    private openRestApiPopup(config: Record<string, any>): void {
        const auth = ['none', 'headers', 'oauth'].includes(String(config.auth)) ? String(config.auth) : 'none';
        const hasSecrets = JSON.stringify(config).includes(SENTINEL);
        const oauth = config.oauth && typeof config.oauth === 'object' ? config.oauth : {};

        new RecordFormModal({
            title: __('aaxis.ontology.connector_config.title_rest_api'),
            subtitle: hasSecrets
                ? __('aaxis.ontology.connector_config.secrets_note')
                : __('aaxis.ontology.connector_config.subtitle'),
            width: '680px',
            resizable: false,
            fields: this.restApiFields(auth),
            values: {
                server: typeof config.server === 'string' ? config.server : '',
                port: typeof config.port === 'number' ? config.port : null,
                headers: this.headersToRows(config.headers),
                auth,
                auth_headers: this.headersToRows(config.auth_headers),
                oauth_path: typeof oauth.path === 'string' ? oauth.path : '',
                oauth_body: this.headersToRows(oauth.body),
                oauth_headers: this.headersToRows(oauth.headers)
            },
            onFieldChange: ctx => {
                if (ctx.key === 'auth' && !ctx.collectionKey) {
                    ctx.api.rebuildFields(this.restApiFields(String(ctx.value)));
                }
            },
            testAction: {
                label: __('aaxis.ontology.connector_config.test'),
                onTest: values => this.runConfigTest(TYPE_REST_API, this.buildRestApiConfig(values))
            },
            onSubmit: values => {
                this.writeConfig(this.buildRestApiConfig(values));
            }
        }).open();
    }

    private buildRestApiConfig(values: Record<string, any>): Record<string, any> {
        const out: Record<string, any> = {
            server: String(values.server || ''),
            headers: this.rowsToHeaders(values.headers),
            auth: String(values.auth || 'none')
        };
        if (values.port != null) {
            out.port = Math.round(Number(values.port));
        }
        if (out.auth === 'headers') {
            out.auth_headers = this.rowsToHeaders(values.auth_headers);
        } else if (out.auth === 'oauth') {
            out.oauth = {
                path: String(values.oauth_path || ''),
                body: this.rowsToHeaders(values.oauth_body),
                headers: this.rowsToHeaders(values.oauth_headers)
            };
        }
        return out;
    }

    private restApiFields(auth: string): FormField[] {
        const t = (key: string): string => __('aaxis.ontology.connector_config.' + key);
        const headerSub: FormField[] = [
            {key: 'name', label: t('header_name'), type: 'text', required: true, width: '40%'},
            {key: 'value', label: t('header_value'), type: 'text'}
        ];
        const fields: FormField[] = [
            {key: 'server', label: t('server'), type: 'text', required: true, row: 'srv', width: '75%'},
            {key: 'port', label: t('port'), type: 'number', min: 1, max: 65535, row: 'srv', width: '25%'},
            {
                key: 'headers', label: t('headers'), type: 'collection',
                fields: headerSub, addLabel: t('add_header')
            },
            {
                key: 'auth', label: t('auth'), type: 'select',
                options: [
                    {value: 'none', label: t('auth_none')},
                    {value: 'headers', label: t('auth_headers_option')},
                    {value: 'oauth', label: t('auth_oauth')}
                ]
            }
        ];
        if (auth === 'headers') {
            fields.push({
                key: 'auth_headers', label: t('auth_headers_section'), type: 'collection',
                fields: headerSub, addLabel: t('add_header')
            });
        } else if (auth === 'oauth') {
            // OAuth: token path textbox, then the body/headers pair as tabs to keep the popup short.
            fields.push(
                {
                    key: 'oauth_path', label: t('oauth_path'), type: 'text', required: true,
                    placeholder: t('oauth_path_placeholder')
                },
                {
                    key: 'oauth_body', label: t('oauth_body_section'), type: 'collection',
                    tabGroup: 'oauth', tabLabel: t('oauth_body_section'),
                    fields: [
                        {key: 'name', label: t('body_name'), type: 'text', required: true, width: '40%'},
                        {key: 'value', label: t('body_value'), type: 'text'}
                    ],
                    addLabel: t('add_body')
                },
                {
                    key: 'oauth_headers', label: t('oauth_headers_section'), type: 'collection',
                    tabGroup: 'oauth', tabLabel: t('oauth_headers_section'),
                    fields: headerSub, addLabel: t('add_header')
                }
            );
        }
        return fields;
    }

    // Bucket (S3-compatible object storage — OCI Object Storage, AWS S3, MinIO): endpoint
    // server/port on the first row, the access key / secret key pair on the second and the bucket
    // to work in below. BOTH keys are password fields: the server-side secret rules already treat
    // any *_key as a secret, so the access key is masked on every read path like the secret key is.
    private openBucketPopup(config: Record<string, any>): void {
        const hasStoredAccessKey = typeof config.access_key === 'string' && config.access_key !== '';
        const hasStoredSecretKey = typeof config.secret_key === 'string' && config.secret_key !== '';

        new RecordFormModal({
            title: __('aaxis.ontology.connector_config.title_bucket'),
            subtitle: hasStoredAccessKey || hasStoredSecretKey
                ? __('aaxis.ontology.connector_config.secrets_note')
                : __('aaxis.ontology.connector_config.subtitle'),
            width: '620px',
            resizable: false,
            fields: this.bucketFields(hasStoredAccessKey, hasStoredSecretKey),
            values: {
                server: typeof config.server === 'string' ? config.server : '',
                port: typeof config.port === 'number' ? config.port : null,
                bucket_name: typeof config.bucket_name === 'string' ? config.bucket_name : ''
                // access_key/secret_key intentionally not prefilled — stored values never reach the form
            },
            testAction: {
                label: __('aaxis.ontology.connector_config.test'),
                onTest: values => this.runConfigTest(
                    TYPE_BUCKET,
                    this.buildBucketConfig(values, config, hasStoredAccessKey, hasStoredSecretKey)
                )
            },
            onSubmit: values => {
                this.writeConfig(this.buildBucketConfig(values, config, hasStoredAccessKey, hasStoredSecretKey));
            }
        }).open();
    }

    private buildBucketConfig(
        values: Record<string, any>,
        config: Record<string, any>,
        hasStoredAccessKey: boolean,
        hasStoredSecretKey: boolean
    ): Record<string, any> {
        // Built key by key (rather than as one literal) so the JSON keeps the form's order with
        // the optional port right after the server.
        const out: Record<string, any> = {server: String(values.server || '')};
        if (values.port != null) {
            out.port = Math.round(Number(values.port));
        }
        out.access_key = this.keepStoredSecret(values.access_key, config.access_key, hasStoredAccessKey);
        out.secret_key = this.keepStoredSecret(values.secret_key, config.secret_key, hasStoredSecretKey);
        out.bucket_name = String(values.bucket_name || '');

        return out;
    }

    /**
     * An empty secret input means "keep what the JSON already holds" — the ******** sentinel for a
     * saved secret, or a value typed earlier this session; a filled one replaces it.
     */
    private keepStoredSecret(submitted: any, stored: any, hasStored: boolean): string {
        const value = submitted ? String(submitted) : '';

        return value === '' && hasStored ? String(stored) : value;
    }

    private bucketFields(hasStoredAccessKey: boolean, hasStoredSecretKey: boolean): FormField[] {
        const t = (key: string): string => __('aaxis.ontology.connector_config.' + key);
        return [
            {key: 'server', label: t('server'), type: 'text', required: true, row: 'srv', width: '75%'},
            {key: 'port', label: t('port'), type: 'number', min: 1, max: 65535, row: 'srv', width: '25%'},
            {
                key: 'access_key', label: t('access_key'), type: 'password', row: 'keys', width: '50%',
                required: !hasStoredAccessKey,
                hint: hasStoredAccessKey ? t('access_key_defined') : undefined
            },
            {
                key: 'secret_key', label: t('secret_key'), type: 'password', row: 'keys', width: '50%',
                required: !hasStoredSecretKey,
                hint: hasStoredSecretKey ? t('secret_key_defined') : undefined
            },
            {
                key: 'bucket_name', label: t('bucket_name'), type: 'text', required: true,
                placeholder: t('bucket_name_placeholder')
            }
        ];
    }

    // Database: server/port, then engine/database/schema, then the credentials. PostgreSQL is the
    // only engine so far — it is still a stored, visible field so the JSON shape does not have to
    // change when a second engine is added. Schema is optional (blank = the server's search_path).
    private openDatabasePopup(config: Record<string, any>): void {
        const hasStoredPassword = typeof config.password === 'string' && config.password !== '';

        new RecordFormModal({
            title: __('aaxis.ontology.connector_config.title_database'),
            subtitle: hasStoredPassword
                ? __('aaxis.ontology.connector_config.secrets_note')
                : __('aaxis.ontology.connector_config.subtitle'),
            width: '640px',
            resizable: false,
            fields: this.databaseFields(hasStoredPassword),
            values: {
                server: typeof config.server === 'string' ? config.server : '',
                port: typeof config.port === 'number' ? config.port : POSTGRES_DEFAULT_PORT,
                engine: ENGINE_POSTGRESQL,
                database: typeof config.database === 'string' ? config.database : '',
                schema: typeof config.schema === 'string' ? config.schema : '',
                user: typeof config.user === 'string' ? config.user : ''
                // password intentionally not prefilled — stored values never reach the form
            },
            testAction: {
                label: __('aaxis.ontology.connector_config.test'),
                onTest: values => this.runConfigTest(
                    TYPE_DATABASE,
                    this.buildDatabaseConfig(values, config, hasStoredPassword)
                )
            },
            onSubmit: values => {
                this.writeConfig(this.buildDatabaseConfig(values, config, hasStoredPassword));
            }
        }).open();
    }

    private buildDatabaseConfig(
        values: Record<string, any>,
        config: Record<string, any>,
        hasStoredPassword: boolean
    ): Record<string, any> {
        const out: Record<string, any> = {
            engine: String(values.engine || ENGINE_POSTGRESQL),
            server: String(values.server || ''),
            port: values.port == null ? POSTGRES_DEFAULT_PORT : Math.round(Number(values.port)),
            database: String(values.database || ''),
            user: String(values.user || ''),
            password: this.keepStoredSecret(values.password, config.password, hasStoredPassword)
        };
        // Optional — omitted entirely rather than stored as "" so the shape says "not configured".
        const schema = String(values.schema || '').trim();
        if (schema !== '') {
            out.schema = schema;
        }

        return out;
    }

    private databaseFields(hasStoredPassword: boolean): FormField[] {
        const t = (key: string): string => __('aaxis.ontology.connector_config.' + key);
        return [
            {key: 'server', label: t('server'), type: 'text', required: true, row: 'srv', width: '75%'},
            {
                key: 'port', label: t('port'), type: 'number', required: true,
                min: 1, max: 65535, row: 'srv', width: '25%'
            },
            {
                key: 'engine', label: t('engine'), type: 'select', row: 'db', width: '24%',
                options: [{value: ENGINE_POSTGRESQL, label: t('engine_postgresql')}]
            },
            {key: 'database', label: t('database'), type: 'text', required: true, row: 'db', width: '38%'},
            {
                key: 'schema', label: t('schema'), type: 'text', row: 'db', width: '38%',
                placeholder: t('schema_placeholder')
            },
            {key: 'user', label: t('user'), type: 'text', required: true, row: 'cred', width: '50%'},
            {
                key: 'password', label: t('password'), type: 'password', row: 'cred', width: '50%',
                required: !hasStoredPassword,
                hint: hasStoredPassword ? t('password_defined') : undefined
            }
        ];
    }

    /** {"Header": "value"} object → collection rows [{name, value}]. */
    private headersToRows(obj: any): Array<{name: string; value: string}> {
        const rows: Array<{name: string; value: string}> = [];
        if (obj && typeof obj === 'object' && !Array.isArray(obj)) {
            Object.keys(obj).forEach(name => {
                rows.push({name, value: obj[name] == null ? '' : String(obj[name])});
            });
        }
        return rows;
    }

    // --- Test connection --------------------------------------------------------

    /**
     * Runs the server-side config test (path / socket / auth checks) against the CURRENT popup
     * values. The config may hold ******** sentinels for untouched stored secrets — the endpoint
     * resolves them from the connector's persisted config (hence connectorId).
     */
    private runConfigTest(type: string, config: Record<string, any>): Promise<TestOutcome> {
        return apiFetch(routing.generate('aaxis_ontology_connector_test'), 'POST', {
            type,
            config,
            id: this.connectorId
        }).then(res => {
            const data = res.data || {};
            if (typeof data.success !== 'boolean') {
                throw new Error(__('aaxis.ontology.connector_config.test_error'));
            }
            return {
                success: data.success,
                message: typeof data.message === 'string' ? data.message : undefined,
                steps: Array.isArray(data.steps) ? data.steps : undefined
            };
        });
    }

    /** Collection rows [{name, value}] → {"Header": "value"} object (rows without a name dropped). */
    private rowsToHeaders(rows: any): Record<string, string> {
        const out: Record<string, string> = {};
        (Array.isArray(rows) ? rows : []).forEach((row: any) => {
            const name = String(row?.name || '').trim();
            if (name !== '') {
                out[name] = String(row?.value || '');
            }
        });
        return out;
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        if (this.$type) {
            this.$type.off('.aaxisConnectorCfg');
        }
        if (this.$button) {
            this.$button.off('.aaxisConnectorCfg');
        }
        if (this.$buttonRow) {
            this.$buttonRow.remove();
        }
        if (this.$display) {
            this.$display.remove();
            this.$config.show();
        }
        super.dispose();
    }
}

export default ConnectorConfigComponent;

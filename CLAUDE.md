# CLAUDE.md — AaxisOntologyBundle

Guidance for working in this bundle. Read alongside `README.md` (user-facing) and
`../CommonBundle/CLAUDE.md` (the shared base this bundle depends on).

## What this bundle is

A back-office (admin) OroCommerce feature modelling a lightweight **data ontology**: systems,
their entities + typed attributes, connectors, flows, the events flows produce, and the versioned
data records themselves. Depends on `AaxisCommonBundle` (TS build pipeline, DataGrid widgets, the
top-level "Aaxis" menu group). Independent of the other feature bundles.

## Identity / naming conventions

| Thing | Value |
|-------|-------|
| PHP namespace | `Aaxis\Bundle\OntologyBundle` |
| Bundle class | `AaxisOntologyBundle` (auto-registered via `Resources/config/oro/bundles.yml`) |
| Config alias | `aaxis_ontology` |
| Back-office route prefix | `/admin/aaxis/ontology` · route names `aaxis_ontology_*` |
| Twig namespace | `@AaxisOntology/...` |
| Asset namespace | `aaxisontology` (`bundles/aaxisontology/...`, JS ids `aaxisontology/js/...`) |
| Translation root | `aaxis.ontology.*` |

## Entities (Entity/ → tables)

| Class | Table | Notes |
|-------|-------|-------|
| `OntologySystem` | `aaxis_ontology_system` | name (**unique**), enabled, optional logo (Oro attachment) |
| `OntologyEntity` | `aaxis_ontology_entity` | belongs to a system; **`(system_id, name)` unique**; owns attributes (1:N); `unique_attribute` (required) = name of the attribute used as `unique_id`. Deliberately a free-text string, NOT a dropdown of attributes — it's defined before attributes exist (and attributes are optional). |

> Systems are referenced by **name**, and entities by **system name + entity name** — hence the
> uniqueness above. Both JSON controllers pre-check for duplicates and return a 422 (`*.name_unique`)
> so the DB unique index never surfaces as a raw 500. ORM mappings deliberately do NOT declare these
> indexes — the installer is the single source of truth for schema (matching the pre-existing
> `logo_id` unique index).
| `OntologyEntityAttribute` | `aaxis_ontology_entity_attribute` | name, datatype (`TYPES` const), required |
| `OntologyConnector` | `aaxis_ontology_connector` | belongs to a system; type + JSON config authored via the per-type "Configure" popup; secret config values are masked on every read path (see "Connector config & secrets" below) |
| `OntologyFlow` | `aaxis_ontology_flow` | name, enabled, `type` (`native` = the two fixture-seeded built-ins, read-only in the UI — gates the grid edit action, the editor page and the update endpoint; user flows are `flow` when their steps contain a trigger, else `subflow` — recomputed from the steps on every save via `computeType()`, never taken from the payload), JSON `steps` (`[{type, name, x, y}]`, types validated against `STEP_TYPES`, names non-empty ≤64 chars and unique per flow — 422 `flow_manager.step_names_unique`), JSON `design` (the editor's versioned canvas state — stored opaquely by the server, strictly validated by the editor on load; unreadable/outdated → "corrupted" flash + empty canvas; NULL → canvas rebuilt from `steps`) |
| `OntologyData` | `aaxis_ontology_data` | latest record; `(entity, unique_id)` unique; `payload` jsonb |
| `OntologyDataHistory` | `aaxis_ontology_data_history` | per-version diffs; `(entity, unique_id, version)` unique |
| `OntologyDataEvent` | `aaxis_ontology_data_events` | one row per flow execution (seen vs changed ids) |

## Data HTTP API (OAuth) — `Api/` + `Manager/OntologyDataApiManager`

OAuth-authenticated REST endpoints over `OntologyData`, addressed by **system name + entity name**.
**Requests and responses deal in the raw payload only** (no record envelope):
- `GET  /admin/api/aaxis/ontology/{systemName}/{entityName}/uid/{uniqueId}` — read; returns the
  record's **payload object** (`{}` if empty).
- `POST /admin/api/aaxis/ontology/{systemName}/{entityName}/upsert` — upsert; the **request body is
  the payload** (a single JSON object = one record, a JSON array = a batch). The unique id of each
  record is **inferred from the payload** via the entity's `unique_attribute` (no id in the URL); the
  API generates the uuid. Validates, then publishes one message to the existing
  `aaxis_ontology_data_upsert` topic (the processor does the real write); returns
  `202 {success, uuid, count}`.
- `POST /admin/api/aaxis/ontology/{systemName}/{entityName}/query?page=&page_size=` — query;
  body `{filter:[{attribute,compare:EQ|LIKE|<|>,value}], orderBy:"attr ASC|DESC"}`, returns
  `{items, page, page_size}` where **items is a list of payload objects** (default order `id ASC`).

Key facts:
- **Reusable core** is `Manager/OntologyDataApiManager` (`read`/`upsert`/`query`) — call it directly
  from any PHP; it holds no HTTP/ACL concerns and throws `Exception/OntologyApiException` (which
  carries the HTTP status). `read` returns the payload (`?array`), `query` returns a list of payloads,
  `upsert(systemName, entityName, records)` resolves/auto-creates the entity then delegates to
  `upsertRecords(OntologyEntity, records)` (the shared core: infer uid from each payload, validate,
  queue one message), returning the batch uuid. The controller (`Api/OntologyDataApiController`) only
  enforces the config toggle + ACL, parses the request, and renders JSON.
- **The back-office "Add Data" modal reuses this**: `OntologyDataController::createAction` decodes the
  payload (JSON/CSV/XML), normalizes single-object-vs-array, and calls `upsertRecords()` — so the
  modal behaves exactly like the API (no Mode selector, no Unique Id field; the uid is inferred from
  the payload via the entity's `unique_attribute`). UI: `data-view-component.ts`.
- **Attribute reconciliation + contract** (`Manager/OntologyAttributeReconciler`, called by
  `upsertRecords` before queueing, so it applies to both the API and the Add Data UI):
  - *Auto-create*: every attribute present in the written payloads but not yet defined on the entity
    is created as datatype `undefined`, not required. Nested object keys become dotted paths
    (`address.city`); scalars and JSON arrays are leaf attributes. An attribute **pre-declared as
    `object`** collapses its subtree — its nested keys are NOT created. (Done on the input payloads;
    cumulative over writes, so equivalent to scanning the merged stored payload. "unknown" =
    the existing `undefined` datatype.)
  - *Validation* (synchronous, 400 on failure): a record missing a **required** attribute is
    rejected; a value whose type differs from the attribute's declared datatype is rejected
    (datatype `undefined` accepts anything). Array-of-object fields validate **per element** (a path
    like `orders.headerData.VBAK_VKORG` resolves to one value per array element; required = present in
    ≥1 element). Type map: boolean→bool, number→int/float (strict — a numeric string is rejected),
    text/date/time/datetime→string, object→array.
- **Flow gating**: every entry point is gated on a named `OntologyFlow` via
  `OntologyDataApiManager::requireEnabledFlow()` — the REST API endpoints (read/upsert/query) use
  `OntologyFlow::NAME_REST_API` ("Ontology REST API"), the "Add Data" modal uses
  `OntologyFlow::NAME_MANUAL` ("Manual"). If that flow is **disabled**, the call fails (409
  `flow_disabled`); if it's missing entirely, 500 `flow_misconfigured`. Upserts stamp the flow's id
  on the queued message / `aaxis_ontology_data_events.flow_id`. The two flows are seeded by the data
  fixture `Migrations/Data/ORM/LoadOntologyFlows` (run `oro:migration:data:load`).
- **Auth**: routes are declared as `/api/...`; Oro's `RouteCollectionListener` prepends `/admin`, so
  they resolve under `/admin/api/...` and ride the **stateless OAuth `api_secured` firewall**
  (pattern `^/admin/api/(?!(rest|doc)…)`). `#[Acl]`/`isGranted` work as for a logged-in user; no CSRF.
  The API controller lives in `Api/` (NOT `Controller/`) so the back-office routing import
  (`prefix: /aaxis/ontology`) doesn't pick it up; it's registered via a no-prefix import in
  `routing.yml` and as a service in `controllers.yml`.
- **ACLs** (action capabilities, `acls.yml`): `aaxis_ontology_api_access_all` (read+upsert+query) and
  `aaxis_ontology_api_access_read` (read+query). Read/query need either; upsert needs `_all`.
  Administrator gets `_all` via `Migrations/Data/ORM/LoadAaxisOntologyApiAdminPermissions`
  (run `oro:migration:data:load`).
- **Config** (System Config → Aaxis → General → Aaxis Ontology → Data API; the tree hangs under
  CommonBundle's shared `aaxis > aaxis_general` tab): per-endpoint enables
  (`api_read_enabled`/`api_upsert_enabled`/`api_query_enabled`, default off → disabled endpoint = 404),
  `api_auto_create` (upsert auto-creates unknown system/entity; read/query on unknown always error),
  `api_auto_create_unique_attribute` (default `id`), `api_query_max_page_size` (default 200).
- Disabled system/entity → error on every call. Query filters/orderBy are fully parameterized
  (attribute keys + values bound; operators/direction whitelisted) — never interpolate user input.

## UI architecture (the part that trips people up)

Most pages (Systems, Entities, Flows, Events, Data View) are **NOT** standard Oro datagrids/forms.
They are TypeScript single-page components built on CommonBundle's reusable `DataGrid` +
`RecordFormModal` widgets, backed by **JSON endpoints** on the bundle's controllers. The Connector
page is the exception — it uses a normal Oro datagrid + server-side CRUD.

### Connector config & secrets

The connector's JSON config is **never typed by hand**: the form textarea is read-only (attr set in
`OntologyConnectorType`) and `connector-config-component.ts` (attached by a page-component div in
`Connector/update.html.twig`, options = the type/config field DOM ids) adds a **"Configure…"
button** that opens a type-specific `RecordFormModal` popup and writes the resulting JSON back into
the textarea. The component **hides the mapped textarea** (it still carries the submitted value)
and shows a display-only twin whose JSON is re-masked client-side — so a secret typed in the popup
never appears readable on the page either, even before saving. The client mirror of the secret-key
rules lives in the component (`SECRET_KEYS`/`SECRET_SUFFIXES`) — **keep it in sync with the PHP
service**. Per-type shapes (also in the entity docblock):

- `file_system` → `{base_path}` (required)
- `sftp` → `{server, port (default 22), user, auth: none|password|key, password?, key?}`
- `rest_api` → `{server, port?, headers{}, auth: none|headers|oauth, auth_headers{}?,
  oauth: {path, headers{}, body{}}?}` (headers are `{name: value}` objects, edited as key/value
  rows; the OAuth token path is a required textbox and the body/headers pair renders as two tabs —
  "OAuth body" first — via the widget's `tabGroup`)

**Secrets round-trip** (`Manager/ConnectorConfigSecrets`): values are stored in clear in
`config` but never rendered. Every read path masks them to the `********` sentinel — the form's
model transformer (edit page), `viewAction`'s `maskedConfig` (view page). On submit the reverse
transformer calls `merge()`: a secret still holding the sentinel is restored from the stored config
at the same path (captured at `PRE_SET_DATA`); a sentinel with no stored counterpart becomes `''`.
A key is "secret" when it equals one of the known names (password, key, secret, token,
authorization, …) case-insensitively, or when its normalized form (`-`/` ` → `_`) ends in
`_key/_token/_secret/_password` — so `X-Api-Key`-style headers are masked too. In the popups,
stored secrets never enter the form: sftp password/key inputs start empty with a "value stored —
leave empty to keep" hint (and the submit re-emits the sentinel); REST header rows just show the
sentinel in the value cell, and leaving it untouched keeps the stored value.

**Type switches are guarded**: changing the Type select while a config exists opens a confirm
dialog (the config is type-specific); confirming clears the textarea, cancelling reverts the
select (programmatic revert is muted via a suppress flag so it doesn't re-trigger the handler).

**Popup layout specifics** (all three popups are `resizable: false`): sftp lays out
server/port as a 75/25 row and auth/user/password as a 24/38/38 row, with the key textarea
full-width below; the password/key controls are ALWAYS rendered — the auth select only toggles
their `disabled` state (per design feedback: control enablement, not visibility). Ports are
`min: 1, max: 65535` (validated by the widget). REST uses the same 75/25 server/port row. The
popups use the shared `RecordFormModal` `password` field type (show/hide toggle inside the input),
`hint` lines, `disabled` fields and number `min`/`max` — documented in `../CommonBundle/CLAUDE.md`.

**"Test" in the popups**: every Configure popup has a widget `testAction` (button on the LEFT of
Cancel/Submit) that POSTs the CURRENT popup values as `{type, config, id?}` to
`aaxis_ontology_connector_test` (`/connectors/test-config`, CSRF-protected, view ACL + a manual
create-OR-update grant check — deliberately NOT the shared `aaxis_common` connection-test
endpoint, which only tests SAVED config). The controller resolves `********` sentinels from the
persisted connector (same-type only) via `ConnectorConfigSecrets::merge()`, then delegates to
`Manager/ConnectorTester`: file_system = base path exists/is dir/readable; sftp = ① TCP socket
(reports the SSH banner) ② authenticate with the informed user/password-or-key; rest_api =
① TCP socket (port defaults from scheme; bare hosts assume https/443) ② `auth: oauth` only —
POST to the OAuth path with the informed headers + form-encoded body, success = HTTP < 400.
SFTP auth prefers **phpseclib3** and falls back to **ext-ssh2** (password only); with neither
installed the auth step fails with an instructive message — the app must
`composer require phpseclib/phpseclib` to test SFTP credentials. Responses are
`{success, message, steps:[{label, success, message}]}` and messages never contain credentials.

⚠️ **Any route called from TypeScript needs `options: ['expose' => true]`** on its `#[Route]`, AND
the exposed-routes dump must be regenerated — `routing.generate()` reads only that dump
(`public/media/js/admin_routes.json`), not the live router. Miss either and the call fails at
runtime with `The route "<name>" does not exist.` while everything else looks healthy (PHP lints,
`tsc` compiles, `debug:router` lists the route). Every JSON endpoint in this bundle carries the
flag. After adding a route:

```bash
docker compose exec php php bin/console fos:js-routing:dump --env=dev   # → public/media/js/admin_routes.json
docker compose exec php sh -c 'grep -c <route_name> public/media/js/admin_routes.json'   # 1 = exposed
```

The **flow editor** (`aaxis_ontology_flow_editor`, `Flow/editor.html.twig` +
`flow-editor-component.ts`, styles in `ontology.scss` under `.aaxis-flow-editor`) is opened by
"Add Flow" and by the flows grid's edit action (disabled for the built-in `type = native` flows,
enforced again by the editor page + update endpoint). It shows the flow name (new flows default to
`new_flow_<6 random alphanumerics>`), an enabled switch and cancel/save, over a dot-matrix canvas
whose spacing comes from `aaxis_ontology.flow_editor_grid_spacing` (System Configuration →
Aaxis Ontology → Flows, default 10px, exposed to CSS as the `--aaxis-flow-grid` custom property).
Topbar: flow name + enabled switch on the left (children forced onto the vertical middle);
Toolbox show/hide toggle + cancel/save on the right (the toolbox title bar also carries a × that
hides it). The draggable toolbox
(Triggers: cron/queue/entity change · Actions: DWL transform/Choice (an "if")/sub-flow ·
Operations: reader/writer/invoke) is the step palette: items are dragged onto the canvas as
square tiles of `flow_editor_step_size_factor` × grid-spacing px (config, default 8 → 80px tiles
on a 10px grid), can be moved freely afterwards and always snap to the grid. Each tile shows its
icon with the step **name** centered below (up to two rows, breaking only at word boundaries):
names default to `<type>-<n>` (first free n) and are unique per flow (client + server enforced).
**Flow links**: every tile has an "×" output port on its right edge (vertically centered; `choice`
has two, at 1/3 and 2/3 height) — drag from a port onto another tile to wire them. Links are SVG
bezier arrows (marker `#aaxis-flow-arrow`) arriving at the target's left-center, 2px off the
border; each port drives exactly one link (re-drag re-wires), each element accepts at most ONE
incoming link, and triggers accept none (invalid drops flash why). Links live in the design as
`{from, fromPort, to}` referencing stable per-step ids, so renames don't touch them.
Double-clicking a tile opens the **step settings** —
a "flying" panel positioned next to the tile over a click-absorbing backdrop (a true modal: the
user must Confirm/Cancel), titled `<type label> - <name>`. It edits the name plus the step's
per-type `config` (an optional object persisted in both `design.steps[]` and the logical
`steps[]`): **cron** requires a valid linux cron expression (`config.expression`) — Confirm is
blocked otherwise (client validator `isValidCron`: @-macros + 5 fields with lists/ranges/steps/
names; server re-validates with `Cron\CronExpression`, 422 `flow_manager.invalid_cron`);
**entity_change** requires `{system, entity}` (selects fed by `aaxis_ontology_entity_list` —
entities filtered by the chosen system, both referenced by NAME per the bundle's addressing);
**reader** requires `{reader: entity|connector, destination}` (destination defaults to
`payload`) plus, per variant, `{system, entity, mode: all|by_id, record_id (when by_id)}` or
`{connector: <id>, path, and for rest_api connectors also operation: get|put|post|patch|delete,
body: empty|json|text|xml, body_content (when body ≠ empty)}`. Reader popup layout: a full-width
FIXED first row `Name | Reader type | Destination` (the reader takes over the name placement via
`$top`), then variant rows in the left column — entity: `System | Entity` row then `Load (| Id)`
row; connector: picker (fed by the exposed `aaxis_ontology_connector_list`, which also provides
each connector's type), then a row that adapts to the chosen connector: rest_api →
`Operation | Path | Body`, sftp/file_system → path only. A non-empty body opens the body-content
textarea in the right column BELOW the fixed row (panel widens via `is-wide`). Every visibility
toggle calls `reposition()` and the panel is viewport-capped (`max-height` + scrolling middle) so
Cancel/Confirm always stay reachable. The modal blocks Confirm on missing
values; the server re-checks any PRESENT config's completeness in `isStepConfigValid()`
(422 `flow_manager.invalid_step_config`) — a null config (never opened) is still saveable.
Other types configure only the name so far. **Selection**: click selects a tile, dragging on empty canvas
rubber-bands a multi-selection (macOS style, blue ring = selected), any outside click clears it;
dragging a tile that belongs to a multi-selection moves the WHOLE selection (relative offsets
preserved — the leader is clamped so the entire group stays on the canvas).
**Right-click** on a tile opens a context menu: Remove (deletes the selection + its links); with a
multi-selection also Align (puts everything on the first-by-X element's Y with exactly one tile
width of gap, keeping the X-then-Y order) and — only when every element after the first receives
no line, none after the first is a trigger and none except the last is a Choice — Connect (chains
the selection in sequence, port 0 re-wired if used). Right-clicking a flow LINE offers Remove for
that single connection (each wire ships an invisible 12px-wide hit twin — the only pointer-enabled
part of the SVG layer — and highlights on hover). **Wire routing**: links are orthogonal polylines
found by A* over the dot grid (cell = spacing, tiles inflated by one clearance cell are obstacles,
turn penalty keeps runs straight, ~20k-expansion cap with a 3-segment fallback), leaving the port
through a horizontal stub and arriving horizontally into the arrow tip — so a line never runs
over/under a tile and deviates before reaching one. Where a horizontal run of one line properly
crosses a vertical run of ANOTHER, the horizontal one draws a small semicircular "jump" arc
(radius ≈ spacing/2, skipped within 2r of corners). Tile drags re-route via a rAF-coalesced
redraw (`scheduleRedraw`). Only one **trigger** step is allowed — dropping a second one
asks to replace the existing trigger. **Reachability marking**: tiles not reachable from the
trigger via the directed links gray out (`is-unreachable`, BFS in `updateReachability()` — no
trigger = everything gray), refreshed on every step/link mutation via addStep + redrawLinks.
**Sub-flow "Start here"**: with no trigger on the canvas, right-clicking an element with no
incoming line offers Start here — it becomes the reachability root, drawn as a short origin-less
arrow into its input (right-click the arrow, or the tile's Remove start, to clear). The marker
counts as the element's one incoming line, persists as `design.start` (validated on restore:
non-trigger target, no incoming link, no trigger present), and dropping a trigger asks before
removing it ("This sub-flow starts at …"). Step metadata (category/icon/label per type) is harvested
from the toolbox items' `data-step-*` attributes, so the twig is the single source of truth for
the palette. Save persists name + enabled + steps (`[{type, name, x, y}]`) + `design` (versioned
canvas state: `{version, steps:[{id, type, name, x, y}], links:[{from, fromPort, to}],
toolbox:{x, y, visible}}` — bump `DESIGN_VERSION` in the component when the shape changes) via
`aaxis_ontology_flow_api_create` / `_update`; the server re-validates step types/names and
recomputes the flow type. **Dirty tracking**: the editor snapshots {name, enabled, design} — Save
is enabled only while the snapshot differs from the last-saved one, and the exit button swaps
between Cancel (dirty) and Close (clean). Saving stays ON the page (a first save adopts the new id
into the URL via `history.replaceState` so refresh reopens the same flow) and re-arms the clean
state; every mutation path (add/move/rename/remove steps, link changes, toolbox move/visibility,
name/switch inputs, drag ends) calls `syncDirty()`. On open the editor restores from `design` (strictly
validated; corrupted/outdated → warning flash + empty canvas; NULL → rebuilt from `steps`).
**Debug**: a Debug topbar button (shown only while a REAL trigger exists, synced in
`updateReachability`) POSTs the CURRENT canvas (steps with configs + links + trigger input,
unsaved edits included) to the exposed `aaxis_ontology_flow_debug` endpoint
(`flow_update` ACL + CSRF), which walks the graph breadth-first from the trigger via
`Manager/FlowDebugExecutor` and returns the output context shown as pretty JSON in a dialog.
Cron/queue triggers run immediately; entity_change first asks for system/entity (prefilled from
the trigger's config, reusing `systemEntitySection`) + a JSON payload seeded into the context as
`payload`. Executed for real: entity readers (`all` = first page via OntologyDataApiManager::query
— capped at 100, `by_id` = read(); a MISSING record yields null, not an error) and **rest_api
connector readers** (URL = connector server[:port] + step path; connector headers; auth=headers
merges auth_headers; auth=oauth POSTs the token path — form-encoded oauth.body + oauth.headers —
and attaches `access_token` as a bearer; the step's operation/body/body_content are honoured;
TLS verification off like the toolbox proxy; JSON responses decoded, others returned raw;
HTTP ≥ 400 aborts naming the step). sftp/file_system connector readers emit a `_debug`
placeholder note; **dwl_transform** steps execute their DWL script via `Dwl/DwlTransformer` with
the WHOLE current context bound as variables (payload, prior destinations…), result stored under
their destination; **writer/entity** steps write the context value named by `config.content`
(a single object or an array of objects) into the configured system/entity via the SAME path as
the Data View "Add Data" — `upsertRecords()` (uid inferred from the entity's unique_attribute,
ONE queued message, write is async) **stamped with the flow being debugged** (the editor sends
`flowId` in the debug POST; a never-saved flow falls back to `requireEnabledFlow(Manual)`) —
storing the receipt `{uuid, count, queued: true}` under the destination; writer/connector emits
a `_debug` placeholder; other step types are no-ops for now. `upsertRecords()` rejects a batch
that REPEATS a unique id (names both record numbers) — the `aaxis_ontology_data_upsert` PG
function would otherwise reject the whole message asynchronously where the only trace is an
`app.ERROR` log line and an event row with `finished_at` set but empty `changed_ids` (the
processor closes events ONLY on validation errors; the success path leaves them open for the
next pipeline stage, still a TODO). The writer's properties dialog reuses the
reader's (`ioSection(kind)`) with the entity variant showing a Content textbox instead of
Load/Id; config discriminator is `writer: entity|connector`.
**DWL engine** (`Dwl/`): the Language+Runtime subset of the user's php-dw DataWeave port
(BSD-3-Clause, license copy in `Dwl/LICENSE`; origin `~/Github/dw-cli/php-dw`), namespaced
`Aaxis\Bundle\OntologyBundle\Dwl\`. Three import gotchas handled in `DwlTransformer`: the AST is
ONE file with 40 classes (upstream classmap) → `loadAst()` require_onces it since PSR-4 can't;
the php↔Value bridge is local (`toValue()`/`->toPhp()`); and `Value::toPhp()` renders DWL objects
as **stdClass** (upstream keeps `{}` vs `[]` apart) — invisible in the JSON debug dialog but fatal
for `is_array()` consumers like the writer ("Record #1 must be a JSON object") → `transform()`
flattens its result to plain assoc arrays via `toPlainPhp()`, matching what readers produce
(`json_decode` assoc). Keep the engine files pristine; fix shapes at this facade. The `%dw` header/`---` separator are
optional (bare expressions work). Scripts are parse-validated on SAVE
(422 `flow_manager.invalid_dwl` with the parser message). The Format/Cli parts of php-dw were
deliberately NOT imported (unused). Errors (unconfigured step, unknown
system/entity, missing connector, failed request) abort with a 422 naming the step.
Gotcha fixed here once: a textarea's initial value must be set via jQuery `.val()` — a `value`
key in the `$('<textarea/>', {...})` creation map is silently ignored.

A typical CRUD field (e.g. on the Entity page) therefore lives in **several places at once** — to
add/change one, touch all of them:
1. `Entity/<X>.php` — Doctrine column + getter/setter.
2. `Migrations/Schema/AaxisOntologyBundleInstaller.php` — the column (source of truth for fresh installs).
3. A new versioned migration (see below) — to alter already-deployed DBs.
4. `Controller/<X>Controller.php` — read/validate in `saveFromJson()` **and** expose in `serialize()`.
5. `Resources/js-src/app/components/<x>-component.ts` — the record type, the form field, the
   edit-load `values`, and the save payload.
6. `Resources/translations/messages.en.yml` — label + any validation/placeholder strings.

## JS / TypeScript — NEVER hand-edit the compiled `.js`

⚠️ **No class-field initializers in `BaseComponent` subclasses.** Oro's `BaseComponent` constructor
calls `initialize()` — so TS field initializers (`private foo = {}`) run **after** `initialize()`
(fields are `undefined` during it, and the initializer then overwrites anything `initialize()`
assigned). Declare fields as `private foo!: T;` and assign them at the top of `initialize()`
(see `flow-editor-component.ts`). Plain classes (e.g. the DataGrid widget) are unaffected.

`.ts` sources live in `Resources/js-src/`; the `.js` in `Resources/public/js/` is **generated** by
`tsc` (CommonBundle's `TypeScriptCompiler`, wired in `services.yml` and run on `oro:assets:build` via
`CompileTypeScriptOnAssetsBuildListener`). Edit only the `.ts`. **Only the `.ts` is committed** — the
emitted `.js` is git-ignored and rebuilt at asset-build time (the build fails loudly if `tsc` is
missing, and recompiles even under `vendor/aaxisdigital/oro*`).

**REQUIRED after ANY TypeScript change** — the browser loads from the webpack `public/build/`
output, NOT from `Resources/public/js`, so regenerating the `.js` alone is not enough:

```bash
docker compose exec php php bin/console oro:assets:build --env=dev   # compiles TS + webpack-bundles into public/build
```

(Don't pass `admin` as a theme — it's a reserved build name; the back-office is the `admin.oro`
theme and is built when you run with no theme arg.)

**REQUIRED after ANY change to `Resources/translations/*.yml`** — new/updated keys won't resolve
in the UI until loaded into the DB-backed translation store. Two gotchas:

- **Domain split.** JS labels (`__()`) read the **`jsmessages`** domain → put them in
  `jsmessages.en.yml`. Server-side strings (PHP `$this->trans()`, Oro entity-config labels,
  datagrids) read **`messages`** → `messages.en.yml`. A label used in BOTH the TS grid/form and
  server-side must live in BOTH files. Symptom of the mistake: the UI shows the raw key + a `*`.
- **Stale catalogue cache.** `oro:translation:load` reads Symfony's cached catalogue, so a
  just-edited YAML can be silently skipped (it reports success but the key never lands in the DB).
  Drop the dev catalogue first (this is NOT the full `cache:clear`):

```bash
docker compose exec php sh -c 'rm -f var/cache/dev/translations/catalogue.en.*'
docker compose exec php php bin/console oro:translation:load --env=dev
```

Run these for the env you're testing in — the user tests in **dev** (`--env=dev`). Building/loading
under `--env=prod` warms prod's build + caches, not dev's, so dev won't reflect the change. Do **not**
run `cache:clear` automatically — the user clears the dev cache themselves when needed.

## Schema / migrations

Pre-production but **DDL has already been run on live DBs**, so this bundle uses versioned
migrations (unlike CommonBundle's edit-the-installer-only rule). For each schema change:
1. Update `Migrations/Schema/AaxisOntologyBundleInstaller.php` (keeps fresh installs correct).
2. Bump its `getMigrationVersion()` to the new `vN_M`.
3. Add `Migrations/Schema/vN_M/<Description>.php` implementing `Migration` (guard with
   `if (!$table->hasColumn(...))`). Oro skips this migration on fresh installs (version ≤ installer
   version) and runs it on existing DBs (version > their recorded version).

⚠️ Still pre-release: **breaking schema changes are OK but confirm with the user first.**

## Data upsert flow

`Migrations/Schema/OntologyDataFunctions.php` ships PostgreSQL functions (validation, diff/merge,
upsert) added as post-queries by the installer. The async path is
`Async/Topic/OntologyDataUpsertTopic` + `Async/OntologyDataUpsertProcessor` (message-queue).

## Verify after changes

This project runs in **Docker** — all PHP/console commands go through the `php` service
(local PHP is 8.4; the container is 8.5, which the app requires):

```bash
docker compose exec php php bin/console oro:migration:load --dry-run --show-queries   # preview DDL
docker compose exec php php bin/console oro:migration:load --force                    # apply
docker compose exec php php bin/console oro:assets:build --env=dev                   # after any .ts change
docker compose exec php php bin/console oro:translation:load --env=dev               # after any translations change
docker compose exec php php bin/console fos:js-routing:dump --env=dev                # after adding an expose'd route
docker compose exec php php bin/console debug:router | grep aaxis_ontology
```

`cache:clear` is intentionally omitted — the user runs it themselves when needed (dev mode).

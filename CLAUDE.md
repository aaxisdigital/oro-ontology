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
| `OntologyConnector` | `aaxis_ontology_connector` | belongs to a system; `type` ∈ sftp, rest_api, file_system, **database, bucket** (the last two are selectable but their config shape + Configure form are not defined yet — the button opens a "not available yet" note and `ConnectorTester` reports no test); JSON config authored via the per-type "Configure" popup; secret config values are masked on every read path (see "Connector config & secrets" below) |
| `OntologyFlow` | `aaxis_ontology_flow` | name, enabled, `type` (`native` = the two fixture-seeded built-ins, read-only in the UI — gates the grid edit action, the editor page and the update endpoint; user flows are `flow` when their steps contain a trigger, else `subflow` — recomputed from the steps on every save via `computeType()`, never taken from the payload), JSON `steps` (`[{type, name, x, y}]`, types validated against `STEP_TYPES`, names non-empty ≤64 chars and unique per flow — 422 `flow_manager.step_names_unique`), JSON `design` (the editor's versioned canvas state — stored opaquely by the server, strictly validated by the editor on load; unreadable/outdated → "corrupted" flash + empty canvas; NULL → canvas rebuilt from `steps`), `last_executed` (datetime NULL — stamped by `FlowDebugExecutor::touchLastExecuted()` at the START of every run with a saved flow: Run Now, each Debug step call, and the future real triggers; failed runs count, unsaved flows don't), `last_modified` (datetime NOT NULL — creation date via the entity CONSTRUCTOR, bumped by every editor save; v1_7 backfilled existing rows with the migration time), `trigger_type` (string(16) NULL — the trigger step's type cron|endpoint|entity_change, denormalized from the steps on every save via `computeTriggerType()` so the SCHEDULER selects candidates with a plain WHERE; v1_8 backfilled from the steps jsonb — that migration still names the OLD `queue` trigger, deliberately: it is applied history, fresh installs skip it (installer ≥ v1_9), and no row ever used it), `last_finished` (datetime NULL — stamped by `FlowDebugExecutor::touchLastFinished()` when a run ENDS, from a `finally` so failures stamp too; v1_9 backfilled it from `last_executed` so pre-existing rows don't look permanently running; installer at v1_9). **`last_executed` + `last_finished` are the running-state pair** — see "Flow concurrency" below |
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

## Flow concurrency (one instance at a time)

A flow's running state is **derived from two timestamps, not stored as a flag**:
`last_executed` is stamped when a run starts, `last_finished` when it ends — so
`OntologyFlow::isRunning()` is simply "started and `last_finished` hasn't caught up".

- **Both stamps go through `FlowDebugExecutor`** (`touchLastExecuted` / `touchLastFinished`), and the
  finish one lives in a **`finally`**: a run that throws must still release the state, or that flow
  would never be schedulable again. `executeFrom()` (the step debugger) stamps a finish on **every**
  call, not just the last step — nothing is executing server-side between debug steps, so a paused
  debug session must not look like a run in progress.
- **The guard is in `ScheduledFlowRunner`**: a due flow whose previous instance is still in flight is
  skipped with status `running`. That is the automatic path where overlap actually arises (the cron
  command fires every minute; a run that outlives its interval would otherwise be doubled up).
- ⚠️ **A dead run must not lock the flow forever.** A process killed mid-run (fatal, container
  restart, deploy) never stamps `last_finished`. `RUN_STALE_AFTER_SECONDS` (6h) is the cut-off after
  which the scheduler assumes the run died and proceeds. Any new caller that BLOCKS on `isRunning()`
  must apply the same cut-off — the entity method is deliberately raw state with no time policy.
- **Manual runs are deliberately NOT blocked** — Run Now / Debug from the editor are explicit user
  actions, and blocking them would make a stuck state undebuggable. If overlap must be prevented
  there too, that is a product decision, not an oversight.
- The Flows grid shows `Last finished`, rendering **"Running…"** while in flight (a blank cell would
  read as "never ran"). The `running` flag is computed in the controller's serializer, not stored.

## UI architecture (the part that trips people up)

Most pages (Systems, Entities, Flows, Events, Data View) are **NOT** standard Oro datagrids/forms.
They are TypeScript single-page components built on CommonBundle's reusable `DataGrid` +
`RecordFormModal` widgets, backed by **JSON endpoints** on the bundle's controllers. The Connector
page is the exception — it uses a normal Oro datagrid + server-side CRUD.

### Data View: deep link + version history dialog

- **Cross-page deep links** follow one pattern: source grid action → `routing.generate(<page route>)`
  + a `?<key>=<name>` query → target page calls `grid.setFilter()` once after its first load.
  Systems → Entities uses `?system=` (filters `systemName`); Entities → Data View uses `?entity=`.
  ⚠️ Both target routes are **page** routes that now carry `options: ['expose' => true]` purely
  because TypeScript generates their URLs — page routes normally don't need it, which is exactly how
  this was missed the first time (runtime `The route "…" does not exist.`, nothing caught at build).
  Re-run `fos:js-routing:dump` after adding one.
- **Entities → Data View**: the Entities grid's `data` action (fa-table, right after the `dwl` badge)
  navigates to `aaxis_ontology_data_view?entity=<name>`, and the page turns that into the grid's
  `entity` column filter via the widget's `setFilter()`. It passes the entity **`name`**, not
  `displayName` or the id, because that is exactly what the Data View's `entity` column holds
  (`OntologyDataController` serializes `getEntity()?->getName()`); the filter is a plain `equals`, so
  the user can see and clear it. Applied **once** after the first load (`entityFilterApplied`) — it
  seeds the view rather than pinning it, so Refresh won't re-impose a filter the user cleared.
- **Version dialog** (`renderVersions`): the picker is an **editable text box holding only the
  current selection**, not a `<select>` of every version — the option list would grow without bound
  with a record's history. Jumping is done by typing a version number (a leading `v` is tolerated) or
  a uuid (full, or a prefix that is unique) and pressing Enter; `findVersion()` resolves it, an
  unmatched query shows an inline error and blur restores the current label so a half-typed query is
  never left on screen. A `/ N` total sits next to the box. Below it a **range slider** — rendered
  only when there is more than one version — has one stop per version, **oldest on the left** (the
  `versions` array is newest-first, hence the `toIndex`/`toSlider` mirroring). It is a POSITION
  picker, not a progress bar: the track is one flat colour end to end (hence overriding
  `-moz-range-progress` and NOT using `accent-color`, both of which fill up to the thumb), with a
  tick per version drawn by a repeating gradient whose `--tick-gap` JS sets inline (`100% / steps`,
  since the count is per record). Box and slider are two views of one `selectedIndex` — everything
  goes through `select()`, so they can't drift.
  Search + prev/next live in the **bottom row next to Copy** (`.aaxis-json-footer--tools`), since
  both act on the JSON above them, preceded by a **Full view ⇄ Diff only** switch.
- **Diff only** reuses the normal diff renderer on a PRUNED pair of snapshots (`pruneToDiff()`)
  rather than filtering the rendered lines — a changed object/array is wrapped in ONE highlight span
  covering many lines, so line filtering would emit unbalanced HTML. Objects prune key by key
  recursively; **arrays and scalars are kept whole** when they differ (pruning array elements would
  renumber the indexes and misrepresent the data). Copy follows what is on screen. The oldest
  version and a no-change version show a muted note instead of an empty pane.
- **Erase records** (Entities grid, eraser between edit and delete): `DELETE
  /entities/api/{id}/records` (`aaxis_ontology_entity_purge_records`) drops the entity's
  `OntologyData` **and** `OntologyDataHistory` rows via DQL deletes (no hydration — an entity can
  hold a lot), keeping the entity and its attributes. Gated on **`OntologyData` DELETE** on top of
  entity VIEW: the entity survives, so this is a data delete, not an entity delete. Flow execution
  events are deliberately NOT touched — they log what the flows did, not the entity's records. The
  action is disabled when `recordCount` is 0 and always confirms first.

### DWL playground (Entities grid → `dwl` badge action)

A DataWeave scratchpad over one entity's stored records, modelled on the official DataWeave
playground. Pieces:

- **Action**: the Entities grid's first action, rendered as a text pill via the widget's new
  `GridAction.badge` (see `../CommonBundle/CLAUDE.md`) and placed **before** edit/delete. It needs no
  extra ACL — the playground only evaluates, never writes, so entity VIEW (which the page already
  requires) is enough.
- **UI**: `Resources/js-src/app/widgets/dwl-playground.ts` — a plain class (NOT a page component, so
  it is **not** in `jsmodules.yml`; `entity-component.ts` imports it relatively and webpack bundles
  it). Two panes, script + read-only Result, in a `Dialog` opened with `movable: true` and a
  `bodyClass` the bundle's `.aaxis-dwl` SCSS turns into a flex column, so the dialog's resize handle
  grows the textareas instead of scrolling the body. Close is ✕ / Escape / backdrop (all from
  `Dialog`).
- **Row limit + total**: the limit box is followed by a `/ N total records` label, filled on open by
  `GET /entities/api/{id}/dwl/count` (`aaxis_ontology_entity_dwl_count`, exposed, same permission pair
  as the run) and refreshed from every run's `total`, so the cap can be judged against the real volume
  before running. ⚠️ That count is **not** the grid's `recordCount` column: `recordCount()` reports the
  **OroCommerce table** size for internal-system entities, while the playground always reads
  `OntologyData` — reusing the column would show a total the run then contradicts. Both endpoints go
  through `dwlPayloadTotal()` so they can never drift. A failed count blanks the label and never
  blocks running.
- **Deliberately not live**: nothing runs until **Run** is pressed. The pane remembers the
  script+limit signature of the last run and greys the Result out (`.is-stale`, light grey) as soon
  as either drifts — so a shown result is never mistaken for the current script's output. The limit
  is part of the signature, not just the script: changing it invalidates the result for the same
  reason.
- **Endpoint**: `POST /entities/api/{id}/dwl` (`aaxis_ontology_entity_dwl`, exposed, CSRF, entity
  VIEW **plus an explicit `OntologyData` VIEW check** — the response carries record *content*, so
  metadata-only access must not be enough). Body `{script, limit}`; `limit` null/0 = the user opted
  out of the cap (default 100).
  Payloads are read with a **DQL projection of the payload column only** — no entity hydration, since
  an uncapped run can span the whole table. The script gets exactly **one** binding, `payload` (the
  list of record payloads); no extra variables, matching the request.
- **Script errors are results, not HTTP errors**: a parse/runtime failure returns `200` with
  `{success: false, error}` and the playground shows it in the Result pane (`.is-error`).
- **Output format**: `Manager/DwlOutputFormatter` reads the `output <mime>` directive from the
  script **header only** (text before `---`, so a `---` or the word "output" inside the body can't
  fool it) and renders json / xml / csv / text; anything unrecognised falls back to JSON, matching
  the engine's tolerance of header-less scripts. The server returns the resolved
  `format`/`mime`/`extension`, and **Export** saves the last run's text verbatim as a client-side
  Blob with that extension — so screen and file always agree. (`DwlTransformer` parses the header
  but returns plain PHP, which is why the directive is re-read here.)
- **Engine boundary**: the playground only calls `DwlTransformer::transform()/validate()`. Everything
  under `Dwl/` is the imported DataWeave engine — treat it as a dependency.

⚠️ **`dw::Runtime::props()` is an environment-disclosure sink.** The engine implements it as
`foreach (getenv() as ...)` (`Dwl/Runtime/StandardLibrary.php`), so a script typed in the playground
could return the whole process environment — and Export would save it. Deployments that pass secrets
as real env vars (compose `environment:`, k8s env, ECS task definitions) would leak a DB DSN /
`APP_SECRET` to anyone who can open the playground; braskem7 today loads secrets from `.env-app`
without `putenv()`, so only PHP build vars are visible there — **do not rely on that.**
`Manager/DwlScriptGuard` therefore screens every playground script and refuses `props`:
- It matches on the **parsed AST**, not the text — the parser normalizes `dw :: Runtime`,
  `dw::/*c*/Runtime` and a newline-split path all to `dw::Runtime`, so a regex would be bypassable.
  It rejects a `ModuleRef` of `dw::Runtime::props` and an `ImportDirective` from `dw::Runtime` that
  is `import *` or names `props`. `dw::Runtime::fail` stays usable; `props` as an object key or
  inside a string is untouched.
- An unparseable script passes the guard on purpose, so the user sees the engine's real parse error
  rather than a guard message about it.
- The refusal is returned as the endpoint's normal `{success: false, error}` 200, so it renders in the
  Result pane like any other script error.
- The rest of the standard library was audited and is pure data manipulation — `getenv()` is its only
  such sink, which is why denying one member is sufficient. **Re-audit if `Dwl/` gains modules.**
- The flow-editor's DWL path is NOT screened: it sits behind an EDIT-level capability, a different
  trust level. Optional future hardening: give the playground its own action ACL (`acls.yml` +
  an admin-grant data migration + gating the badge in twig) so script execution is a capability
  distinct from reading data — a permissions-model decision, deliberately not taken unilaterally.

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
CANVAS ARCHITECTURE: `__canvas-wrap` (non-scrolling anchor) > `__canvas` = the SCROLL VIEWPORT
(`data-role="canvas-viewport"`) > `__canvas-inner` = the content plane carrying the dots, steps
and wires — and, crucially, **`data-role="canvas"`**, so `canvas()` (all coordinate math measures
against ITS rect) stays scroll-correct without per-site fixes; `canvasViewport()` returns the
scroller. `syncCanvasExtent()` (called from every `redrawLinks`) sizes the plane to the step
extent + one tile, so content beyond the visible area produces SCROLLBARS (place() clamps only
top-left now — tiles may grow the plane). The toolbox lives in `__canvas-wrap`, OUTSIDE the
scroller, so it stays viewport-pinned; its drag/clamp math uses the viewport. The editor fills
the page via `fitEditorHeight()` (measured top → viewport bottom, refreshed on window resize —
the CSS `min-height` calc is only a fallback; the resize handler also re-clamps the toolbox).
Topbar: flow name + enabled switch on the left as STACKED label-above-control fields (`__field` /
`__field-label`, controls bottom-aligned — keeps the left side narrow); every right-side button is
icon + a `__btn-text` span that a `max-width: 1199px` viewport hides (icon-only mode, the `title`
tooltips carry the labels — which is why syncDirty updates the cancel button's LABEL SPAN
(`data-role="cancel-label"`) + title, never `.text()` on the button, which would wipe the icon).
Toolbox show/hide toggle + **Organize** (hidden without steps:
lays every step out in execution order — BFS from the trigger/"Start here", unreachable steps
appended in reading order — with one-tile gaps, wrapping rows before the viewport width, then
scrolls to top-left) + cancel/save on the right (the toolbox title bar also carries a × that
hides it). The draggable toolbox
(Triggers: cron/endpoint/entity change · Actions: DWL transform/Choice (an "if")/sub-flow ·
Operations: reader/writer/invoke) is the step palette: items are dragged onto the canvas as
square tiles of `flow_editor_step_size_factor` × grid-spacing px (config, default 8 → 80px tiles
on a 10px grid), can be moved freely afterwards and always snap to the grid. A step is added ONLY
by a real drag out of the toolbox: `startGhostDrag()` merely arms the gesture and the preview tile
is materialised on the first move past `DRAG_THRESHOLD` (5px), so a plain click adds nothing, and
`dropGhost()` additionally rejects a release still inside the toolbox — it floats **above** the
canvas, so its bounds would otherwise pass the in-canvas test. Consequence to keep in mind:
`ghostDrag.el` is nullable while a drag is armed. Each tile shows its
icon with the step **name** centered below (up to two rows, breaking only at word boundaries):
names default to `<type>-<n>` (first free n) and are unique per flow (client + server enforced).
⚠️ **Settings-panel catalogs are cached in memory** (`entityCatalog()` / `connectorCatalog()`, warmed
by `prefetchCatalogs()` at the end of `initialize()`). The reader/writer panel used to re-request
`aaxis_ontology_entity_list` + `aaxis_ontology_connector_list` on EVERY open, and in dev a round trip
is ~1.5s of kernel boot (measured; the queries themselves are trivial) — so opening step properties
took seconds, every time. Consequences to keep in mind: a **failed** fetch is deliberately NOT cached
(the field resets so the next open retries), and the catalogs are a **page-load snapshot** — a
connector/entity created in another tab won't appear until the editor page is reloaded. Add new
panel data sources through the same accessors rather than a bare `fetch`.

**Flow links**: every tile has an "×" output port on its right edge (vertically centered; `choice`
has two, at 1/3 and 2/3 height) — drag from a port onto another tile to wire them. An existing wire
can be **re-routed by dragging its arrow head** (an invisible `wire-end` grip circle at the last
route point): the link is pulled out of `links` for the drag — so the canvas previews the result and
the "already has an incoming line" rule doesn't count the link being edited — then on release,
dropping it back on **the element the line STARTS from deletes it**, dropping on a valid free
element retargets it, and dropping on its current target / the background / an element that already
has an incoming line puts it back unchanged. Links are SVG
bezier arrows (marker `#aaxis-flow-arrow`) arriving at the target's left-center, 2px off the
border; each port drives exactly one link (re-drag re-wires), each element accepts at most ONE
incoming link, and triggers accept none (invalid drops flash why). Links live in the design as
`{from, fromPort, to}` referencing stable per-step ids, so renames don't touch them.
Double-clicking a tile opens the **step settings** —
a "flying" panel positioned next to the tile over a click-absorbing backdrop (a true modal: the
user must Confirm/Cancel; Escape = Cancel via `keydown.aaxisFlowSettings`, active even under the
loading overlay), titled `<type label> - <name>`. It edits the name plus the step's
per-type `config` (an optional object persisted in both `design.steps[]` and the logical
`steps[]`): **cron** (shown as **"Schedule"** — the TYPE stays `cron`; default step names derive
from the sanitized toolbox LABEL, so new ones are `schedule-n`) owns its first row `Name | Mode`
(`scheduleSection`) with two modes — `{mode: interval, value: int ≥ 1, unit: minute|hour|day|
week|month|year}` (a number + unit row) or `{mode: cron, expression}`; the cron expression input
tints light red while invalid (`__cron-input--invalid`, client validator `isValidCron`: @-macros
+ 5 fields with lists/ranges/steps/names) and a hint line under it shows the valid symbols for
the cron FIELD the caret is in (recomputed per input/caret move; @-start shows the macro list).
LEGACY configs (`{expression}` without mode) open/validate as cron mode. Server: `isStepConfigValid`
checks both shapes; a present expression still re-validates with `Cron\CronExpression`
(422 `flow_manager.invalid_cron`);
**entity_change** requires `{system, entity}` (selects fed by `aaxis_ontology_entity_list` —
entities filtered by the chosen system, both referenced by NAME per the bundle's addressing);
**reader** requires `{reader: entity|connector, destination}` (destination defaults to
`payload`) plus, per variant, `{system, entity, mode: all|by_id, record_id (when by_id)}` — in
`all` mode the Load row also offers OPTIONAL `order_by` (the selected entity's attributes, fed by
the entity_list payload via `systemEntitySection().attributes()`; picking one reveals `order_dir`
asc|desc) and `limit` (No limit | 1 | 10 | 100 | 1000). Entity "all" reads go through
`OntologyDataApiManager::queryForFlow()` — NOT page-capped like the outside-facing `query()`
(flows get every record unless the step limits itself; ordering compares the jsonb value:
numbers numerically, strings lexically, id as tiebreaker) — or
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
multi-selection also Align (one row anchored at the leftmost tile's x/y with exactly one tile
width of gap; ORDER per `alignOrderedSelection()` — steps chained by flow lines WITHIN the
selection keep their flow sequence, chains first walked BFS from their heads (heads by X, cycle
fallback enters at the leftmost), then every unconnected step by X-then-Y — so a link-free
selection degrades to the old X ordering) and — only when every element after the first receives
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
**Debug / Run Now**: two topbar buttons — shown only while a REAL trigger exists (synced in
`updateReachability`) and DISABLED while the Enabled switch is off or a session is already open
(`syncDebugButtons()`, re-synced on switch change + session open/close). Both collect the trigger
input via `collectDebugInput()` (cron/endpoint run immediately, entity_change asks for its event
first) and then open the **debugger SIDEBAR** (`data-role="debugger"`, left of the design area in
`__canvas-wrap`) instead of popups — `startDebugSession(mode, input)` SNAPSHOTS the canvas
definition (steps+links) so mid-session edits can't desync the running session, and stale
responses are ignored via session-identity checks. The sidebar holds: ONE title line — the mode
name in bold (`__debugger-mode`) with the status right after it in regular weight
(` — <step> (i/N)` / Running… / Finished / Failed: err, inline, never flashes); the VARIABLES
section (`renderVariablesList()`: one line PER top-level context entry — objects/arrays start
COLLAPSED with the count preview and expand on click, primitives inline; refreshed after every
step); a DWL EVALUATOR just before the buttons (a `createDwlField` fixed instance whose Evaluate
button POSTs {expression, context} to the exposed `aaxis_ontology_flow_debug_eval` endpoint →
`DwlTransformer::transform` against the CURRENT variables, enabled once a context exists — the
result/engine error opens in a MODAL Dialog, class `aaxis-debug-eval-error` for failures, not
inline); and the action buttons (Cancel | Run all | Next step while stepping, just Close when
done/failed). **Debug** (mode 'step', `data-role="debug-step"`) walks one step per POST to
`aaxis_ontology_flow_debug_step` (`FlowDebugExecutor::executeFrom()` — index 0 seeds the context
and mints the run's flowUuid; the context is held SERVER-SIDE between calls (cache.app,
key aaxis_ontology_debug_ctx.<flowUuid>, TTL 1h — storeDebugContext/loadDebugContext): the
client round-trips only `contextKey`, because shipping a large context (a reader loading
thousands of records) in the request body blew past nginx's client_max_body_size and returned
an HTML 413 page; an expired key 422s with 'restart the debug'; the full-run endpoint stores
its final context too, so the sidebar evaluator works after Run Now; `runAll: true` finishes
the rest in one call; the response's `step.id` marks the tile
that just executed with the AMBER `is-debug-active` class, cleared on close). **Run Now** (mode
'run', `data-role="debug"`) executes everything in ONE POST to `aaxis_ontology_flow_debug`
(`FlowDebugExecutor::execute()`) — while it runs only the button spinner shows; the sidebar
APPEARS WHEN THE RUN FINISHES with the final context (or the failure). Aborting cannot undo
writers that already ran. NOTE: new exposed routes need
`fos:js-routing:dump --format=json --target=public/media/js/admin_routes.json`.
Cron/queue triggers run immediately; entity_change first asks for system/entity (prefilled from
the trigger's config, reusing `systemEntitySection`) + a JSON payload seeded into the context as
`payload`. Every TOP-LEVEL execution mints ONE v4 uuid up front, seeded into the context as
**`flowUuid`** (first key of the output JSON): all writers of the run stamp their upserts with
it, so several write events group under a single identity in Events/Data View. Sub-flows never
mint their own — `execute()`'s `$executionUuid` param carries the CALLER's uuid down (sub-flow
invocation itself is still unimplemented; whoever builds it must pass the current uuid). DWL
scripts use `flowUuid` DIRECTLY (renamed from the old hyphenated `flow-uuid`, which was not a
valid DWL identifier; the transformer still binds the whole context as a `context` object for
OTHER non-identifier keys, e.g. hyphenated destinations). `flowUuid` is a RESERVED destination —
the legacy `flow-uuid` spelling too (rejected in `isStepConfigValid` + the TS panels'
`destinationError()` — jsmessage `destination_reserved`). Executed for real: entity readers (`all` = first page via OntologyDataApiManager::query
— capped at 100, `by_id` = read(); a MISSING record yields null, not an error) and **rest_api
connector readers** (URL = connector server[:port] + step path; connector headers; auth=headers
merges auth_headers; auth=oauth POSTs the token path — form-encoded oauth.body + oauth.headers —
and attaches `access_token` as a bearer; the step's operation/body/body_content are honoured;
TLS verification off like the toolbox proxy; JSON responses decoded, others returned raw;
HTTP ≥ 400 aborts naming the step). sftp/file_system connector readers emit a `_debug`
placeholder note; **dwl_transform** steps execute their DWL script via `Dwl/DwlTransformer` with
the WHOLE current context bound as variables (payload, prior destinations…), result stored under
their destination; **writer/entity** steps write the context value named by `config.content`
(a single object or an array of objects) into the configured system/entity **synchronously** via
`OntologyDataApiManager::upsertRecordsSync()` — same validation + `aaxis_ontology_data_upsert` PG
function as the async Data View "Add Data" path (`upsertRecords()`, still queued), but no queue:
uid inferred from the entity's unique_attribute, **stamped with the flow being debugged** (the
editor sends `flowId` in the debug POST; a never-saved flow falls back to
`requireEnabledFlow(Manual)`) **and with the run's `flowUuid`** (the optional uuid arg). The
receipt stored under the destination reports the REAL outcome — `{uuid, count, upsert:
<created+changed>, changedIds: [<the upserted ids>]}` (unchanged records excluded; an EMPTY
content — null / [] / "" — is NOT an error: the write is skipped, no event row, receipt
`{uuid: <run uuid>, count: 0, upsert: 0, changedIds: []}`) — and the
event row is recorded AND completed inline (unique_ids = seen, changed_ids = upserted,
finished_at set); PG validation errors throw, naming the step. Writer/connector emits a `_debug`
placeholder; other step types are no-ops for now. `prepareUpsertBatch()` (shared by both paths)
rejects a batch that REPEATS a unique id, naming both record numbers. GOTCHA (async path only,
i.e. Add Data / REST API): the consumer closes events ONLY on validation errors — the only trace
is an `app.ERROR` log line and an event with `finished_at` set but empty `changed_ids`; the
success path leaves events open for the next pipeline stage (still a TODO). The writer's properties dialog reuses the
reader's (`ioSection(kind)`) with the entity variant showing a Content textarea instead of
Load/Id; config discriminator is `writer: entity|connector`.
**DWL-toggled fields** render through ONE shared widget — `widgets/dwl-field.ts`,
`createDwlField({label, value, dwl, fixed?, editorClass?, labelClass?, rowClass?, $tools?})`
(local relative import; also exports `prettyPrintDwl`). The widget owns the title row — label
left; optional host `$tools` + the pure-text/DWL switch ALWAYS right-aligned over the textarea
(`.aaxis-dwl-field__*` block; switch visuals reused from the flow editor's small switch) — the
textarea (each host brings its sizing via `editorClass`) and the behavior: whenever DWL mode is
active (toggle turned on, opening with it on, or `fixed`) the content is pretty-printed via
`prettyPrintDwl()` (re-indents each line to its brace/bracket/paren depth, 2 spaces per level;
string/comment contents never count) and shown with code styling (`__textarea--code`, spellcheck
off). Users: the connector Body content, the writer's Content (compact settings textarea — ALWAYS
a textarea so toggling changes no visuals), the transform's code (`fixed`: always-DWL, switch
HIDDEN) and the Entities DWL playground's script pane (`fixed`, with the limit/Run tools passed
as `$tools`, so the script pretty-prints on open too). Config flags `body_dwl` / `content_dwl` (lenient-absent bools in
`isStepConfigValid`). ON = the field is evaluated as a DWL expression against the execution
context at run time: the body result is sent as-is when a string and JSON-encoded otherwise
(`renderDwl()`); the writer content result IS the record(s) to write (`content_dwl` off keeps the
literal context-key lookup). Both parse-validate on save via `stepDwlSnippets()` → the same 422
`flow_manager.invalid_dwl`.
**Settings loading**: sections doing catalog fetches (`systemEntitySection`, `ioSection`) return a
`ready` promise; `openStepSettings` overlays a spinner (`__settings-loading`) that blocks the whole
panel (buttons included, submit guarded) until every `ready` settles, then focuses the name input.
**Scheduled execution** (`Command/RunScheduledFlowsCommand` → `Manager/ScheduledFlowRunner`): the
`aaxis:ontology:flows:run-due` command implements `CronCommandScheduleDefinitionInterface` with
`* * * * *` (register via `oro:cron:definitions:load`; Oro's cron infra must be consuming — run it
by hand for a one-off sweep). Every minute it selects candidates by PLAIN COLUMNS
(`enabled + type=flow + trigger_type='cron'`), rebuilds each flow from its saved `design` (the only
persisted shape with step ids + links) and runs the DUE ones through the same
`FlowDebugExecutor::execute()` as Run Now — so flowUuid, last_executed stamping, sync writes and
event rows behave identically; one flow's failure logs and never blocks the rest. DUE rules:
interval mode = `value unit` elapsed since `last_executed ?? last_modified` (the creation date
until first edit; datetimes compared as UTC wall-clock regardless of PHP tz); cron mode (and the
legacy `{expression}` config) = the CURRENT minute matches the expression, with a same-minute
guard against double runs; broken/unconfigured schedules and unreadable designs report
skipped/not-due, never crash the sweep.
**DWL engine** (`Dwl/`): the Language+Runtime subset of the user's php-dw DataWeave port
(BSD-3-Clause, license copy in `Dwl/LICENSE`; origin `~/Github/dw-cli/php-dw`), namespaced
`Aaxis\Bundle\OntologyBundle\Dwl\`. Three import gotchas handled in `DwlTransformer`: the AST is
ONE file with 40 classes (upstream classmap) → `loadAst()` require_onces it since PSR-4 can't;
the php↔Value bridge is local (`toValue()`/`->toPhp()`); and `Value::toPhp()` renders DWL objects
as **stdClass** (upstream keeps `{}` vs `[]` apart) — invisible in the JSON debug dialog but fatal
for `is_array()` consumers like the writer ("Record #1 must be a JSON object") → `transform()`
flattens its result to plain assoc arrays via `toPlainPhp()`, matching what readers produce
(`json_decode` assoc). SHAPE fixes belong at this facade; engine FEATURES are added to the engine
files and always MIRRORED to the upstream repo (namespace swap only, `DataWeave\` ↔ this bundle;
run upstream's phpunit after) so the copies never diverge — done so far for min/max-over-arrays,
Value::compare, the COMPLETE `dw::core::Arrays` module (`import * from dw::core::Arrays` →
`getCoreArraysModule()`: slice (until-exclusive) · divideBy · take/drop · takeWhile/dropWhile ·
firstWith · indexOf/lastIndexOf/indexWhere · partition {success, failure} · splitAt/splitWhere
{l, r} · join/leftJoin/outerJoin ({l, r} rows, type-tagged match keys) · countBy/sumBy/every/some),
the `mod` operator (global env function; NOTE the parser gates infix names by the
`isInfixFunction()` WHITELIST — 2-arg functions must be listed there to be used infix, the
evaluator then resolves non-builtins from the env) and the DATE SUPPORT: `|…|` literals (ISO dates/times and `|PT1S|` periods — the
lexer falls back to the `|` operator when the pipes don't wrap something date-shaped), temporal
Values (`Value::dateTime(dt, kind)` with kind date|time|localtime|local_datetime|datetime,
`Value::period()` keeping the ISO text; both leave the engine as ISO strings via toPhp/toString),
DateTime ± Period arithmetic (null temporal propagates null so `default` catches missing dates),
`as Date/Time/LocalTime/DateTime/LocalDateTime {format: …}` and `as String {format: …}` coercions
(Java-style patterns translated by `javaDateFormatToPhp()` — yyyy-MM-dd HH:mm:ss.SSSSSS →
Y-m-d H:i:s.u; parsing uses `!`-reset createFromFormat), fractional-second periods via
DateInterval->f, and `now()` returning a real DateTime. NULL passes through every coercion. The `%dw` header/`---` separator are
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

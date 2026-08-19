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
| `OntologyConnector` | `aaxis_ontology_connector` | belongs to a system; `type` ∈ sftp, rest_api, file_system, bucket, database — **all five now have a Configure popup and a Test** (`openUnsupportedPopup()` survives only as the safety net for a type added to `TYPES` before its form); JSON config authored via the per-type "Configure" popup; secret config values are masked on every read path (see "Connector config & secrets" below) |
| `OntologyFlow` | `aaxis_ontology_flow` | name, enabled, `type` (`native` = the two fixture-seeded built-ins, read-only in the UI — gates the grid edit action, the editor page and the update endpoint; user flows are `flow` when their steps contain a trigger, else `subflow` — recomputed from the steps on every save via `computeType()`, never taken from the payload), JSON `steps` (`[{type, name, x, y}]`, types validated against `STEP_TYPES`, names non-empty ≤64 chars and unique per flow — 422 `flow_manager.step_names_unique`), JSON `design` (the editor's versioned canvas state — stored opaquely by the server, strictly validated by the editor on load; unreadable/outdated → "corrupted" flash + empty canvas; NULL → canvas rebuilt from `steps`), `last_executed` (datetime NULL — stamped by `FlowDebugExecutor::touchLastExecuted()` at the START of every run with a saved flow: Run Now, each Debug step call, and the future real triggers; failed runs count, unsaved flows don't), `last_modified` (datetime NOT NULL — creation date via the entity CONSTRUCTOR, bumped by every editor save; v1_7 backfilled existing rows with the migration time), `trigger_type` (string(16) NULL — the trigger step's type cron|endpoint|entity_change, denormalized from the steps on every save via `computeTriggerType()` so the SCHEDULER selects candidates with a plain WHERE; v1_8 backfilled from the steps jsonb — that migration still names the OLD `queue` trigger, deliberately: it is applied history, fresh installs skip it (installer ≥ v1_9), and no row ever used it), `last_finished` (datetime NULL — stamped by `FlowDebugExecutor::touchLastFinished()` when a run ENDS, from a `finally` so failures stamp too; v1_9 backfilled it from `last_executed` so pre-existing rows don't look permanently running; installer at v1_9). **`last_executed` + `last_finished` are the running-state pair** — see "Flow concurrency" below |
| `OntologyData` | `aaxis_ontology_data` | latest record; `(entity, unique_id)` unique; `payload` jsonb |
| `OntologyDataHistory` | `aaxis_ontology_data_history` | per-version diffs; `(entity, unique_id, version)` unique. **Version continuity** (v1_10): `aaxis_ontology_data_upsert` numbers records PAST whatever history survives for the unique id — insert starts at `max(history)+1`, update archives at `GREATEST(live, max(history)+1)` — because a re-created record restarting at version 1 used to CRASH this unique index on its first change (the pre-9582e03 purge deleted data but kept history; inside the async consumer that read as a logged error + a "no changes" event + a silently unchanged record). Never assume live version = history+1 |
| `OntologyDataEvent` | `aaxis_ontology_data_events` | one row per flow execution (seen vs changed ids); `error` text NULL (v1_10) = how the run failed — status is DERIVED: finished_at null = running, error null = success, else error |

## Data HTTP API (OAuth) — `Api/` + `Manager/OntologyDataApiManager`

OAuth-authenticated REST endpoints over `OntologyData`, addressed by **system name + entity name**.
**Requests and responses deal in the raw payload only** (no record envelope):
- `GET  /admin/api/aaxis/ontology/data/{systemName}/{entityName}/uid/{uniqueId}` — read; returns the
  record's **payload object** (`{}` if empty).
- `POST /admin/api/aaxis/ontology/data/{systemName}/{entityName}/upsert` — upsert; the **request body is
  the payload** (a single JSON object = one record, a JSON array = a batch). The unique id of each
  record is **inferred from the payload** via the entity's `unique_attribute` (no id in the URL); the
  API generates the uuid. Validates, then publishes one message to the existing
  `aaxis_ontology_data_upsert` topic (the processor does the real write); returns
  `202 {success, uuid, count}`.
- `POST /admin/api/aaxis/ontology/data/{systemName}/{entityName}/query?page=&page_size=` — query;
  body `{filter:[{attribute,compare:EQ|LIKE|<|>,value}], orderBy:"attr ASC|DESC"}`, returns
  `{items, page, page_size}` where **items is a list of payload objects** (default order `id ASC`).

The `/data/` segment was added when the FLOW-ENDPOINT API arrived — the two share the
`/admin/api/aaxis/ontology/` root: `data/` = the record API above, `flow/` = the Endpoint-trigger
API below. There is no legacy alias: the old un-segmented data paths 404.

## Flow-endpoint HTTP API — `Api/OntologyFlowApiController` + `Manager/EndpointFlowRunner`

`ANY /admin/api/aaxis/ontology/flow/{path}` (one catch-all route; methods GET/POST/PUT/QUERY/
PATCH/DELETE = `EndpointFlowRunner::METHODS`) runs the ENABLED flow whose **Endpoint trigger**
matches the request's method + path. `EndpointFlowRunner` mirrors `ScheduledFlowRunner` (same
design-rebuild via `parseDesign`, same executor — flowUuid, last_executed, event rows identical);
candidates come from the denormalized columns (`type=flow AND enabled AND trigger_type=endpoint`).
Trigger config `{enabled, method, path, public}`; the path is SEGMENT-matched — literal segments
verbatim, `{param}` matches any one segment and captures it — and when several flows match, MOST
LITERAL SEGMENTS wins (tie: lowest id), so `orders/latest` beats `orders/{id}`. Stored paths may carry a
leading "/" (kept as typed — matching and validation trim slashes on both sides). The flow sees the
request as context variables (seeded by `initialContext`'s endpoint arm): each captured `{param}`
under its own name, the body as `body` (JSON-decoded when parseable, raw text otherwise, null when
empty), the query string as `queryParams` (an object, {} when none), the headers as `headers`
(lowercased; authorization/cookie/php-auth-* STRIPPED so
credentials never enter contexts or event rows) and — on ANY AUTHENTICATED call, public
endpoints included — `OAuthApplication`: the NAME of the OAuth application behind the bearer
token (the OAuth2Token's `client` attribute; null when the caller authenticated another way,
e.g. a back-office session). Anonymous calls define no such variable. The trigger's optional RESPONSE binding
(`config.response`, always-DWL, `EndpointFlowRunner::respond()`) is evaluated against the FINAL
context and must produce `{statusCode, body}` — either element fixed or a variable, e.g.
`{statusCode: 200, body: payload}` or `{statusCode: code, body: 'the end'}`; statusCode defaults
to 200 when absent and must be 100–599, body is JSON-encoded as-is, a non-object result or a
failing expression = `422 flow_failed`. Responses without a binding: `200 {success, flowUuid,
context}`,
`404 endpoint_not_found`, `401 unauthenticated`, `403 forbidden` (authenticated but holding
neither `aaxis_ontology_api_access_flow` nor `aaxis_ontology_api_access_all` — the guard twin of
the data API's), `422 flow_failed` (+failedStepId). AUTH: the
bundle's `Resources/config/oro/app.yml` grants the prefix PUBLIC_ACCESS **under the `oro_security`
scope** (plain `security:` access_control in a bundle app.yml is rejected by OroPlatformExtension;
the rule lands before the `^/admin` catch-all and Oro prepends the backend prefix) — a bearer
token still authenticates when present, and the CONTROLLER 401s a non-public trigger without an
authenticated user. NOTE: Oro's API error renderer WRAPS non-2xx JSON responses under /api in its
problem format (our JSON body lands in `detail`); status codes stay correct. Controllers under
`Api/` must be registered in `controllers.yml` (setContainer + service_subscriber tag) and the
routing resource is the whole `@AaxisOntologyBundle/Api` DIRECTORY.

Key facts:
- **Reusable core** is `Manager/OntologyDataApiManager` (`read`/`upsert`/`query`) — call it directly
  from any PHP; it holds no HTTP/ACL concerns and throws `Exception/OntologyApiException` (which
  carries the HTTP status). `read` returns the payload (`?array`), `query` returns a list of payloads,
  `upsert(systemName, entityName, records)` resolves/auto-creates the entity then delegates to
  `upsertRecords(OntologyEntity, records)` (the shared core: infer uid from each payload, validate,
  queue one message), returning the batch uuid. The controller (`Api/OntologyDataApiController`) only
  enforces the config toggle + ACL, parses the request, and renders JSON.
- **INTERNAL-system entities** (system `external = false`; the ontology entity's `name` is an Oro
  entity class) have NO rows in `aaxis_ontology_data` — `read()`, `queryForFlow()` and
  `queryForFlowByAttribute()` branch to `Manager/OroEntityReader`, which selects from the Oro
  entity itself via DQL (no hydration).
  Payload = the entity's configured attributes (names are Oro field names, enforced by the entity
  form); NO attributes → every scalar column (`searchableFields()` exposes that resolved list —
  the entity_list serializer ships it as `readerAttributes`). To-one associations become the
  related id (`IDENTITY()`), to-many are skipped, date/datetime/time are formatted per Doctrine
  type (`Y-m-d` / ATOM / `H:i:s`). By-id compares the entity's `unique_attribute` column (a
  non-numeric id against an integer column is "not found", not a DB error); by-attribute
  (`readByAttribute`) compares any payload field's column and treats a value the column type
  cannot hold (SQLSTATE class 22) as "no match"; order_by must be a real field or it falls back
  to identifier order (same leniency as the jsonb path). A misconfigured entity (name not a
  managed class / no readable attribute / by-attribute on an unknown field) throws
  `internal_entity_unreadable` (422).
  WRITES: `upsertRecordsSync()` (flow writer steps) branches to `Manager/OroEntityWriter` —
  UPDATES of existing rows only (matched by the unique attribute; a missing/ambiguous row rejects
  the whole batch BEFORE anything is written; creating Oro entities generically is deliberately
  unsupported: required relations/ownership). A record writes payload keys ∩ the reader's field
  set minus identifier/unique columns — present keys written (null clears), absent left alone;
  to-one associations take the related id (`getReference`, numeric-cast); values coerced per
  Doctrine column type; ONE `flush()` per batch; only rows whose values actually differed count
  as changed (dates compare by value, scalars loosely). Events are recorded/closed exactly like
  the store path, and `prepareUpsertBatch(..., syncAttributes: false)` skips
  `syncFromRecords` — an 88-column payload must NOT materialize 88 configured attributes
  (`assertValid` still runs). The QUEUED path (`upsertRecords` — Add Data modal, REST API) throws
  `invalid_payload` for internal entities instead of silently storing rows nothing reads.
  Failures: `internal_entity_unwritable` (422).
  KNOWN GAPS: the paged `query()` endpoint, the Data View listing and the entities playground still
  read only `aaxis_ontology_data` (empty for internal entities).
- **The back-office "Add Data" modal reuses this**: `OntologyDataController::createAction` decodes the
  payload (JSON/CSV/XML), normalizes single-object-vs-array, and calls `upsertRecords()` — so the
  modal behaves exactly like the API (no Mode selector, no Unique Id field; the uid is inferred from
  the payload via the entity's `unique_attribute`). UI: `data-view-component.ts`. NOTE: for
  INTERNAL-system entities this queued path throws (see above) — only flow writer steps can write
  them.
- **The Data View's per-row "Update" action is the SAME modal**: `openAddData(record?)` — with a
  record it retitles to `update_title`, preloads system/entity from the row's `systemId`/`entityId`
  (added to `serialize()` for this; names alone are ambiguous across systems), replaces the entity
  options with just that entity, and sets `prop('disabled', true)` on both selects BEFORE Select2
  initialises (Select2 renders the disabled state from the source select; a disabled select still
  answers `.val()`, so the submit body is unaffected). The payload loads pretty-printed
  (`prettyJsonOrRaw`, format forced to json) and `addBaseline` holds that exact text: `updateAddState`
  keeps Submit disabled until the textarea DIFFERS from it, so reopening a record and closing cannot
  rewrite it. `disposeAddSelects()` clears the baseline so the next plain "+ Add Data" is ungated.
  Submitting is the ordinary upsert — the record gains a new version, and editing the unique id
  inside the payload writes a DIFFERENT record rather than renaming this one.
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
  The flow-endpoint API adds `aaxis_ontology_api_access_flow`: a NON-PUBLIC Endpoint trigger needs
  `_flow` OR `_all` (public ones need nothing).
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
  covering many lines, so line filtering would emit unbalanced HTML. Objects prune key by key and
  ARRAYS element by element, both recursively (a change deep inside one element keeps only that
  element, pruned to its changed attributes; kept pairs stay index-aligned between the two sides
  so the renderer matches them up) — the trade-off being a diff-only array is COMPACTED, so
  element POSITIONS are only exact in the full view. Scalars/type-mismatches are kept whole. Copy
  follows what is on screen. The OLDEST version shows its complete payload in BOTH modes with no
  change markers (everything is "new" in v1 — highlighting the whole document would be noise); a
  no-change version shows a muted note instead of an empty pane.
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
- `bucket` → `{server, port?, access_key, secret_key, bucket_name}` — S3-compatible object storage
  (OCI Object Storage, AWS S3, MinIO). `server` is the endpoint HOST, not a bucket URL (OCI:
  `<namespace>.compat.objectstorage.<region>.oraclecloud.com`); `bucket_name` is what Cyberduck
  calls "Path". ⚠️ **BOTH keys are secrets** — `access_key` and `secret_key` end in `_key`, so the
  existing suffix rule masks them with no rule change, and both render as `password` fields. The
  access key being masked like a password is deliberate (asked for), at the cost of not being able
  to see WHICH key a saved connector uses. **No region field**: SigV4 needs one, and
  `S3RequestSigner::regionFromHost()` derives it from the hostname (OCI/AWS patterns, else
  `us-east-1`) — a provider that neither encodes the region in the host nor accepts `us-east-1`
  would need the field added.
- `database` → `{engine: postgresql, server, port, database, schema?, user, password}` —
  **PostgreSQL only for now**; `engine` is a select with a single option, stored anyway so adding
  a second engine needs no reshaping of saved configs (`ConnectorTester::ENGINE_POSTGRESQL` mirrors
  the TS constant, and an ABSENT engine is read as postgresql). `schema` is optional and omitted
  from the JSON when blank (= the server's `search_path`).

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
`min: 1, max: 65535` (validated by the widget). REST, bucket and database use the same 75/25
server/port row; bucket follows it with a 50/50 access-key / secret-key row (both `password`) and a
full-width "Bucket name" below, database with a 24/38/38 engine/database/schema row (the same
proportions as sftp's auth row) over a 50/50 user/password row. The
popups use the shared `RecordFormModal` `password` field type (show/hide toggle inside the input),
`hint` lines, `disabled` fields and number `min`/`max` — documented in `../CommonBundle/CLAUDE.md`.

**"Test" on the VIEW page**: the connector view page's title bar carries a Test button
(`data-role="connector-test-stored"` in view.html.twig's navButtons) probing the SAVED config via
`aaxis_ontology_connector_test_stored` (`POST /connectors/test-stored/{id}`, CSRF-protected,
plain VIEW ACL — no config travels in either direction, so unlike test-config there is nothing to
probe arbitrarily and no edit grant is needed). `connector-view-test-component.ts` (document-
delegated click: the button sits OUTSIDE the component element) renders the same overall +
per-step lines under the Configuration block (`.aaxis-connector-test` in ontology.scss — the
popup's `.aaxis-rfm__test-*` styles are scoped inside the fixed-overlay modal root and can't be
reused). Same `ConnectorTester` backend as the popup below.

**"Test" in the popups**: every Configure popup has a widget `testAction` (button on the LEFT of
Cancel/Submit) that POSTs the CURRENT popup values as `{type, config, id?}` to
`aaxis_ontology_connector_test` (`/connectors/test-config`, CSRF-protected, view ACL + a manual
create-OR-update grant check — deliberately NOT the shared `aaxis_common` connection-test
endpoint, which only tests SAVED config). The controller resolves `********` sentinels from the
persisted connector (same-type only) via `ConnectorConfigSecrets::merge()`, then delegates to
`Manager/ConnectorTester`: file_system = base path exists/is dir/readable; sftp = ① TCP socket
(reports the SSH banner) ② authenticate with the informed user/password-or-key; rest_api =
① TCP socket (port defaults from scheme; bare hosts assume https/443) ② `auth: oauth` only —
POST to the OAuth path with the informed headers + form-encoded body, success = HTTP < 400;
bucket = ① TCP socket ② `GET /<bucket_name>?list-type=2&max-keys=1` signed with AWS SigV4
(`Manager/S3RequestSigner`), so ONE call validates the credentials *and* the bucket's existence —
403 and 404 get their own messages. Path-style addressing is used (endpoint/bucket), which keeps
the signed host independent of the bucket name and works on every S3-compatible provider.
⚠️ **A just-created key legitimately 403s for a few minutes** (measured on OCI: >2.5 min), which is
why the 403 message says so — do not read an immediate 403 on a fresh key as a wrong secret.
`S3RequestSigner` signs **body-less requests only** (payload hash is hard-coded to the empty-string
SHA-256); an upload path must hash the real body. Its two subtleties: the signed `host` must equal
what the HTTP client actually sends (`hostHeader()` adds `:port` only for non-default ports), and
the region comes from the hostname (`regionFromHost()`), since bucket configs carry no region.
database = ① TCP socket (default port 5432) ② a real `PDO` connection with the informed
credentials, reporting the server version ③ **only when a schema is configured** — it exists AND
the user holds USAGE on it. Three things to keep in mind: credentials are passed as PDO
CONSTRUCTOR ARGUMENTS, never in the DSN (a driver message built from the DSN would carry them),
and `withoutSecret()` scrubs the password from any exception text on top of that; a `;` in the
server or database name is REFUSED because PDO splits the pgsql DSN on `;` before libpq sees it,
so such a value would inject a connection parameter and no quoting can prevent it; and the schema
check reads `pg_catalog.pg_namespace`, NOT `information_schema.schemata` — the latter lists only
schemas the user OWNS, so a readable schema would wrongly look missing.
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
enforced again by the editor page + update endpoint). The flows grid also carries a DELETE action
(trash, `variant: 'danger'`, disabled for native — enforced again by `deleteAction`, route
`aaxis_ontology_flow_delete`, ACL `aaxis_ontology_flow_delete`/DELETE): confirm dialog, then the
flow definition is removed permanently while its events/data stay (events reference `flow_id`
without an FK, so the Events page shows those rows with no flow name afterwards). GOTCHA fixed
once: the flow controller must extend `AbstractOntologyController` (that is where
`deleteEntity()` lives) — it extended the bare AbstractController for a while and `deleteAction`
fataled with "Call to undefined method deleteEntity()". It shows the flow name (new flows default to
`new_flow_<6 random alphanumerics>`), an enabled switch and cancel/save, over a dot-matrix canvas
whose spacing comes from `aaxis_ontology.flow_editor_grid_spacing` (System Configuration →
Aaxis Ontology → Flows, default 10px, exposed to CSS as the `--aaxis-flow-grid` custom property).
**ENABLED moved onto the TRIGGER**: there is no top-bar enabled switch anymore — every trigger's
properties popup LEADS ITS FIRST ROW with the Enabled switch, before the name (`enabledSwitch()`
returning a settingsCol built on the shared `plainSwitch()` markup — the old body-row
`triggerEnabledSection` is gone; config key `enabled`). Rows: cron `Enabled | Name | Mode`,
endpoint `Enabled | Name | Public` + `Method | Path` + the optional always-DWL Response binding,
entity_change/subflow `Enabled | Name`. A
freshly DROPPED trigger is SEEDED with `{enabled: true}` right in `addStep()` — without the seed
a new flow read as disabled until the user happened to open the trigger's properties once — and
the validator treats an enabled-only config as "not configured yet" (`$onlyEnabled`), so the
fresh tile stays green while cron/entity_change/endpoint wait for their real fields. The Endpoint
trigger (`endpointSection`) configures `{method: GET|POST|PUT|QUERY|PATCH|DELETE, path, public}` —
path segments literal or `{param}` (identifier, NOT a reserved context name: body/headers/
queryParams/OAuthApplication/flowUuid/choiceResults/payload — `FlowStepValidator::endpointPathOk` server-side, mirrored in the
section's error()); `public` defaults OFF (= requires auth); `response` optional always-DWL
(parse-checked via stepDwlSnippets), binding {statusCode, body} onto the HTTP answer at run end —
a fresh endpoint DROP seeds the example shape (`ENDPOINT_RESPONSE_EXAMPLE`), and `$onlyEnabled()`
ignores BOTH `enabled` and `response` so the seeded tile still counts as "not configured yet".
See "Flow-endpoint HTTP API". `flows.enabled` is
DERIVED on every save
(`OntologyFlow::computeEnabled()`: true only when a trigger exists AND carries `enabled: true` —
a missing/unconfigured trigger or a missing flag reads as DISABLED, so a broken entry point never
runs; the column stays synced for the grid and the scheduler's plain WHERE). The client mirror is
`flowEnabled()` (gates Run Now/Debug, refreshed via syncDirty). v1_10's
`SeedTriggerEnabledFlags` seeded the flag into existing triggers from the old column so no flow
changed state; `FlowPortability::import()` forces the imported trigger's flag OFF (steps + design)
so the column and the config agree on "imported = disabled". `computeType()`: a REAL trigger
(cron/endpoint/entity_change) = `flow`; the Subflow trigger — or, legacy, no trigger — =
`subflow`. Since only ONE trigger may exist per canvas, flow-vs-subflow is mutually exclusive by
construction, and a subflow now anchors the executor's BFS like any trigger (subflows are
runnable/debuggable; the legacy "Start here" marker remains only for old triggerless designs).
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
**TABS**: the editor is multi-flow — one CANVAS, one tab per open flow (`EditorTab[]`: the
active tab mirrors the live canvas; switching STASHES the working state — `currentDesign()` +
dirty baseline + red-mark names — and restores the target's, reusing the design
serialize/restore machinery; the URL replaceState-follows the active tab). Tab title = the
TRIGGER STEP's name ("unnamed — add a trigger" until one exists — the flow is NAMED BY ITS
TRIGGER; there is no top-bar name display anymore), a • marks unsaved changes, × closes (confirm
when dirty; the editor always keeps at least one tab). Two ICON ACTION tabs sit at the end:
folder = open any existing non-native flow in a NEW tab (picker over `aaxis_ontology_flow_list`;
an already-open flow just focuses its tab), + = a new empty flow tab. Switching tabs closes any
debug session. A TRIGGER IS REQUIRED TO SAVE (`structuralErrors`: `trigger_required` — blocks
save, import and the run gate alike; client pre-checks with a flash; the step-less NATIVE flows
never pass through save). The save payload carries no name; the server
derives it (`deriveFlowName()`: trigger step name → stored name → generated `new_flow_<hex>`),
re-checking uniqueness against the derived value, and `FlowPortability::import()` derives it the
same way (document name only as the legacy triggerless fallback, re-checked for collisions).
Topbar fields are STACKED label-above-control (`__field` /
`__field-label`, controls bottom-aligned — keeps the left side narrow); every right-side button is
icon + a `__btn-text` span that a `max-width: 1199px` viewport hides (icon-only mode, the `title`
tooltips carry the labels — which is why syncDirty updates the cancel button's LABEL SPAN
(`data-role="cancel-label"`) + title, never `.text()` on the button, which would wipe the icon).
Toolbox show/hide toggle + **Organize** (hidden without steps:
lays every step out in execution order — BFS from the trigger/"Start here", unreachable steps
appended in reading order — with one-tile gaps, wrapping rows before the viewport width, then
scrolls to top-left) + cancel/save on the right (the toolbox title bar also carries a × that
hides it). The draggable toolbox
(Triggers: cron/endpoint/entity change/Subflow (`subflow` — the ENTRY POINT of a callable
subflow; a step TYPE, distinct from the flow TYPE 'subflow' and from the "Call Subflow" action
`sub_flow`) · Flow Control: Choice (an "if")/Call Subflow (`sub_flow`)/Foreach Loop
(`foreach`, PLACEHOLDER — name-only settings, no-op at runtime) · Operations: DWL transform/Entity
Read/Entity Write/HTTP Request/SQL Query/Invoke PHP (`invoke_php`, PLACEHOLDER) · File Operations:
Read File/Write File/List Folder/Delete/Rename · Notification: Logger (`logger`, IMPLEMENTED —
see the step docs) /Event/Email/MS Teams (`event`/`email`/`ms_teams` — PLACEHOLDERS like foreach:
toolbox tile + name-only settings, valid with any config, no-op at runtime)) is the step palette — each SECTION collapses via the
+/- at the right of its title (`data-role="toolbox-section-toggle"` → `.is-collapsed` hides the
items; in-memory only, every load starts expanded). The toolbox's position/visibility are a
WORKSPACE preference, NOT flow state: saved to localStorage (`aaxis.ontology.flowEditor.toolbox`,
one spot shared by every flow — `saveToolboxState()` on drag end / visibility toggle,
`restoreToolboxState()` at load) and deliberately ABSENT from `currentDesign()` (a stored spot
that no longer fits the current window falls back to the default top-right placement instead of
being clipped; the init visibility sync passes `persist=false` so it never overwrites the stored
spot). Old designs may still carry a `toolbox` key — restore ignores it, next save drops it, the
DESIGN_VERSION stays 2 (both shapes read fine). The toolbox is also HEIGHT-CAPPED to the working
area (`clampToolboxIntoView()` sets max-height = viewport - 48; the handle stays put and the
sections scroll inside `__toolbox-body`, or the user collapses sections). Icons: entity_read/entity_write carry the
classic fa-download/fa-upload (inherited from the removed generic reader/writer). Items are dragged onto the canvas as
square tiles of `flow_editor_step_size_factor` × grid-spacing px (config, default 8 → 80px tiles
on a 10px grid), can be moved freely afterwards and always snap to the grid. A step is added ONLY
by a real drag out of the toolbox: `startGhostDrag()` merely arms the gesture and the preview tile
is materialised on the first move past `DRAG_THRESHOLD` (5px), so a plain click adds nothing, and
`dropGhost()` additionally rejects a release still inside the toolbox — it floats **above** the
canvas, so its bounds would otherwise pass the in-canvas test. Consequence to keep in mind:
`ghostDrag.el` is nullable while a drag is armed. Drops land through `addStepFromDrop()`:
released ONTO an existing tile that still has a FREE flow-out port (first free port; triggers
never chain — they take no in-line), the new tile becomes that element's NEXT step — placed one
Align-gap (2×tileSize) along that port's flow-out side (occupied cells advance along the
direction, wall-clamps switch to east-scan, exactly like Align's `claim()`), linked immediately,
and its in-anchor faces the parent (opposite of the parent's out side, skipped when that would
collide with the fresh tile's own default out sides). Fresh drops of types needing configuration
START RED: the module-level `REQUIRES_CONFIG` set mirrors the server's isStepConfigValid arms
(triggers + parameterless placeholders excluded) — keep the two in sync when adding step types.
**Unsaved-changes guards** (ANY open tab dirty, `anyTabDirty()`): `beforeunload` (close /
reload / external URL — native browser prompt), mediator `openLink:before` (Oro's in-app
pushState navigation — same hook PageStateView uses for forms) and `page:beforeRefresh`
(deferred into the refresh queue), plus a `pageStateChecker.registerChecker()` registration so
grid inline-editing / config-form / hidden-redirect checks see the editor too; all use Oro's
`oro.ui.leave_page_with_unsaved_data_confirm` message and every hook is removed in dispose().
The editor's own cancel button (labelled "Discard" while dirty, "Close" when clean) is an
EXPLICIT discard: it sets `discarding = true` before navigating, which makes `anyTabDirty()`
answer false so none of the guards fire on that exit. Each tile shows its
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
has two, at 1/3 and 2/3 height — port 0 GREEN "when the expression matches", port 1 RED "when it
does not"; the ports, their wires, temp drag wires and arrow heads are tinted via `data-branch`
attributes + the `#aaxis-flow-arrow-green/-red` marker twins, since markers can't inherit stroke) —
drag from a port onto another tile to wire them. An existing wire
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
**Anchor sides**: which tile side a line arrives at / leaves from is user-configurable per tile
via the right-click menu — "flow-in" (hidden on triggers, which accept no incoming) and
"flow-out" (a choice shows "flow-true"/"flow-false" instead, one per port) are HOVER SUBMENUS
(`addSubmenu` in `showMenuAt`: a wrapper div + pure-CSS `:hover` reveal, child list overlapping
4px so the pointer never crosses a dead gap) listing North/South/West/East with the CURRENT side
checked and sides used by the tile's other anchors DISABLED (each anchor keeps its own side; a
choice occupies three of the four). DRAG gestures re-anchor too (`dropAnchor` + `nearestSide`):
dropping a relink-drag's arrow head back on its CURRENT target moves that tile's flow-in to the
side nearest the release point, and releasing a port drag on its OWN tile moves that port's
flow-out the same way — an occupied side leaves things unchanged.
Defaults: in = west, out = east, choice's false port = south. Model: `PlacedStep.inSide` /
`outSides[port]` (`AnchorSide`, `SIDE_VECTOR`), persisted in design.steps as optional
`inSide`/`outSides` keys ONLY when changed (defaults stay implicit; DESIGN_VERSION unchanged —
`sanitizeAnchors()` validates them LENIENTLY on restore: bad values or colliding resolved sides
just fall back to defaults, never "corrupted"). Geometry: `anchorPoint()` (side midpoints) feeds
`outputPos`/`inputPos`; the port "×" handles are placed by INLINE styles (`positionPortsEl`,
beating the CSS defaults); `routeLink()`'s stubs leave/arrive along each anchor side's outward
normal (`SIDE_VECTOR`, ±2 grid cells) with the A* core unchanged, and the "Start here" arrow
follows the in-side too.
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
**entity_read / entity_write** ("Entity Read"/"Entity Write", `entityIoSection`): first row
`Name | Destination` (destination defaults to `payload`), then `System | Entity` (selects fed by
`aaxis_ontology_entity_list`, both referenced by NAME per the bundle's addressing), then for READS
the Load row `{mode: all|by_id|by_attribute, record_id (when by_id), attribute + attr_value (when
by_attribute)}` — in `all` mode the row also offers OPTIONAL `order_by` (picking one reveals
`order_dir` asc|desc) and `limit` (No limit | 1 | 10 | 100 | 1000); `by_attribute` shows an
Attribute select + a Value input instead — and for WRITES the Content DWL field (`content` +
`content_dwl`: a context key, or a DWL expression when the toggle is on). Both the Order By and
Attribute selects list the entity's `readerAttributes` from the entity_list payload (via
`systemEntitySection().attributes()`, which prefers `readerAttributes` over the configured
`attributes`): what a read can actually address — for INTERNAL entities the readable Oro fields
(`OroEntityReader::searchableFields()`, every scalar column when no attributes are configured),
for external ones the configured attribute names. Entity "all" reads go through
`OntologyDataApiManager::queryForFlow()` — NOT page-capped like the outside-facing `query()`
(flows get every record unless the step limits itself; ordering compares the jsonb value:
numbers numerically, strings lexically, id as tiebreaker); `by_attribute` reads go through
`queryForFlowByAttribute()` (external: `payload ->> attr = value` text equality; internal: the
Oro column compared, a value the column type can't hold = no match) and yield the LIST of
matches, [] when none. Validator: shared `$entityReadOk`/`$entityWriteOk` closures; executor:
the `readEntity`/`writeEntity` arms.
**The LEGACY generic reader/writer types are REMOVED** (they were these steps plus a type
selector and a connector variant): v1_10's `ConvertLegacyReaderWriterSteps` migration rewrote
stored flows — reader/entity → entity_read, writer/entity → entity_write, connector variants →
invoke (rest_api) or file_read/file_write (file-based, gaining `path_dwl: false`), discriminator
keys dropped, in BOTH `steps` and `design.steps`. Old EXPORT files carrying those types are now
rejected by import (normalize fails on the unknown type).
**invoke** ("HTTP Request", `httpRequestSection`) is a rest_api connector call, the response under
the destination (executor: the `readConnector` arm, so operation/body/auth behave exactly like a
rest_api reader): row 1 `Name | Connector (rest_api only — the catalog is filtered client-side) |
Destination`, row 2 `Operation | Path | Body`, row 3 the Body-content DWL field ALWAYS visible but
disabled (`.aaxis-dwl-field--disabled` + textarea/switch inert) while Body = Empty. The TYPE key
stays `invoke` (only the label changed) so stored designs keep validating; validator arm:
`destinationOk && connectorOk`, body_content DWL-checked via `stepDwlSnippets`.
**sql_query** ("SQL Query", `sqlQuerySection`) runs SQL against a DATABASE connector
(`Manager/DatabaseQueryRunner` — PostgreSQL via PDO, same connection rules as the connector Test;
executor arm `sqlQuery()`): row 1 `Name | Connector (database only) | Destination`, row 2 the SQL
(a DWL field: literal statement, or an expression BUILDING one), row 3 the optional Bindings
(context key or DWL, like a writer's Content). The SQL takes `:name` placeholders (PDO named
params — `::type` casts and quoted strings are NOT placeholders); the Bindings result feeds them:
an OBJECT = one run, a LIST = the same prepared statement run once per element with the
destination holding the per-run results in order. Resultset statements (SELECT / RETURNING) yield
rows as a list of objects, plain DML yields `{affected: n}`. Binding values must be scalars/null
(typed PDO binds); a missing key or a SQL failure aborts the step naming it. Validator arm:
connector + `sql` (+ `sql_dwl`/`binding`/`binding_dwl` shapes), both texts DWL-syntax-checked when
their toggle is on.
**logger** ("Logger", `loggerSection`) writes ONE line into the PHP application log: settings are
`Name` plus the Message — a TOGGLEABLE text/DWL field like a file path (config keys `message` +
`message_dwl`; DWL-parse-checked via `stepDwlSnippets` only while the toggle is ON — plain mode
text may legally look like DWL). Executor arm (no destination — before the destination gate, like
sub_flow): plain mode logs the text verbatim, DWL mode resolves it against the context; emits
`[Aaxis Flow - <flow name>] <text>` (a null $flow — unsaved debug — logs as "unsaved"; string
results go verbatim, everything else JSON-encoded). It logs through its OWN monolog channel
(`aaxis_ontology.flow_logger`, channel name `aaxis_flow`, a manually-wired StreamHandler on
`%kernel.logs_dir%/%kernel.environment%.log` at info) — NOT '@logger', because Oro's LoggerBundle
keeps the default handlers at ERROR unless the temporary "detailed logs" level is raised, which
would silently swallow the lines.
**sub_flow** ("Call Subflow", `subFlowSection`) invokes another flow of TYPE `subflow` inline:
settings are just `Name | Subflow` — the Subflow picker is a SEARCHABLE combobox
(`.aaxis-flow-editor__combo`: a text input filtering a dropdown of every `type === 'subflow'`
flow except the one being edited, alphabetized, fed by `flowCatalog()` — a cached
`aaxis_ontology_flow_list` fetch mirroring `connectorCatalog()`'s contract; options select on
POINTERDOWN because blur hides the list before click would land; typing clears the selection
until an option is picked, blur restores the picked name). Config stores `{subflow: <flow id>}`;
validator arm only checks the key is a non-empty scalar (existence/type/enabled are RUNTIME
checks — the target can change after save). Executor: `callSubflow()` loads the target and FAILS
the run when it is missing ("no longer exists"), not TYPE_SUBFLOW, DISABLED (the flow-level
derived flag, i.e. its `subflow` trigger's enabled switch — a disabled subflow is a FAILED
execution by requirement), or has no design steps; then re-enters `execute()` with the target's
design.steps/links, the SAME executionUuid and the CALLER'S CONTEXT as `$seedContext`
(`execute()`'s optional 5th param — seed wins over `initialContext()`), so subflow steps read
payload/vars the caller built and everything they write flows back (the arm ASSIGNS the returned
context, then the main flow continues at the next link). Guards: `$subflowStack` (flow ids)
rejects CIRCULAR call chains and caps nesting DEPTH at 10; `lastExecutedIds` is saved/restored
around the nested run (finally) so the caller's debug trail keeps CALLER step ids only, and
nested failures are re-wrapped `Step "<caller step>": <inner message>`. The nested run's inner
trigger step is a no-op (triggers are skipped as entry markers), and `touchLastExecuted/`
`touchLastFinished` stamp the SUBFLOW row too (it ran).
Every visibility
toggle calls `reposition()` and the panel is viewport-capped (`max-height` + scrolling middle) so
Cancel/Confirm always stay reachable. The modal blocks Confirm on missing
values; INCOMPLETE configs are CONFIRMABLE AND SAVEABLE — the popup's section errors no longer
block Confirm (only empty/duplicate NAMES do): the config is stored as-is and the tile turns RED
(`is-config-invalid`, `markStepInvalid()`), and a flow with any red tile CANNOT RUN — Run
Now/Debug are disabled client-side (`syncDebugButtons`, jsmessage `invalid_steps_blocked`) and the
EXECUTOR enforces the same bar on every entry point (`FlowDebugExecutor::assertRunnable()` at
execute()/executeFrom() start — UI, scheduler and future triggers all pass through it, throwing
the first problem BEFORE lastExecuted is stamped). The red set is server-computed
(`invalidStepNames()`, shipped as `invalidSteps` in the flow serialization — applied at editor
load and after every save) and locally updated on each popup Confirm. A NULL (never-confirmed)
config is NOT a free pass: `isStepConfigValid` swaps it for `[]` and runs the type's arm, so a
freshly-dropped dwl_transform/choice/file op/… is exactly as red and run-blocking as a
half-confirmed one (triggers pass via `$onlyEnabled()`, parameterless placeholders via the
default arm) — the editor pre-marks those drops red client-side via `REQUIRES_CONFIG`.
**`Manager/FlowStepValidator` owns those step rules**, now split by consumer:
`structuralErrors()` (duplicate names — the only thing that BLOCKS the editor's `save()`),
`stepErrors()` (per-step map name → first problem: completeness `isStepConfigValid`, `Cron\\
CronExpression`, DWL parse), `invalidStepNames()` (its keys), and `validate()` = both (the FULL
bar — still gates `FlowPortability::import()` and every RUN). Never re-implement a step rule in a
caller: an imported flow has to be exactly as valid as a hand-built one, or it stores something
the editor/executor chokes on (e.g. `"limit": "100"` as a string passes a loose check and then
makes the flow permanently unsavable).
Other types configure only the name so far. **Selection**: click selects a tile, dragging on empty canvas
rubber-bands a multi-selection (macOS style, blue ring = selected), any outside click clears it;
dragging a tile that belongs to a multi-selection moves the WHOLE selection (relative offsets
preserved — the leader is clamped so the entire group stays on the canvas).
**Right-click** on a tile opens a context menu: Remove (deletes the selection + its links); with a
multi-selection also Align (DIRECTION-AWARE since the anchor-sides feature: each linked child is
placed one tile-gap from its parent ALONG the parent's flow-out side for that link — east=right,
south=below, … — so a green-east/red-south choice lays out as an L and all-default flows
reproduce the old left-to-right row; chains walk BFS from their heads (heads by X, cycle fallback
enters at the leftmost), heads and unconnected steps start at the leftmost selected tile's spot
sliding east past occupied cells, and an occupied target keeps advancing along ITS direction —
`claim()`, which switches PERMANENTLY to east scanning when clamped into a canvas wall, or a
west/north walk would ping-pong forever and overlap; so a link-free
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
asks to replace the existing trigger, and the replacement TAKES THE OLD ONE'S PLACE: same spot,
same out-anchor sides, the old trigger's outgoing links rewritten onto the new step (the drop
position was only the gesture) — fresh name/config as usual (`{enabled: true}` seed applies). **Reachability marking**: tiles not reachable from the
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
the rest in one call. **Canvas marks**: every EXECUTED step keeps the AMBER `is-debug-active`
class for the whole session (marks accumulate, cleared only on session start/close), a FAILED
step turns RED (`is-debug-failed`, declared after amber so it wins) with the error text in the
sidebar as before. Both debug endpoints return `executedIds` (this call's successful trail —
`FlowDebugExecutor::lastExecutedIds()`, reset per execute/executeFrom) and, on a step failure,
`failedStepId` + `executedIds` via `Exception/FlowStepFailure` (executeStepTracked wraps step
errors; callers catching plain \RuntimeException still work). **Run Now** (mode
'run', `data-role="debug"`) executes everything in ONE POST to `aaxis_ontology_flow_debug`
(`FlowDebugExecutor::execute()`) — while it runs only the button spinner shows; the sidebar
APPEARS WHEN THE RUN FINISHES with the final context (or the failure), and the canvas marks are
painted only THEN too (no intermediate updates by design: it is one request). Aborting cannot
undo writers that already ran. NOTE: new exposed routes need
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
`choiceResults` and the legacy `flow-uuid` spelling too (rejected in `isStepConfigValid` + the TS
panels' `destinationError()` — jsmessage `destination_reserved`). **choice** steps evaluate their
`config.expression` (DWL, required; syntax-checked on save via `stepDwlSnippets`) against the
context: truthy continues on the GREEN port 0, falsy on the RED port 1 (optional — no link ends
the flow); the verdict lands in the context under `choiceResults` (step id => bool), which is what
`executionOrder(steps, links, choiceResults)` uses to follow only the taken branch — an
undecided choice ends the walk, so `execute()`/`executeFrom()` RE-DERIVE the order after each
executed choice (the stepper's `total` grows as branches resolve; the prefix never reorders
because every port drives one link and every step accepts one incoming). Executed for real: **entity_read** (`all`
= EVERY record via `OntologyDataApiManager::queryForFlow()` — not page-capped, optional
order_by/order_dir/limit from the step's config — `by_id` = read(); a MISSING record yields null,
not an error; `by_attribute` = the LIST of records whose attribute equals the value via
`queryForFlowByAttribute()`, [] when none match; INTERNAL-system entities read from the
OroCommerce entity itself — see `Manager/OroEntityReader` under "Data HTTP API") and **invoke
("HTTP Request")** (URL = connector server[:port] + step path; connector headers; auth=headers
merges auth_headers; auth=oauth POSTs the token path — form-encoded oauth.body + oauth.headers —
and attaches `access_token` as a bearer; the step's operation/body/body_content are honoured;
TLS verification off like the toolbox proxy; JSON responses decoded, others returned raw;
HTTP ≥ 400 aborts naming the step). **The File Operations steps run for real** via
`Manager/FileConnectorTransfer` (see "File-based connector transfers" below); **dwl_transform** steps execute their DWL script via `Dwl/DwlTransformer` with
the WHOLE current context bound as variables (payload, prior destinations…), result stored under
their destination; **entity_write** steps write the context value named by `config.content`
(a single object or an array of objects) into the configured system/entity **synchronously** via
`OntologyDataApiManager::upsertRecordsSync()` — external entities: same validation +
`aaxis_ontology_data_upsert` PG function as the async Data View "Add Data" path
(`upsertRecords()`, still queued); INTERNAL entities: `Manager/OroEntityWriter` updates the Oro
rows themselves (existing records only — see "Data HTTP API"). No queue either way:
uid inferred from the entity's unique_attribute, **stamped with the flow being debugged** (the
editor sends `flowId` in the debug POST; a never-saved flow falls back to
`requireEnabledFlow(Manual)`) **and with the run's `flowUuid`** (the optional uuid arg). The
receipt stored under the destination reports the REAL outcome — `{uuid, count, upsert:
<created+changed>, changedIds: [<the upserted ids>]}` (unchanged records excluded; an EMPTY
content — null / [] / "" — is NOT an error: the write is skipped, no event row, receipt
`{uuid: <run uuid>, count: 0, upsert: 0, changedIds: []}`) — and the
event row is recorded AND completed inline (unique_ids = seen, changed_ids = upserted,
finished_at set); PG validation errors throw, naming the step. **writer/connector** writes for real
through file_system/sftp/bucket (below); rest_api/database writers emit a `_debug` placeholder;
other step types are no-ops for now. Both writers resolve their content through the SAME
`resolveContent()` (context key, or the DWL expression when `content_dwl` is on), so the two cannot
drift on how a step's content is read. `prepareUpsertBatch()` (shared by both paths)
rejects a batch that REPEATS a unique id, naming both record numbers.
**`Manager/OntologyDataEventRecorder` is the SINGLE writer of `aaxis_ontology_data_events`** — used
by BOTH the synchronous path (`upsertRecordsSync`, internal-writer arm included) and the async
consumer, because the two used to
duplicate this bookkeeping and drifted (the consumer left every successful event open, so Manual
"Add Data" rows showed no Changed Ids and no Finished At). `open()` inserts the row,
`close(id, changedIds, ?error)` stamps changed_ids + finished_at + the failure description from a
**`finally`** on both paths, so a rejected batch or a crash
never leaves an event "open"; `parseUpsertResponse()` is the one place that reads the PG function's
answer (an UNTOUCHED record is marked json null, so "changed" = the keys carrying a diff;
numeric-looking unique ids come back as INT array keys and are strval'd; the failure payload is
recognised BY SHAPE — the whole payload being `{errors: [strings]}` — so a record whose unique id
is literally `errors` is not misread as a rejection). `encodeIds()` writes NULL, never `''`, for an
empty list: Doctrine's simple_array reads `''` back as `['']`, a phantom blank element that would
show up as a count of 1. The `error` column (v1_10) carries HOW a run failed (rejected-batch
reasons joined, or the crash message; null = success): every close site records it — the async
processor from its catch/rejection arms, the sync store arm and the internal-writer arm via a
record-then-rethrow catch — and the Events page derives a Status badge from finished_at + error
(running / success / error + description). Rows from before v1_10 are all null, i.e. read as
Success — historical failures are only in the logs. The Events page also accepts **`?uuid=`**
(first load pre-filters the grid, same pattern as Entities' `?system=` and Data View's
`?entity=`) — it is what a cmd/ctrl+click on a row's uuid-filter icon opens in a new tab; plain
click filters in place. Grid actions that NAVIGATE (entity → Data View, system → Entities, flow →
editor, the Add Flow button) honor cmd/ctrl+click too, via the grid's `navigateTo` helper (see
oro-common's CLAUDE.md).
GOTCHA: the queue consumers are LONG-RUNNING php processes — they keep executing the old code until
restarted, so a change here needs `oro:message-queue:consume` restarted (plus a cache clear for the
container, since the processor's constructor arguments changed).
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
**Settings loading**: sections doing catalog fetches (`systemEntitySection`, `entityIoSection`,
`httpRequestSection`, `fileOpSection`) return a
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
QUOTED-KEY selectors (`payload.'my key'` / `."k"` / `..'k'` / `.*'k'` — the Dot arms accept a
STRING token besides identifiers/keywords) and the `~=` SIMILARITY operator (lexed as SimilarTo
all along but had no evaluator arm: `isSimilar()` = equal ignoring type — strict equality, else
number-vs-numeric-string compare numerically so "1.0" ~= 1, else scalar string forms; structures
and null only equal themselves),
the `mod` operator (global env function; NOTE the parser gates infix names by the
`isInfixFunction()` WHITELIST — 2-arg functions must be listed there to be used infix, the
evaluator then resolves non-builtins from the env), the structural `-` operator (`object - "key"`
removes every pair of that key, `array - item` every equal item — before, `-` was numeric-only and
coerced objects to 0, breaking the DW replace-a-key idiom `obj - 'k' ++ {k: v}`; `-` binds tighter
than `++` so no parentheses needed) and the DATE SUPPORT: `|…|` literals (ISO dates/times and `|PT1S|` periods — the
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

## File-based connector transfers (`Manager/FileConnectorTransfer`)

The read/write/delete/rename half of the **file_system, sftp and bucket** connectors, used by
the "File Operations" toolbox steps (`file_read` / `file_write` /
`file_list` / `file_delete` / `file_rename` — executor `fileOperation()`: row-1 connector limited
to the three file types, a DWL-capable Path — `path`+`path_dwl`, literal text when the toggle is
off, an expression that must yield non-empty text when on (`resolveDwlText()`) — file_write adds
Content with the WRITER semantics (context key or DWL), file_rename adds New name, same
DWL-capable shape; validator arm `$fileOpOk` + per-type extras). One class covers all three
storages so a flow behaves the same whichever it points at — `supports($type)` is the single place
that answers "is this connector file-based".

**Result contract** (what lands in the step's destination variable):

| Case | Value |
|------|-------|
| read a FOLDER | a LIST of `{name, path, type: file\|folder, size, modified}`, sorted by name |
| read a FILE | its content as a plain STRING |
| write | `{isError: false, message, path, bytes}` — file_system writes add `absolutePath` (the resolved server-side target): they land on the APPLICATION container's filesystem, so without it people hunt for the file on the host/their machine |
| delete | `{isError: false, message, path}` — FILES only (a folder path is refused, recursing is worse); a missing path fails on every storage — object storage would happily "delete" a key that never existed, so `delete`/`rename` HEAD the object first |
| rename | `{isError: false, message, path: <new>, from: <old>}` — `new_name` without `/` renames within the source folder, with `/` it is a full path against the base (= move); an EXISTING target is a failure everywhere (no silent overwrite); buckets have no rename so it is a signed server-side copy (`x-amz-copy-source`, extra signed headers via `S3RequestSigner::headers(..., $extraHeaders)`) + delete of the source — beware the S3 quirk of a 200 carrying an `<Error>` body, which the copy checks for |
| I/O failure (any) | `{isError: true, message, exception: {class, message}}` — root cause when the storage threw, the transfer failure itself otherwise, so the key is always readable |

- ⚠️ **I/O failures are RETURNED, not thrown** — a missing path or refused permission is a result
  the flow can branch on, NOT a reason to abort the run. This is deliberately unlike the rest_api
  reader (which aborts on HTTP ≥ 400). What still aborts the step is a broken *definition*: no
  server/base path/bucket configured, no credentials, phpseclib missing. `Exception/ConnectorTransferFailure`
  is what separates the two — the transfer throws it internally for soft failures and converts it to
  the payload at the ONE public boundary; a plain `\RuntimeException` escapes and aborts.
- **Folder vs file**: file_system and sftp ask the storage (`is_dir`). Object storage has no
  folders, so a bucket path counts as a folder when EMPTY or ending in `/`, and as an object key
  otherwise — a bucket "folder" read is a prefix listing (delimiter `/`, `CommonPrefixes` reported
  as `type: folder`). Consequence: a listed folder's `path` KEEPS its trailing slash so it can be
  fed straight back into the next step; file_system/sftp tolerate the slash.
- **File content is NOT JSON-decoded** (again unlike the rest_api reader): a file's type is not
  knowable from the transport, so the raw string is handed over and a DWL step parses if it wants.
- ⚠️ **file_system reads/writes are confined to the connector's `base_path`** (`localTarget()`):
  a flow author types the path, so without the containment check a step could read `/etc/passwd`
  through a connector scoped to an import folder. Paths that do not exist yet (every write target)
  are checked LEXICALLY, since `realpath()` returns false for them.
- Local writes do **not** create missing folders — a typo would otherwise scatter directories
  instead of reporting the mistake.
- Bucket writes are `PUT` with a signed body: `S3RequestSigner::headers()` takes a `$payloadHash`
  for that (omit it and an empty body is signed). The URL is built with the signer's public
  `encodePath()`/`encodeQuery()`, because `http_build_query()` renders a space as `+` while SigV4
  demands `%20` — a mismatch there is an instant signature failure.
- SFTP needs **phpseclib3** (no ext-ssh2 fallback here, unlike `ConnectorTester`); without it the
  step aborts with an instructive message.

## Flow portability (export / import)

`Manager/FlowPortability` moves a flow between environments as a JSON document; the flows grid has a
per-row **Export** action and an **Import Flow** button (gated on `aaxis_ontology_flow_create`, so it
matches the endpoint). Endpoints: `GET /flows/api/{id}/export` (view ACL) and
`POST /flows/api/import` (create ACL + CSRF, 2 MB cap, outer+inner `json_decode` with
JSON_THROW_ON_ERROR and depth 64 — the client posts the file TEXT and the server decodes it, so a
malformed file fails the same way as any bad document).

WHY it is not just "dump the jsonb": a flow already names systems/entities rather than pointing at
ids, but connector steps hold the numeric `config.connector` and Call Subflow steps the numeric
`config.subflow` (the target flow's id) — both meaningless elsewhere. Export rewrites them to
`connectorRef: {name, type, system}` / `subflowRef: {name}` (flow names are unique, so the name IS
the identity) in BOTH `steps` and `design.steps` (each carries its own copy of every config) and
adds an `entities` manifest with each referenced entity's `uniqueAttribute` — the piece step
configs do NOT carry, and what requirement (d) checks. Import resolves the descriptors back to
local ids and drops the refs.

Document: `{format: 'aaxis-ontology-flow', version: 1, exportedAt, flow: {name, type, steps, design},
entities: [{system, entity, uniqueAttribute}]}`. `flow.type` is informational — import always
recomputes type/triggerType from the steps.

Import refuses (collecting EVERY problem into `FlowImportException::getErrors()`, rendered as a list
in the dialog): wrong format/`version`; a `design.version` ≠ `OntologyFlow::DESIGN_VERSION` (**that
PHP constant mirrors DESIGN_VERSION in flow-editor-component.ts — keep them in step**; a mismatched
canvas would still be SCHEDULED and executed, since `ScheduledFlowRunner` reads `design.steps`, while
the editor calls it corrupted and opens empty = an uneditable running flow); `steps` and
`design.steps` describing different flows (they drive different consumers); a taken name; a leftover
RAW `config.connector` or `config.subflow` id (the exact cross-environment mis-binding this feature
exists to prevent); a connector ref missing name/type/system (a partial descriptor used to match
loosely) or a subflowRef without a name; an unmatched or ambiguous connector; a subflowRef whose
name matches NO flow here (**the referenced subflow must be imported FIRST — callers after their
subflows**) or matches a flow that is not TYPE_SUBFLOW; an entity missing here or keyed by a
different unique attribute; a malformed step element (never silently truncate a flow); and anything
`FlowStepValidator` rejects. Export refuses a `config.subflow` pointing at a missing or wrong-type
flow (like a missing connector: the flow is already broken). Imported flows are always created
**disabled** (requirement e).

KNOWN GAPS (deliberate, surfaced to the user): a referenced entity/system that exists here but is
DISABLED imports clean and 422s on the first run — reporting it needs a warnings channel the
response shape does not have yet; export does not detect ambiguity in the SOURCE environment
(connector names are not unique — no unique index on `(system_id, name, type)`); and subflow
references travel by NAME with no manifest, so a same-named-but-different subflow in the target
environment binds silently, there is no bulk import (subflows one file at a time, callers after),
and MUTUALLY-referencing subflows cannot be imported at all (each demands the other pre-exist).
(`invoke`'s `connector` id, `sub_flow`'s `subflow` id and entity_read/entity_write's system+entity
ARE covered: the connector ⇄ connectorRef and subflow ⇄ subflowRef rewrites and the entity
manifest match by config KEY, not by step type.)

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

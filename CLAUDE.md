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
| `OntologyConnector` | `aaxis_ontology_connector` | belongs to a system; type + JSON config |
| `OntologyFlow` | `aaxis_ontology_flow` | name, enabled, JSON steps |
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
docker compose exec php php bin/console debug:router | grep aaxis_ontology
```

`cache:clear` is intentionally omitted — the user runs it themselves when needed (dev mode).

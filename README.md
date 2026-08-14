# Aaxis Ontology Bundle

A back-office (admin) feature for OroCommerce that models a lightweight **data ontology**: the
systems you integrate with, the entities and attributes they expose, the connectors and flows that
move data, the events those flows produce, and the versioned data records themselves.

It renders under the shared **"Aaxis"** application-menu group, in its own **"Ontology"** sub-group.

- Namespace: `Aaxis\Bundle\OntologyBundle`
- Bundle class: `AaxisOntologyBundle` (auto-registered)
- Back-office route prefix: `/admin/aaxis/ontology`
- Config alias: `aaxis_ontology`

> **Related Aaxis bundles**
> - **`AaxisCommonBundle`** — shared base bundle (TypeScript build pipeline, the top-level "Aaxis"
>   menu group and its icon). Required by this bundle.
> - **`AaxisDevToolsBundle`** — the operational/developer toolbox (Runtime Config, Filesystem &
>   Bucket browsers, Database / Elastic / Redis / MongoDB viewers, Network Tools).
> - **`AaxisToolsBundle`** — the lighter toolbox (Queue Monitor, API Collection, Base64).
> - All feature bundles attach to the same top-level **"Aaxis"** menu group (`aaxis_tab`, provided
>   by CommonBundle) and are independent of one another: any can be installed without the others
>   (all require CommonBundle).

---

## Installation

Add both repositories and require the package — Composer pulls in `aaxisdigital/oro-common`
automatically (the project already has the Oro Composer registry, so `oro/platform` resolves):

```jsonc
// composer.json
"repositories": {
    "aaxis-common":   { "type": "vcs", "url": "https://github.com/aaxisdigital/oro-common.git" },
    "aaxis-ontology": { "type": "vcs", "url": "https://github.com/aaxisdigital/oro-ontology.git" }
}
```

```bash
composer require aaxisdigital/oro-ontology:7.0.*
```

The bundle is auto-registered via `Resources/config/oro/bundles.yml` (the Oro kernel scans `vendor/`
and `src/` — no `AppKernel` edit needed). After install/update:

```bash
bin/console oro:migration:load --force                 # creates the ontology tables (+ admin ACL)
bin/console oro:migration:data:load --no-interaction   # seeds flows (Manual / REST API) + admin API ACL
bin/console cache:clear --no-interaction
bin/console oro:assets:build --no-interaction
bin/console oro:translation:load --no-interaction
bin/console oro:translation:rebuild-cache --no-interaction
```

---

## Menu & pages

The **Ontology** group exposes one page per concept (all gated by per-entity ACLs):

| Page | Route | UI | Summary |
|------|-------|----|---------|
| Systems | `aaxis_ontology_systems` | TS DataGrid | Named systems (with optional logo) that own entities and connectors |
| Entities | `aaxis_ontology_entities` | TS DataGrid | Data entities belonging to a system, each owning typed attributes (1:N) |
| Connectors | `aaxis_ontology_connectors` | Oro datagrid + CRUD | Integrations (SFTP / REST API / File System) for a system; the per-type JSON config is read-only on the form and edited through a type-specific "Configure…" popup, with secret values (passwords, keys, Authorization headers) always shown masked |
| Flows | `aaxis_ontology_flows` | TS DataGrid | Named, toggleable pipelines whose ordered steps are stored as JSON |
| Flow editor | `aaxis_ontology_flow_editor` | TS page | Opened by "Add Flow" / a row's edit action: flow name + enabled switch (left), Toolbox show/hide toggle + Close/Cancel + Save (right — Save enables only with pending changes, the exit button reads Close when the state is saved, and saving stays on the page) over a dot-matrix canvas with a draggable, hideable step toolbox (Triggers / Actions / Operations). Steps are dragged onto the canvas as square tiles (icon + unique step name below it — up to two rows — defaulting to `type-n`), movable and always grid-snapped; double-click opens the step's settings panel (modal, next to the tile) where the name — and the step's configuration (Cron: a validated linux cron expression · Entity change: system + entity · Reader: entity-sourced — all records or one by id — or connector-sourced with path, plus operation and body (+ content) for REST API connectors; destination defaults to `payload`) — is edited; a flow can hold only one trigger (dropping a second asks to replace). Tiles are wired by dragging from the "×" output port on their right edge (Choice has two ports) to another tile — arrows arrive at the target's left side; one incoming link per element, triggers accept none. Lines route orthogonally around tiles (never over an element, deviating shortly before it) and draw a small round "jump" where one line crosses another. Steps not reachable from the trigger through the flow lines are shown with a light gray background — they would not execute. In sub-flows (no trigger), right-click an element with no incoming line and choose "Start here" to mark the entry point (an origin-less arrow); adding a trigger later asks before replacing that marker. Tiles are selectable (click or macOS-style rubber band) and a multi-selection drags as a group; right-click opens a context menu with Remove, and for multi-selections Align (same Y as the leftmost, one-tile gaps) and Connect (chains the selection left-to-right when the wiring rules allow); right-clicking a flow line offers Remove for that single connection. A **Debug** button (flows with a trigger) executes the current canvas server-side — cron runs immediately, entity change asks for system/entity + a JSON payload — and shows the resulting output context as JSON (e.g. `{"payload": …}` for a single reader with destination `payload`). Entity readers return `null` for a missing "by id" record; REST API connector readers perform the real HTTP call (including OAuth token fetch when configured). The canvas state is saved in the flow's `design` and restored on edit (a corrupted/outdated design is reported and the editor starts empty). Dot spacing and tile size (default 8× the spacing) are configurable in System Configuration → Aaxis Ontology → Flows |
| Events | `aaxis_ontology_events` | TS DataGrid | Read-only log of flow executions (which unique ids were seen / changed) |
| Data View | `aaxis_ontology_data_view` | TS DataGrid | Consolidated, versioned data records that flowed through the model |

Systems, Entities, Flows, Events and the Data View are rendered by a reusable, self-contained
**TypeScript DataGrid** widget (client-side sorting/filtering/paging, per-user column preferences);
the Connector page uses a standard Oro datagrid with full server-side CRUD.

On the connector form the configuration JSON is never typed by hand: a **"Configure…" popup**
tailored to the selected type collects the values (File System: base path · SFTP: server/port/user
+ none/password/key auth · REST API: server/port/headers + none/headers/OAuth auth, the OAuth
token path plus its body/headers as tabs). Stored secrets never leave the server — they are masked
as `********` everywhere, and editing a connector only replaces a secret when a new value is
entered. Switching the connector type asks for confirmation first, since the existing
configuration is cleared. Each popup also offers a **Test** button that probes the entered values
server-side: File System checks the base path exists and is readable; SFTP opens a socket to
server/port and then authenticates with the informed credentials (requires `phpseclib/phpseclib`,
or `ext-ssh2` for passwords); REST API opens a socket and, for OAuth, calls the token path with
the informed headers/body and reports the HTTP status.

---

## Concepts (entities)

| Class | Table | Notes |
|-------|-------|-------|
| `OntologySystem` | `aaxis_ontology_system` | name, enabled, optional logo (Oro attachment) |
| `OntologyEntity` | `aaxis_ontology_entity` | belongs to a system; owns attributes |
| `OntologyEntityAttribute` | `aaxis_ontology_entity_attribute` | name, datatype (boolean/text/number/date/time/datetime/object/undefined), required |
| `OntologyConnector` | `aaxis_ontology_connector` | belongs to a system; type + JSON config (per-type shape; secret values masked on every read) |
| `OntologyFlow` | `aaxis_ontology_flow` | name, enabled, `type` (`native` = the two built-in flows, read-only; user flows are `flow` with a trigger step, `subflow` without — recomputed from the steps on every save), JSON `steps` `[{type, name, x, y}]` (step names unique per flow), JSON `design` (the editor's canvas state, restored when editing) |
| `OntologyData` | `aaxis_ontology_data` | latest version of a record; `(entity, unique_id)` unique; `payload` is `jsonb` |
| `OntologyDataHistory` | `aaxis_ontology_data_history` | previous-value diffs per version; `(entity, unique_id, version)` unique |
| `OntologyDataEvent` | `aaxis_ontology_data_events` | one row per flow execution (seen vs. changed ids) |

The grids are rendered by the reusable DataGrid widget and persist per-user layout via the
`GridPreference` store — both provided by **`AaxisCommonBundle`**.

---

## Async data upsert flow

Inbound data records are upserted off the request cycle:

1. A producer sends the **`aaxis_ontology_data_upsert`** topic (`Async/Topic/OntologyDataUpsertTopic`)
   with `flow_id`, `uuid`, `entity_id`, parallel `unique_id` / `payload` arrays and `updated_at`.
2. **`OntologyDataUpsertProcessor`** records an event row in `aaxis_ontology_data_events`, then
   delegates validation + upsert to the PostgreSQL function **`aaxis_ontology_data_upsert(jsonb)`**.
3. That function validates the message, upserts into `aaxis_ontology_data`, and archives the previous
   values of changed keys into `aaxis_ontology_data_history`, using the JSONB diff/merge helpers
   (`aaxis_ontology_jsonb_diff`, `aaxis_ontology_jsonb_diff_previous`, `aaxis_ontology_jsonb_deep_merge`).

All four functions are defined once in `Migrations/Schema/OntologyDataFunctions` (the single source
of truth used by the installer) so the live definitions never drift. The Data View reconstructs any
past snapshot by walking the history diffs in reverse.

---

## Access control (ACL)

- **Per-entity ACLs** are declared via `#[Acl]` attributes on the CRUD controllers
  (`aaxis_ontology_system_view/create/update/delete`, and likewise for entity / connector / data),
  with VIEW/CREATE/EDIT/DELETE permissions.
- The shared grid-preference endpoints used by the grids are owned and gated by **`AaxisCommonBundle`**
  (the `aaxis_common` capability), not by this bundle.

---

## System Configuration & feature toggle

A dedicated page lives under **System Configuration → Aaxis → General → Aaxis Ontology** with a
single **Enabled** toggle (`aaxis_ontology.enabled`). That toggle drives the Oro **feature**
`aaxis_ontology` (`Resources/config/oro/features.yml`): disabling it hides the Ontology menu group
and returns HTTP 404 for all of its routes.

---

## Persistence (migrations)

The schema is created by a single consolidated install migration
(`Migrations/Schema/AaxisOntologyBundleInstaller`, version `v1_0`) covering every table listed under
*Concepts* above plus the PostgreSQL functions. Run it with:

```bash
bin/console oro:migration:load --force
```

---

## Front-end / build

TypeScript sources live in `Resources/js-src` (the `system`, `entity`, `data-view`, `event` and
`flow` components) and compile to `Resources/public/js` via
`bin/console aaxis:ontology:typescript:compile` (also triggered automatically on `oro:assets:build`
by an event listener). The components import the reusable DataGrid / dialog / record-form widgets
from **`AaxisCommonBundle`** (`aaxiscommon/js/app/widgets/*`) and use Oro's `oroui` / `routing` /
`orotranslation` modules.

Typical deploy cycle after changes:

```bash
bin/console aaxis:ontology:typescript:compile
bin/console cache:clear --no-interaction
bin/console oro:assets:build --no-interaction
bin/console oro:translation:load --no-interaction
bin/console oro:translation:rebuild-cache --no-interaction
bin/console oro:migration:load --force        # only when entities/migrations changed
bin/console oro:migration:data:load           # only when data fixtures (flows / ACLs) changed
```

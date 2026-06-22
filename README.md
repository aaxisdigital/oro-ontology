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
    "aaxis-common":   { "type": "vcs", "url": "git@github.com:aaxisdigital/oro-common.git" },
    "aaxis-ontology": { "type": "vcs", "url": "git@github.com:aaxisdigital/oro-ontology.git" }
}
```

```bash
composer require aaxisdigital/oro-ontology:7.0.*
```

The bundle is auto-registered via `Resources/config/oro/bundles.yml` (the Oro kernel scans `vendor/`
and `src/` — no `AppKernel` edit needed). After install/update:

```bash
bin/console oro:migration:load --force                 # creates the ontology tables (+ admin ACL)
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
| Connectors | `aaxis_ontology_connectors` | Oro datagrid + CRUD | Integrations (SFTP / REST API / File System) for a system; per-type config stored as JSON |
| Flows | `aaxis_ontology_flows` | TS DataGrid | Named, toggleable pipelines whose ordered steps are stored as JSON |
| Events | `aaxis_ontology_events` | TS DataGrid | Read-only log of flow executions (which unique ids were seen / changed) |
| Data View | `aaxis_ontology_data_view` | TS DataGrid | Consolidated, versioned data records that flowed through the model |

Systems, Entities, Flows, Events and the Data View are rendered by a reusable, self-contained
**TypeScript DataGrid** widget (client-side sorting/filtering/paging, per-user column preferences);
the Connector page uses a standard Oro datagrid with full server-side CRUD.

---

## Concepts (entities)

| Class | Table | Notes |
|-------|-------|-------|
| `OntologySystem` | `aaxis_ontology_system` | name, enabled, optional logo (Oro attachment) |
| `OntologyEntity` | `aaxis_ontology_entity` | belongs to a system; owns attributes |
| `OntologyEntityAttribute` | `aaxis_ontology_entity_attribute` | name, datatype (boolean/text/number/date/time/datetime/object/undefined), required |
| `OntologyConnector` | `aaxis_ontology_connector` | belongs to a system; type + JSON config |
| `OntologyFlow` | `aaxis_ontology_flow` | name, enabled, JSON steps |
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

A dedicated page lives under **System Configuration → General Setup → Ontology** with a single
**Enabled** toggle (`aaxis_ontology.enabled`). That toggle drives the Oro **feature**
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
```

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
| Entities | `aaxis_ontology_entities` | TS DataGrid | Data entities belonging to a system, each owning typed attributes (1:N). Each row also carries a **`dwl`** action that opens a DataWeave playground over that entity's records (see below), and an **Erase** action: erase all stored records, or only those whose attribute matches a comparison (=, ≠, <, ≤, >, ≥, contains, matches with `*`/`?` wildcards, is empty, and their negations), with a live count and an optional paged preview before anything is deleted. Erased records lose their version history too; the entity and its attributes stay |
| Connectors | `aaxis_ontology_connectors` | Oro datagrid + CRUD | Integrations (SFTP / REST API / File System, plus Database and Bucket whose configuration forms are still to be defined) for a system; the per-type JSON config is read-only on the form and edited through a type-specific "Configure…" popup, with secret values (passwords, keys, Authorization headers) always shown masked |
| Flows | `aaxis_ontology_flows` | TS DataGrid | Named, toggleable pipelines whose ordered steps are stored as JSON, with their Last executed / Last modified timestamps. Each row can be **exported** to a JSON file, and an **Import Flow** button (before "Add Flow") recreates one from such a file in another environment — see *Moving flows between environments* below. Clicking a row opens that flow in the editor; to work on several at once, use **Open flows** inside the editor (one tab each) |
| Flow editor | `aaxis_ontology_flow_editor` | TS page | Opened by "Add Flow" / a row click: one tab per open flow, and no top bar — every action is an icon at the right of the tab bar, in two groups: toolbox show/hide (Organize behind its caret) and Run Now (Debug behind its caret); then New, Open (a popup listing the flows and subflows with their status and last modification — filter by name, sort, mark several and **Open selected**, or open one directly from its row), Save, Save all, View source, History and × (close the editor and return to the flows list, asking Save / Don't save / Cancel when anything is unsaved). Save enables only while the focused flow has changes; Save all only while a flow in another tab has changes too; saving stays on the page over a dot-matrix canvas with a draggable, hideable step toolbox (Triggers / Actions / Operations; its title bar can expand or collapse every section at once). Steps are dragged onto the canvas as square tiles (icon + unique step name below it — up to two rows — defaulting to `type-n`), movable and always grid-snapped; double-click opens the step's settings panel (modal, next to the tile) where the name — and the step's configuration (Schedule: an every-N interval (minute/hour/day/week/month/year) or a linux cron expression, whose textbox tints light red while invalid and shows symbol guidance for the cron field being edited · Entity change: system + entity · Reader: entity-sourced — all records (never page-capped for flows; optionally ordered by any entity attribute asc/desc and/or limited to 1/10/100/1000) or one by id — or connector-sourced with path, plus operation and body (+ content) for REST API connectors; destination defaults to `payload` · DWL transform: a DataWeave script (bundled engine — including date/time literals like `|2003-10-01|` / `|PT1S|`, date ± period arithmetic and `{format: …}` date coercions) that sees every debug-context value as a variable and writes its result to the destination · Writer: like the reader, but the entity variant takes a Content — the context value to write (single object or array, unique ids may not repeat within one write), written synchronously through the same validation and upsert function as the Data View "Add Data", stamped with the flow being debugged (Manual only when the flow was never saved); its receipt reports the real outcome — `{uuid, count, upsert, changedIds}` — and the flow's event row completes immediately; an empty content (null / `[]` / `""`) is not an error — the write is skipped and the receipt reports zeros; a writer on a file/SFTP/bucket connector also takes a Content field — DWL-capable, in its own row under the path — since those connectors have no request body to carry what to write). The connector Body content and the writer Content carry a "DWL" on/off switch next to their label: on, the field is evaluated as a DWL expression against the execution context (the body sends string results verbatim and JSON-encodes the rest; the writer's result is the record(s) to write) — off keeps the literal text / context-key behavior; DWL-toggled fields are parse-validated on save. Step settings that load catalogs (systems, entities, connectors) show a blocking spinner over the properties popup until the data is in — is edited; a flow can hold only one trigger (dropping a second asks to replace). Tiles are wired by dragging from the "×" output port on their right edge (Choice has two ports) to another tile — arrows arrive at the target's left side; one incoming link per element, triggers accept none. Lines route orthogonally around tiles (never over an element, deviating shortly before it) and draw a small round "jump" where one line crosses another. Steps not reachable from the trigger through the flow lines are shown with a light gray background — they would not execute. In sub-flows (no trigger), right-click an element with no incoming line and choose "Start here" to mark the entry point (an origin-less arrow); adding a trigger later asks before replacing that marker. Tiles are selectable (click or macOS-style rubber band) and a multi-selection drags as a group; right-click opens a context menu with Remove, and for multi-selections Align (one row starting at the leftmost tile with one-tile gaps — steps already chained by flow lines keep their flow sequence, then any unconnected ones follow by X position) and Connect (chains the selection left-to-right when the wiring rules allow); right-clicking a flow line offers Remove for that single connection. Selected elements can be copied (context menu, or Ctrl/⌘+C) and pasted (right-click the empty canvas, or Ctrl/⌘+V where the pointer is) within and across flows. Connector pickers in the step settings filter as you type and end with "Add new connector…", which opens the connector form in a new browser tab and selects the new connector when you come back. The canvas fills the page and scrolls whenever a step sits beyond the visible area; an **Organize** button lays all steps out in execution order, wrapping rows at the visible width. **Run Now** — with **Debug** as its sub-option behind the caret next to it — (flows with a trigger; disabled flows run manually too, the Enabled switch only gates the automatic runs) open a debugger sidebar left of the design area — cron runs immediately, entity change asks for system/entity + a JSON payload first. Debug steps through the execution: after every step the sidebar lists the variables one by one (objects/arrays start collapsed with their element count and expand on click), offers the Next step / Run all / Cancel buttons, and a DWL evaluator — just above the buttons — that runs any expression against the current variables at any point, showing the result in a modal; executed tiles turn light green on the canvas and the step about to run light orange, and in Debug and Run Now alike a failing step turns light red. Run Now executes everything in one go and the same sidebar appears once the run finishes, showing only the final result (all steps of a session share one `flowUuid`); Escape closes any step properties popup like its Cancel button. Every top-level execution mints one uuid, visible in the output as `flowUuid` (a reserved destination name, directly usable in DWL scripts): all writes of the run are stamped with it, grouping their events and records under a single identity even when the flow holds several writers — sub-flows inherit the caller's uuid instead of minting their own. Fields that accept pure text or DWL share one component (title, the switch always right-aligned over the textarea — hidden where the content must be DWL, like the transform's code — and the textarea) that pretty-prints the code whenever DWL mode is active; the Entities DWL playground's script editor reuses the same component. Entity readers return `null` for a missing "by id" record; REST API connector readers perform the real HTTP call (including OAuth token fetch when configured). The canvas state is saved in the flow's `design` and restored on edit (a corrupted/outdated design is reported and the editor starts empty). Dot spacing and tile size (default 8× the spacing) are configurable in System Configuration → Aaxis Ontology → Flows |
| Events | `aaxis_ontology_events` | TS DataGrid | One row per flow run (flow, uuid, started/finished, elapsed, status, event count) for the runs started within **Flow execution history days** (System Configuration → Aaxis → Ontology → Settings; 30 by default), newest first; **Refresh** fetches only the runs that changed since the last load. A row's "view events" popup lists the run's events. Rows come from the run index (`aaxis_ontology_flow_runs`, one row per run kept current by the event consumer) — see "Run index" below |
| Data View | `aaxis_ontology_data_view` | TS DataGrid | Consolidated, versioned data records that flowed through the model (system, entity, unique id and last update; the record's content is shown by its **Versions** action). Each row carries an **Update** action (before Versions) that reopens the "Add Data" form preloaded with that record — system and entity locked, the payload pretty-printed — and only enables Submit once the payload is actually edited; saving goes through the same upsert, so the record gains a new version |

Systems, Entities, Flows, Events and the Data View are rendered by a reusable, self-contained
**TypeScript DataGrid** widget (client-side sorting/filtering/paging, per-user column preferences);
the Connector page uses a standard Oro datagrid with full server-side CRUD. Their sort and column
filters are kept in the page URL (`sort=…`, `f.<column>=…`), so a refresh, a bookmark or a shared
link opens the same view. The flow editor names the browser tab after the flow being edited.

### DataWeave playground

Every row on the Entities page has a **`dwl`** action that opens a DataWeave scratchpad for that
entity — a movable, resizable modal with two panes: the **DWL Script** and the read-only **Result**.
The entity's stored records are handed to the script as `payload` (the only binding), and the script
starts as a pass-through so the first run shows the data as-is:

```dataweave
output application/json
---
payload
```

Nothing is evaluated until you press **Run**, so an expensive transform never fires while you type.
Once the script (or the row limit) changes, the Result pane greys out to make clear it no longer
matches what you are looking at — press Run again to refresh it. Runs are capped at **100 records**
by default, with the entity's full size shown right beside the limit (`/ 1,916 total records`) so you
can see what you are sampling from; untick *Limit rows* to run over everything (slower on large data
sets). The status line above the Result reports how many records that run actually covered.

**Export** downloads the last result exactly as shown, in the format the script's `output` header
declares — `application/json`, `application/xml`, `application/csv` and `text/plain` are rendered
natively (anything else falls back to JSON), and the file is named after the entity with a matching
extension. Script errors appear in the Result pane rather than as a page error, so a typo is just
another result you can fix and re-run.

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
| `OntologyFlow` | `aaxis_ontology_flow` | name, enabled, `type` (`native` = the two built-in flows, read-only; user flows are `flow` with a trigger step, `subflow` without — recomputed from the steps on every save), JSON `steps` `[{type, name, x, y}]` (step names unique per flow), JSON `design` (the editor's canvas state, restored when editing), `last_executed` (when the flow last ran — debug/Run Now, later the real triggers; empty = never), `last_modified` (never empty: the creation date, updated on every save) |
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
2. **`OntologyDataUpsertProcessor`** opens an event row in `aaxis_ontology_data_events`, then
   delegates validation + upsert to the PostgreSQL function **`aaxis_ontology_data_upsert(jsonb)`**
   and closes that row with the ids it actually created/updated and the finish time — whether the
   batch succeeded, was rejected or crashed. Both this consumer and the synchronous path used by
   flow writer steps go through **`Manager/OntologyDataEventRecorder`**, the single writer of the
   events table, so the Events page reports the same way for every kind of run.
3. That function validates the message, upserts into `aaxis_ontology_data`, and archives the previous
   values of changed keys into `aaxis_ontology_data_history`, using the JSONB diff/merge helpers
   (`aaxis_ontology_jsonb_diff_previous`, `aaxis_ontology_jsonb_deep_merge`). Since v1_15 the whole
   batch is written by one set-based statement, so its cost grows linearly with the batch (50,000
   records take seconds). It answers `{uuid, entity_id, payload: {<unique id>: null | {uuid, version}}}`,
   where `null` means the record was already stored unchanged.

All four functions are defined once in `Migrations/Schema/OntologyDataFunctions` (the single source
of truth used by the installer) so the live definitions never drift. The Data View reconstructs any
past snapshot by walking the history diffs in reverse.

---

## Async HTTP requests (fire and forget)

An **HTTP Request** flow step normally waits for the response and stores it under its
destination. Turn its **Async** switch on and the step instead queues the request and lets the flow
continue straight away. Use this for large batches (e.g. a Foreach Loop pushing thousands of
records to an API) where the flow does not need the answers.

- The **Destination** is emptied and disabled while Async is on: the response is discarded and
  nothing is stored. Config: `async: true`, no `destination`.
- The step resolves everything that needs the flow when it runs. The connector must exist, be
  `rest_api` and have a server, and a DWL body is evaluated against the current context. A broken
  definition therefore fails the run before anything is queued.
- The message (topic **`aaxis_ontology_http_request`**) carries the connector **id**, never its
  credentials. **`Async/OntologyHttpRequestProcessor`** reads the connector and sends the request
  with the same transport as a synchronous step (`Manager/RestConnectorClient`: headers, auth, OAuth
  token reuse, the 401 retry).
- A failed request (HTTP >= 400, transport error, connector gone) cannot fail the run, which has
  already moved on. It is logged as an error and recorded as a `log-message` event of that run, and
  it is **not retried**, because re-sending a POST could duplicate its effect.
- Requests are sent only while message consumers are running, and with several consumers they may
  arrive **out of order and in parallel**. Mind the target API's rate limits.

---

## Inner flows

An **Inner Flow** (toolbox → Flow Control) is a subflow drawn inside the flow that uses it. It has
no Subflow trigger of its own, and it is invoked exactly like a subflow: wherever a **Call Subflow**
or **Foreach Loop** lets you pick a subflow, the canvas's inner flows are offered too, listed first
as "*name* (inner flow)".

- On the canvas it is a box two tiles tall, as wide as the elements it holds (at least two tiles).
- Drop elements from the toolbox, or drag existing ones, into the box. They line up left to right
  and are **connected automatically** in that order. Where you release an element decides its
  place: before or after the elements next to it. Dragging one within the box re-orders it.
- An element dragged **out** of the box lands on the canvas unconnected. An element dragged **in**
  loses its own flow lines.
- The box itself takes no flow lines. Triggers and other inner flows cannot go inside one.
- A **Choice** inside an inner flow continues to the right when it matches. When it does not
  match, it follows its red line down into its **false** row, which always has a slot below the
  Choice. Drop elements there; they chain left to right like the main row, and the inner flow ends
  when that row does. A Choice in a false row gets a false row of its own further down. Dragging a
  Choice within the box takes its false row along; a Choice that leaves the box (or is removed)
  leaves its false row behind, in its place.
- Removing the box removes everything inside it. A Call Subflow / Foreach Loop that pointed at it
  turns red until it is pointed somewhere else.

At run time an inner flow behaves like a subflow call: its elements share the caller's context, and
a Foreach Loop runs it once per element. It is still part of the caller's run (same flow, same
events, no separate "last executed" stamps). The Events page shows its boundaries as
`inner-flow-start` / `inner-flow-finish` naming the inner flow (runs logged before 2026-11-24 show
`subflow-start` / `subflow-finish` instead). Removing an inner flow that holds elements asks first,
since they are removed with it. In the debugger, **Step into** walks it
on the same canvas, and a failing element is painted red where it sits.

Config: the box stores its elements' step ids in order (`children`); a caller stores
`inner: <the box's step id>` instead of `subflow: <flow id>`. Element visibility in the toolbox
follows System Configuration like every other element (`flow_element_inner_flow`, on by default).

---

## Debugging flows: breakpoints and large variables

- **Debug Breakpoint** (right-click a tile): a red dot marks the element. In the editor's **Debug**
  mode, starting a session and **Continue** run up to the next breakpoint and pause *before* it,
  including breakpoints inside subflows and inner flows the run reaches. Run Now and the real
  triggers ignore breakpoints. They belong to your browser (stored per flow, like the toolbox
  position), not to the flow: setting one does not change the flow.
- The **Variables** pane shows at most 100 entries of any object or array, then how many more
  there are. Use the DWL evaluator under it to look into the rest; the evaluator always sees the
  whole context.

## Computed entity names (Entity Read / Write)

System and Entity are normally picked from the known names. Turn on the **DWL** switch inside
either field to compute the name at run time instead (e.g. `payload.system`). The expression must
produce a non-empty text. While a name is computed, the reader's Attribute and Order by are typed
in, since the entity's attributes are only known when the flow runs. Exported flows do not list
computed entities in their entity manifest, and import does not check them.

---

## Quieter event logs ("Log event" switch)

Every flow run records its trail on the **Events** page. On stable, high-volume flows that trail
can grow large, so every step type except the triggers has a **Log event** switch. It sits on the
left of the settings popup's bottom row, under a divider and opposite Cancel/Confirm. It is on by
default.

Switched **off**, the step records none of its own events:

- its `step` row;
- for **Entity Write**, the `data-upsert` row;
- for **Call Subflow** / **Foreach Loop**, the `subflow-start` / `subflow-finish` rows of the runs it
  invokes (`inner-flow-start` / `inner-flow-finish` for an inner flow), and the row of the subflow's trigger. The subflow's own steps follow their own switches.

What stays recorded regardless:

- **failures** (`flow-exception`);
- the run's `flow-start` / `flow-finish`;
- an **Event** step's `log-message`, which is that step's purpose;
- an async HTTP request's failure message.

Config: `log_event: false`, stored only while the switch is off. The switch applies to Run Now,
scheduled and endpoint runs, and the stepwise debugger alike.

---

## Run index (Events page performance)

The Events page used to rebuild every run from **all** stored events on each load and Refresh (and
the stale-debug sweep did the same every minute), so it slowed down as history grew. Since
migration `v1_14` a small table, `aaxis_ontology_flow_runs`, keeps **one row per run**: the flow,
start/finish times, the stored elapsed time, status and event count. The event consumer updates it
as each event is stored, for both event storages (table or bucket).

- The page reads that table, limited to the history window, and Refresh asks only for the runs
  changed since the previous answer.
- The migration fills the table from the existing `aaxis_ontology_flow_events` rows.
- **Retention**: every day at 02:15 (Oro cron), `aaxis:ontology:flow-history:purge` removes the
  history older than **Flow execution history days** (30 by default). It cleans the events table,
  the run index and, whenever a bucket is configured, the bucket's `flow-events/` day folders.
  Run `oro:cron:definitions:load` after deploying so Oro schedules it.
- The same Settings page shows, right after the history days, the **last cleanup** (when, the
  cutoff, and how many events, runs and bucket objects it removed) and a **Clean up now** button
  that runs the same cleanup immediately, in the background, after a confirmation. It uses the
  saved number of days. After a cleanup, each table it removed rows from is vacuumed, so the freed
  space is reused by new rows (the table stops growing).
- Deleting rows never makes a PostgreSQL table smaller on disk: **DB Usage** shows the row count
  dropping while the size stays. **Compact tables** (below Clean up now) rewrites the two history
  tables (flow events and the run index) to give that space back. While each table is rewritten,
  usually for seconds, flow event writes and the Events page wait; a table that stays busy for
  5 seconds is skipped and shown as such. The page shows each table's size before and after the
  last compaction.
- **System Configuration → Aaxis → Ontology → Settings** shows the run index status:
  - "In sync", or "Out of sync since …" when an event could not be added to it (only the first
    such failure is recorded);
  - the last rebuild;
  - a **Rebuild run index** button. The rebuild runs in the background; reload the page to see
    the result.

  Use the button once after deploying with **Use Bucket for Flow Events** on (the migration cannot
  reach the bucket), after switching that toggle, or whenever the status says out of sync.
  `php bin/console aaxis:ontology:flow-runs:rebuild [--days=N]` does the same in the foreground.
- Restart the message consumers after deploying (new and changed processors).

---

## Moving flows between environments

A flow row's **Export** action downloads it as a self-contained JSON file, and the **Import Flow**
button on the same page recreates it elsewhere.

Flows are almost portable already — their steps name systems and entities rather than pointing at
database ids — with one exception: connector steps hold the connector's numeric id, which means
nothing in another database. The export therefore replaces every connector id with a
`{name, type, system}` descriptor, and the import resolves those back to local ids. It also records
each referenced entity's **unique attribute**, which the steps themselves do not carry, so the
target environment can be checked for a same-named entity that is keyed differently.

Before anything is created, the import checks — and reports **every** problem at once:

- the file is a flow export of a supported format and canvas version;
- the flow name is not already taken here;
- every referenced connector exists here with the same name, type and system (an ambiguous or
  incompletely described connector is refused rather than guessed);
- every referenced entity exists here under the same system name and with the same unique attribute;
- the steps clear exactly the same rules the flow editor enforces when saving.

Imported flows are always created **disabled**, so nothing starts running on the strength of a file.

---

## Scheduled flow execution

Flows whose trigger is a **Schedule** run automatically: the `aaxis:ontology:flows:run-due`
command is registered with Oro's cron to run **every minute** (`oro:cron:definitions:load` after
deploying) and executes every enabled flow that is due:

- **Interval** schedules run when `value × unit` has elapsed since the flow's last execution —
  or since its creation when it never ran.
- **Cron** schedules run when the current minute matches the cron expression.

Due flows execute through the same engine as the editor's "Run Now" (same `flowUuid`,
`last_executed` stamping, synchronous writes and event rows); a failing flow is logged and does
not block the others. The command can also be run by hand for a one-off sweep. Candidate lookup
uses the denormalized `trigger_type` column, kept up to date on every save.

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

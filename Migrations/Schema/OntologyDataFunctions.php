<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Migrations\Schema;

/**
 * Single source of truth for the Ontology PostgreSQL functions used by the async data
 * upsert flow. Both the installer (fresh installs) and the versioned migrations (upgrades) build
 * their CREATE OR REPLACE statements from here so the live definitions never drift.
 *
 * The diff helpers recurse into both objects AND arrays so that only the values that actually
 * changed are captured. An array diff is encoded as an object whose keys are the changed indices
 * tagged as "__<index>_" (e.g. {"__0_": ..., "__2_": null}); the tag keeps the node a JSON object
 * (never mistaken for a real array) and lets it be told apart from a plain object diff.
 */
final class OntologyDataFunctions
{
    /**
     * All Ontology data functions in dependency-friendly order. Each is a standalone CREATE OR REPLACE
     * statement and may be replayed safely.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::jsonbDiff(),
            self::jsonbDiffPrevious(),
            self::jsonbDeepMerge(),
            self::dataUpsert(),
        ];
    }

    /**
     * Recursively returns the keys/values present in p_new that are new or differ from p_old.
     * Objects are diffed per key, arrays per index (each changed index stored under the "__<i>_"
     * tag); a removed array element is recorded as null.
     */
    public static function jsonbDiff(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION aaxis_ontology_jsonb_diff(p_old jsonb, p_new jsonb)
RETURNS jsonb
LANGUAGE plpgsql
IMMUTABLE
AS $func$
DECLARE
    v_result jsonb := '{}'::jsonb;
    v_patch jsonb := '{}'::jsonb;
    v_key text;
    v_new_val jsonb;
    v_old_val jsonb;
    v_sub jsonb;
    v_i int;
    v_len_old int;
    v_len_new int;
BEGIN
    IF p_old IS NOT NULL AND p_new IS NOT NULL
        AND jsonb_typeof(p_old) = 'object' AND jsonb_typeof(p_new) = 'object' THEN
        FOR v_key, v_new_val IN SELECT * FROM jsonb_each(p_new) LOOP
            IF NOT (p_old ? v_key) THEN
                v_result := v_result || jsonb_build_object(v_key, v_new_val);
            ELSE
                v_old_val := p_old -> v_key;
                IF (jsonb_typeof(v_new_val) = 'object' AND jsonb_typeof(v_old_val) = 'object')
                    OR (jsonb_typeof(v_new_val) = 'array' AND jsonb_typeof(v_old_val) = 'array') THEN
                    v_sub := aaxis_ontology_jsonb_diff(v_old_val, v_new_val);
                    IF v_sub <> '{}'::jsonb THEN
                        v_result := v_result || jsonb_build_object(v_key, v_sub);
                    END IF;
                ELSIF v_new_val IS DISTINCT FROM v_old_val THEN
                    v_result := v_result || jsonb_build_object(v_key, v_new_val);
                END IF;
            END IF;
        END LOOP;

        RETURN v_result;
    END IF;

    IF p_old IS NOT NULL AND p_new IS NOT NULL
        AND jsonb_typeof(p_old) = 'array' AND jsonb_typeof(p_new) = 'array' THEN
        v_len_old := jsonb_array_length(p_old);
        v_len_new := jsonb_array_length(p_new);
        FOR v_i IN 0 .. GREATEST(v_len_old, v_len_new) - 1 LOOP
            IF v_i < v_len_old AND v_i < v_len_new THEN
                v_old_val := p_old -> v_i;
                v_new_val := p_new -> v_i;
                IF (jsonb_typeof(v_new_val) = 'object' AND jsonb_typeof(v_old_val) = 'object')
                    OR (jsonb_typeof(v_new_val) = 'array' AND jsonb_typeof(v_old_val) = 'array') THEN
                    v_sub := aaxis_ontology_jsonb_diff(v_old_val, v_new_val);
                    IF v_sub <> '{}'::jsonb THEN
                        v_patch := v_patch || jsonb_build_object('__' || v_i::text || '_', v_sub);
                    END IF;
                ELSIF v_new_val IS DISTINCT FROM v_old_val THEN
                    v_patch := v_patch || jsonb_build_object('__' || v_i::text || '_', v_new_val);
                END IF;
            ELSIF v_i >= v_len_old THEN
                v_patch := v_patch || jsonb_build_object('__' || v_i::text || '_', p_new -> v_i);
            ELSE
                v_patch := v_patch || jsonb_build_object('__' || v_i::text || '_', 'null'::jsonb);
            END IF;
        END LOOP;

        RETURN v_patch;
    END IF;

    IF p_new IS DISTINCT FROM p_old THEN
        RETURN p_new;
    END IF;

    RETURN '{}'::jsonb;
END;
$func$;
SQL;
    }

    /**
     * Mirrors {@see jsonbDiff} structurally but captures the PREVIOUS values (from p_old) of the
     * keys/elements that changed: a brand new key or array element is recorded as null, a removed
     * array element keeps its old value so it can be restored when reverting.
     */
    public static function jsonbDiffPrevious(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION aaxis_ontology_jsonb_diff_previous(p_old jsonb, p_new jsonb)
RETURNS jsonb
LANGUAGE plpgsql
IMMUTABLE
AS $func$
DECLARE
    v_result jsonb := '{}'::jsonb;
    v_patch jsonb := '{}'::jsonb;
    v_key text;
    v_new_val jsonb;
    v_old_val jsonb;
    v_sub jsonb;
    v_i int;
    v_len_old int;
    v_len_new int;
BEGIN
    IF p_old IS NOT NULL AND p_new IS NOT NULL
        AND jsonb_typeof(p_old) = 'object' AND jsonb_typeof(p_new) = 'object' THEN
        -- Only the keys whose value differs can contribute (an equal value — object, array or
        -- scalar — adds nothing below), so they are filtered here: a record usually changes a few
        -- of its keys, and looping over all of them dominated the cost of a large update batch.
        FOR v_key, v_new_val IN
            SELECT n.key, n.value FROM jsonb_each(p_new) AS n
            WHERE (p_old -> n.key) IS DISTINCT FROM n.value
        LOOP
            IF NOT (p_old ? v_key) THEN
                v_result := v_result || jsonb_build_object(v_key, 'null'::jsonb);
            ELSE
                v_old_val := p_old -> v_key;
                IF (jsonb_typeof(v_new_val) = 'object' AND jsonb_typeof(v_old_val) = 'object')
                    OR (jsonb_typeof(v_new_val) = 'array' AND jsonb_typeof(v_old_val) = 'array') THEN
                    v_sub := aaxis_ontology_jsonb_diff_previous(v_old_val, v_new_val);
                    IF v_sub <> '{}'::jsonb THEN
                        v_result := v_result || jsonb_build_object(v_key, v_sub);
                    END IF;
                ELSIF v_new_val IS DISTINCT FROM v_old_val THEN
                    v_result := v_result || jsonb_build_object(v_key, v_old_val);
                END IF;
            END IF;
        END LOOP;

        RETURN v_result;
    END IF;

    IF p_old IS NOT NULL AND p_new IS NOT NULL
        AND jsonb_typeof(p_old) = 'array' AND jsonb_typeof(p_new) = 'array' THEN
        v_len_old := jsonb_array_length(p_old);
        v_len_new := jsonb_array_length(p_new);
        FOR v_i IN 0 .. GREATEST(v_len_old, v_len_new) - 1 LOOP
            IF v_i < v_len_old AND v_i < v_len_new THEN
                v_old_val := p_old -> v_i;
                v_new_val := p_new -> v_i;
                IF (jsonb_typeof(v_new_val) = 'object' AND jsonb_typeof(v_old_val) = 'object')
                    OR (jsonb_typeof(v_new_val) = 'array' AND jsonb_typeof(v_old_val) = 'array') THEN
                    v_sub := aaxis_ontology_jsonb_diff_previous(v_old_val, v_new_val);
                    IF v_sub <> '{}'::jsonb THEN
                        v_patch := v_patch || jsonb_build_object('__' || v_i::text || '_', v_sub);
                    END IF;
                ELSIF v_new_val IS DISTINCT FROM v_old_val THEN
                    v_patch := v_patch || jsonb_build_object('__' || v_i::text || '_', v_old_val);
                END IF;
            ELSIF v_i >= v_len_old THEN
                v_patch := v_patch || jsonb_build_object('__' || v_i::text || '_', 'null'::jsonb);
            ELSE
                v_patch := v_patch || jsonb_build_object('__' || v_i::text || '_', p_old -> v_i);
            END IF;
        END LOOP;

        RETURN v_patch;
    END IF;

    IF p_new IS DISTINCT FROM p_old THEN
        RETURN p_old;
    END IF;

    RETURN '{}'::jsonb;
END;
$func$;
SQL;
    }

    /**
     * Recursively merges p_new into p_old, keeping every existing key and overriding/adding keys
     * coming from p_new (objects are merged, scalars/arrays are replaced).
     */
    public static function jsonbDeepMerge(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION aaxis_ontology_jsonb_deep_merge(p_old jsonb, p_new jsonb)
RETURNS jsonb
LANGUAGE plpgsql
IMMUTABLE
AS $func$
DECLARE
    v_result jsonb;
    v_key text;
    v_new_val jsonb;
BEGIN
    IF p_old IS NULL OR jsonb_typeof(p_old) <> 'object' THEN
        RETURN COALESCE(p_new, p_old);
    END IF;
    IF p_new IS NULL OR jsonb_typeof(p_new) <> 'object' THEN
        RETURN p_old;
    END IF;

    -- Every key of p_new wins (one concatenation instead of a jsonb_set per key, each of which
    -- copied the whole object); only the keys that are objects on BOTH sides are then merged
    -- recursively instead of replaced.
    v_result := p_old || p_new;
    FOR v_key, v_new_val IN
        SELECT n.key, n.value FROM jsonb_each(p_new) AS n
        WHERE jsonb_typeof(n.value) = 'object' AND jsonb_typeof(p_old -> n.key) = 'object'
    LOOP
        v_result := jsonb_set(v_result, ARRAY[v_key], aaxis_ontology_jsonb_deep_merge(p_old -> v_key, v_new_val));
    END LOOP;

    RETURN v_result;
END;
$func$;
SQL;
    }

    /**
     * Validates an inbound Ontology data flow message and upserts the records into aaxis_ontology_data,
     * archiving the previous values of changed keys into aaxis_ontology_data_history.
     *
     * VERSION CONTINUITY: a record's version numbering continues after whatever its history table
     * already holds — a fresh INSERT starts at max(history)+1, and an UPDATE archives at
     * GREATEST(live, max(history)+1). A clean record chain behaves exactly as before (insert = 1,
     * archive = live, live + 1); the GREATEST only bites when history from a PREVIOUS generation of
     * the same unique id survived a delete/reload (the pre-v1_10 purge kept history rows), which
     * used to violate the (entity_id, unique_id, version) unique index and silently abort the
     * whole batch from inside the async consumer.
     *
     * SET-BASED (v1_15): the batch is written by ONE statement — a plan of every record (insert /
     * unchanged / update, joined to its live row and its highest surviving history version), then
     * the inserts, the history archive and the updates as data-modifying CTEs over that plan, and
     * the response aggregated once. The former per-record loop grew its response with
     * `v_changes := v_changes || …`, which copies the whole object on every record: a batch of N
     * records cost O(N²) (4 000 records took 26 s, a 50k-row file never finished and pinned a CPU).
     *
     * RESPONSE: {uuid, entity_id, payload: {<unique id>: null | {uuid, version}}} — null = the stored
     * payload already contained the record (nothing written), an object = created or updated, with
     * the record's new version. Callers only tell those two apart
     * ({@see \Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager::parseUpsertResponse()}),
     * so the record's payload/diff is NOT echoed back: it made the response as big as the batch.
     * Validation failures answer {uuid, entity_id, payload: {errors: [...]}} as before.
     */
    public static function dataUpsert(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION aaxis_ontology_data_upsert(p_input jsonb)
RETURNS jsonb
LANGUAGE plpgsql
AS $func$
DECLARE
    v_errors text[] := ARRAY[]::text[];
    v_flow_raw jsonb;
    v_flow_num numeric;
    v_uuid text;
    v_entity_id int;
    v_unique_ids jsonb;
    v_payload jsonb;
    v_updated_at timestamp;
    v_changes jsonb;
BEGIN
    v_flow_raw   := p_input -> 'flow_id';
    v_uuid       := p_input ->> 'uuid';
    v_unique_ids := p_input -> 'unique_id';
    v_payload    := p_input -> 'payload';

    IF v_flow_raw IS NULL OR jsonb_typeof(v_flow_raw) = 'null' OR jsonb_typeof(v_flow_raw) <> 'number' THEN
        v_errors := array_append(v_errors, 'invalid flow_id received');
    ELSE
        v_flow_num := (p_input ->> 'flow_id')::numeric;
        IF v_flow_num < 0 OR v_flow_num <> floor(v_flow_num) THEN
            v_errors := array_append(v_errors, 'invalid flow_id received');
        END IF;
    END IF;

    IF v_uuid IS NULL
        OR v_uuid !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN
        v_errors := array_append(v_errors, 'invalid uuid');
    END IF;

    IF jsonb_typeof(p_input -> 'entity_id') = 'number' THEN
        BEGIN
            v_entity_id := (p_input ->> 'entity_id')::int;
        EXCEPTION WHEN OTHERS THEN
            v_entity_id := NULL;
        END;
    END IF;
    IF v_entity_id IS NULL OR NOT EXISTS (SELECT 1 FROM aaxis_ontology_entity WHERE id = v_entity_id) THEN
        v_errors := array_append(v_errors, 'invalid entity');
    END IF;

    IF v_payload IS NULL OR jsonb_typeof(v_payload) <> 'array' THEN
        v_errors := array_append(v_errors, 'invalid payload format');
    END IF;

    IF v_unique_ids IS NULL OR jsonb_typeof(v_unique_ids) <> 'array'
        OR v_payload IS NULL OR jsonb_typeof(v_payload) <> 'array' THEN
        v_errors := array_append(v_errors, 'mismatch between unique_id and payload sizes');
    ELSIF jsonb_array_length(v_unique_ids) <> jsonb_array_length(v_payload) THEN
        v_errors := array_append(v_errors, 'mismatch between unique_id and payload sizes');
    END IF;

    IF v_unique_ids IS NOT NULL AND jsonb_typeof(v_unique_ids) = 'array' THEN
        IF (SELECT count(*) FROM jsonb_array_elements_text(v_unique_ids)) <>
           (SELECT count(DISTINCT e) FROM jsonb_array_elements_text(v_unique_ids) AS e) THEN
            v_errors := array_append(v_errors, 'cannot received duplicated unique_ids in a single operation');
        END IF;

        IF EXISTS (
            SELECT 1 FROM jsonb_array_elements(v_unique_ids) AS e
            WHERE jsonb_typeof(e) = 'null'
               OR btrim(COALESCE(e #>> '{}', '')) = ''
        ) THEN
            v_errors := array_append(v_errors, 'unique_id value cannot be empty/null');
        END IF;
    END IF;

    IF COALESCE(array_length(v_errors, 1), 0) > 0 THEN
        RETURN jsonb_build_object(
            'uuid', p_input -> 'uuid',
            'entity_id', p_input -> 'entity_id',
            'payload', jsonb_build_object('errors', to_jsonb(v_errors))
        );
    END IF;

    v_updated_at := (p_input ->> 'updated_at')::timestamp;

    -- The unique ids are distinct (checked above), so every record touches its own row and the
    -- CTEs below never race each other inside the statement.
    WITH batch AS (
        SELECT u.uid, p.item
        FROM jsonb_array_elements_text(v_unique_ids) WITH ORDINALITY AS u(uid, ord)
        JOIN jsonb_array_elements(v_payload) WITH ORDINALITY AS p(item, ord) USING (ord)
    ),
    plan AS MATERIALIZED (
        SELECT b.uid,
               b.item,
               d.id,
               d.payload AS old_payload,
               d.uuid AS old_uuid,
               d.updated_at AS old_updated_at,
               CASE
                   WHEN d.id IS NULL THEN 'insert'
                   WHEN d.payload @> b.item THEN 'same'
                   ELSE 'update'
               END AS action,
               -- insert: the new record's version; update: the version the replaced payload is
               -- archived under (the live row then moves to version + 1).
               CASE
                   WHEN d.id IS NULL THEN COALESCE(h.max_version, 0) + 1
                   ELSE GREATEST(d.version, COALESCE(h.max_version, 0) + 1)
               END AS version
        FROM batch b
        LEFT JOIN aaxis_ontology_data d
               ON d.entity_id = v_entity_id AND d.unique_id = b.uid
        LEFT JOIN LATERAL (
            SELECT MAX(hh.version) AS max_version
            FROM aaxis_ontology_data_history hh
            WHERE hh.entity_id = v_entity_id AND hh.unique_id = b.uid
        ) h ON true
    ),
    inserted AS (
        INSERT INTO aaxis_ontology_data (entity_id, unique_id, uuid, version, payload, updated_at)
        SELECT v_entity_id, p.uid, v_uuid, p.version, p.item, v_updated_at
        FROM plan p
        WHERE p.action = 'insert'
    ),
    archived AS (
        INSERT INTO aaxis_ontology_data_history (entity_id, unique_id, uuid, version, payload, updated_at)
        SELECT v_entity_id, p.uid, p.old_uuid, p.version,
               aaxis_ontology_jsonb_diff_previous(p.old_payload, p.item), p.old_updated_at
        FROM plan p
        WHERE p.action = 'update'
    ),
    updated AS (
        UPDATE aaxis_ontology_data d
        SET payload = aaxis_ontology_jsonb_deep_merge(p.old_payload, p.item),
            uuid = v_uuid,
            updated_at = v_updated_at,
            version = p.version + 1
        FROM plan p
        WHERE p.action = 'update' AND d.id = p.id
    )
    SELECT jsonb_object_agg(
               p.uid,
               CASE p.action
                   WHEN 'same' THEN 'null'::jsonb
                   WHEN 'insert' THEN jsonb_build_object('uuid', v_uuid, 'version', p.version)
                   ELSE jsonb_build_object('uuid', v_uuid, 'version', p.version + 1)
               END
           )
      INTO v_changes
      FROM plan p;

    RETURN jsonb_build_object(
        'uuid', v_uuid,
        'entity_id', v_entity_id,
        'payload', COALESCE(v_changes, '{}'::jsonb)
    );
END;
$func$;
SQL;
    }
}

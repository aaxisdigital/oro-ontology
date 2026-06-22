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
        FOR v_key, v_new_val IN SELECT * FROM jsonb_each(p_new) LOOP
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
    v_old_val jsonb;
BEGIN
    IF p_old IS NULL OR jsonb_typeof(p_old) <> 'object' THEN
        RETURN COALESCE(p_new, p_old);
    END IF;
    IF p_new IS NULL OR jsonb_typeof(p_new) <> 'object' THEN
        RETURN p_old;
    END IF;

    v_result := p_old;
    FOR v_key, v_new_val IN SELECT * FROM jsonb_each(p_new) LOOP
        IF v_result ? v_key THEN
            v_old_val := v_result -> v_key;
            IF jsonb_typeof(v_new_val) = 'object' AND jsonb_typeof(v_old_val) = 'object' THEN
                v_result := jsonb_set(v_result, ARRAY[v_key], aaxis_ontology_jsonb_deep_merge(v_old_val, v_new_val));
            ELSE
                v_result := jsonb_set(v_result, ARRAY[v_key], v_new_val);
            END IF;
        ELSE
            v_result := v_result || jsonb_build_object(v_key, v_new_val);
        END IF;
    END LOOP;

    RETURN v_result;
END;
$func$;
SQL;
    }

    /**
     * Validates an inbound Ontology data flow message and upserts the records into aaxis_ontology_data,
     * archiving the previous values of changed keys into aaxis_ontology_data_history.
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
    v_changes jsonb := '{}'::jsonb;
    v_idx int;
    v_uid text;
    v_item jsonb;
    v_existing_id int;
    v_existing_payload jsonb;
    v_existing_version int;
    v_existing_uuid text;
    v_existing_updated_at timestamp;
    v_diff jsonb;
    v_history_diff jsonb;
    v_new_payload jsonb;
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

    FOR v_idx IN 0 .. jsonb_array_length(v_unique_ids) - 1 LOOP
        v_uid  := v_unique_ids ->> v_idx;
        v_item := v_payload -> v_idx;

        SELECT d.id, d.payload, d.version, d.uuid, d.updated_at
            INTO v_existing_id, v_existing_payload, v_existing_version, v_existing_uuid, v_existing_updated_at
        FROM aaxis_ontology_data d
        WHERE d.entity_id = v_entity_id AND d.unique_id = v_uid;

        IF NOT FOUND THEN
            INSERT INTO aaxis_ontology_data (entity_id, unique_id, uuid, version, payload, updated_at)
            VALUES (v_entity_id, v_uid, v_uuid, 1, v_item, v_updated_at);

            v_changes := v_changes || jsonb_build_object(
                v_uid, jsonb_build_object('uuid', v_uuid, 'payload', v_item, 'version', 1)
            );
        ELSIF v_existing_payload @> v_item THEN
            v_changes := v_changes || jsonb_build_object(v_uid, 'null'::jsonb);
        ELSE
            v_diff := aaxis_ontology_jsonb_diff(v_existing_payload, v_item);
            v_history_diff := aaxis_ontology_jsonb_diff_previous(v_existing_payload, v_item);

            INSERT INTO aaxis_ontology_data_history (entity_id, unique_id, uuid, version, payload, updated_at)
            VALUES (v_entity_id, v_uid, v_existing_uuid, v_existing_version, v_history_diff, v_existing_updated_at);

            v_new_payload := aaxis_ontology_jsonb_deep_merge(v_existing_payload, v_item);

            UPDATE aaxis_ontology_data
            SET payload = v_new_payload,
                uuid = v_uuid,
                updated_at = v_updated_at,
                version = v_existing_version + 1
            WHERE id = v_existing_id;

            v_changes := v_changes || jsonb_build_object(
                v_uid, jsonb_build_object('uuid', v_uuid, 'payload', v_diff, 'version', v_existing_version + 1)
            );
        END IF;
    END LOOP;

    RETURN jsonb_build_object(
        'uuid', v_uuid,
        'entity_id', v_entity_id,
        'payload', v_changes
    );
END;
$func$;
SQL;
    }
}

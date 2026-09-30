<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * The per-type completeness rules of a flow step's config: what {@see FlowStepValidator} checks to
 * decide whether a step can RUN. Pure and DB/container-free — whether a connector, subflow or
 * service actually exists is a runtime check.
 *
 * A missing config is NOT a free pass: a type that needs parameters is exactly as broken
 * never-configured as it is half-configured (fresh drops start red and the flow cannot run until
 * they are completed). Types whose rules accept an empty config — triggers via
 * {@see onlyEnabled()}, parameterless placeholders via the default arm — stay valid.
 *
 * @internal
 */
final class FlowStepConfigRules
{
    /**
     * "flowUuid" is reserved (every execution seeds its uuid into the context under it) and so is
     * "choiceResults" (choice steps record their branch verdicts there); the legacy "flow-uuid"
     * spelling stays rejected too.
     */
    private const array RESERVED_DESTINATIONS = ['flowuuid', 'flow-uuid', 'choiceresults'];

    /** Endpoint {params} are seeded into the context by name: they must not shadow these. */
    private const array RESERVED_ENDPOINT_PARAMS = [
        'body', 'headers', 'queryparams', 'oauthapplication', 'flowuuid', 'flow-uuid', 'choiceresults', 'payload',
    ];

    /** A foreach item must not shadow the injected `index` or a reserved context name. */
    private const array RESERVED_LOOP_ITEMS = ['index', 'flowuuid', 'flow-uuid', 'choiceresults'];

    /**
     * @param array<string, mixed> $config the step's config ([] when it has none)
     */
    public static function isValid(mixed $type, array $config): bool
    {
        return match ($type) {
            'cron' => self::scheduleOk($config),
            'entity_change' => self::entityChangeOk($config),
            // The remaining triggers carry only the enabled flag (bool when set; a missing flag
            // reads as DISABLED at evaluation, not as invalid — old flows stay green).
            'subflow' => self::boolOk($config, 'enabled'),
            'endpoint' => self::endpointOk($config),
            // Choice: the success expression is the whole config (syntax checked separately as
            // DWL); the branch links live in the design, not here.
            'choice' => self::filled($config, 'expression'),
            'dwl_transform' => self::filled($config, 'code') && self::destinationOk($config),
            'entity_read' => self::destinationOk($config) && self::entityReadOk($config),
            'entity_write' => self::destinationOk($config) && self::entityWriteOk($config),
            // "HTTP Request": a rest_api connector call — connector/operation/path/body shape, the
            // response stored under the destination.
            'invoke' => self::destinationOk($config) && self::connectorOk($config),
            'file_read', 'file_list', 'file_delete' => self::destinationOk($config) && self::fileOpOk($config),
            'file_write' => self::destinationOk($config) && self::fileOpOk($config)
                && self::filled($config, 'content') && self::boolOk($config, 'content_dwl'),
            'file_rename' => self::destinationOk($config) && self::fileOpOk($config)
                && self::filled($config, 'new_name') && self::boolOk($config, 'new_name_dwl'),
            // "Call Subflow": which subflow to invoke (existence/type/enabled are RUNTIME checks).
            'sub_flow' => self::selected($config, 'subflow'),
            'foreach' => self::foreachOk($config),
            'invoke_php' => self::invokePhpOk($config),
            // "Logger": the message text (DWL-capable via its toggle).
            'logger' => self::filled($config, 'message') && self::boolOk($config, 'message_dwl'),
            // "Event": the value emitted as a log-message flow event (DWL-capable via toggle).
            'event' => self::filled($config, 'value') && self::boolOk($config, 'value_dwl'),
            'ms_teams' => self::msTeamsOk($config),
            'sql_query' => self::sqlQueryOk($config),
            default => true,
        };
    }

    private static function filled(array $config, string $key): bool
    {
        return \is_string($config[$key] ?? null) && trim($config[$key]) !== '';
    }

    /** Enum keys are lenient when ABSENT (configs saved by older editors) but strict when set. */
    private static function enumOk(array $config, string $key, array $allowed): bool
    {
        return !isset($config[$key]) || \in_array($config[$key], $allowed, true);
    }

    /** The DWL on/off toggles (body_dwl / content_dwl …) are lenient when absent, bool when set. */
    private static function boolOk(array $config, string $key): bool
    {
        return !isset($config[$key]) || \is_bool($config[$key]);
    }

    /** A picked connector/subflow id: any non-empty scalar. */
    private static function selected(array $config, string $key): bool
    {
        return is_scalar($config[$key] ?? null) && (string) $config[$key] !== '';
    }

    /**
     * A trigger holding ONLY its enabled flag counts as "not configured yet" (the editor seeds
     * {enabled: true} the moment a trigger is dropped, so the flow starts armed without the fresh
     * tile turning red for the fields the user has not filled in yet). Endpoint drops also seed the
     * Response EXAMPLE, so that key is ignored by the test too.
     */
    private static function onlyEnabled(array $config): bool
    {
        return array_diff_key($config, ['enabled' => 0, 'response' => 0]) === [];
    }

    private static function destinationOk(array $config): bool
    {
        return self::filled($config, 'destination')
            && !\in_array(strtolower(trim((string) $config['destination'])), self::RESERVED_DESTINATIONS, true);
    }

    private static function connectorOk(array $config): bool
    {
        return self::selected($config, 'connector')
            && self::filled($config, 'path')
            && self::enumOk($config, 'operation', ['get', 'put', 'post', 'patch', 'delete'])
            && self::enumOk($config, 'body', ['empty', 'json', 'text', 'xml'])
            && self::boolOk($config, 'body_dwl');
    }

    /**
     * The entity READ rules — shared by the generic reader's entity variant and the typed
     * entity_read step (identical config, minus the reader discriminator).
     */
    private static function entityReadOk(array $config): bool
    {
        return self::filled($config, 'system') && self::filled($config, 'entity')
            && self::enumOk($config, 'mode', ['all', 'by_id', 'by_attribute'])
            && (($config['mode'] ?? 'all') !== 'by_id' || self::filled($config, 'record_id'))
            // "By attribute" carries the attribute to compare and the value to match.
            && (($config['mode'] ?? 'all') !== 'by_attribute'
                || (self::filled($config, 'attribute') && self::filled($config, 'attr_value')))
            // "All" extras, each optional: order_by attribute (+ direction) and limit.
            && (!isset($config['order_by']) || \is_string($config['order_by']))
            && self::enumOk($config, 'order_dir', ['asc', 'desc'])
            && self::enumOk($config, 'limit', [1, 10, 100, 1000]);
    }

    /** The entity WRITE rules — shared by the generic writer's entity variant and entity_write. */
    private static function entityWriteOk(array $config): bool
    {
        return self::filled($config, 'system') && self::filled($config, 'entity')
            && self::filled($config, 'content') && self::boolOk($config, 'content_dwl');
    }

    /** File Operations share a connector + DWL-capable path core. */
    private static function fileOpOk(array $config): bool
    {
        return self::selected($config, 'connector') && self::filled($config, 'path') && self::boolOk($config, 'path_dwl');
    }

    /**
     * Schedule: interval (value + unit) or cron (expression; also the LEGACY shape, which carries
     * no mode). Expression syntax is checked separately with Cron\CronExpression.
     */
    private static function scheduleOk(array $config): bool
    {
        return self::boolOk($config, 'enabled') && (self::onlyEnabled($config) || match ($config['mode'] ?? 'cron') {
            'interval' => \is_int($config['value'] ?? null) && $config['value'] >= 1
                && \in_array($config['unit'] ?? null, ['minute', 'hour', 'day', 'week', 'month', 'year'], true),
            'cron' => self::filled($config, 'expression'),
            default => false,
        });
    }

    private static function entityChangeOk(array $config): bool
    {
        return self::boolOk($config, 'enabled')
            && (self::onlyEnabled($config) || (self::filled($config, 'system') && self::filled($config, 'entity')));
    }

    /**
     * Endpoint trigger: method + path (+ the public switch). Path segments are literals or {param}
     * placeholders; a param name must be a valid identifier and not shadow a reserved context
     * variable (the params are seeded into the context by name).
     */
    private static function endpointOk(array $config): bool
    {
        return self::boolOk($config, 'enabled') && (self::onlyEnabled($config) || (
            self::enumOk($config, 'method', ['GET', 'POST', 'PUT', 'QUERY', 'PATCH', 'DELETE'])
            && self::boolOk($config, 'public')
            && self::endpointPathOk((string) ($config['path'] ?? ''))
            && (!isset($config['response']) || \is_string($config['response']))
        ));
    }

    /**
     * Endpoint trigger path: non-empty; each segment a literal ([A-Za-z0-9_.-]+) or a "{param}"
     * placeholder whose name is a valid identifier and not a reserved context variable.
     */
    private static function endpointPathOk(string $path): bool
    {
        $path = trim($path, " \t/");
        if ($path === '') {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (!self::endpointSegmentOk($segment)) {
                return false;
            }
        }

        return true;
    }

    private static function endpointSegmentOk(string $segment): bool
    {
        if (preg_match('/^\{([a-zA-Z_]\w*)\}$/', $segment, $m) === 1) {
            return !\in_array(strtolower($m[1]), self::RESERVED_ENDPOINT_PARAMS, true);
        }

        return preg_match('/^[A-Za-z0-9_.-]+$/', $segment) === 1;
    }

    /**
     * "Foreach Loop": the subflow + the array variable + the per-element variable name (which
     * must not shadow the injected `index` or a reserved context name).
     */
    private static function foreachOk(array $config): bool
    {
        return self::selected($config, 'subflow')
            && self::filled($config, 'array')
            && self::filled($config, 'item')
            && !\in_array(strtolower(trim((string) $config['item'])), self::RESERVED_LOOP_ITEMS, true);
    }

    /**
     * "Invoke PHP": the service class + method (existence/callability are RUNTIME checks — this
     * stays container-free) and the optional parameters DWL.
     */
    private static function invokePhpOk(array $config): bool
    {
        return self::destinationOk($config) && self::filled($config, 'class') && self::filled($config, 'method')
            && (!isset($config['params']) || \is_string($config['params']));
    }

    /**
     * "MS Teams": the context variable holding the message (stringified at run time) plus the
     * Power Automate webhook — a well-formed HTTPS URL (plain text, not DWL; the domain is
     * deliberately NOT restricted to powerplatform.com).
     */
    private static function msTeamsOk(array $config): bool
    {
        return self::filled($config, 'message')
            && \is_string($config['webhook'] ?? null)
            && filter_var(trim($config['webhook']), FILTER_VALIDATE_URL) !== false
            && str_starts_with(strtolower(trim($config['webhook'])), 'https://');
    }

    /** "SQL Query": a database connector, the (DWL-capable) SQL, optional bindings. */
    private static function sqlQueryOk(array $config): bool
    {
        return self::destinationOk($config)
            && self::selected($config, 'connector')
            && self::filled($config, 'sql') && self::boolOk($config, 'sql_dwl')
            && (!isset($config['binding']) || \is_string($config['binding']))
            && self::boolOk($config, 'binding_dwl');
    }
}

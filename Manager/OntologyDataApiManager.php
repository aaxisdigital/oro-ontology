<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyDataUpsertTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;

/**
 * Reusable core for the Ontology Data HTTP API (read / upsert / query), addressing records by
 * *system name + entity name*. Holds no HTTP/ACL concerns so it can be called directly from any
 * other part of the project; failures surface as {@see OntologyApiException} (which carries the HTTP
 * status the controller should map to).
 *
 * Upserts are not written synchronously: this validates the input and publishes a message to the
 * existing {@see OntologyDataUpsertTopic}, whose processor performs the actual upsert.
 */
class OntologyDataApiManager
{
    /** Allowed filter operators mapped to their SQL operator. */
    private const array COMPARATORS = [
        'EQ' => '=',
        'LIKE' => 'LIKE',
        '<' => '<',
        '>' => '>',
    ];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly MessageProducerInterface $producer,
        private readonly ConfigManager $configManager,
        private readonly OntologyAttributeReconciler $attributeReconciler,
        private readonly OntologyDataEventRecorder $events,
        private readonly OroEntityReader $oroReader,
        private readonly OroEntityWriter $oroWriter,
    ) {
    }

    /**
     * Reads a single record identified by system + entity name and unique id, returning its payload.
     *
     * An INTERNAL system's data lives in the OroCommerce entity itself (the ontology entity's name
     * is the class), never in `aaxis_ontology_data` — so those reads go to {@see OroEntityReader}.
     *
     * @return array<int|string, mixed>|null
     *
     * @throws OntologyApiException
     */
    public function read(string $systemName, string $entityName, string $uniqueId): ?array
    {
        $entity = $this->resolveEntity($systemName, $entityName, false);

        if ($this->isInternal($entity)) {
            $payload = $this->oroReader->readById($entity, $uniqueId);
            if ($payload === null) {
                throw OntologyApiException::recordNotFound($uniqueId);
            }

            return $payload;
        }

        /** @var OntologyData|null $record */
        $record = $this->doctrine->getRepository(OntologyData::class)
            ->findOneBy(['entity' => $entity, 'uniqueId' => $uniqueId]);

        if ($record === null) {
            throw OntologyApiException::recordNotFound($uniqueId);
        }

        return $record->getPayload();
    }

    /** An internal system's records live in the Oro entity itself, not the ontology data store. */
    private function isInternal(OntologyEntity $entity): bool
    {
        $system = $entity->getSystem();

        return $system !== null && !$system->isExternal();
    }

    /**
     * Resolves a flow by name and ensures it is enabled. Entry points call this to gate their calls
     * on the flow's mode — the back-office "Add Data" UI uses {@see OntologyFlow::NAME_MANUAL} and
     * the HTTP API uses {@see OntologyFlow::NAME_REST_API}; a disabled flow blocks those calls.
     *
     * @throws OntologyApiException when the flow is missing (misconfigured) or disabled
     */
    public function requireEnabledFlow(string $flowName): OntologyFlow
    {
        $flow = $this->doctrine->getRepository(OntologyFlow::class)->findOneBy(['name' => $flowName]);
        if ($flow === null) {
            throw OntologyApiException::flowMisconfigured($flowName);
        }
        if (!$flow->isEnabled()) {
            throw OntologyApiException::flowDisabled($flowName);
        }

        return $flow;
    }

    /**
     * Validates one or more record payloads and queues a single upsert message for the async
     * pipeline, resolving (and optionally auto-creating) the entity by name. The unique id of each
     * record is inferred from its payload using the entity's configured unique attribute (no id is
     * taken from the caller). The message/event is stamped with the given flow. Returns the batch
     * message uuid.
     *
     * @param array<int, array<int|string, mixed>> $records list of payload objects (one per record)
     *
     * @throws OntologyApiException
     */
    public function upsert(string $systemName, string $entityName, array $records, OntologyFlow $flow): string
    {
        $entity = $this->resolveEntity(
            $systemName,
            $entityName,
            (bool) $this->configManager->get('aaxis_ontology.api_auto_create')
        );

        return $this->upsertRecords($entity, $records, $flow);
    }

    /**
     * Same as {@see upsert()} but for an already-resolved entity (e.g. the back-office "Add Data"
     * modal, which picks the entity from a dropdown). Infers each record's unique id from its payload
     * via the entity's unique attribute, then queues one upsert message stamped with the flow.
     * Returns the batch uuid.
     *
     * @param array<int, array<int|string, mixed>> $records list of payload objects (one per record)
     * @param string|null                          $uuid    stamp the batch with this uuid instead of
     *                                                      minting one — lets a flow execution group
     *                                                      SEVERAL writes under one identity
     *
     * @throws OntologyApiException
     */
    public function upsertRecords(OntologyEntity $entity, array $records, OntologyFlow $flow, ?string $uuid = null): string
    {
        // Internal-system records live in the Oro entity itself and are only written SYNCHRONOUSLY
        // ({@see upsertRecordsSync}, used by flow writer steps) — the queued path would silently
        // store rows nothing ever reads, so it refuses loudly instead.
        if ($this->isInternal($entity)) {
            throw OntologyApiException::invalidPayload(sprintf(
                'Entity "%s" belongs to an internal system — its records live in OroCommerce itself'
                . ' and can only be written by a flow writer step (update of existing records).',
                (string) $entity->getName()
            ));
        }

        [$uuid, $uniqueIds, $payloads] = $this->prepareUpsertBatch($entity, $records, $uuid);

        // The async upsert flow expects unique_id and payload as parallel arrays (one per record).
        $this->producer->send(OntologyDataUpsertTopic::getName(), [
            'flow_id' => $flow->getId(),
            'uuid' => $uuid,
            'entity_id' => $entity->getId(),
            'unique_id' => $uniqueIds,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'payload' => $payloads,
        ]);

        return $uuid;
    }

    /**
     * Like {@see upsertRecords()} but SYNCHRONOUS — for flow executions, which report the write's
     * actual outcome in their step receipt. Calls the aaxis_ontology_data_upsert function directly
     * (no queue) and records the SAME event row the async consumer would, completed on the spot
     * (changed_ids + finished_at). Validation errors from the function throw instead of vanishing
     * into a log line.
     *
     * @param array<int, array<int|string, mixed>> $records
     *
     * @return array{uuid: string, seen: array<int, string>, changed: array<int, string>}
     *         seen = every unique id in the batch; changed = the ids actually created or updated
     *         (unchanged records are excluded)
     *
     * @throws OntologyApiException
     */
    public function upsertRecordsSync(OntologyEntity $entity, array $records, OntologyFlow $flow, ?string $uuid = null): array
    {
        if ($this->isInternal($entity)) {
            return $this->updateOroEntitySync($entity, $records, $flow, $uuid);
        }

        [$uuid, $uniqueIds, $payloads] = $this->prepareUpsertBatch($entity, $records, $uuid);

        $connection = $this->connection();
        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Encoded BEFORE the event is opened: JSON_THROW_ON_ERROR can reject the batch (an INF
        // from a DWL float overflow, a >512-deep payload…) and a throw between open() and the
        // try/finally below would leave an event open forever — the very state this class exists
        // to prevent. Failing here means no event row at all, which is the truth: nothing ran.
        $input = json_encode([
            'flow_id' => $flow->getId(),
            'uuid' => $uuid,
            'entity_id' => $entity->getId(),
            'unique_id' => $uniqueIds,
            'updated_at' => $startedAt->format(\DateTimeInterface::ATOM),
            'payload' => $payloads,
        ], JSON_THROW_ON_ERROR);

        $eventId = $this->events->open($flow->getId(), $uuid, $entity->getId(), $uniqueIds, $startedAt);
        $changed = [];
        try {
            $raw = $connection->fetchOne('SELECT aaxis_ontology_data_upsert(CAST(? AS jsonb))', [$input]);
            $response = json_decode((string) $raw, true);
            $outcome = $this->events->parseUpsertResponse(\is_array($response) ? $response : []);
            $changed = $outcome['changed'];

            if ($outcome['errors'] !== null) {
                throw OntologyApiException::invalidPayload(implode('; ', $outcome['errors']));
            }
        } finally {
            // Closed however this ends — rejected batch or a database failure included — so an
            // event never stays open (an open event now reads as "still running").
            $this->events->close($eventId, $changed);
        }

        return ['uuid' => $uuid, 'seen' => $uniqueIds, 'changed' => $changed];
    }

    /**
     * The internal-system arm of {@see upsertRecordsSync}: UPDATES the OroCommerce rows behind the
     * entity through {@see OroEntityWriter} (existing records only — no creation), with the same
     * validation, event recording and receipt shape as the store path. The attribute definitions
     * are NOT synced from the payloads: the Oro entity itself is the contract, and an 88-column
     * payload must not materialize 88 configured attributes.
     *
     * @param array<int, array<int|string, mixed>> $records
     *
     * @return array{uuid: string, seen: array<int, string>, changed: array<int, string>}
     *
     * @throws OntologyApiException
     */
    private function updateOroEntitySync(OntologyEntity $entity, array $records, OntologyFlow $flow, ?string $uuid): array
    {
        [$uuid, $uniqueIds, $payloads] = $this->prepareUpsertBatch($entity, $records, $uuid, false);

        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $eventId = $this->events->open($flow->getId(), $uuid, $entity->getId(), $uniqueIds, $startedAt);
        $changed = [];
        try {
            $changed = $this->oroWriter->update($entity, $uniqueIds, $payloads);
        } finally {
            $this->events->close($eventId, $changed);
        }

        return ['uuid' => $uuid, 'seen' => $uniqueIds, 'changed' => $changed];
    }

    /**
     * The shared per-record validation of a write batch: unique attribute present and non-empty on
     * every record, no repeated ids, attribute contract enforced and — unless $syncAttributes is
     * off (internal-system writes) — missing attribute definitions synced from the payloads.
     * Returns the batch uuid (validated or minted) and the parallel id/payload arrays.
     *
     * @param array<int, array<int|string, mixed>> $records
     *
     * @return array{0: string, 1: array<int, string>, 2: array<int, array<int|string, mixed>>}
     *
     * @throws OntologyApiException
     */
    private function prepareUpsertBatch(OntologyEntity $entity, array $records, ?string $uuid, bool $syncAttributes = true): array
    {
        // The aaxis_ontology_data_upsert PG function also enforces this shape — reject a malformed
        // caller-provided uuid here, where the caller can see it.
        if ($uuid !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            throw OntologyApiException::invalidPayload(sprintf('"%s" is not a valid batch uuid.', $uuid));
        }
        if ($records === []) {
            throw OntologyApiException::invalidPayload('At least one record is required.');
        }

        $uniqueAttribute = (string) $entity->getUniqueAttribute();
        if ($uniqueAttribute === '') {
            throw OntologyApiException::invalidPayload(
                sprintf('Entity "%s" has no unique attribute configured.', (string) $entity->getName())
            );
        }

        $uniqueIds = [];
        $payloads = [];
        $seenAt = [];
        foreach (array_values($records) as $i => $record) {
            if (!\is_array($record)) {
                throw OntologyApiException::invalidPayload(sprintf('Record #%d must be a JSON object.', $i + 1));
            }

            $idValue = $record[$uniqueAttribute] ?? null;
            if ($idValue === null || $idValue === '' || !\is_scalar($idValue)) {
                throw OntologyApiException::invalidPayload(sprintf(
                    'Record #%d is missing a non-empty value for the unique attribute "%s".',
                    $i + 1,
                    $uniqueAttribute
                ));
            }

            // A batch cannot repeat a unique id — the aaxis_ontology_data_upsert function rejects
            // the WHOLE batch, so fail fast here where the caller can see it.
            $idString = (string) $idValue;
            if (isset($seenAt[$idString])) {
                throw OntologyApiException::invalidPayload(sprintf(
                    'Record #%d duplicates the unique attribute value "%s" of record #%d — unique ids cannot repeat in one operation.',
                    $i + 1,
                    $idString,
                    $seenAt[$idString]
                ));
            }
            $seenAt[$idString] = $i + 1;

            // Enforce the entity's attribute contract (required attributes + declared types) before
            // accepting the write.
            $this->attributeReconciler->assertValid($entity, $record);

            $uniqueIds[] = $idString;
            $payloads[] = $record;
        }

        // Reconcile the entity's attribute definitions with the data being written: any attribute
        // present in the payloads but not yet defined is created (datatype undefined, not required).
        if ($syncAttributes) {
            $this->attributeReconciler->syncFromRecords($entity, $payloads);
        }

        return [$uuid ?? $this->generateUuid(), $uniqueIds, $payloads];
    }

    /**
     * Queries records of a system/entity, filtered by payload attributes; returns the matching
     * records' payloads (ordered).
     *
     * @param array<int, array{attribute?: mixed, compare?: mixed, value?: mixed}> $filters
     *
     * @return array<int, array<int|string, mixed>|null>
     *
     * @throws OntologyApiException
     */
    public function query(
        string $systemName,
        string $entityName,
        array $filters,
        ?string $orderBy,
        int $page,
        int $pageSize,
    ): array {
        $entity = $this->resolveEntity($systemName, $entityName, false);

        $maxPageSize = max(1, (int) $this->configManager->get('aaxis_ontology.api_query_max_page_size'));
        $pageSize = max(1, min($pageSize, $maxPageSize));
        $page = max(1, $page);
        $offset = ($page - 1) * $pageSize;

        $where = ['entity_id = :entity_id'];
        $params = ['entity_id' => $entity->getId()];
        $types = ['entity_id' => ParameterType::INTEGER];

        foreach (array_values($filters) as $i => $filter) {
            $this->appendFilter($i, \is_array($filter) ? $filter : [], $where, $params);
        }

        // page_size/offset are validated integers, inlined to avoid Postgres LIMIT param typing issues.
        $sql = sprintf(
            'SELECT payload FROM aaxis_ontology_data WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
            implode(' AND ', $where),
            $this->buildOrderBy($orderBy, $params),
            $pageSize,
            $offset
        );

        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        return array_map(fn (array $row): ?array => $this->decodePayload($row['payload']), $rows);
    }

    /**
     * Reads an entity's records for a FLOW execution. Unlike {@see query()} — the outside-facing
     * API, capped by the configured max page size — this is NOT paged: a flow step gets every
     * record unless it asks for a limit itself. Optional ordering by ONE payload attribute uses
     * jsonb comparison (numbers order numerically, strings lexically), with the row id as a
     * stable tiebreaker. Internal-system entities read from the Oro entity itself instead
     * ({@see OroEntityReader}), ordered by a column of the same name.
     *
     * @return array<int, array<int|string, mixed>|null>
     *
     * @throws OntologyApiException
     */
    public function queryForFlow(
        string $systemName,
        string $entityName,
        ?string $orderBy = null,
        string $direction = 'ASC',
        ?int $limit = null,
    ): array {
        $entity = $this->resolveEntity($systemName, $entityName, false);

        if ($this->isInternal($entity)) {
            return $this->oroReader->readAll($entity, $orderBy, $direction, $limit);
        }

        $sql = 'SELECT payload FROM aaxis_ontology_data WHERE entity_id = :entity_id';
        $params = ['entity_id' => $entity->getId()];
        $types = ['entity_id' => ParameterType::INTEGER];

        $orderBy = trim((string) $orderBy);
        if ($orderBy !== '') {
            $params['orderAttr'] = $orderBy;
            $sql .= sprintf(' ORDER BY payload -> :orderAttr %s, id ASC', strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
        } else {
            $sql .= ' ORDER BY id ASC';
        }
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . $limit; // validated integer, inlined (Postgres LIMIT param typing)
        }

        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        return array_map(fn (array $row): ?array => $this->decodePayload($row['payload']), $rows);
    }

    /**
     * Reads every record whose $attribute equals $value, for a FLOW execution ("by attribute"
     * reader mode) — a LIST, [] when nothing matches (an attribute is not necessarily unique).
     * External entities compare the jsonb attribute's text form against the value; internal ones
     * compare the Oro entity's column of that name ({@see OroEntityReader::readByAttribute}).
     *
     * @return array<int, array<int|string, mixed>|null>
     *
     * @throws OntologyApiException
     */
    public function queryForFlowByAttribute(string $systemName, string $entityName, string $attribute, string $value): array
    {
        $entity = $this->resolveEntity($systemName, $entityName, false);

        if ($this->isInternal($entity)) {
            return $this->oroReader->readByAttribute($entity, $attribute, $value);
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT payload FROM aaxis_ontology_data'
            . ' WHERE entity_id = :entity_id AND payload ->> :attr = :value ORDER BY id ASC',
            ['entity_id' => $entity->getId(), 'attr' => $attribute, 'value' => $value],
            ['entity_id' => ParameterType::INTEGER]
        );

        return array_map(fn (array $row): ?array => $this->decodePayload($row['payload']), $rows);
    }

    /**
     * Resolves the entity by system + entity name. Disabled system/entity always errors. Unknown
     * system/entity errors unless $allowAutoCreate, in which case the missing ones are created
     * (enabled), with the entity's unique_attribute taken from configuration.
     *
     * @throws OntologyApiException
     */
    private function resolveEntity(string $systemName, string $entityName, bool $allowAutoCreate): OntologyEntity
    {
        $systemName = trim($systemName);
        $entityName = trim($entityName);

        $system = $this->doctrine->getRepository(OntologySystem::class)->findOneBy(['name' => $systemName]);
        if ($system === null) {
            if (!$allowAutoCreate) {
                throw OntologyApiException::unknownSystem($systemName);
            }
            $system = $this->createSystem($systemName);
        } elseif (!$system->isEnabled()) {
            throw OntologyApiException::disabledSystem($systemName);
        }

        $entity = $this->doctrine->getRepository(OntologyEntity::class)
            ->findOneBy(['system' => $system, 'name' => $entityName]);
        if ($entity === null) {
            if (!$allowAutoCreate) {
                throw OntologyApiException::unknownEntity($systemName, $entityName);
            }

            return $this->createEntity($system, $entityName);
        }

        if (!$entity->isEnabled()) {
            throw OntologyApiException::disabledEntity($systemName, $entityName);
        }

        return $entity;
    }

    private function createSystem(string $name): OntologySystem
    {
        $system = (new OntologySystem())->setName($name)->setEnabled(true);
        $em = $this->doctrine->getManagerForClass(OntologySystem::class);
        $em->persist($system);
        $em->flush();

        return $system;
    }

    private function createEntity(OntologySystem $system, string $name): OntologyEntity
    {
        $uniqueAttribute = (string) $this->configManager->get('aaxis_ontology.api_auto_create_unique_attribute');
        $entity = (new OntologyEntity())
            ->setSystem($system)
            ->setName($name)
            ->setUniqueAttribute($uniqueAttribute !== '' ? $uniqueAttribute : 'id')
            ->setEnabled(true);
        $em = $this->doctrine->getManagerForClass(OntologyEntity::class);
        $em->persist($entity);
        $em->flush();

        return $entity;
    }

    /**
     * Validates one filter and appends its SQL fragment + bound parameters.
     *
     * @param array<string, mixed> $filter
     * @param array<int, string>   $where
     * @param array<string, mixed> $params
     *
     * @throws OntologyApiException
     */
    private function appendFilter(int $index, array $filter, array &$where, array &$params): void
    {
        $attribute = isset($filter['attribute']) ? trim((string) $filter['attribute']) : '';
        if ($attribute === '') {
            throw OntologyApiException::invalidQuery('Each filter requires a non-empty "attribute".');
        }

        $compare = (string) ($filter['compare'] ?? 'EQ');
        if (!isset(self::COMPARATORS[$compare])) {
            throw OntologyApiException::invalidQuery(sprintf('Unsupported filter compare "%s".', $compare));
        }

        $value = $filter['value'] ?? null;
        if ($value !== null && !\is_scalar($value)) {
            throw OntologyApiException::invalidQuery('Filter "value" must be a scalar.');
        }

        $attrKey = 'attr' . $index;
        $valKey = 'val' . $index;
        $params[$attrKey] = $attribute;

        if (($compare === '<' || $compare === '>') && is_numeric($value)) {
            // Compare numerically, but only for rows whose value looks numeric, to avoid cast errors.
            // The bound value is cast in-SQL so it stays a plain (text) parameter.
            $where[] = sprintf(
                "(payload->>:%s ~ '^-?[0-9]+(\\.[0-9]+)?$' AND (payload->>:%s)::numeric %s CAST(:%s AS numeric))",
                $attrKey,
                $attrKey,
                self::COMPARATORS[$compare],
                $valKey
            );
            $params[$valKey] = (string) $value;
        } else {
            $where[] = sprintf('payload->>:%s %s :%s', $attrKey, self::COMPARATORS[$compare], $valKey);
            $params[$valKey] = (string) $value;
        }
    }

    /**
     * Builds the ORDER BY clause. Defaults to "id ASC"; an "<attribute> [ASC|DESC]" string orders by
     * that payload attribute. The attribute is bound; the direction is whitelisted.
     *
     * @param array<string, mixed> $params
     *
     * @throws OntologyApiException
     */
    private function buildOrderBy(?string $orderBy, array &$params): string
    {
        $orderBy = trim((string) $orderBy);
        if ($orderBy === '') {
            return 'id ASC';
        }

        $parts = preg_split('/\s+/', $orderBy) ?: [];
        $attribute = trim((string) ($parts[0] ?? ''));
        $direction = strtoupper(trim((string) ($parts[1] ?? 'ASC')));

        if ($attribute === '') {
            throw OntologyApiException::invalidQuery('orderBy requires an attribute name.');
        }
        if (!\in_array($direction, ['ASC', 'DESC'], true)) {
            throw OntologyApiException::invalidQuery(sprintf('Unsupported orderBy direction "%s".', $direction));
        }

        $params['orderAttr'] = $attribute;

        return sprintf('payload->>:orderAttr %s', $direction);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function decodePayload(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function connection(): Connection
    {
        return $this->doctrine->getConnection();
    }

    /**
     * Generates an RFC 4122 version 4 UUID (mirrors OntologyDataController). Public so callers
     * batching SEVERAL upserts under one identity (e.g. a flow execution) can mint it up front.
     */
    public function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0f) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

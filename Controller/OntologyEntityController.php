<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyDataHistory;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore;
use Aaxis\Bundle\OntologyBundle\Manager\DwlOutputFormatter;
use Aaxis\Bundle\OntologyBundle\Manager\DwlScriptGuard;
use Aaxis\Bundle\OntologyBundle\Manager\OroEntityReader;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\EntityBundle\Provider\EntityFieldProvider;
use Oro\Bundle\EntityBundle\Provider\EntityProvider;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller for the Ontology "Entity".
 *
 * The page is a TypeScript-driven UI (entity-component) backed by the JSON endpoints below;
 * entities own a 1:N collection of attributes edited inline in the record form.
 */
class OntologyEntityController extends AbstractOntologyController
{
    // expose: the Systems grid links here (?system=…) via routing.generate() in TypeScript.
    #[Route(path: '/entities', name: 'aaxis_ontology_entities', options: ['expose' => true])]
    #[Template('@AaxisOntology/Entity/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_entity_view', type: 'entity', class: OntologyEntity::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return [];
    }

    #[Route(path: '/entities/api/list', name: 'aaxis_ontology_entity_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_entity_view')]
    public function listAction(): JsonResponse
    {
        $entities = $this->registry()->getRepository(OntologyEntity::class)->findBy([], ['name' => 'ASC']);
        $systems = $this->registry()->getRepository(OntologySystem::class)->findBy([], ['name' => 'ASC']);

        // One grouped query for all external entities' record counts (internal ones are counted
        // against their own OroCommerce table, per entity, inside recordCount()).
        $externalCounts = $this->externalRecordCounts();

        return new JsonResponse([
            'entities' => array_map(
                fn (OntologyEntity $entity) => $this->serialize($entity, $this->recordCount($entity, $externalCounts)),
                $entities
            ),
            'systems' => array_map(
                static fn (OntologySystem $s) => [
                    'id' => $s->getId(),
                    'name' => $s->getName(),
                    'external' => $s->isExternal(),
                ],
                $systems
            ),
            'datatypes' => $this->datatypeOptions(),
        ]);
    }

    /**
     * Lists the OroCommerce entities (as at /admin/entity/config/) for the internal-system entity
     * picker. Value = entity class name; label = its human label.
     */
    #[Route(path: '/entities/api/oro-entities', name: 'aaxis_ontology_entity_oro_entities', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_entity_view')]
    public function oroEntitiesAction(): JsonResponse
    {
        $options = [];
        foreach ($this->entityProvider()->getEntities(false) as $entity) {
            $options[] = ['value' => $entity['name'], 'label' => (string) $entity['label']];
        }

        return new JsonResponse(['entities' => $options]);
    }

    /**
     * Lists the fields of an OroCommerce entity (by class name, `?entity=`), each with a value
     * (field name), label and the mapped ontology datatype. Used to constrain attribute names (and
     * pre-fill datatypes) when the selected system is internal.
     */
    #[Route(path: '/entities/api/oro-fields', name: 'aaxis_ontology_entity_oro_fields', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_entity_view')]
    public function oroFieldsAction(Request $request): JsonResponse
    {
        $entityClass = trim((string) $request->query->get('entity', ''));
        if ($entityClass === '') {
            return new JsonResponse(['fields' => []]);
        }

        return new JsonResponse(['fields' => $this->oroFieldOptions($entityClass)]);
    }

    #[Route(path: '/entities/api', name: 'aaxis_ontology_entity_api_create', options: ['expose' => true], methods: ['POST'])]
    #[Acl(id: 'aaxis_ontology_entity_create', type: 'entity', class: OntologyEntity::class, permission: 'CREATE')]
    #[CsrfProtection]
    public function apiCreateAction(Request $request): JsonResponse
    {
        return $this->saveFromJson(new OntologyEntity(), $request);
    }

    #[Route(
        path: '/entities/api/{id}',
        name: 'aaxis_ontology_entity_api_update',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['PUT', 'POST']
    )]
    #[Acl(id: 'aaxis_ontology_entity_update', type: 'entity', class: OntologyEntity::class, permission: 'EDIT')]
    #[CsrfProtection]
    public function apiUpdateAction(OntologyEntity $entity, Request $request): JsonResponse
    {
        return $this->saveFromJson($entity, $request);
    }

    #[Route(path: '/entities/delete/{id}', name: 'aaxis_ontology_entity_delete', requirements: ['id' => '\d+'], options: ['expose' => true], methods: ['DELETE'])]
    #[Acl(id: 'aaxis_ontology_entity_delete', type: 'entity', class: OntologyEntity::class, permission: 'DELETE')]
    #[CsrfProtection]
    public function deleteAction(OntologyEntity $entity): JsonResponse
    {
        return $this->deleteEntity($entity);
    }

    /**
     * DWL playground: evaluates a DataWeave script against the entity's stored records.
     *
     * Body `{script, limit}` — `limit` null/0 means "no limit" (the user opted out; can be slow on
     * large entities, which is why the UI defaults to 100). The script sees ONE binding, `payload`,
     * holding the list of record payloads, and its `output <mime>` header decides how the result is
     * rendered ({@see DwlOutputFormatter}) — so the on-screen Result and the exported file match.
     *
     * Script errors are NOT HTTP errors: they come back as `{success: false, error}` with a 200 so
     * the playground can show them in the Result area like any other outcome.
     *
     * Permissions: entity VIEW opens the page, but the response contains record CONTENT, so
     * `OntologyData` VIEW is required as well — otherwise entity-only metadata access would be
     * enough to read the data itself. Scripts are additionally screened by {@see DwlScriptGuard}
     * (the engine's `dw::Runtime::props()` would otherwise dump the process environment).
     */
    #[Route(
        path: '/entities/api/{id}/dwl',
        name: 'aaxis_ontology_entity_dwl',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['POST']
    )]
    #[AclAncestor('aaxis_ontology_entity_view')]
    #[CsrfProtection]
    public function dwlAction(OntologyEntity $entity, Request $request): JsonResponse
    {
        if (!$this->canReadRecordContent()) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid request body.'], 400);
        }
        $script = (string) ($body['script'] ?? '');
        if (trim($script) === '') {
            return new JsonResponse(['success' => false, 'error' => 'The script is empty.'], 400);
        }
        $refusal = $this->container->get(DwlScriptGuard::class)->check($script);
        if ($refusal !== null) {
            // A refusal is a script-level outcome, so it renders in the Result pane like any error.
            return new JsonResponse(['success' => false, 'error' => $refusal]);
        }

        $rawLimit = $body['limit'] ?? null;
        $limit = is_numeric($rawLimit) && (int) $rawLimit > 0 ? (int) $rawLimit : null;

        $total = $this->dwlPayloadTotal($entity);
        $payload = $this->loadEntityPayloads($entity, $limit);

        $formatter = $this->container->get(DwlOutputFormatter::class);
        $output = $formatter->detect($script);

        try {
            $result = $this->container->get(DwlTransformer::class)->transform($script, ['payload' => $payload]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'rows' => \count($payload),
                'total' => $total,
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'result' => $formatter->serialize($result, $output['format']),
            'format' => $output['format'],
            'mime' => $output['mime'],
            'extension' => $output['extension'],
            'rows' => \count($payload),
            'total' => $total,
            'truncated' => $limit !== null && $total > $limit,
        ]);
    }

    /**
     * Deletes every stored record of the entity (the "erase records" action on the Entities grid)
     * while keeping the entity and its attributes. Irreversible: there is no soft delete and the
     * version history goes with it.
     *
     * Permissions: the entity itself survives, so this is NOT an entity-delete — it is a data
     * delete, gated on `OntologyData` DELETE on top of the page's entity VIEW.
     *
     * Flow execution events (`aaxis_ontology_data_events`) are deliberately left alone: they are a
     * log of what the flows DID, not records of the entity.
     */
    #[Route(
        path: '/entities/api/{id}/records',
        name: 'aaxis_ontology_entity_purge_records',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['DELETE']
    )]
    #[AclAncestor('aaxis_ontology_entity_view')]
    #[CsrfProtection]
    public function purgeRecordsAction(OntologyEntity $entity): JsonResponse
    {
        if (!$this->isGranted('DELETE', 'entity:' . OntologyData::class)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied.'], 403);
        }

        // Bucket-backed entities: drop their objects too (records AND history). The DB deletes
        // below still run — they clear any rows left from before the backend switch.
        $bucketDeleted = 0;
        if (($entity->getSystem()?->isExternal() ?? false)
            && $this->container->get(BucketEntityDataStore::class)->isEnabled()) {
            // Even for force-db entities: erase means erase — stale bucket objects written
            // before the flag flipped must go too.
            $bucketDeleted = $this->container->get(BucketEntityDataStore::class)->purgeEntity($entity);
        }

        $em = $this->registry()->getManagerForClass(OntologyData::class);
        // DQL deletes: no hydration, since an entity can hold a very large number of records.
        $em->createQuery('DELETE FROM ' . OntologyDataHistory::class . ' h WHERE h.entity = :entity')
            ->setParameter('entity', $entity)
            ->execute();
        $deleted = (int) $em->createQuery('DELETE FROM ' . OntologyData::class . ' d WHERE d.entity = :entity')
            ->setParameter('entity', $entity)
            ->execute();

        return new JsonResponse(['success' => true, 'deleted' => $deleted + $bucketDeleted]);
    }

    /**
     * How many records the playground would feed a script — shown next to its row limit so the user
     * can judge whether to lift the cap, before running anything.
     *
     * Deliberately NOT the grid's `recordCount` column: that one reports the OroCommerce table size
     * for internal-system entities ({@see recordCount()}), whereas the playground always reads
     * `OntologyData`. Serving the same count the run itself uses keeps the two from disagreeing.
     */
    #[Route(
        path: '/entities/api/{id}/dwl/count',
        name: 'aaxis_ontology_entity_dwl_count',
        requirements: ['id' => '\d+'],
        options: ['expose' => true],
        methods: ['GET']
    )]
    #[AclAncestor('aaxis_ontology_entity_view')]
    public function dwlCountAction(OntologyEntity $entity): JsonResponse
    {
        if (!$this->canReadRecordContent()) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
        }

        return new JsonResponse(['success' => true, 'total' => $this->dwlPayloadTotal($entity)]);
    }

    /**
     * The playground exposes record CONTENT, so entity VIEW (page access) is not sufficient on its
     * own — `OntologyData` VIEW is required too. Shared by the run and count endpoints.
     */
    private function canReadRecordContent(): bool
    {
        return $this->isGranted('VIEW', 'entity:' . OntologyData::class);
    }

    /** Number of records available to a playground run (single source of truth for both endpoints). */
    private function dwlPayloadTotal(OntologyEntity $entity): int
    {
        if ($this->bucketBacked($entity)) {
            return $this->container->get(BucketEntityDataStore::class)->countLatest($entity);
        }

        return (int) $this->registry()->getRepository(OntologyData::class)->count(['entity' => $entity]);
    }

    /**
     * Whether this EXTERNAL entity's records live on the config bucket: toggle on + configured,
     * and the entity does not FORCE DB storage (the per-entity opt-out for hot entities).
     */
    private function bucketBacked(OntologyEntity $entity): bool
    {
        return ($entity->getSystem()?->isExternal() ?? false)
            && !$entity->isForceDbStorage()
            && $this->container->get(BucketEntityDataStore::class)->isEnabled();
    }

    /**
     * The entity's stored record payloads, oldest first. Projects the payload column only (no entity
     * hydration) since an unlimited playground run can span the whole table.
     *
     * @return list<array<string, mixed>>
     */
    private function loadEntityPayloads(OntologyEntity $entity, ?int $limit): array
    {
        if ($this->bucketBacked($entity)) {
            $payloads = array_map(
                static fn (array $envelope): array => \is_array($envelope['payload']) ? $envelope['payload'] : [],
                $this->container->get(BucketEntityDataStore::class)->listLatest($entity)
            );

            return $limit !== null ? \array_slice($payloads, 0, $limit) : $payloads;
        }

        $query = $this->registry()->getManagerForClass(OntologyData::class)
            ->createQuery(
                'SELECT d.payload FROM ' . OntologyData::class . ' d'
                . ' WHERE d.entity = :entity ORDER BY d.id ASC'
            )
            ->setParameter('entity', $entity);
        if ($limit !== null) {
            $query->setMaxResults($limit);
        }

        return array_map(
            static fn (array $row): array => \is_array($row['payload'] ?? null) ? $row['payload'] : [],
            $query->getArrayResult()
        );
    }

    /**
     * Creates/updates an entity (and its 1:N attributes) from a JSON body.
     */
    private function saveFromJson(OntologyEntity $entity, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['success' => false, 'message' => $this->trans('aaxis.ontology.entity_manager.name_required')], 422);
        }

        $systemId = (int) ($payload['systemId'] ?? 0);
        $system = $systemId > 0 ? $this->registry()->getRepository(OntologySystem::class)->find($systemId) : null;
        if ($system === null) {
            return new JsonResponse(['success' => false, 'message' => $this->trans('aaxis.ontology.entity_manager.system_required')], 422);
        }

        $uniqueAttribute = trim((string) ($payload['uniqueAttribute'] ?? ''));
        if ($uniqueAttribute === '') {
            return new JsonResponse(['success' => false, 'message' => $this->trans('aaxis.ontology.entity_manager.unique_attribute_required')], 422);
        }

        // The unique id is read with a flat top-level lookup ($record[$uniqueAttribute]) when
        // upserting, so a dotted/nested path (or an array path) can never resolve. Reject it here
        // rather than letting every upsert fail with "missing unique attribute".
        if (str_contains($uniqueAttribute, '.')) {
            return new JsonResponse(['success' => false, 'message' => $this->trans('aaxis.ontology.entity_manager.unique_attribute_no_dots')], 422);
        }

        $existing = $this->registry()->getRepository(OntologyEntity::class)->findOneBy(['system' => $system, 'name' => $name]);
        if ($existing !== null && $existing->getId() !== $entity->getId()) {
            return new JsonResponse(['success' => false, 'message' => $this->trans('aaxis.ontology.entity_manager.name_unique')], 422);
        }

        $attributeRows = \is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];

        // Internal systems mirror the real OroCommerce model: the entity must be a known OroCommerce
        // entity (name = its class) and every attribute must be one of that entity's fields.
        if (!$system->isExternal()) {
            $invalid = $this->validateInternalEntity($name, $attributeRows);
            if ($invalid !== null) {
                return new JsonResponse(['success' => false, 'message' => $invalid], 422);
            }
        }

        $entity->setName($name);
        $entity->setSystem($system);
        $entity->setUniqueAttribute($uniqueAttribute);
        $entity->setEnabled((bool) ($payload['enabled'] ?? true));
        $entity->setForceDbStorage((bool) ($payload['forceDbStorage'] ?? false));

        $this->syncAttributes($entity, $attributeRows);

        $em = $this->registry()->getManagerForClass(OntologyEntity::class);
        $em->persist($entity);
        $em->flush();

        return new JsonResponse(['success' => true, 'entity' => $this->serialize($entity)]);
    }

    /**
     * Replaces the entity's attribute collection with the given rows (orphanRemoval deletes the
     * ones no longer present).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function syncAttributes(OntologyEntity $entity, array $rows): void
    {
        foreach ($entity->getAttributes()->toArray() as $existing) {
            $entity->removeAttribute($existing);
        }

        $uniqueAttribute = (string) $entity->getUniqueAttribute();

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $datatype = (string) ($row['datatype'] ?? OntologyEntityAttribute::TYPE_UNDEFINED);
            if (!\in_array($datatype, OntologyEntityAttribute::TYPES, true)) {
                $datatype = OntologyEntityAttribute::TYPE_UNDEFINED;
            }

            // The attribute used as the entity's unique id is always required; it cannot be unmarked.
            $required = (bool) ($row['required'] ?? false) || ($name === $uniqueAttribute);

            $attribute = new OntologyEntityAttribute();
            $attribute->setName($name);
            $attribute->setDatatype($datatype);
            $attribute->setRequired($required);
            $entity->addAttribute($attribute);
        }
    }

    /**
     * @param int|null $recordCount Pre-resolved record count; computed on demand when null.
     *
     * @return array<string, mixed>
     */
    private function serialize(OntologyEntity $entity, ?int $recordCount = null): array
    {
        $attributes = [];
        foreach ($entity->getAttributes() as $attribute) {
            $attributes[] = [
                'name' => $attribute->getName(),
                'datatype' => $attribute->getDatatype(),
                'required' => $attribute->isRequired(),
            ];
        }

        // For internal-system entities, `name` is the OroCommerce entity class; show its human
        // label in the grid instead of the raw class.
        $system = $entity->getSystem();
        $displayName = (string) $entity->getName();
        if ($system !== null && !$system->isExternal()) {
            $displayName = $this->oroEntityLabel((string) $entity->getName()) ?? $displayName;
        }

        return [
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'displayName' => $displayName,
            'uniqueAttribute' => $entity->getUniqueAttribute(),
            'enabled' => $entity->isEnabled(),
            'forceDbStorage' => $entity->isForceDbStorage(),
            'systemId' => $system?->getId(),
            'systemName' => $system?->getName(),
            'attributeCount' => \count($attributes),
            'attributes' => $attributes,
            // What a flow reader can address (order by / search by attribute): for INTERNAL
            // entities the actual readable Oro fields — which may be every scalar column when no
            // attributes are configured — for external ones the configured attribute names.
            // Separate from `attributes` so the grid's attributeCount keeps meaning "configured".
            'readerAttributes' => $this->readerAttributes($entity, $attributes),
            // Number of stored records (ontology data for external systems, the OroCommerce table
            // itself for internal systems).
            'recordCount' => $recordCount ?? $this->recordCount($entity),
            // How many flows reference this entity. Not implemented yet — hard-coded for now.
            'flowCount' => 0,
        ];
    }

    /**
     * @param array<int, array{name: ?string, datatype: ?string, required: bool}> $attributes
     *
     * @return array<int, string>
     */
    private function readerAttributes(OntologyEntity $entity, array $attributes): array
    {
        $system = $entity->getSystem();
        if ($system !== null && !$system->isExternal()) {
            try {
                return $this->container->get(OroEntityReader::class)->searchableFields($entity);
            } catch (\Throwable) {
                // Unreadable internal entity (unknown class…): fall through to configured names.
            }
        }

        return array_values(array_filter(array_map(
            static fn (array $attribute): string => trim((string) $attribute['name']),
            $attributes
        ), static fn (string $name): bool => $name !== ''));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function datatypeOptions(): array
    {
        return array_map(
            fn (string $type) => [
                'value' => $type,
                'label' => $this->trans('aaxis.ontology.entity_attribute.datatype.' . $type),
            ],
            OntologyEntityAttribute::TYPES
        );
    }

    /**
     * Builds the selectable field options of an OroCommerce entity (by class name): value = field
     * name, label = field label, datatype = the field's Oro type mapped to an ontology datatype.
     *
     * @return array<int, array{value: string, label: string, datatype: string}>
     */
    private function oroFieldOptions(string $entityClass): array
    {
        $options = [];
        $fields = $this->entityFieldProvider()->getEntityFields(
            $entityClass,
            EntityFieldProvider::OPTION_WITH_RELATIONS
                | EntityFieldProvider::OPTION_APPLY_EXCLUSIONS
                | EntityFieldProvider::OPTION_TRANSLATE
        );
        foreach ($fields as $field) {
            $options[] = [
                'value' => (string) $field['name'],
                'label' => (string) ($field['label'] ?? $field['name']),
                'datatype' => $this->mapOroTypeToDatatype((string) ($field['type'] ?? '')),
            ];
        }

        return $options;
    }

    /**
     * Number of stored records for an entity: rows in the OroCommerce table for an internal-system
     * entity (name = its class), otherwise rows in `aaxis_ontology_data`. When $externalCounts is
     * supplied (the grouped map built by {@see externalRecordCounts}), the external lookup avoids a
     * per-entity query.
     *
     * @param array<int, int>|null $externalCounts
     */
    private function recordCount(OntologyEntity $entity, ?array $externalCounts = null): int
    {
        $system = $entity->getSystem();
        if ($system !== null && !$system->isExternal()) {
            return $this->oroEntityRecordCount((string) $entity->getName());
        }

        if ($this->bucketBacked($entity)) {
            // One LIST per entity; the grouped-query shortcut below only covers the DB backend.
            return $this->container->get(BucketEntityDataStore::class)->countLatest($entity);
        }

        if ($externalCounts !== null) {
            return (int) ($externalCounts[(int) $entity->getId()] ?? 0);
        }

        return (int) $this->registry()->getRepository(OntologyData::class)->count(['entity' => $entity]);
    }

    /**
     * Counts ontology-data rows per entity in a single grouped query.
     *
     * @return array<int, int> entity id => record count
     */
    private function externalRecordCounts(): array
    {
        $rows = $this->registry()->getManagerForClass(OntologyData::class)
            ->createQuery(
                'SELECT IDENTITY(d.entity) AS entityId, COUNT(d.id) AS cnt'
                . ' FROM ' . OntologyData::class . ' d GROUP BY d.entity'
            )
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['entityId']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Validates an entity destined for an internal system. Returns an error message, or null when
     * valid: the name must be a known OroCommerce entity (class) and every attribute must be one of
     * that entity's fields.
     *
     * @param array<int, array<string, mixed>> $attributeRows
     */
    private function validateInternalEntity(string $entityClass, array $attributeRows): ?string
    {
        $fieldOptions = $this->oroFieldOptions($entityClass);
        if ($fieldOptions === []) {
            return $this->trans('aaxis.ontology.entity_manager.entity_not_oro');
        }

        $validNames = array_column($fieldOptions, 'value');
        foreach ($attributeRows as $row) {
            $attrName = trim((string) ($row['name'] ?? ''));
            if ($attrName === '' || \in_array($attrName, $validNames, true)) {
                continue;
            }

            return $this->trans('aaxis.ontology.entity_manager.attribute_not_oro_field', ['{{ name }}' => $attrName]);
        }

        return null;
    }

    /** Maps an OroCommerce field type to the closest ontology attribute datatype. */
    private function mapOroTypeToDatatype(string $type): string
    {
        return match ($type) {
            'boolean' => OntologyEntityAttribute::TYPE_BOOLEAN,
            'integer', 'smallint', 'bigint', 'float', 'decimal', 'money', 'percent' => OntologyEntityAttribute::TYPE_NUMBER,
            'string', 'text' => OntologyEntityAttribute::TYPE_TEXT,
            'date' => OntologyEntityAttribute::TYPE_DATE,
            'time' => OntologyEntityAttribute::TYPE_TIME,
            'datetime', 'datetimetz' => OntologyEntityAttribute::TYPE_DATETIME,
            'array', 'simple_array', 'json', 'json_array', 'object' => OntologyEntityAttribute::TYPE_OBJECT,
            default => OntologyEntityAttribute::TYPE_UNDEFINED,
        };
    }

    private function oroEntityLabel(string $entityClass): ?string
    {
        try {
            $entity = $this->entityProvider()->getEntity($entityClass);
            $label = $entity['label'] ?? null;

            return $label !== null ? (string) $label : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function entityProvider(): EntityProvider
    {
        return $this->container->get(EntityProvider::class);
    }

    private function entityFieldProvider(): EntityFieldProvider
    {
        return $this->container->get(EntityFieldProvider::class);
    }

    private function registry(): ManagerRegistry
    {
        return $this->container->get(ManagerRegistry::class);
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->container->get(TranslatorInterface::class)->trans($key, $params);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            \Aaxis\Bundle\OntologyBundle\Manager\BucketEntityDataStore::class,
            EntityProvider::class,
            EntityFieldProvider::class,
            DwlTransformer::class,
            DwlOutputFormatter::class,
            DwlScriptGuard::class,
            OroEntityReader::class,
        ]);
    }
}

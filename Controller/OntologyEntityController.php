<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
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
    #[Route(path: '/entities', name: 'aaxis_ontology_entities')]
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
            'systemId' => $system?->getId(),
            'systemName' => $system?->getName(),
            'attributeCount' => \count($attributes),
            'attributes' => $attributes,
            // Number of stored records (ontology data for external systems, the OroCommerce table
            // itself for internal systems).
            'recordCount' => $recordCount ?? $this->recordCount($entity),
            // How many flows reference this entity. Not implemented yet — hard-coded for now.
            'flowCount' => 0,
        ];
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
            EntityProvider::class,
            EntityFieldProvider::class,
        ]);
    }
}

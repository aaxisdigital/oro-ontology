<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntityAttribute;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Doctrine\Persistence\ManagerRegistry;
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

        return new JsonResponse([
            'entities' => array_map($this->serialize(...), $entities),
            'systems' => array_map(
                static fn (OntologySystem $s) => ['id' => $s->getId(), 'name' => $s->getName()],
                $systems
            ),
            'datatypes' => $this->datatypeOptions(),
        ]);
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

        $existing = $this->registry()->getRepository(OntologyEntity::class)->findOneBy(['system' => $system, 'name' => $name]);
        if ($existing !== null && $existing->getId() !== $entity->getId()) {
            return new JsonResponse(['success' => false, 'message' => $this->trans('aaxis.ontology.entity_manager.name_unique')], 422);
        }

        $entity->setName($name);
        $entity->setSystem($system);
        $entity->setUniqueAttribute($uniqueAttribute);
        $entity->setEnabled((bool) ($payload['enabled'] ?? true));

        $this->syncAttributes($entity, \is_array($payload['attributes'] ?? null) ? $payload['attributes'] : []);

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
     * @return array<string, mixed>
     */
    private function serialize(OntologyEntity $entity): array
    {
        $attributes = [];
        foreach ($entity->getAttributes() as $attribute) {
            $attributes[] = [
                'name' => $attribute->getName(),
                'datatype' => $attribute->getDatatype(),
                'required' => $attribute->isRequired(),
            ];
        }

        return [
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'uniqueAttribute' => $entity->getUniqueAttribute(),
            'enabled' => $entity->isEnabled(),
            'systemId' => $entity->getSystem()?->getId(),
            'systemName' => $entity->getSystem()?->getName(),
            'attributeCount' => \count($attributes),
            'attributes' => $attributes,
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

    private function registry(): ManagerRegistry
    {
        return $this->container->get(ManagerRegistry::class);
    }

    private function trans(string $key): string
    {
        return $this->container->get(TranslatorInterface::class)->trans($key);
    }
}

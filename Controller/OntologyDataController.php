<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyData;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyDataHistory;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Exception\OntologyApiException;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consolidated view of the Ontology "Data" records, rendered by the reusable TypeScript
 * DataGrid widget (data-view-component) via the JSON endpoints below.
 */
class OntologyDataController extends AbstractController
{
    // expose: the Entities grid links here (?entity=…) via routing.generate() in TypeScript.
    #[Route(path: '/data-view', name: 'aaxis_ontology_data_view', options: ['expose' => true])]
    #[Template('@AaxisOntology/Data/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_data_view', type: 'entity', class: OntologyData::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return [];
    }

    #[Route(path: '/data-view/api/list', name: 'aaxis_ontology_data_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_data_view')]
    public function listAction(): JsonResponse
    {
        $registry = $this->container->get(ManagerRegistry::class);

        /** @var OntologyData[] $records */
        $records = $registry->getRepository(OntologyData::class)->findBy([], ['updatedAt' => 'DESC']);
        $systems = $registry->getRepository(OntologySystem::class)->findBy([], ['name' => 'ASC']);
        $entities = $registry->getRepository(OntologyEntity::class)->findBy([], ['name' => 'ASC']);

        return new JsonResponse([
            'records' => array_map($this->serialize(...), $records),
            'systems' => array_map(
                static fn (OntologySystem $s) => ['id' => $s->getId(), 'name' => $s->getName()],
                $systems
            ),
            'entities' => array_map(
                static fn (OntologyEntity $e) => [
                    'id' => $e->getId(),
                    'name' => $e->getName(),
                    'systemId' => $e->getSystem()?->getId(),
                    'uniqueAttribute' => $e->getUniqueAttribute(),
                ],
                $entities
            ),
        ]);
    }

    #[Route(path: '/data-view/api/{id}/versions', name: 'aaxis_ontology_data_versions', requirements: ['id' => '\d+'], options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_data_view')]
    public function versionsAction(int $id): JsonResponse
    {
        $registry = $this->container->get(ManagerRegistry::class);

        /** @var OntologyData|null $record */
        $record = $registry->getRepository(OntologyData::class)->find($id);
        if ($record === null) {
            return new JsonResponse(['success' => false, 'message' => 'Record not found.'], 404);
        }

        /** @var OntologyDataHistory[] $history */
        $history = $registry->getRepository(OntologyDataHistory::class)->findBy(
            ['entity' => $record->getEntity(), 'uniqueId' => $record->getUniqueId()],
            ['version' => 'DESC']
        );

        // The current record holds the full, latest payload. Each history row holds the PREVIOUS
        // values of the keys that changed when moving from that version to the next one (a null
        // marks a key that did not exist yet). Reconstruct every past snapshot by walking the
        // history from the newest to the oldest and reverting those changes one step at a time.
        $versions = [[
            'version' => $record->getVersion(),
            'uuid' => $record->getUuid(),
            'updatedAt' => $record->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'current' => true,
            'payload' => $record->getPayload() ?? [],
        ]];

        $running = $record->getPayload() ?? [];
        foreach ($history as $row) {
            $running = $this->applyReverseDiff($running, $row->getPayload() ?? []);
            $versions[] = [
                'version' => $row->getVersion(),
                'uuid' => $row->getUuid(),
                'updatedAt' => $row->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'current' => false,
                'payload' => $running,
            ];
        }

        return new JsonResponse(['versions' => $versions]);
    }

    /**
     * Reverts a single upsert step: given a snapshot and the "previous values" diff that was
     * archived for it, returns the snapshot as it was before that step. A null in the diff means
     * the key/element was newly added at the later version, so it is removed when reverting.
     *
     * @param array<int|string, mixed> $snapshot
     * @param array<int|string, mixed> $diff
     *
     * @return array<int|string, mixed>
     */
    private function applyReverseDiff(array $snapshot, array $diff): array
    {
        foreach ($diff as $key => $value) {
            if ($value === null) {
                unset($snapshot[$key]);

                continue;
            }

            $snapshot[$key] = $this->reverseValue($snapshot[$key] ?? null, $value);
        }

        return $snapshot;
    }

    /**
     * Reverts a single value against its archived diff node: an array patch (an object whose keys
     * are index tags like "__0_") is applied element by element, an object diff recurses, and
     * anything else is the previous value stored verbatim (scalar, whole array, or a type change).
     */
    private function reverseValue(mixed $current, mixed $diff): mixed
    {
        if ($this->isArrayPatch($diff)) {
            $list = \is_array($current) ? $current : [];

            return $this->applyReverseArray($list, $diff);
        }

        if ($this->isObject($diff) && $this->isObject($current)) {
            return $this->applyReverseDiff($current, $diff);
        }

        return $diff;
    }

    /**
     * Reverts an array given a positional patch keyed by index tags ("__<index>_" => element diff).
     * Null entries are elements that were added later (dropped when reverting); the remaining
     * entries restore or revert the element at that index.
     *
     * @param array<int, mixed>        $list
     * @param array<string, mixed>     $patch
     *
     * @return array<int, mixed>
     */
    private function applyReverseArray(array $list, array $patch): array
    {
        $map = $list;
        $remove = [];
        foreach ($patch as $tag => $diff) {
            $idx = (int) substr((string) $tag, 2, -1);
            if ($diff === null) {
                $remove[$idx] = true;

                continue;
            }

            $map[$idx] = $this->reverseValue($map[$idx] ?? null, $diff);
        }

        foreach (array_keys($remove) as $idx) {
            unset($map[$idx]);
        }

        ksort($map, SORT_NUMERIC);

        return array_values($map);
    }

    /**
     * Whether the value is an array patch: a non-empty object whose every key is an index tag of
     * the form "__<digits>_".
     */
    private function isArrayPatch(mixed $value): bool
    {
        if (!\is_array($value) || $value === []) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (!\is_string($key) || preg_match('/^__\d+_$/', $key) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the value is a JSON "object" (an associative array), as opposed to a JSON list or a
     * scalar.
     */
    private function isObject(mixed $value): bool
    {
        return \is_array($value) && ($value === [] ? false : !array_is_list($value));
    }

    #[Route(path: '/data-view/api', name: 'aaxis_ontology_data_create', options: ['expose' => true], methods: ['POST'])]
    #[Acl(id: 'aaxis_ontology_data_create', type: 'entity', class: OntologyData::class, permission: 'CREATE')]
    #[CsrfProtection]
    public function createAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $registry = $this->container->get(ManagerRegistry::class);

        $entityId = (int) ($payload['entityId'] ?? 0);
        $entity = $entityId > 0 ? $registry->getRepository(OntologyEntity::class)->find($entityId) : null;
        if ($entity === null) {
            return new JsonResponse(['success' => false, 'message' => 'A valid entity is required.'], 422);
        }

        $format = (string) ($payload['format'] ?? 'json');
        $raw = (string) ($payload['payload'] ?? '');
        try {
            $decoded = $this->decodePayload($format, $raw);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // Like the API: a single object is one record, a list (JSON array / CSV rows) is a batch.
        // Each record's unique id is inferred from its payload via the entity's unique attribute,
        // and the upsert is delegated to the shared manager (which validates and queues the message).
        $records = array_is_list($decoded) ? $decoded : [$decoded];

        try {
            $manager = $this->container->get(OntologyDataApiManager::class);
            $flow = $manager->requireEnabledFlow(OntologyFlow::NAME_MANUAL);
            $manager->upsertRecords($entity, $records, $flow);
        } catch (OntologyApiException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * Decodes the raw payload text into a structure suitable for the JSONB column, according to the
     * given format. Throws when the input is invalid.
     *
     * @return array<int|string, mixed>
     */
    private function decodePayload(string $format, string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        switch ($format) {
            case 'json':
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                return \is_array($data) ? $data : ['value' => $data];

            case 'csv':
                return $this->decodeCsv($raw);

            case 'xml':
                return $this->decodeXml($raw);

            default:
                throw new \InvalidArgumentException('Unsupported format.');
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function decodeCsv(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $lines = array_values(array_filter($lines, static fn ($l) => trim((string) $l) !== ''));
        if ($lines === []) {
            return [];
        }

        $headers = str_getcsv((string) array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            $values = str_getcsv($line);
            $row = [];
            foreach ($headers as $i => $header) {
                $row[(string) $header] = $values[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeXml(string $raw): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \InvalidArgumentException('The XML payload is not valid.');
        }

        $json = json_encode($xml);

        return $json === false ? [] : (array) json_decode($json, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OntologyData $record): array
    {
        $payload = $record->getPayload();

        return [
            'id' => $record->getId(),
            'system' => $record->getSystem()?->getName(),
            'entity' => $record->getEntity()?->getName(),
            // Ids as well as names: the row's "Update" action reopens the Add Data form locked to
            // exactly this record's system/entity, and names alone are ambiguous across systems.
            'systemId' => $record->getSystem()?->getId(),
            'entityId' => $record->getEntity()?->getId(),
            'uniqueId' => $record->getUniqueId(),
            'uuid' => $record->getUuid(),
            'version' => $record->getVersion(),
            'payload' => $payload === null ? '' : json_encode($payload),
            'updatedAt' => $record->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ManagerRegistry::class,
            OntologyDataApiManager::class,
        ]);
    }
}

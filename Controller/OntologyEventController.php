<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlowEvent;
use Aaxis\Bundle\OntologyBundle\Manager\BucketFlowEventStore;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunIndex;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of the Ontology FLOW-EXECUTION events (aaxis_ontology_flow_events — one row per
 * flow-start / flow-finish / flow-exception / data-upsert / log-message / step event, written
 * asynchronously by the flow-event queue processor), rendered by the reusable TypeScript DataGrid
 * widget (event-component) via the JSON endpoint below.
 */
class OntologyEventController extends AbstractController
{
    // Exposed: the Flows list's "View events" generates it from TypeScript.
    #[Route(path: '/events', name: 'aaxis_ontology_events', options: ['expose' => true])]
    #[Template('@AaxisOntology/Event/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_event_view', type: 'entity', class: OntologyFlowEvent::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return [];
    }

    /**
     * ONE ROW PER RUN (flow uuid), newest first, read from the run index ({@see FlowRunIndex} —
     * kept current by the flow-event consumer, so nothing is aggregated here): the run's flow and
     * name, start (the flow-start's time, else its earliest event), finish (the last flow-finish
     * or flow-exception), stored elapsed time, status and event count.
     *
     * Only the runs of the active event backend (table, or bucket with "Use Bucket for Flow
     * Events"), started within the last flow_execution_history_days days (30 when unset). With
     * `since` (the `cursor` of a previous answer) only the runs changed after it come back — the
     * page's Refresh merges them into what it already shows.
     */
    #[Route(path: '/events/api/list', name: 'aaxis_ontology_event_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_event_view')]
    public function listAction(Request $request): JsonResponse
    {
        $bucket = $this->container->get(BucketFlowEventStore::class)->isEnabled();
        $days = (int) $this->container->get(ConfigManager::class)->get('aaxis_ontology.flow_execution_history_days');
        $startedSince = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $days > 0 ? $days : 30))
            ->format('Y-m-d H:i:s');
        $since = (string) $request->query->get('since', '');
        $changedSince = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $since) === 1 ? $since : null;

        $index = $this->container->get(FlowRunIndex::class);
        // Taken BEFORE the read: whatever changes while it runs is fetched again next time.
        $cursor = $index->cursor();
        $rows = $index->runs($bucket ? FlowRunIndex::STORAGE_BUCKET : FlowRunIndex::STORAGE_DB, $startedSince, $changedSince);

        return new JsonResponse([
            'records' => array_map(static fn (array $row): array => self::runRecord($row), $rows),
            'cursor' => $cursor,
            'incremental' => $changedSince !== null,
        ]);
    }

    /**
     * @param array<string, mixed> $row one run index row
     *
     * @return array<string, mixed>
     */
    private static function runRecord(array $row): array
    {
        $record = [
            'flowUuid' => $row['flow_uuid'],
            'flowId' => $row['flow_id'] !== null ? (int) $row['flow_id'] : null,
            'flowName' => $row['flow_name'],
            'startedAt' => self::atom($row['started_at']),
            'finishedAt' => self::atom($row['finished_at']),
            // Milliseconds between start and finish; null = still running (grid sorts on it).
            'elapsedMs' => $row['elapsed_ms'] !== null ? (int) $row['elapsed_ms'] : null,
            // How the run ended: which finish event came last (null = still running).
            'status' => self::runStatus($row['finish_event'] ?? null),
            'events' => (int) $row['events'],
        ];
        if ($row['storage'] === FlowRunIndex::STORAGE_BUCKET) {
            // The popup needs these to find the run's day folders (see runAction's bucket arm).
            $record += ['bucket' => true, 'startedAtRaw' => $row['started_at'], 'finishedAtRaw' => $row['finished_at']];
        }

        return $record;
    }

    /** The FULL event list of one run (uuid), datetime ASC — the "view events" popup. */
    #[Route(path: '/events/api/run', name: 'aaxis_ontology_event_run', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_event_view')]
    public function runAction(Request $request): JsonResponse
    {
        $uuid = trim((string) $request->query->get('uuid', ''));
        if ($uuid === '') {
            return new JsonResponse(['records' => []]);
        }

        // Bucket-backed run rows (the list arm marks them bucket: true) address their events by
        // uuid + flow id + the run's day range — the store scans the matching day folders.
        return $request->query->get('bucket')
            ? $this->bucketRunEvents($request, $uuid)
            : $this->tableRunEvents($uuid);
    }

    private function bucketRunEvents(Request $request, string $uuid): JsonResponse
    {
        $flowIdRaw = $request->query->get('flowId');
        $records = $this->container->get(BucketFlowEventStore::class)->runEvents(
            is_numeric($flowIdRaw) ? (int) $flowIdRaw : null,
            $uuid,
            $request->query->get('startedAt') ?: null,
            $request->query->get('finishedAt') ?: null
        );

        return new JsonResponse([
            'records' => array_map(static function (array $event, int $i): array {
                $at = $event['datetime'] !== ''
                    ? date_create($event['datetime'], new \DateTimeZone('UTC'))
                    : false;

                return [
                    'id' => $i + 1,
                    'flowName' => $event['flowName'],
                    'event' => $event['event'],
                    'datetime' => $at !== false ? $at->format(\DateTimeInterface::ATOM) : null,
                    'ms' => $at !== false ? (float) $at->format('U.u') * 1000 : null,
                    'payload' => $event['payload'],
                ];
            }, $records, array_keys($records)),
        ]);
    }

    private function tableRunEvents(string $uuid): JsonResponse
    {
        /** @var OntologyFlowEvent[] $records */
        $records = $this->container->get(ManagerRegistry::class)
            ->getRepository(OntologyFlowEvent::class)
            ->findBy(['flowUuid' => $uuid], ['datetime' => 'ASC', 'id' => 'ASC']);

        return new JsonResponse([
            'records' => array_map(static fn (OntologyFlowEvent $event): array => [
                'id' => $event->getId(),
                'flowName' => $event->getFlowName(),
                'event' => $event->getEvent(),
                'datetime' => self::atom($event->getDatetime()),
                // Micro-precision epoch millis: the popup shows step rows as a DELTA from the
                // previous event, which second-precision ATOM cannot express.
                'ms' => self::millis($event->getDatetime()),
                'payload' => $event->getPayload(),
            ], $records),
        ]);
    }

    /** A UTC timestamp string as ATOM; null stays null. */
    private static function atom(?string $raw): ?string
    {
        return $raw !== null ? date_create($raw, new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM) : null;
    }

    /** A UTC timestamp string as epoch milliseconds (micro-precision); null stays null. */
    private static function millis(?string $raw): ?float
    {
        return $raw !== null ? (float) date_create($raw, new \DateTimeZone('UTC'))->format('U.u') * 1000 : null;
    }

    /** How a run ended, from its last finish event (anything else = still running). */
    private static function runStatus(?string $finishEvent): string
    {
        return match ($finishEvent) {
            'flow-finish' => 'success',
            'flow-exception' => 'exception',
            default => 'running',
        };
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ManagerRegistry::class,
            BucketFlowEventStore::class,
            ConfigManager::class,
            FlowRunIndex::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Controller;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlowEvent;
use Aaxis\Bundle\OntologyBundle\Manager\BucketFlowEventStore;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of the Ontology FLOW-EXECUTION events (aaxis_ontology_flow_events — one row per
 * flow-start / flow-finish / flow-exception / data-upsert / log-message / step event, written
 * asynchronously by the flow-event queue processor), rendered by the reusable TypeScript DataGrid
 * widget (event-component) via the JSON endpoint below.
 */
class OntologyEventController extends AbstractController
{
    #[Route(path: '/events', name: 'aaxis_ontology_events')]
    #[Template('@AaxisOntology/Event/index.html.twig')]
    #[Acl(id: 'aaxis_ontology_event_view', type: 'entity', class: OntologyFlowEvent::class, permission: 'VIEW')]
    public function indexAction(): array
    {
        return [];
    }

    /**
     * ONE ROW PER RUN (flow uuid): the root flow-start's flow/name/time, the finish time
     * (flow-finish or flow-exception, whichever came last), and the event count — newest runs
     * first. Runs with no flow-start (e.g. bare data-upserts from the async consumer) fall back
     * to their earliest event.
     */
    #[Route(path: '/events/api/list', name: 'aaxis_ontology_event_list', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_event_view')]
    public function listAction(): JsonResponse
    {
        $store = $this->container->get(BucketFlowEventStore::class);
        if ($store->isEnabled()) {
            return $this->listFromBucket($store);
        }

        $rows = $this->container->get(ManagerRegistry::class)->getConnection()->fetchAllAssociative(
            "SELECT flow_uuid,
                    (array_agg(flow_id ORDER BY id) FILTER (WHERE event = 'flow-start'))[1] AS start_flow_id,
                    (array_agg(flow_id ORDER BY id) FILTER (WHERE flow_id IS NOT NULL))[1] AS any_flow_id,
                    (array_agg(flow_name ORDER BY id) FILTER (WHERE event = 'flow-start'))[1] AS start_name,
                    (array_agg(flow_name ORDER BY id) FILTER (WHERE flow_name IS NOT NULL))[1] AS any_name,
                    coalesce(min(datetime) FILTER (WHERE event = 'flow-start'), min(datetime)) AS started_at,
                    max(datetime) FILTER (WHERE event IN ('flow-finish', 'flow-exception')) AS finished_at,
                    (array_agg(event ORDER BY datetime DESC, id DESC) FILTER (WHERE event IN ('flow-finish', 'flow-exception')))[1] AS finish_event,
                    count(*) AS events
             FROM aaxis_ontology_flow_events
             GROUP BY flow_uuid
             ORDER BY coalesce(min(datetime) FILTER (WHERE event = 'flow-start'), min(datetime)) DESC"
        );

        $atom = static fn (?string $raw): ?string => $raw !== null
            ? date_create($raw, new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
            : null;
        $millis = static fn (?string $raw): ?float => $raw !== null
            ? (float) date_create($raw, new \DateTimeZone('UTC'))->format('U.u') * 1000
            : null;

        return new JsonResponse([
            'records' => array_map(static function (array $row) use ($atom, $millis): array {
                $start = $millis($row['started_at']);
                $finish = $millis($row['finished_at']);

                return [
                    'flowUuid' => $row['flow_uuid'],
                    'flowId' => $row['start_flow_id'] ?? $row['any_flow_id'],
                    'flowName' => $row['start_name'] ?? $row['any_name'],
                    'startedAt' => $atom($row['started_at']),
                    'finishedAt' => $atom($row['finished_at']),
                    // Milliseconds between start and finish; null = still running (grid sorts on it).
                    'elapsedMs' => $start !== null && $finish !== null ? max(0, (int) round($finish - $start)) : null,
                    // How the run ended: which finish event came last (null = still running).
                    'status' => match ($row['finish_event'] ?? null) {
                        'flow-finish' => 'success',
                        'flow-exception' => 'exception',
                        default => 'running',
                    },
                    'events' => (int) $row['events'],
                ];
            }, $rows),
        ]);
    }

    /** The FULL event list of one run (uuid), datetime ASC — the "view events" popup. */
    #[Route(path: '/events/api/run', name: 'aaxis_ontology_event_run', options: ['expose' => true], methods: ['GET'])]
    #[AclAncestor('aaxis_ontology_event_view')]
    public function runAction(\Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        $uuid = trim((string) $request->query->get('uuid', ''));
        if ($uuid === '') {
            return new JsonResponse(['records' => []]);
        }

        // Bucket-backed run rows (the list arm marks them bucket: true) address their events by
        // uuid + flow id + the run's day range — the store scans the matching day folders.
        if ($request->query->get('bucket')) {
            $store = $this->container->get(BucketFlowEventStore::class);
            $flowIdRaw = $request->query->get('flowId');
            $records = $store->runEvents(
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

        /** @var OntologyFlowEvent[] $records */
        $records = $this->container->get(ManagerRegistry::class)
            ->getRepository(OntologyFlowEvent::class)
            ->findBy(['flowUuid' => $uuid], ['datetime' => 'ASC', 'id' => 'ASC']);

        return new JsonResponse([
            'records' => array_map(static fn (OntologyFlowEvent $event): array => [
                'id' => $event->getId(),
                'flowName' => $event->getFlowName(),
                'event' => $event->getEvent(),
                'datetime' => $event->getDatetime() !== null
                    ? date_create($event->getDatetime(), new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
                    : null,
                // Micro-precision epoch millis: the popup shows step rows as a DELTA from the
                // previous event, which second-precision ATOM cannot express.
                'ms' => $event->getDatetime() !== null
                    ? (float) date_create($event->getDatetime(), new \DateTimeZone('UTC'))->format('U.u') * 1000
                    : null,
                'payload' => $event->getPayload(),
            ], $records),
        ]);
    }

    /**
     * The bucket arm of {@see listAction}: one row per run aggregated from the last
     * flow_execution_history_days days' KEY listings (kind + micro-time live in the filenames —
     * no object bodies are fetched here). Flow names resolve from the flows table by id (a
     * deleted flow shows no name — bucket keys don't carry it).
     */
    private function listFromBucket(BucketFlowEventStore $store): JsonResponse
    {
        $days = (int) $this->container->get(ConfigManager::class)->get('aaxis_ontology.flow_execution_history_days');
        $runs = $store->listRuns($days > 0 ? $days : 30);

        $flowIds = array_values(array_unique(array_filter(array_column($runs, 'flowId'))));
        $names = [];
        if ($flowIds !== []) {
            foreach ($this->container->get(ManagerRegistry::class)->getRepository(OntologyFlow::class)
                         ->findBy(['id' => $flowIds]) as $flow) {
                $names[(int) $flow->getId()] = $flow->getName();
            }
        }

        $atom = static fn (?string $raw): ?string => $raw !== null
            ? date_create($raw, new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
            : null;
        $millis = static fn (?string $raw): ?float => $raw !== null
            ? (float) date_create($raw, new \DateTimeZone('UTC'))->format('U.u') * 1000
            : null;

        $records = array_map(static function (array $run) use ($atom, $millis, $names): array {
            $start = $millis($run['startedAtRaw']);
            $finish = $millis($run['finishedAtRaw']);

            return [
                'flowUuid' => $run['flowUuid'],
                'flowId' => $run['flowId'],
                'flowName' => $run['flowId'] !== null ? ($names[$run['flowId']] ?? null) : null,
                'startedAt' => $atom($run['startedAtRaw']),
                'finishedAt' => $atom($run['finishedAtRaw']),
                'elapsedMs' => $start !== null && $finish !== null ? max(0, (int) round($finish - $start)) : null,
                'status' => match ($run['finishEvent']) {
                    'flow-finish' => 'success',
                    'flow-exception' => 'exception',
                    default => 'running',
                },
                'events' => $run['events'],
                // The popup needs these to find the run's day folders (see runAction's bucket arm).
                'bucket' => true,
                'startedAtRaw' => $run['startedAtRaw'],
                'finishedAtRaw' => $run['finishedAtRaw'],
            ];
        }, $runs);
        usort($records, static fn (array $a, array $b): int => strcmp((string) $b['startedAt'], (string) $a['startedAt']));

        return new JsonResponse(['records' => $records]);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            ManagerRegistry::class,
            BucketFlowEventStore::class,
            ConfigManager::class,
        ]);
    }
}

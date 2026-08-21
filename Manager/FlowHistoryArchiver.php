<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Doctrine\Persistence\ManagerRegistry;

/**
 * Archives a flow's definition into aaxis_ontology_flow_history right before a SAVE replaces it —
 * so a version that actually ran is never lost to an edit.
 *
 * Only EXECUTED versions are archived. The rule: the flow's current `last_executed` (raw column
 * value) must differ from the `last_executed` stored on the LATEST history row — equal values
 * mean no run happened since that archive, i.e. the revision being replaced never executed and
 * needs no snapshot (it is simply overwritten). A flow that never ran at all archives nothing,
 * and a save that leaves steps+design untouched archives nothing either.
 *
 * Versions are a per-flow sequence starting at 1; rows are removed with their flow (FK CASCADE).
 */
class FlowHistoryArchiver
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    /**
     * Call BEFORE flushing an update, with the definition the save is about to replace.
     *
     * @param array<int, mixed>|null   $oldSteps  the stored steps being replaced
     * @param array<string, mixed>|null $oldDesign the stored design being replaced
     * @param array<int, mixed>|null   $newSteps  the steps about to be stored
     * @param array<string, mixed>|null $newDesign the design about to be stored
     */
    public function archiveIfExecuted(
        int $flowId,
        string $name,
        ?array $oldSteps,
        ?array $oldDesign,
        ?array $newSteps,
        ?array $newDesign,
    ): void {
        // An unchanged definition has nothing to protect.
        if (json_encode($oldSteps) === json_encode($newSteps)
            && json_encode($oldDesign) === json_encode($newDesign)
        ) {
            return;
        }

        $connection = $this->doctrine->getConnection();
        // The RAW column value (not a hydrated \DateTime): the equality check below must be
        // string-exact, immune to timezone/format round trips.
        $lastExecuted = $connection->fetchOne(
            'SELECT last_executed FROM aaxis_ontology_flow WHERE id = ?',
            [$flowId]
        );
        if ($lastExecuted === false || $lastExecuted === null) {
            return; // the flow never ran — no executed version to lose
        }

        $latest = $connection->fetchAssociative(
            'SELECT version, last_executed FROM aaxis_ontology_flow_history
             WHERE flow_id = ? ORDER BY version DESC LIMIT 1',
            [$flowId]
        );
        if ($latest !== false && (string) $latest['last_executed'] === (string) $lastExecuted) {
            return; // no run since the latest archive — the replaced revision never executed
        }

        $connection->executeStatement(
            'INSERT INTO aaxis_ontology_flow_history
                (flow_id, version, name, steps, design, last_executed, archived_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $flowId,
                ($latest !== false ? (int) $latest['version'] : 0) + 1,
                $name,
                $oldSteps === null ? null : json_encode($oldSteps),
                $oldDesign === null ? null : json_encode($oldDesign),
                $lastExecuted,
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ]
        );
    }
}

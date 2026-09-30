<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

/**
 * Rebuilds executor inputs from a flow's saved design (version 2), shared by the runners that start
 * a flow from one particular trigger step.
 *
 * Each runner keys on its own trigger type — 'cron' for the scheduled runner, 'endpoint' for the
 * endpoint one — which is the only thing that ever differed between their copies of this parser.
 */
trait FlowDesignParserTrait
{
    /**
     * Returns the normalized steps [{id, type, name, config}], the links, and the first step of
     * type `$triggerType`. Null when the design is missing/unreadable, contains a malformed step,
     * or carries no step of that trigger type.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, mixed>}|null
     */
    private function parseDesign(mixed $design, string $triggerType): ?array
    {
        if (!\is_array($design) || !\is_array($design['steps'] ?? null)) {
            return null;
        }
        $steps = $this->parseDesignSteps($design['steps']);
        $trigger = null;
        foreach ($steps ?? [] as $step) {
            if ($step['type'] === $triggerType) {
                $trigger = $step;
                break;
            }
        }
        if ($trigger === null) {
            return null;
        }

        return [$steps, $this->parseDesignLinks($design['links'] ?? null), $trigger];
    }

    /**
     * @param array<mixed> $rawSteps
     *
     * @return array<int, array<string, mixed>>|null null when any step is malformed
     */
    private function parseDesignSteps(array $rawSteps): ?array
    {
        $steps = [];
        foreach ($rawSteps as $step) {
            if (!\is_array($step) || !\is_string($step['id'] ?? null) || !\is_string($step['type'] ?? null)) {
                return null;
            }
            $config = $step['config'] ?? null;
            $steps[] = [
                'id' => $step['id'],
                'type' => $step['type'],
                'name' => \is_string($step['name'] ?? null) ? $step['name'] : $step['id'],
                'config' => \is_array($config) ? $config : null,
            ];
        }

        return $steps;
    }

    /**
     * The well-formed links ({from, to} strings, fromPort defaulting to 0); the rest is dropped.
     *
     * @return array<int, array{from: string, fromPort: int, to: string}>
     */
    private function parseDesignLinks(mixed $rawLinks): array
    {
        $links = [];
        foreach (\is_array($rawLinks) ? $rawLinks : [] as $link) {
            if (\is_array($link) && \is_string($link['from'] ?? null) && \is_string($link['to'] ?? null)) {
                $links[] = ['from' => $link['from'], 'fromPort' => (int) ($link['fromPort'] ?? 0), 'to' => $link['to']];
            }
        }

        return $links;
    }
}

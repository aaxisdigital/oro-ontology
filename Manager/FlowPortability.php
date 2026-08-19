<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Exception\FlowImportException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Moves flows between environments as a self-contained JSON document.
 *
 * A flow is almost portable already — its steps name systems and entities rather than pointing at
 * ids — with TWO exceptions: connector steps hold the numeric connector id and Call Subflow steps
 * the numeric target-flow id, both meaningless in another database. Export therefore rewrites
 * every `connector` id into a `connectorRef` {name, type, system} descriptor and every `subflow`
 * id into a `subflowRef` {name} descriptor (flow names are unique — in BOTH `steps` and
 * `design.steps`, which each carry their own copy of every config), and import resolves the
 * descriptors back to local ids. For a subflow that means the referenced subflow must exist HERE
 * by that name already: import the subflows first, their callers after.
 *
 * Entities travel as names, so the document also carries a manifest of the referenced entities
 * with their unique attribute — the piece the step configs do not hold — so the import can refuse
 * a target environment whose entity of the same name is keyed differently.
 *
 * Import validates everything BEFORE writing anything and reports every problem at once
 * ({@see FlowImportException}); the created flow is always disabled, so nothing starts running on
 * the strength of a file.
 */
class FlowPortability
{
    public const string FORMAT = 'aaxis-ontology-flow';
    public const int VERSION = 1;

    /** Step config keys naming an entity — both are needed to identify one. */
    private const string KEY_SYSTEM = 'system';
    private const string KEY_ENTITY = 'entity';

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TranslatorInterface $translator,
        private readonly FlowStepValidator $stepValidator,
    ) {
    }

    /**
     * Builds the transport document for a flow.
     *
     * @return array<string, mixed>
     *
     * @throws FlowImportException when a step points at a connector or subflow that no longer
     *                             exists — the flow is already broken and the file could never
     *                             be imported
     */
    public function export(OntologyFlow $flow): array
    {
        $errors = [];
        $steps = $this->rewriteStepList($flow->getSteps(), $errors);
        $design = $flow->getDesign();
        if (\is_array($design) && \is_array($design['steps'] ?? null)) {
            $design['steps'] = $this->rewriteStepList($design['steps'], $errors);
        }
        if ($errors !== []) {
            throw new FlowImportException(array_values(array_unique($errors)));
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exportedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'flow' => [
                'name' => $flow->getName(),
                'type' => $flow->getType(),
                'steps' => $steps,
                'design' => $design,
            ],
            'entities' => $this->entityManifest($flow),
        ];
    }

    /**
     * Validates a document and creates the flow (always disabled). Returns the new flow.
     *
     * @param mixed $document the decoded JSON document
     *
     * @throws FlowImportException with every problem found
     */
    public function import(mixed $document): OntologyFlow
    {
        $errors = [];
        if (!\is_array($document) || array_is_list($document)) {
            throw new FlowImportException([$this->trans('invalid_format')]);
        }
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new FlowImportException([$this->trans('invalid_format')]);
        }
        if (($document['version'] ?? null) !== self::VERSION) {
            throw new FlowImportException([$this->trans('unsupported_version', [
                '{{ version }}' => \is_scalar($document['version'] ?? null) ? (string) $document['version'] : '?',
                '{{ supported }}' => (string) self::VERSION,
            ])]);
        }

        $flowData = $document['flow'] ?? null;
        if (!\is_array($flowData) || array_is_list($flowData)) {
            throw new FlowImportException([$this->trans('invalid_format')]);
        }
        $name = \is_string($flowData['name'] ?? null) ? trim($flowData['name']) : '';
        if ($name === '' || mb_strlen($name) > 128) {
            $errors[] = $this->trans('invalid_name');
        } elseif ($this->doctrine->getRepository(OntologyFlow::class)->findOneBy(['name' => $name]) !== null) {
            $errors[] = $this->trans('name_taken', ['{{ name }}' => $name]);
        }

        $steps = $flowData['steps'] ?? null;
        if ($steps !== null && !\is_array($steps)) {
            $errors[] = $this->trans('invalid_format');
            $steps = null;
        }
        $design = $flowData['design'] ?? null;
        if ($design !== null && (!\is_array($design) || array_is_list($design))) {
            $errors[] = $this->trans('invalid_format');
            $design = null;
        }
        if (\is_array($design)) {
            // A design of another canvas version would still be scheduled and executed (the runner
            // reads design.steps) while the editor refuses it as corrupted and opens empty — an
            // uneditable running flow. Refuse the file instead.
            if (($design['version'] ?? null) !== OntologyFlow::DESIGN_VERSION) {
                $errors[] = $this->trans('unsupported_design', [
                    '{{ version }}' => \is_scalar($design['version'] ?? null) ? (string) $design['version'] : '?',
                    '{{ supported }}' => (string) OntologyFlow::DESIGN_VERSION,
                ]);
            }
            // The logical steps decide type/triggerType (so, scheduling) while the canvas steps are
            // what actually executes: a document where they disagree is not importable.
            if (!$this->stepsAgree($steps, $design['steps'] ?? null)) {
                $errors[] = $this->trans('steps_design_mismatch');
            }
        }

        // A document must never smuggle in a RAW connector or subflow id: it would silently point
        // at whatever those ids happen to be here. Export always replaces both with descriptors.
        $rawKeys = ['connector' => 'raw_connector_id', 'subflow' => 'raw_subflow_id'];
        foreach ($this->allConfigs($steps, $design) as $config) {
            foreach ($rawKeys as $rawKey => $message) {
                if (\array_key_exists($rawKey, $config)) {
                    $errors[] = $this->trans($message);
                    unset($rawKeys[$rawKey]);
                }
            }
            if ($rawKeys === []) {
                break;
            }
        }

        // Resolve references against THIS environment, collecting every mismatch.
        $connectorIds = $this->resolveConnectors($steps, $design, $errors);
        $subflowIds = $this->resolveSubflows($steps, $design, $errors);
        $this->checkEntities($steps, $design, \is_array($document['entities'] ?? null) ? $document['entities'] : [], $errors);

        if ($errors !== []) {
            throw new FlowImportException(array_values(array_unique($errors)));
        }

        $importedSteps = $this->applySubflowIds(
            $this->applyConnectorIds(\is_array($steps) ? $steps : [], $connectorIds),
            $subflowIds
        );
        if (\is_array($design) && \is_array($design['steps'] ?? null)) {
            $design['steps'] = $this->applySubflowIds($this->applyConnectorIds($design['steps'], $connectorIds), $subflowIds);
        }

        // The imported steps must clear exactly the bar an editor save clears — otherwise a file
        // could store a flow the editor or the executor chokes on. Run it AFTER the connector ids
        // are back, since the step rules require a resolved connector.
        $normalized = $this->stepValidator->normalize($importedSteps);
        if ($normalized === null) {
            throw new FlowImportException([$this->trans('invalid_steps')]);
        }
        $stepErrors = $this->stepValidator->validate($normalized);
        if ($stepErrors !== []) {
            throw new FlowImportException($stepErrors);
        }
        $importedSteps = $normalized;

        $flow = new OntologyFlow();
        // The flow name IS the trigger step's name now — the document's name is only the fallback
        // for (legacy) triggerless subflow files. NOTE: the unique-name check above ran on the
        // document name; re-check when the trigger disagrees.
        foreach ($importedSteps as $importedStep) {
            if (\in_array($importedStep['type'] ?? null, OntologyFlow::TRIGGER_STEP_TYPES, true)
                && \is_string($importedStep['name'] ?? null) && trim($importedStep['name']) !== ''
            ) {
                $name = trim($importedStep['name']);
                break;
            }
        }
        if ($this->doctrine->getRepository(OntologyFlow::class)->findOneBy(['name' => $name]) !== null) {
            throw new FlowImportException([$this->trans('name_taken', ['{{ name }}' => $name])]);
        }
        $flow->setName($name);
        // Requirement: an imported flow never starts running on the strength of a file. Enabled
        // now LIVES on the trigger step's config, so the flag is forced off there (steps AND
        // design copies) — the column below only mirrors it.
        $importedSteps = $this->disableTrigger($importedSteps);
        if (\is_array($design) && \is_array($design['steps'] ?? null)) {
            $design['steps'] = $this->disableTrigger($design['steps']);
        }
        $flow->setEnabled(false);
        $flow->setSteps($importedSteps === [] ? null : $importedSteps);
        $flow->setDesign(\is_array($design) ? $design : null);
        // Never trust the document's type: derive it, exactly as a normal save does.
        $flow->setType(OntologyFlow::computeType($flow->getSteps()));
        $flow->setTriggerType(OntologyFlow::computeTriggerType($flow->getSteps()));
        $flow->setLastModified(new \DateTime('now', new \DateTimeZone('UTC')));

        $em = $this->doctrine->getManagerForClass(OntologyFlow::class);
        $em->persist($flow);
        $em->flush();

        return $flow;
    }

    /**
     * Replaces each config's connector id with a portable {name, type, system} descriptor and
     * each Call Subflow target id with a {name} one.
     *
     * @param mixed              $steps
     * @param array<int, string> $errors
     *
     * @return array<int, array<string, mixed>>
     */
    private function rewriteStepList(mixed $steps, array &$errors): array
    {
        $out = [];
        foreach (\is_array($steps) ? $steps : [] as $step) {
            if (!\is_array($step)) {
                // Never emit a silently truncated document: a malformed step means the flow is
                // broken, and dropping it would export something that no longer is that flow.
                $errors[] = $this->trans('malformed_step');
                continue;
            }
            $config = $step['config'] ?? null;
            if (\is_array($config) && \array_key_exists('connector', $config)) {
                $connector = $this->doctrine->getRepository(OntologyConnector::class)->find((int) $config['connector']);
                if ($connector === null) {
                    $errors[] = $this->trans('export_connector_missing', [
                        '{{ name }}' => (string) ($step['name'] ?? '?'),
                    ]);
                } else {
                    unset($config['connector']);
                    $config['connectorRef'] = [
                        'name' => $connector->getName(),
                        'type' => $connector->getType(),
                        'system' => $connector->getSystem()?->getName(),
                    ];
                }
                $step['config'] = $config;
            }
            if (\is_array($config) && \array_key_exists('subflow', $config)) {
                $target = $this->doctrine->getRepository(OntologyFlow::class)->find((int) $config['subflow']);
                // A wrong-type target is exactly as broken as a missing one: the run would fail.
                if ($target === null || $target->getType() !== OntologyFlow::TYPE_SUBFLOW) {
                    $errors[] = $this->trans('export_subflow_missing', [
                        '{{ name }}' => (string) ($step['name'] ?? '?'),
                    ]);
                } else {
                    unset($config['subflow']);
                    $config['subflowRef'] = ['name' => $target->getName()];
                }
                $step['config'] = $config;
            }
            $out[] = $step;
        }

        return $out;
    }

    /**
     * The referenced entities with the unique attribute the step configs do not carry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entityManifest(OntologyFlow $flow): array
    {
        $manifest = [];
        foreach ($this->entityRefs($flow->getSteps(), $flow->getDesign()) as $key => [$systemName, $entityName]) {
            $entity = $this->findEntity($systemName, $entityName);
            $manifest[] = [
                'system' => $systemName,
                'entity' => $entityName,
                // Null when the entity is already missing locally: names still travel, so the
                // export stays usable and the import reports what it cannot match.
                'uniqueAttribute' => $entity?->getUniqueAttribute(),
            ];
            unset($key);
        }

        return $manifest;
    }

    /**
     * Every (system, entity) pair referenced by a flow's steps, keyed to de-duplicate.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function entityRefs(mixed $steps, mixed $design): array
    {
        $refs = [];
        foreach ($this->allConfigs($steps, $design) as $config) {
            $system = \is_string($config[self::KEY_SYSTEM] ?? null) ? trim($config[self::KEY_SYSTEM]) : '';
            $entity = \is_string($config[self::KEY_ENTITY] ?? null) ? trim($config[self::KEY_ENTITY]) : '';
            if ($system !== '' && $entity !== '') {
                $refs[$system . "\0" . $entity] = [$system, $entity];
            }
        }

        return $refs;
    }

    /**
     * Every step config in both copies (the logical steps and the editor design).
     *
     * @return array<int, array<string, mixed>>
     */
    private function allConfigs(mixed $steps, mixed $design): array
    {
        $lists = [\is_array($steps) ? $steps : []];
        if (\is_array($design) && \is_array($design['steps'] ?? null)) {
            $lists[] = $design['steps'];
        }
        $configs = [];
        foreach ($lists as $list) {
            foreach ($list as $step) {
                if (\is_array($step) && \is_array($step['config'] ?? null)) {
                    $configs[] = $step['config'];
                }
            }
        }

        return $configs;
    }

    /**
     * Resolves every connectorRef to a local connector id, recording an error per unmatched (or
     * ambiguous) descriptor. Returns a map of descriptor key => local id.
     *
     * @param array<int, string> $errors
     *
     * @return array<string, int>
     */
    private function resolveConnectors(mixed $steps, mixed $design, array &$errors): array
    {
        $resolved = [];
        foreach ($this->allConfigs($steps, $design) as $config) {
            $ref = $config['connectorRef'] ?? null;
            if (!\is_array($ref)) {
                continue;
            }
            $key = self::connectorKey($ref);
            if (isset($resolved[$key])) {
                continue;
            }
            $name = \is_string($ref['name'] ?? null) ? trim($ref['name']) : '';
            $type = \is_string($ref['type'] ?? null) ? trim($ref['type']) : '';
            $systemName = \is_string($ref['system'] ?? null) ? trim($ref['system']) : '';
            if ($name === '' || $type === '' || $systemName === '') {
                // A partially-filled descriptor used to match loosely (any type / any system),
                // which is exactly the mis-binding this feature exists to prevent.
                $errors[] = $this->trans('incomplete_connector_ref', ['{{ name }}' => $name !== '' ? $name : '?']);
                continue;
            }

            /** @var OntologyConnector[] $candidates */
            $candidates = $this->doctrine->getRepository(OntologyConnector::class)->findBy(['name' => $name]);
            $matches = array_values(array_filter($candidates, static function (OntologyConnector $c) use ($type, $systemName): bool {
                $typeOk = $type === '' || (string) $c->getType() === $type;
                $systemOk = $systemName === '' || (string) $c->getSystem()?->getName() === $systemName;

                return $typeOk && $systemOk;
            }));

            if ($matches === []) {
                $errors[] = $this->trans('connector_missing', [
                    '{{ name }}' => $name,
                    '{{ type }}' => $type !== '' ? $type : '?',
                ]);
                continue;
            }
            if (\count($matches) > 1) {
                // Connector names are not unique across systems: refuse to guess.
                $errors[] = $this->trans('connector_ambiguous', ['{{ name }}' => $name]);
                continue;
            }
            $resolved[$key] = (int) $matches[0]->getId();
        }

        return $resolved;
    }

    /**
     * Resolves every subflowRef to a local flow id: the subflow must already exist HERE by name
     * (names are unique) and be of type subflow — so callers import AFTER their subflows.
     *
     * @param array<int, string> $errors
     *
     * @return array<string, int> trimmed name => local flow id
     */
    private function resolveSubflows(mixed $steps, mixed $design, array &$errors): array
    {
        $resolved = [];
        foreach ($this->allConfigs($steps, $design) as $config) {
            $ref = $config['subflowRef'] ?? null;
            if (!\is_array($ref)) {
                continue;
            }
            $name = \is_string($ref['name'] ?? null) ? trim($ref['name']) : '';
            if ($name === '') {
                $errors[] = $this->trans('incomplete_subflow_ref');
                continue;
            }
            if (isset($resolved[$name])) {
                continue;
            }
            $target = $this->doctrine->getRepository(OntologyFlow::class)->findOneBy(['name' => $name]);
            if ($target === null) {
                $errors[] = $this->trans('subflow_missing', ['{{ name }}' => $name]);
                continue;
            }
            if ($target->getType() !== OntologyFlow::TYPE_SUBFLOW) {
                $errors[] = $this->trans('subflow_not_subflow', ['{{ name }}' => $name]);
                continue;
            }
            $resolved[$name] = (int) $target->getId();
        }

        return $resolved;
    }

    /**
     * Checks every referenced entity exists here under the same system, with the same unique
     * attribute when the document states one.
     *
     * @param array<int, mixed>  $manifest
     * @param array<int, string> $errors
     */
    private function checkEntities(mixed $steps, mixed $design, array $manifest, array &$errors): void
    {
        $expectedAttribute = [];
        foreach ($manifest as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $system = \is_string($row[self::KEY_SYSTEM] ?? null) ? trim($row[self::KEY_SYSTEM]) : '';
            $entity = \is_string($row[self::KEY_ENTITY] ?? null) ? trim($row[self::KEY_ENTITY]) : '';
            if ($system !== '' && $entity !== '' && \is_string($row['uniqueAttribute'] ?? null)) {
                $expectedAttribute[$system . "\0" . $entity] = trim($row['uniqueAttribute']);
            }
        }

        // Driven by what the STEPS actually reference, so a doctored/short manifest cannot hide a
        // reference from validation.
        foreach ($this->entityRefs($steps, $design) as $key => [$systemName, $entityName]) {
            $entity = $this->findEntity($systemName, $entityName);
            if ($entity === null) {
                $errors[] = $this->trans('entity_missing', [
                    '{{ entity }}' => $entityName,
                    '{{ system }}' => $systemName,
                ]);
                continue;
            }
            $expected = $expectedAttribute[$key] ?? null;
            if ($expected !== null && $expected !== (string) $entity->getUniqueAttribute()) {
                $errors[] = $this->trans('entity_attribute_mismatch', [
                    '{{ entity }}' => $entityName,
                    '{{ system }}' => $systemName,
                    '{{ expected }}' => $expected,
                    '{{ actual }}' => (string) $entity->getUniqueAttribute(),
                ]);
            }
        }
    }

    /**
     * Puts the resolved connector ids back into the configs, dropping the descriptors.
     *
     * @param array<int, mixed>   $steps
     * @param array<string, int>  $connectorIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyConnectorIds(array $steps, array $connectorIds): array
    {
        $out = [];
        foreach ($steps as $step) {
            if (!\is_array($step)) {
                // Keep it: the shared step validator rejects the document rather than importing a
                // silently truncated flow.
                $out[] = $step;
                continue;
            }
            $config = $step['config'] ?? null;
            if (\is_array($config) && \is_array($config['connectorRef'] ?? null)) {
                $key = self::connectorKey($config['connectorRef']);
                unset($config['connectorRef']);
                if (isset($connectorIds[$key])) {
                    // Stored as a string, matching what the editor saves.
                    $config['connector'] = (string) $connectorIds[$key];
                }
                $step['config'] = $config;
            }
            $out[] = $step;
        }

        return $out;
    }

    /**
     * Puts the resolved subflow ids back into the configs, dropping the descriptors.
     *
     * @param array<int, mixed>  $steps
     * @param array<string, int> $subflowIds
     *
     * @return array<int, mixed>
     */
    private function applySubflowIds(array $steps, array $subflowIds): array
    {
        $out = [];
        foreach ($steps as $step) {
            if (!\is_array($step)) {
                $out[] = $step;
                continue;
            }
            $config = $step['config'] ?? null;
            if (\is_array($config) && \is_array($config['subflowRef'] ?? null)) {
                $name = \is_string($config['subflowRef']['name'] ?? null) ? trim($config['subflowRef']['name']) : '';
                unset($config['subflowRef']);
                if (isset($subflowIds[$name])) {
                    // Stored as a string, matching what the editor saves.
                    $config['subflow'] = (string) $subflowIds[$name];
                }
                $step['config'] = $config;
            }
            $out[] = $step;
        }

        return $out;
    }

    /**
     * Do the logical steps and the canvas steps describe the same flow? Compared by the (type,
     * name) multiset — coordinates and key order are cosmetic, but a difference here means the
     * flow would be SCHEDULED from one definition and EXECUTED from the other.
     */
    private function stepsAgree(mixed $steps, mixed $designSteps): bool
    {
        $key = static function (mixed $list): array {
            $out = [];
            foreach (\is_array($list) ? $list : [] as $step) {
                if (\is_array($step)) {
                    $out[] = ($step['type'] ?? '?') . "\0" . ($step['name'] ?? '?');
                }
            }
            sort($out);

            return $out;
        };

        return $key($steps) === $key($designSteps);
    }

    private function findEntity(string $systemName, string $entityName): ?OntologyEntity
    {
        $system = $this->doctrine->getRepository(OntologySystem::class)->findOneBy(['name' => $systemName]);
        if ($system === null) {
            return null;
        }

        return $this->doctrine->getRepository(OntologyEntity::class)
            ->findOneBy(['system' => $system, 'name' => $entityName]);
    }

    /**
     * @param array<string, mixed> $ref
     */
    private static function connectorKey(array $ref): string
    {
        return implode("\0", [
            \is_string($ref['name'] ?? null) ? trim($ref['name']) : '',
            \is_string($ref['type'] ?? null) ? trim($ref['type']) : '',
            \is_string($ref['system'] ?? null) ? trim($ref['system']) : '',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    /**
     * Forces every trigger step's `enabled` config flag OFF (import safety: nothing runs on the
     * strength of a file).
     *
     * @param array<int, mixed> $steps
     *
     * @return array<int, mixed>
     */
    private function disableTrigger(array $steps): array
    {
        foreach ($steps as $i => $step) {
            if (\is_array($step) && \in_array($step['type'] ?? null, OntologyFlow::TRIGGER_STEP_TYPES, true)) {
                $config = \is_array($step['config'] ?? null) ? $step['config'] : [];
                $config['enabled'] = false;
                $step['config'] = $config;
                $steps[$i] = $step;
            }
        }

        return $steps;
    }

    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans('aaxis.ontology.flow_portability.' . $key, $params, 'messages');
    }
}

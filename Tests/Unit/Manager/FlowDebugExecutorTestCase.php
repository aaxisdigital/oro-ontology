<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyEntity;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Entity\OntologySystem;
use Aaxis\Bundle\OntologyBundle\Manager\DatabaseQueryRunner;
use Aaxis\Bundle\OntologyBundle\Manager\FileConnectorTransfer;
use Aaxis\Bundle\OntologyBundle\Manager\FlowDebugExecutor;
use Aaxis\Bundle\OntologyBundle\Manager\OAuthTokenProvider;
use Aaxis\Bundle\OntologyBundle\Manager\FlowStepValidator;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Aaxis\Bundle\OntologyBundle\Manager\PhpMethodInvoker;
use Aaxis\Bundle\OntologyBundle\Manager\RestConnectorClient;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Shared harness for the FlowDebugExecutor tests: every collaborator is a mock, the HTTP client a
 * MockHttpClient fed from $responses, every emitted flow event is recorded in $events as
 * [event, flow name, payload] and every queued message in $queued as [topic, body].
 */
abstract class FlowDebugExecutorTestCase extends TestCase
{
    protected OntologyDataApiManager&MockObject $dataApi;
    protected ManagerRegistry&MockObject $doctrine;
    protected ObjectManager&MockObject $em;
    protected FileConnectorTransfer&MockObject $files;
    protected FlowStepValidator&MockObject $validator;
    protected DatabaseQueryRunner&MockObject $database;
    protected LoggerInterface&MockObject $logger;
    protected PhpMethodInvoker&MockObject $php;
    protected OntologyFlowEventEmitter&MockObject $emitter;
    protected MessageProducerInterface&MockObject $producer;
    protected FlowDebugExecutor $executor;

    /** The OAuth token cache (aaxis_ontology.cache in the app) — real, so token reuse is exercised. */
    protected ArrayAdapter $oauthCache;

    /** @var array<int, MockResponse> */
    protected array $responses = [];

    /** @var array<int, array{method: string, url: string, options: array<string, mixed>}> */
    protected array $requests = [];

    /** @var array<int, array{0: string, 1: string|null, 2: array<string, mixed>}> */
    protected array $events = [];

    /** @var array<int, array{0: string, 1: mixed}> messages sent to the queue: [topic, body] */
    protected array $queued = [];

    /** When set, the message producer throws it instead of queueing. */
    protected ?\Throwable $queueFailure = null;

    /** @var array<int, OntologyFlow> */
    protected array $flows = [];

    /** @var array<int, OntologyConnector> */
    protected array $connectors = [];

    /** @var array<string, OntologyEntity> "system/entity" => entity */
    protected array $entities = [];

    protected function setUp(): void
    {
        $this->dataApi = $this->createMock(OntologyDataApiManager::class);
        $this->dataApi->method('generateUuid')->willReturn('run-uuid');
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->doctrine->method('getRepository')->willReturnCallback(fn (string $class): ObjectRepository => $this->repository($class));
        $this->em = $this->createMock(ObjectManager::class);
        $this->doctrine->method('getManagerForClass')->with(OntologyFlow::class)->willReturn($this->em);
        $this->files = $this->createMock(FileConnectorTransfer::class);
        $this->files->method('supports')->willReturnCallback(
            static fn (string $type): bool => \in_array($type, ['file_system', 'sftp', 'bucket'], true)
        );
        $this->validator = $this->createMock(FlowStepValidator::class);
        $this->validator->method('validate')->willReturn([]);
        $this->database = $this->createMock(DatabaseQueryRunner::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->php = $this->createMock(PhpMethodInvoker::class);
        $this->emitter = $this->createMock(OntologyFlowEventEmitter::class);
        $this->emitter->method('emit')->willReturnCallback(function (?OntologyFlow $flow, ?string $uuid, string $event, array $payload = []): void {
            $this->events[] = [$event, $flow?->getName(), $payload];
        });
        $this->emitter->method('emitRaw')->willReturnCallback(function (?int $id, ?string $name, ?string $uuid, string $event, array $payload = []): void {
            $this->events[] = [$event, $name, $payload];
        });
        $this->producer = $this->createMock(MessageProducerInterface::class);
        $this->producer->method('send')->willReturnCallback(function (string $topic, mixed $body): void {
            if ($this->queueFailure !== null) {
                throw $this->queueFailure;
            }
            $this->queued[] = [$topic, $body];
        });
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? new MockResponse('{}');
        });

        $this->executor = new FlowDebugExecutor(
            $this->dataApi,
            $this->doctrine,
            $http,
            new DwlTransformer(),
            $this->files,
            $this->validator,
            $this->database,
            $this->logger,
            $this->php,
            $this->emitter,
            new RestConnectorClient($http, new OAuthTokenProvider($http, $this->oauthCache = new ArrayAdapter())),
            $this->producer
        );
    }

    private function repository(string $class): ObjectRepository
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->willReturnCallback(fn (mixed $id): ?object => match ($class) {
            OntologyFlow::class => $this->flows[(int) $id] ?? null,
            OntologyConnector::class => $this->connectors[(int) $id] ?? null,
            default => null,
        });
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria) use ($class): ?object {
            if ($class === OntologySystem::class) {
                foreach ($this->entities as $entity) {
                    if ($entity->getSystem()->getName() === $criteria['name']) {
                        return $entity->getSystem();
                    }
                }

                return null;
            }
            if ($class === OntologyEntity::class) {
                return $this->entities[$criteria['system']->getName() . '/' . $criteria['name']] ?? null;
            }
            if ($class === OntologyFlow::class) {
                // Flow names are unique: a computed subflow target is looked up by name.
                foreach ($this->flows as $flow) {
                    if ($flow->getName() === $criteria['name']) {
                        return $flow;
                    }
                }
            }

            return null;
        });

        return $repository;
    }

    /**
     * @param array<string, mixed>|null $config
     *
     * @return array{id: string, type: string, name: string, config: array<string, mixed>|null}
     */
    protected static function step(string $id, string $type, ?array $config = null, ?string $name = null): array
    {
        return ['id' => $id, 'type' => $type, 'name' => $name ?? $id, 'config' => $config];
    }

    /**
     * @return array{from: string, fromPort: int, to: string}
     */
    protected static function link(string $from, string $to, int $port = 0): array
    {
        return ['from' => $from, 'fromPort' => $port, 'to' => $to];
    }

    /** A saved flow (it has an id, so its run stamps are persisted). */
    protected function savedFlow(int $id, string $name, string $type = OntologyFlow::TYPE_FLOW): OntologyFlow
    {
        $flow = (new OntologyFlow())->setName($name)->setType($type);
        (new \ReflectionProperty(OntologyFlow::class, 'id'))->setValue($flow, $id);

        return $this->flows[$id] = $flow;
    }

    /**
     * A saved, enabled subflow whose design runs $steps (the first one should be its trigger).
     *
     * @param array<int, array<string, mixed>> $steps
     * @param array<int, array<string, mixed>> $links
     */
    protected function subflow(int $id, string $name, array $steps, array $links = []): OntologyFlow
    {
        return $this->savedFlow($id, $name, OntologyFlow::TYPE_SUBFLOW)->setDesign(['steps' => $steps, 'links' => $links]);
    }

    protected function connector(int $id, string $type, ?array $config = null): OntologyConnector
    {
        $connector = (new OntologyConnector())->setName('C' . $id)->setType($type)->setConfig($config);
        (new \ReflectionProperty(OntologyConnector::class, 'id'))->setValue($connector, $id);

        return $this->connectors[$id] = $connector;
    }

    protected function entity(string $system, string $name): OntologyEntity
    {
        return $this->entities[$system . '/' . $name] = (new OntologyEntity())
            ->setSystem((new OntologySystem())->setName($system))
            ->setName($name);
    }

    /**
     * Runs a trigger + ONE step of the given type and returns the resulting context.
     *
     * @param array<string, mixed>|null $config
     * @param array<string, mixed>      $payload the trigger's payload input
     *
     * @return array<string, mixed>
     */
    protected function runStep(string $type, ?array $config, array $payload = [], ?OntologyFlow $flow = null): array
    {
        return $this->executor->execute(
            [self::step('t', 'entity_change'), self::step('s', $type, $config, 'Step')],
            [self::link('t', 's')],
            ['payload' => $payload],
            $flow
        );
    }

    /** The recorded event kinds, in emission order. */
    protected function eventKinds(): array
    {
        return array_map(static fn (array $e): string => $e[0], $this->events);
    }
}

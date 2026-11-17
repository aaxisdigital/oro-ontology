<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Async;

use Aaxis\Bundle\OntologyBundle\Async\OntologyHttpRequestProcessor;
use Aaxis\Bundle\OntologyBundle\Async\Topic\OntologyHttpRequestTopic;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Manager\OAuthTokenProvider;
use Aaxis\Bundle\OntologyBundle\Manager\OntologyFlowEventEmitter;
use Aaxis\Bundle\OntologyBundle\Manager\RestConnectorClient;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OntologyHttpRequestProcessorTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private OntologyFlowEventEmitter&MockObject $emitter;
    private OntologyHttpRequestProcessor $processor;

    /** @var array<int, OntologyConnector> */
    private array $connectors = [];

    /** @var array<int, MockResponse> */
    private array $responses = [];

    /** @var array<int, array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<int, array<int, mixed>> emitRaw() arguments, in order */
    private array $events = [];

    protected function setUp(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->willReturnCallback(fn (mixed $id): ?OntologyConnector => $this->connectors[(int) $id] ?? null);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getRepository')->with(OntologyConnector::class)->willReturn($repository);
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? new MockResponse('{}');
        });
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->emitter = $this->createMock(OntologyFlowEventEmitter::class);
        $this->emitter->method('emitRaw')->willReturnCallback(function (mixed ...$args): void {
            $this->events[] = $args;
        });

        $this->processor = new OntologyHttpRequestProcessor(
            $doctrine,
            new RestConnectorClient($http, new OAuthTokenProvider($http, new ArrayAdapter())),
            $this->emitter,
            $this->logger
        );
    }

    private function connector(int $id, string $type, ?array $config): void
    {
        $connector = (new OntologyConnector())->setName('C' . $id)->setType($type)->setConfig($config);
        (new \ReflectionProperty(OntologyConnector::class, 'id'))->setValue($connector, $id);
        $this->connectors[$id] = $connector;
    }

    private function process(mixed $body): string
    {
        $message = $this->createMock(MessageInterface::class);
        $message->method('getBody')->willReturn($body);

        return $this->processor->process($message, $this->createMock(SessionInterface::class));
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(array $overrides = []): array
    {
        return $overrides + [
            'connector_id' => 7, 'method' => 'POST', 'path' => '/orders', 'body' => '{"id":3}', 'body_type' => 'json',
            'step' => 'Notify', 'flow_id' => 4, 'flow_name' => 'Batch', 'flow_uuid' => 'run-1',
        ];
    }

    public function testItSubscribesToTheHttpRequestTopic(): void
    {
        self::assertSame([OntologyHttpRequestTopic::getName()], OntologyHttpRequestProcessor::getSubscribedTopics());
    }

    public function testTheRequestIsSentAndItsResponseDiscarded(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'api.example.com', 'headers' => ['X-Tenant' => 't1']]);
        $this->responses[] = new MockResponse('{"stored":"nowhere"}', ['http_code' => 201]);
        $this->logger->expects(self::never())->method('error');

        self::assertSame(MessageProcessorInterface::ACK, $this->process(self::message()));

        self::assertCount(1, $this->requests);
        self::assertSame(['POST', 'https://api.example.com/orders'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame('{"id":3}', $this->requests[0]['options']['body']);
        self::assertContains('Content-Type: application/json', $this->requests[0]['options']['headers']);
        self::assertContains('X-Tenant: t1', $this->requests[0]['options']['headers']);
        self::assertSame([], $this->events);
    }

    public function testOAuthTokensOfUnknownLifetimeAreScopedToTheQueuingRun(): void
    {
        $this->connector(7, 'rest_api', ['server' => 'https://api', 'auth' => 'oauth', 'oauth' => ['path' => 'token']]);
        $this->responses = [new MockResponse('{"access_token":"abc"}'), new MockResponse('[1]'), new MockResponse('[2]')];

        $this->process(self::message());
        $this->process(self::message());

        self::assertSame(
            ['POST https://api/token', 'POST https://api/orders', 'POST https://api/orders'],
            array_map(static fn (array $r): string => $r['method'] . ' ' . $r['url'], $this->requests)
        );
        self::assertContains('Authorization: Bearer abc', $this->requests[2]['options']['headers']);
    }

    /**
     * @dataProvider failures
     *
     * @param array<int, MockResponse> $responses
     */
    public function testAFailureIsLoggedAndRecordedOnTheRun(?string $type, array $responses, string $detail): void
    {
        if ($type !== null) {
            $this->connector(7, $type, ['server' => 'h']);
        }
        $this->responses = $responses;
        $expected = 'Step "Notify" (async HTTP request): ' . $detail;
        $this->logger->expects(self::once())->method('error')->with('aaxis_ontology_http_request: ' . $expected);

        self::assertSame(MessageProcessorInterface::REJECT, $this->process(self::message()));

        self::assertSame([[4, 'Batch', 'run-1', 'log-message', ['message' => $expected]]], $this->events);
    }

    /**
     * @return array<string, array{0: string|null, 1: array<int, MockResponse>, 2: string}>
     */
    public static function failures(): array
    {
        return [
            'connector gone' => [null, [], 'the configured connector no longer exists.'],
            'connector no longer rest_api' => ['sftp', [], 'a "sftp" connector cannot send HTTP requests.'],
            'error status' => ['rest_api', [new MockResponse('down', ['http_code' => 503])], 'the connector responded HTTP 503.'],
            'transport error' => ['rest_api', [new MockResponse('', ['error' => 'timeout'])], 'timeout'],
        ];
    }

    public function testAFailureOfAnUnsavedFlowStillReachesTheLog(): void
    {
        $this->logger->expects(self::once())->method('error');

        self::assertSame(MessageProcessorInterface::REJECT, $this->process(['connector_id' => 7, 'flow_id' => '4', 'flow_uuid' => 9]));

        self::assertSame([[null, null, null, 'log-message', ['message' => 'Step "" (async HTTP request): the configured connector no longer exists.']]], $this->events);
    }

    /**
     * @dataProvider malformedMessages
     */
    public function testAMalformedMessageIsRejected(mixed $body): void
    {
        $this->logger->expects(self::once())->method('error')->with('aaxis_ontology_http_request: malformed message.');

        self::assertSame(MessageProcessorInterface::REJECT, $this->process($body));

        self::assertSame([], $this->requests);
        self::assertSame([], $this->events);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedMessages(): array
    {
        return [
            'not an array' => ['garbage'],
            'no connector' => [['path' => 'x']],
            'connector not an id' => [['connector_id' => '7']],
        ];
    }
}

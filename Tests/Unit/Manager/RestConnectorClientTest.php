<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorRequestFailure;
use Aaxis\Bundle\OntologyBundle\Manager\OAuthTokenProvider;
use Aaxis\Bundle\OntologyBundle\Manager\RestConnectorClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The client's request behaviour is exercised end to end through the HTTP Request step in
 * FlowDebugExecutorTest; these cover its own contract.
 */
class RestConnectorClientTest extends TestCase
{
    /** @var array<int, array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    private function client(MockResponse ...$responses): RestConnectorClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new RestConnectorClient($http, new OAuthTokenProvider($http, new ArrayAdapter()));
    }

    private static function connector(?array $config): OntologyConnector
    {
        return (new OntologyConnector())->setName('C')->setType(OntologyConnector::TYPE_REST_API)->setConfig($config);
    }

    /**
     * @dataProvider baseUrls
     */
    public function testBaseUrl(array $config, string $expected): void
    {
        self::assertSame($expected, $this->client()->baseUrl(self::connector($config)));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function baseUrls(): array
    {
        return [
            'scheme assumed' => [['server' => 'api.example.com/'], 'https://api.example.com'],
            'port appended' => [['server' => 'http://h', 'port' => 81], 'http://h:81'],
            'explicit port wins' => [['server' => 'https://h:9443', 'port' => 81], 'https://h:9443'],
        ];
    }

    public function testBaseUrlNeedsAServer(): void
    {
        $this->expectException(ConnectorRequestFailure::class);
        $this->expectExceptionMessage('the connector has no server configured.');

        $this->client()->baseUrl(self::connector(['server' => ' ']));
    }

    public function testAnEmptyRenderedBodyIsStillSent(): void
    {
        self::assertSame('raw', $this->client(new MockResponse('raw'))->send(self::connector(['server' => 'h']), 'put', 'x', '', 'xml', null));

        self::assertSame('PUT', $this->requests[0]['method']);
        self::assertContains('Content-Type: application/xml', $this->requests[0]['options']['headers']);
    }

    public function testFailuresKeepTheirCause(): void
    {
        try {
            $this->client(new MockResponse('', ['error' => 'refused']))->send(self::connector(['server' => 'h']), '', 'x', null, 'text', null);
            self::fail('Expected a failure.');
        } catch (ConnectorRequestFailure $e) {
            self::assertSame('refused', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
        self::assertSame('GET', $this->requests[0]['method']);
    }
}

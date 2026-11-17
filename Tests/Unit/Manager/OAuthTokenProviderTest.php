<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Exception\OAuthTokenFailure;
use Aaxis\Bundle\OntologyBundle\Manager\OAuthTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OAuthTokenProviderTest extends TestCase
{
    private const BASE = 'https://api';
    private const OAUTH = ['path' => '/token', 'headers' => ['X-A' => 'b', '' => 'skip'], 'body' => ['grant_type' => 'client_credentials', 'n' => ['skip']]];

    /** @var list<MockResponse> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    private ArrayAdapter $cache;

    private OAuthTokenProvider $provider;

    protected function setUp(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? new MockResponse('{}');
        });
        $this->cache = new ArrayAdapter();
        $this->provider = new OAuthTokenProvider($http, $this->cache);
    }

    private function token(string $value, ?int $expiresIn = null, ?string $type = null): MockResponse
    {
        return new MockResponse((string) json_encode(array_filter(
            ['access_token' => $value, 'expires_in' => $expiresIn, 'token_type' => $type],
            static fn (mixed $v): bool => $v !== null,
        )));
    }

    public function testRequestsTheTokenAsConfigured(): void
    {
        $this->responses[] = $this->token('abc', 3600, 'MAC');

        self::assertSame(
            ['authorization' => 'MAC abc', 'cached' => false],
            $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-1')
        );
        self::assertSame(['POST', 'https://api/token'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame('grant_type=client_credentials', $this->requests[0]['options']['body']);
        self::assertContains('X-A: b', $this->requests[0]['options']['headers']);
    }

    public function testATokenWithALifetimeIsSharedAcrossRuns(): void
    {
        $this->responses[] = $this->token('abc', 3600);

        $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-1');
        $again = $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-2');
        $otherProcess = (new OAuthTokenProvider(new MockHttpClient(), $this->cache))->authorization(7, self::BASE, self::OAUTH, null);

        self::assertSame(['authorization' => 'Bearer abc', 'cached' => true], $again);
        self::assertSame(['authorization' => 'Bearer abc', 'cached' => true], $otherProcess);
        self::assertCount(1, $this->requests);
    }

    public function testATokenWithoutALifetimeIsOnlyReusedWithinItsRun(): void
    {
        $this->responses = [$this->token('one'), $this->token('two'), $this->token('three'), $this->token('four')];

        $first = $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-1');
        $sameRun = $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-1');
        $nextRun = $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-2');
        $noRun = $this->provider->authorization(7, self::BASE, self::OAUTH, null);
        $noRunAgain = $this->provider->authorization(7, self::BASE, self::OAUTH, null);

        self::assertSame(['Bearer one', false], [$first['authorization'], $first['cached']]);
        self::assertSame(['Bearer one', true], [$sameRun['authorization'], $sameRun['cached']]);
        self::assertSame(['Bearer two', false], [$nextRun['authorization'], $nextRun['cached']]);
        self::assertSame('Bearer three', $noRun['authorization']);
        self::assertSame('Bearer four', $noRunAgain['authorization']);
        self::assertFalse($this->cache->hasItem('aaxis_ontology_oauth_token.7'));
    }

    public function testAnExpiredOnArrivalTokenIsNotCached(): void
    {
        $this->responses = [$this->token('one', 0), $this->token('two', 0)];

        $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-1');
        $again = $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-1');

        self::assertSame(['authorization' => 'Bearer two', 'cached' => false], $again);
    }

    public function testDifferentSettingsOrConnectorsDoNotShareTokens(): void
    {
        $this->responses = [$this->token('a', 3600), $this->token('b', 3600), $this->token('c', 3600), $this->token('d', 3600)];

        $this->provider->authorization(7, self::BASE, self::OAUTH, null);
        $otherConnector = $this->provider->authorization(8, self::BASE, self::OAUTH, null);
        $otherServer = $this->provider->authorization(7, 'https://elsewhere', self::OAUTH, null);
        $otherSecret = $this->provider->authorization(7, self::BASE, ['body' => ['client_secret' => 'x']] + self::OAUTH, null);

        self::assertSame(
            ['Bearer b', 'Bearer c', 'Bearer d'],
            [$otherConnector['authorization'], $otherServer['authorization'], $otherSecret['authorization']]
        );
    }

    public function testAnUnsavedConnectorIsNeverCached(): void
    {
        $this->responses = [$this->token('a', 3600), $this->token('b', 3600)];

        $this->provider->authorization(null, self::BASE, self::OAUTH, 'run-1');
        $again = $this->provider->authorization(null, self::BASE, self::OAUTH, 'run-1');
        $this->provider->invalidate(null);

        self::assertSame(['authorization' => 'Bearer b', 'cached' => false], $again);
    }

    public function testInvalidateDropsBothKindsOfToken(): void
    {
        $this->responses = [$this->token('shared', 3600), $this->token('fresh', 3600), $this->token('run'), $this->token('fresh-run')];

        $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-1');
        $this->provider->invalidate(7);
        $shared = $this->provider->authorization(7, self::BASE, self::OAUTH, 'run-1');

        $this->provider->authorization(8, self::BASE, self::OAUTH, 'run-1');
        $this->provider->invalidate(8);
        $run = $this->provider->authorization(8, self::BASE, self::OAUTH, 'run-1');

        self::assertSame(['authorization' => 'Bearer fresh', 'cached' => false], $shared);
        self::assertSame(['authorization' => 'Bearer fresh-run', 'cached' => false], $run);
    }

    public function testAForeignCacheEntryIsIgnored(): void
    {
        $item = $this->cache->getItem('aaxis_ontology_oauth_token.7');
        $this->cache->save($item->set('garbage'));
        $this->responses[] = $this->token('abc', 3600);

        self::assertSame(
            ['authorization' => 'Bearer abc', 'cached' => false],
            $this->provider->authorization(7, self::BASE, self::OAUTH, null)
        );
    }

    /**
     * @dataProvider lifetimes
     */
    public function testCachesUntilShortlyBeforeExpiry(int $expiresIn, int $ttl): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with($ttl)->willReturnSelf();
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $pool->expects(self::once())->method('save')->with($item);
        $http = new MockHttpClient([$this->token('abc', $expiresIn)]);

        (new OAuthTokenProvider($http, $pool))->authorization(7, self::BASE, self::OAUTH, null);
    }

    public static function lifetimes(): array
    {
        return [
            'an hour keeps a minute in hand' => [3600, 3540],
            'short lifetimes keep 10%' => [300, 270],
            'tiny lifetimes keep nothing back' => [5, 5],
        ];
    }

    /**
     * @dataProvider failures
     *
     * @param array<string, mixed> $oauth
     */
    public function testFailures(array $oauth, ?MockResponse $response, string $message): void
    {
        if ($response !== null) {
            $this->responses[] = $response;
        }
        $this->expectException(OAuthTokenFailure::class);
        $this->expectExceptionMessage($message);

        $this->provider->authorization(7, self::BASE, $oauth, null);
    }

    public static function failures(): array
    {
        return [
            'no token path' => [[], null, 'the connector has no OAuth token path configured.'],
            'transport failure' => [['path' => 't'], new MockResponse('', ['error' => 'refused']), 'OAuth token request failed — refused'],
            'no access token' => [['path' => 't'], new MockResponse('{"token_type":"x"}'), 'the OAuth response carries no access_token.'],
            'not json' => [['path' => 't'], new MockResponse('nope'), 'the OAuth response carries no access_token.'],
        ];
    }
}

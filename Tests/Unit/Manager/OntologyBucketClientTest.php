<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\OntologyBucketClient;
use Aaxis\Bundle\OntologyBundle\Manager\S3RequestSigner;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OntologyBucketClientTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    /** @var array<int, MockResponse|\Throwable> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->config = [
            'aaxis_ontology.use_bucket_for_entity_data' => true,
            'aaxis_ontology.use_bucket_for_flow_events' => false,
            'aaxis_ontology.bucket_endpoint_url' => 'https://ns.compat.objectstorage.sa-saopaulo-1.oraclecloud.com',
            'aaxis_ontology.bucket_name' => 'ontology',
            'aaxis_ontology.bucket_access_key' => 'enc(AKID)',
            'aaxis_ontology.bucket_secret_key' => 'enc(SECRET)',
            'aaxis_ontology.bucket_base_path' => ' /aaxis-ontology/ ',
        ];
        $this->requests = [];
        $this->responses = [];
    }

    private function client(): OntologyBucketClient
    {
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnCallback(fn (string $name) => $this->config[$name] ?? null);

        // Stored keys are encrypted by the config form type; "enc(x)" stands for that here.
        $crypter = $this->createMock(SymmetricCrypterInterface::class);
        $crypter->method('decryptData')->willReturnCallback(
            static fn (string $value): string => preg_replace('/^enc\((.*)\)$/', '$1', $value) ?? $value
        );

        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string) ($options['body'] ?? '')];

            $next = array_shift($this->responses) ?? new MockResponse('', ['http_code' => 200]);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });

        return new OntologyBucketClient($config, $crypter, new S3RequestSigner(), $http);
    }

    private function respond(int $status, string $body = ''): void
    {
        $this->responses[] = new MockResponse($body, ['http_code' => $status]);
    }

    /**
     * @dataProvider incompleteConfigProvider
     */
    public function testIsNotConfiguredWithoutAllOfEndpointBucketAndKeys(string $missingKey): void
    {
        $this->config[$missingKey] = $missingKey === 'aaxis_ontology.bucket_name' ? '  ' : '';

        self::assertFalse($this->client()->isConfigured());
        self::assertFalse($this->client()->isEnabled());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function incompleteConfigProvider(): array
    {
        return [
            'endpoint' => ['aaxis_ontology.bucket_endpoint_url'],
            'bucket' => ['aaxis_ontology.bucket_name'],
            'access key' => ['aaxis_ontology.bucket_access_key'],
            'secret key' => ['aaxis_ontology.bucket_secret_key'],
        ];
    }

    public function testEnabledNeedsBothTheToggleAndAUsableConnection(): void
    {
        self::assertTrue($this->client()->isConfigured());
        self::assertTrue($this->client()->isEnabled());
        self::assertFalse($this->client()->isEnabledFor('use_bucket_for_flow_events'));

        $this->config['aaxis_ontology.use_bucket_for_flow_events'] = true;
        self::assertTrue($this->client()->isEnabledFor('use_bucket_for_flow_events'));

        $this->config['aaxis_ontology.use_bucket_for_entity_data'] = false;
        self::assertFalse($this->client()->isEnabled());
        self::assertTrue($this->client()->isConfigured(), 'a switched-off toggle does not make the connection unusable');
    }

    public function testEnabledIsFalseWhenTheToggleIsOnButTheConnectionIsNot(): void
    {
        $this->config['aaxis_ontology.bucket_name'] = '';

        self::assertFalse($this->client()->isEnabled());
    }

    public function testBasePathIsTrimmedOfSlashesAndSpaces(): void
    {
        self::assertSame('aaxis-ontology', $this->client()->basePath());

        $this->config['aaxis_ontology.bucket_base_path'] = null;
        self::assertSame('', $this->client()->basePath());
    }

    public function testGetReturnsTheBodyThroughASignedPathStyleRequest(): void
    {
        $this->respond(200, '{"a":1}');

        self::assertSame('{"a":1}', $this->client()->get('aaxis-ontology/data/1 2.json'));

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('GET', $request['method']);
        // Path-style addressing, each segment URL-encoded exactly as it was signed.
        self::assertSame(
            'https://ns.compat.objectstorage.sa-saopaulo-1.oraclecloud.com/ontology/aaxis-ontology/data/1%202.json',
            $request['url']
        );
        // Credentials are decrypted before signing, and the region comes from the host.
        self::assertMatchesRegularExpression(
            '~^AWS4-HMAC-SHA256 Credential=AKID/\d{8}/sa-saopaulo-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$~',
            $request['headers']['authorization']
        );
        self::assertSame(hash('sha256', ''), $request['headers']['x-amz-content-sha256']);
        self::assertStringNotContainsString('SECRET', implode(' ', $request['headers']));
    }

    public function testGetReturnsNullForAMissingKeyAndThrowsForOtherErrors(): void
    {
        $client = $this->client();
        $this->respond(404, 'NoSuchKey');
        self::assertNull($client->get('k'));

        $this->respond(403, 'denied');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The ontology bucket refused GET of "k" (HTTP 403).');
        $client->get('k');
    }

    public function testPutSendsTheBodyWithItsHashAndAJsonContentType(): void
    {
        $this->respond(200);

        $this->client()->put('some/key.json', '{"x":"ü"}');

        $request = $this->requests[0];
        self::assertSame('PUT', $request['method']);
        self::assertSame('{"x":"ü"}', $request['body']);
        self::assertSame(hash('sha256', '{"x":"ü"}'), $request['headers']['x-amz-content-sha256']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertStringContainsString('SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date', $request['headers']['authorization']);
    }

    public function testPutFailureIsReported(): void
    {
        $this->respond(500);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The ontology bucket refused PUT of "k" (HTTP 500).');
        $this->client()->put('k', 'b');
    }

    public function testDeleteIsIdempotent(): void
    {
        $client = $this->client();
        $this->respond(204);
        $this->respond(404);

        $client->delete('a');
        $client->delete('b');

        self::assertSame(['DELETE', 'DELETE'], array_column($this->requests, 'method'));
    }

    public function testDeleteFailureIsReported(): void
    {
        $this->respond(403);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The ontology bucket refused DELETE of "a" (HTTP 403).');
        $this->client()->delete('a');
    }

    public function testListKeysFollowsContinuationTokensAndDecodesXml(): void
    {
        $this->respond(200, '<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>tok/1+2</NextContinuationToken>'
            . '<Contents><Key>p/a&amp;b.json</Key></Contents><Contents><Key>p/c.json</Key></Contents></ListBucketResult>');
        $this->respond(200, '<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>p/d.json</Key></Contents></ListBucketResult>');

        $keys = $this->client()->listKeys('p/');

        self::assertSame(['p/a&b.json', 'p/c.json', 'p/d.json'], $keys);
        self::assertCount(2, $this->requests);
        self::assertStringEndsWith('/ontology?list-type=2&prefix=p%2F', $this->requests[0]['url']);
        self::assertStringEndsWith('/ontology?continuation-token=tok%2F1%2B2&list-type=2&prefix=p%2F', $this->requests[1]['url']);
    }

    public function testListKeysOfAnEmptyPrefixIsEmptyAndAnEmptyTokenStopsPaging(): void
    {
        // "truncated" without a usable token must not loop forever.
        $this->respond(200, '<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken></NextContinuationToken></ListBucketResult>');

        self::assertSame([], $this->client()->listKeys('none/'));
        self::assertCount(1, $this->requests);
    }

    public function testListKeysErrorsAreReported(): void
    {
        $this->respond(503);
        $client = $this->client();
        try {
            $client->listKeys('p/');
            self::fail('a RuntimeException was expected');
        } catch (\RuntimeException $e) {
            self::assertSame('The ontology bucket refused LIST of "p/" (HTTP 503).', $e->getMessage());
        }

        $this->respond(200, 'not xml at all');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The ontology bucket returned an unreadable listing.');
        $client->listKeys('p/');
    }

    public function testListPrefixesReturnsTheCommonPrefixesAcrossPages(): void
    {
        $this->respond(200, '<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>t2</NextContinuationToken>'
            . '<CommonPrefixes><Prefix>flow-events/2026/</Prefix></CommonPrefixes></ListBucketResult>');
        $this->respond(200, '<ListBucketResult><CommonPrefixes><Prefix>flow-events/2027/</Prefix></CommonPrefixes></ListBucketResult>');

        self::assertSame(['flow-events/2026/', 'flow-events/2027/'], $this->client()->listPrefixes('flow-events/'));

        self::assertStringEndsWith('/ontology?delimiter=%2F&list-type=2&prefix=flow-events%2F', $this->requests[0]['url']);
        self::assertStringContainsString('continuation-token=t2', $this->requests[1]['url']);
    }

    public function testListPrefixesErrorsAreReported(): void
    {
        $this->respond(404);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The ontology bucket refused LIST of "x/" (HTTP 404).');
        $this->client()->listPrefixes('x/');
    }

    public function testATransportFailureIsWrappedWithoutLosingTheCause(): void
    {
        $cause = new \RuntimeException('connection reset');
        $this->responses[] = $cause;

        try {
            $this->client()->get('k');
            self::fail('a RuntimeException was expected');
        } catch (\RuntimeException $e) {
            self::assertSame('The ontology bucket request failed: connection reset', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }

    public function testRequestsWithoutAConfiguredBucketFailBeforeAnythingIsSent(): void
    {
        $this->config['aaxis_ontology.bucket_access_key'] = '';

        try {
            $this->client()->get('k');
            self::fail('a RuntimeException was expected');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('The ontology bucket is not fully configured', $e->getMessage());
        }
        self::assertSame([], $this->requests);
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testEndpointUrlsAreResolvedToHostHeaderSchemeAndRegion(string $endpoint, string $expectedUrlPrefix, string $expectedRegion): void
    {
        $this->config['aaxis_ontology.bucket_endpoint_url'] = $endpoint;
        $this->respond(200, 'x');

        $this->client()->get('k');

        self::assertSame($expectedUrlPrefix . '/ontology/k', $this->requests[0]['url']);
        self::assertStringContainsString('/' . $expectedRegion . '/s3/aws4_request', $this->requests[0]['headers']['authorization']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function endpointProvider(): array
    {
        return [
            'https default port' => ['https://s3.eu-west-2.amazonaws.com', 'https://s3.eu-west-2.amazonaws.com', 'eu-west-2'],
            'https custom port' => ['https://minio.local:9443/ignored/path', 'https://minio.local:9443', 'us-east-1'],
            'http default port' => ['HTTP://minio.local', 'http://minio.local', 'us-east-1'],
            'http custom port' => ['http://localhost:9000', 'http://localhost:9000', 'us-east-1'],
            'bare host assumes https' => ['s3.us-west-2.amazonaws.com/bucket', 'https://s3.us-west-2.amazonaws.com', 'us-west-2'],
            'bare host with port' => ['minio.local:9000', 'https://minio.local:9000', 'us-east-1'],
        ];
    }

    public function testAnEndpointWithoutAHostIsInvalid(): void
    {
        $this->config['aaxis_ontology.bucket_endpoint_url'] = '/only/a/path';

        self::assertFalse($this->client()->isConfigured());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The ontology bucket endpoint URL is not a valid URL.');
        $this->client()->get('k');
    }

    public function testTheEndpointIsResolvedOnlyOncePerInstance(): void
    {
        $client = $this->client();
        $this->respond(200, 'a');
        $this->respond(200, 'b');
        $client->get('k');

        // Changing the config afterwards does not affect the already-resolved connection.
        $this->config['aaxis_ontology.bucket_name'] = 'other';
        $client->get('k');

        self::assertStringEndsWith('/ontology/k', $this->requests[1]['url']);
    }
}

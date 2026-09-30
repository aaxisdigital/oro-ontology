<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorConfigurationException;
use Aaxis\Bundle\OntologyBundle\Exception\ConnectorTransferFailure;
use Aaxis\Bundle\OntologyBundle\Manager\FileConnectorTransfer;
use Aaxis\Bundle\OntologyBundle\Manager\S3RequestSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The bucket arm of FileConnectorTransfer, against a scripted S3 endpoint.
 */
class FileConnectorTransferBucketTest extends TestCase
{
    private const string NS = 'xmlns="http://s3.amazonaws.com/doc/2006-03-01/"';

    /** @var array<int, MockResponse|\Throwable> */
    private array $responses = [];

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    private FileConnectorTransfer $transfer;

    protected function setUp(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
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
        $this->transfer = new FileConnectorTransfer(new S3RequestSigner(), $http);
    }

    private function respond(int $status, string $body = ''): void
    {
        $this->responses[] = new MockResponse($body, ['http_code' => $status]);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function connector(array $override = []): OntologyConnector
    {
        return (new OntologyConnector())->setType(OntologyConnector::TYPE_BUCKET)->setConfig($override + [
            'server' => 'https://ns.compat.objectstorage.sa-saopaulo-1.oraclecloud.com',
            'bucket_name' => ' data ',
            'access_key' => ' AKID ',
            'secret_key' => 'SECRET',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function assertFailure(array $payload, string $message): void
    {
        self::assertTrue($payload['isError']);
        self::assertSame($message, $payload['message']);
        self::assertSame(ConnectorTransferFailure::class, $payload['exception']['class']);
    }

    // --- endpoint -----------------------------------------------------------------------------

    public function testRequestsArePathStyleAndSignedWithTheDerivedRegion(): void
    {
        $this->respond(200, 'content');

        self::assertSame('content', $this->transfer->read($this->connector(), '/reports/2026 q1.csv'));

        $request = $this->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertSame(
            'https://ns.compat.objectstorage.sa-saopaulo-1.oraclecloud.com/data/reports/2026%20q1.csv',
            $request['url']
        );
        self::assertMatchesRegularExpression(
            '~^AWS4-HMAC-SHA256 Credential=AKID/\d{8}/sa-saopaulo-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$~',
            $request['headers']['authorization']
        );
    }

    /**
     * @dataProvider endpointProvider
     *
     * @param array<string, mixed> $override
     */
    public function testEndpointResolution(array $override, string $expectedUrlBase): void
    {
        $this->transfer->read($this->connector($override), 'k');

        self::assertSame($expectedUrlBase . '/data/k', $this->requests[0]['url']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function endpointProvider(): array
    {
        return [
            'https default port' => [['server' => 'https://s3.example.com'], 'https://s3.example.com'],
            'url with port' => [['server' => 'http://minio.local:9000/ignored'], 'http://minio.local:9000'],
            'http default port' => [['server' => 'HTTP://minio.local'], 'http://minio.local'],
            'bare host assumes https' => [['server' => 's3.example.com/path'], 'https://s3.example.com'],
            'bare host with port' => [['server' => 'minio.local:9443'], 'https://minio.local:9443'],
            'config port wins' => [['server' => 'http://minio.local:9000', 'port' => '9100'], 'http://minio.local:9100'],
            'config port 0 is ignored' => [['server' => 'http://minio.local:9000', 'port' => 0], 'http://minio.local:9000'],
        ];
    }

    /**
     * @dataProvider brokenDefinitionProvider
     *
     * @param array<string, mixed> $override
     */
    public function testABrokenDefinitionAbortsBeforeAnyRequest(array $override, string $message): void
    {
        try {
            $this->transfer->read($this->connector($override), 'k');
            self::fail('a ConnectorConfigurationException was expected');
        } catch (ConnectorConfigurationException $e) {
            self::assertSame($message, $e->getMessage());
        }
        self::assertSame([], $this->requests);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function brokenDefinitionProvider(): array
    {
        return [
            'no server' => [['server' => ''], 'the connector has no server configured'],
            'no bucket' => [['bucket_name' => '  '], 'the connector has no bucket name configured'],
        ];
    }

    public function testATransportFailureIsAResultCarryingTheRootCause(): void
    {
        $this->responses[] = new \RuntimeException('Could not resolve host');

        $result = $this->transfer->read($this->connector(), 'k');

        self::assertTrue($result['isError']);
        self::assertSame('The bucket request failed: Could not resolve host', $result['message']);
        self::assertSame(\RuntimeException::class, $result['exception']['class']);
        self::assertSame('Could not resolve host', $result['exception']['message']);
    }

    // --- read ---------------------------------------------------------------------------------

    public function testListingAPrefixReportsFoldersAndFiles(): void
    {
        $this->respond(200, '<?xml version="1.0"?><ListBucketResult ' . self::NS . '>'
            . '<CommonPrefixes><Prefix>in/sub/</Prefix></CommonPrefixes>'
            . '<Contents><Key>in/</Key><Size>0</Size></Contents>'
            . '<Contents><Key>in/b.csv</Key><Size>20</Size><LastModified>2023-11-14T22:13:20.000Z</LastModified></Contents>'
            . '<Contents><Key>in/a.csv</Key><Size>10</Size><LastModified>not a date</LastModified></Contents>'
            . '</ListBucketResult>');

        $items = $this->transfer->read($this->connector(), 'in/');

        self::assertSame(['a.csv', 'b.csv', 'sub'], array_column($items, 'name'));
        self::assertSame(
            ['name' => 'a.csv', 'path' => 'in/a.csv', 'type' => 'file', 'size' => 10, 'modified' => null],
            $items[0]
        );
        self::assertSame(
            ['name' => 'b.csv', 'path' => 'in/b.csv', 'type' => 'file', 'size' => 20, 'modified' => '2023-11-14T22:13:20+00:00'],
            $items[1]
        );
        self::assertSame(
            ['name' => 'sub', 'path' => 'in/sub/', 'type' => 'folder', 'size' => null, 'modified' => null],
            $items[2]
        );
        // The bucket root: prefix omitted, delimiter and page size always sent.
        self::assertSame(
            'https://ns.compat.objectstorage.sa-saopaulo-1.oraclecloud.com/data?delimiter=%2F&list-type=2&max-keys=1000&prefix=in%2F',
            $this->requests[0]['url']
        );
    }

    public function testAnEmptyPathListsTheBucketRoot(): void
    {
        $this->respond(200, '<ListBucketResult><Contents><Key>top.txt</Key><Size>3</Size></Contents></ListBucketResult>');

        $items = $this->transfer->read($this->connector(), '');

        self::assertSame('top.txt', $items[0]['path']);
        self::assertStringNotContainsString('prefix', $this->requests[0]['url']);
    }

    public function testAnEmptyBucketListsNothing(): void
    {
        $this->respond(200, '<ListBucketResult ' . self::NS . '/>');

        self::assertSame([], $this->transfer->read($this->connector(), '/'));
    }

    public function testListingFailures(): void
    {
        $this->respond(403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>');
        self::assertFailure(
            $this->transfer->read($this->connector(), 'in/'),
            'The bucket rejected "in/" with HTTP 403 — Access Denied'
        );

        $this->respond(200, 'this is not xml');
        self::assertFailure($this->transfer->read($this->connector(), 'in/'), 'Bucket "data" returned an unreadable listing.');
    }

    public function testReadingAnObjectReturnsItsBody(): void
    {
        $this->respond(200, "raw\x00bytes");

        self::assertSame("raw\x00bytes", $this->transfer->read($this->connector(), 'bin/file'));
    }

    /**
     * @dataProvider notFoundProvider
     */
    public function testAMissingObjectAndAMissingBucketAreToldApart(string $body, string $message): void
    {
        $this->respond(404, $body);

        self::assertFailure($this->transfer->read($this->connector(), 'reports/x.csv'), $message);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function notFoundProvider(): array
    {
        return [
            'no such key' => ['<Error><Code>NoSuchKey</Code></Error>', 'Object "reports/x.csv" does not exist in bucket "data".'],
            'no body' => ['', 'Object "reports/x.csv" does not exist in bucket "data".'],
            'aws bucket' => ['<Error><Code>NoSuchBucket</Code></Error>', 'Bucket "data" does not exist on this endpoint.'],
            'oci bucket' => ['<Error><Code>BucketNotFound</Code></Error>', 'Bucket "data" does not exist on this endpoint.'],
        ];
    }

    public function testOtherReadErrorsCarryTheServersMessageWhenThereIsOne(): void
    {
        $this->respond(500, '<Error xmlns="urn:x"><Message>boom</Message></Error>');
        self::assertFailure($this->transfer->read($this->connector(), 'k'), 'The bucket rejected "k" with HTTP 500 — boom');

        $this->respond(503, 'plain text');
        self::assertFailure($this->transfer->read($this->connector(), 'k'), 'The bucket rejected "k" with HTTP 503.');

        $this->respond(400, '<Error><Code>X</Code></Error>');
        self::assertFailure($this->transfer->read($this->connector(), 'k'), 'The bucket rejected "k" with HTTP 400.');
    }

    // --- write --------------------------------------------------------------------------------

    public function testWritingPutsTheSignedBodyAndReturnsAReceipt(): void
    {
        $this->respond(200);

        $result = $this->transfer->write($this->connector(), '/out/data.json', '{"é":1}');

        self::assertSame([
            'isError' => false,
            'message' => 'Wrote 8 byte(s) to "out/data.json".',
            'path' => 'out/data.json',
            'bytes' => 8,
        ], $result);
        $request = $this->requests[0];
        self::assertSame('PUT', $request['method']);
        self::assertSame('{"é":1}', $request['body']);
        self::assertSame(hash('sha256', '{"é":1}'), $request['headers']['x-amz-content-sha256']);
    }

    public function testWriteFailures(): void
    {
        self::assertFailure($this->transfer->write($this->connector(), 'folder/', 'x'), '"folder/" is a folder path — a write needs a file path.');
        self::assertSame([], $this->requests);

        $this->respond(403, '<Error><Message>Denied</Message></Error>');
        self::assertFailure($this->transfer->write($this->connector(), 'k', 'x'), 'The bucket rejected "k" with HTTP 403 — Denied');
    }

    // --- delete -------------------------------------------------------------------------------

    public function testDeletingChecksTheObjectExistsFirst(): void
    {
        $this->respond(200);
        $this->respond(204);

        $result = $this->transfer->delete($this->connector(), 'old/x.csv');

        self::assertSame(['isError' => false, 'message' => 'Deleted "old/x.csv".', 'path' => 'old/x.csv'], $result);
        self::assertSame(['HEAD', 'DELETE'], array_column($this->requests, 'method'));
    }

    public function testDeleteFailures(): void
    {
        $this->respond(404);
        self::assertFailure($this->transfer->delete($this->connector(), 'ghost'), 'Object "ghost" does not exist in bucket "data".');

        $this->respond(403);
        self::assertFailure($this->transfer->delete($this->connector(), 'k'), 'The bucket rejected "k" with HTTP 403.');

        $this->respond(200);
        $this->respond(500, '<Error><Message>Internal</Message></Error>');
        self::assertFailure($this->transfer->delete($this->connector(), 'k'), 'The bucket rejected "k" with HTTP 500 — Internal');

        self::assertFailure($this->transfer->delete($this->connector(), 'folder/'), '"folder/" is a folder path — delete needs a file path.');
    }

    // --- rename -------------------------------------------------------------------------------

    public function testRenamingCopiesServerSideThenDeletesTheSource(): void
    {
        $this->respond(200);                  // HEAD source: exists
        $this->respond(404);                  // HEAD target: free
        $this->respond(200, '<CopyObjectResult><ETag>"x"</ETag></CopyObjectResult>');
        $this->respond(204);                  // DELETE source

        $result = $this->transfer->rename($this->connector(), 'in/a b.csv', 'archive/a.csv');

        self::assertSame(
            ['isError' => false, 'message' => 'Renamed "in/a b.csv" to "archive/a.csv".', 'path' => 'archive/a.csv', 'from' => 'in/a b.csv'],
            $result
        );
        self::assertSame(['HEAD', 'HEAD', 'PUT', 'DELETE'], array_column($this->requests, 'method'));
        $copy = $this->requests[2];
        self::assertStringEndsWith('/data/archive/a.csv', $copy['url']);
        // The copy source is the URL-encoded /bucket/key, and it is one of the SIGNED headers.
        self::assertSame('/data/in/a%20b.csv', $copy['headers']['x-amz-copy-source']);
        self::assertStringContainsString('x-amz-copy-source', $copy['headers']['authorization']);
    }

    public function testRenameFailures(): void
    {
        $this->respond(404);
        self::assertFailure($this->transfer->rename($this->connector(), 'ghost', 'x'), 'Object "ghost" does not exist in bucket "data".');

        $this->respond(200);
        $this->respond(200);
        self::assertFailure($this->transfer->rename($this->connector(), 'a', 'b'), 'Path "b" already exists.');
    }

    public function testACopyFailureIsReported(): void
    {
        $this->respond(200);
        $this->respond(404);
        $this->respond(403, '<Error><Message>Denied</Message></Error>');

        self::assertFailure($this->transfer->rename($this->connector(), 'a', 'b'), 'The bucket rejected "a" with HTTP 403 — Denied');
    }

    public function testACopyThatFailsWithA200ErrorBodyIsStillAFailure(): void
    {
        $this->respond(200);
        $this->respond(404);
        $this->respond(200, '<Error><Code>InternalError</Code><Message>We encountered an internal error</Message></Error>');

        // The status is coerced to 400 so the message never claims "HTTP 200".
        self::assertFailure(
            $this->transfer->rename($this->connector(), 'a', 'b'),
            'The bucket rejected "a" with HTTP 400 — We encountered an internal error'
        );
        self::assertSame(['HEAD', 'HEAD', 'PUT'], array_column($this->requests, 'method'), 'the source must not be deleted');
    }

    public function testAFailedSourceDeletionAfterTheCopyIsSpelledOut(): void
    {
        $this->respond(200);
        $this->respond(404);
        $this->respond(200, '<CopyObjectResult/>');
        $this->respond(403, '<Error><Message>No delete</Message></Error>');

        self::assertFailure(
            $this->transfer->rename($this->connector(), 'a', 'b'),
            'Copied to "b" but the source "a" could not be deleted: The bucket rejected "a" with HTTP 403 — No delete'
        );
    }

    public function testAnUnreachableSourceCheckAborts(): void
    {
        $this->respond(500);

        self::assertFailure($this->transfer->rename($this->connector(), 'a', 'b'), 'The bucket rejected "a" with HTTP 500.');
    }
}

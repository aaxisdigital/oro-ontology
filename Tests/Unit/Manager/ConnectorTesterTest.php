<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyConnector;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorTester;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures\OfflineConnectorTester;
use phpseclib3\Crypt\Common\PrivateKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConnectorTesterTest extends TestCase
{
    private const string TEST_KEY = "-----BEGIN PRIVATE KEY-----\n"
        . "MC4CAQAwBQYDK2VwBCIEIGZm+9dOCRiXYBKA5GHtSu4Fqbn9Vo//UPzDDhq5OT/K\n"
        . "-----END PRIVATE KEY-----\n";

    private OfflineConnectorTester $tester;

    protected function setUp(): void
    {
        $this->tester = new OfflineConnectorTester();
    }

    /**
     * @param array<string, mixed>|null $config
     *
     * @return array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>}
     */
    private function test(string $type, ?array $config): array
    {
        return $this->tester->test($type, $config);
    }

    /**
     * @param array{success: bool, message: string, steps: list<array{label: string, success: bool, message: string}>} $result
     *
     * @return list<string> "label:ok|fail" per step
     */
    private static function outline(array $result): array
    {
        return array_map(static fn (array $step): string => $step['label'] . ':' . ($step['success'] ? 'ok' : 'fail'), $result['steps']);
    }

    /**
     * A connected socket that already carries $banner, standing in for a server that greets first.
     *
     * @return resource
     */
    private static function socketSaying(string $banner)
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        fwrite($pair[1], $banner);

        return $pair[0];
    }

    // ---------------------------------------------------------------------------------------------
    // dispatch + result shape

    public function testAnUnknownTypeIsAConfigurationFailure(): void
    {
        $result = $this->test('carrier_pigeon', []);

        self::assertFalse($result['success']);
        self::assertSame('Unknown connector type.', $result['message']);
        self::assertSame(['Configuration:fail'], self::outline($result));
    }

    public function testANullConfigIsTreatedAsEmpty(): void
    {
        $result = $this->test(OntologyConnector::TYPE_FILE_SYSTEM, null);

        self::assertSame('No base path configured.', $result['message']);
    }

    // ---------------------------------------------------------------------------------------------
    // file_system

    public function testFileSystemReportsAnExistingReadableDirectory(): void
    {
        $dir = sys_get_temp_dir();

        $result = $this->test(OntologyConnector::TYPE_FILE_SYSTEM, ['base_path' => '  ' . $dir . ' ']);

        self::assertTrue($result['success']);
        self::assertSame('All checks passed.', $result['message']);
        self::assertSame([[
            'label' => 'Path check',
            'success' => true,
            'message' => sprintf('Path "%s" exists and is readable.', $dir),
        ]], $result['steps']);
    }

    public function testFileSystemRejectsAMissingPath(): void
    {
        $path = sys_get_temp_dir() . '/aaxis-ontology-missing-' . uniqid('', true);

        $result = $this->test(OntologyConnector::TYPE_FILE_SYSTEM, ['base_path' => $path]);

        self::assertFalse($result['success']);
        self::assertSame(sprintf('Path "%s" does not exist on the server.', $path), $result['message']);
    }

    public function testFileSystemRejectsAFile(): void
    {
        $result = $this->test(OntologyConnector::TYPE_FILE_SYSTEM, ['base_path' => __FILE__]);

        self::assertFalse($result['success']);
        self::assertSame(sprintf('Path "%s" is not a directory.', __FILE__), $result['message']);
    }

    // ---------------------------------------------------------------------------------------------
    // sftp

    public function testSftpNeedsAServerAndAValidPort(): void
    {
        self::assertSame('No server configured.', $this->test(OntologyConnector::TYPE_SFTP, ['server' => ' '])['message']);

        foreach ([0, 65536, 'abc', -5] as $port) {
            $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'port' => $port]);
            self::assertSame('The port must be between 1 and 65535.', $result['message'], 'port ' . var_export($port, true));
        }
        self::assertSame([], $this->tester->addresses, 'nothing may be dialled for a bad configuration');
    }

    public function testSftpSkipsAuthenticationWhenTheServerIsUnreachable(): void
    {
        $this->tester->sockets = ['Connection timed out'];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'sftp.example.com', 'user' => 'u', 'password' => 'p', 'auth' => 'password']);

        self::assertFalse($result['success']);
        self::assertSame('Could not connect to sftp.example.com:22 — Connection timed out.', $result['message']);
        self::assertSame(['Socket:fail', 'Authentication:fail'], self::outline($result));
        self::assertSame('Skipped — the server is unreachable.', $result['steps'][1]['message']);
        self::assertSame(['tcp://sftp.example.com:22'], $this->tester->addresses);
    }

    public function testAnEmptySocketErrorTextFallsBackToAGenericOne(): void
    {
        $this->tester->sockets = [''];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'port' => '2222']);

        self::assertSame('Could not connect to h:2222 — connection failed.', $result['message']);
    }

    public function testSftpReportsTheSshBannerAndSkipsAuthWhenNoneIsConfigured(): void
    {
        $this->tester->sockets = [self::socketSaying("SSH-2.0-OpenSSH_9.6\r\n")];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'port' => 2200]);

        self::assertTrue($result['success']);
        self::assertSame('Connected to h:2200. Server: SSH-2.0-OpenSSH_9.6.', $result['steps'][0]['message']);
        self::assertSame('No authentication configured — skipped.', $result['steps'][1]['message']);
        self::assertSame(['tcp://h:2200'], $this->tester->addresses);
    }

    public function testSftpIgnoresAGreetingThatIsNotAnSshBanner(): void
    {
        $this->tester->sockets = [self::socketSaying("HTTP/1.1 400 Bad Request\r\n")];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h']);

        self::assertSame('Connected to h:22.', $result['steps'][0]['message']);
    }

    public function testSftpNeedsAUserForAnyAuthentication(): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'auth' => 'password', 'password' => 'p']);

        self::assertSame(['Socket:ok', 'Authentication:fail'], self::outline($result));
        self::assertSame('No user configured.', $result['message']);
    }

    public function testSftpNeedsTheSecretForTheChosenAuthMode(): void
    {
        $this->tester->sockets = [self::socketSaying(''), self::socketSaying('')];

        $password = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'user' => 'u', 'auth' => 'password', 'key' => 'k']);
        $key = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'user' => 'u', 'auth' => 'key', 'password' => 'p']);

        self::assertSame('No password value available to test.', $password['message']);
        self::assertSame('No key value available to test.', $key['message']);
    }

    public function testSftpAuthenticatesWithAPasswordThroughPhpseclib(): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'port' => 2222, 'user' => 'deploy', 'auth' => 'password', 'password' => 'pw']);

        self::assertTrue($result['success']);
        self::assertSame('Authenticated as "deploy".', $result['steps'][1]['message']);
        self::assertNotNull($this->tester->sftp);
        self::assertSame(['h', 2222, 8], [$this->tester->sftp->connectedHost, $this->tester->sftp->connectedPort, $this->tester->sftp->connectedTimeout]);
        self::assertSame(['deploy', 'pw'], $this->tester->sftp->loginArguments);
    }

    public function testSftpAuthenticatesWithAPrivateKeyThroughPhpseclib(): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'user' => 'deploy', 'auth' => 'key', 'key' => self::TEST_KEY]);

        self::assertTrue($result['success']);
        self::assertNotNull($this->tester->sftp);
        self::assertSame('deploy', $this->tester->sftp->loginArguments[0]);
        self::assertInstanceOf(PrivateKey::class, $this->tester->sftp->loginArguments[1]);
    }

    /**
     * @dataProvider rejectedCredentialProvider
     */
    public function testSftpReportsRejectedCredentials(string $auth, string $expected): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $config = ['server' => 'h', 'user' => 'deploy', 'auth' => $auth, 'password' => 'pw', 'key' => self::TEST_KEY];

        $this->tester->sftpAccepts = false;

        $result = $this->tester->test(OntologyConnector::TYPE_SFTP, $config);

        self::assertFalse($result['success']);
        self::assertSame($expected, $result['message']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedCredentialProvider(): array
    {
        return [
            'password' => ['password', 'The server rejected the password for user "deploy".'],
            'key' => ['key', 'The server rejected the key for user "deploy".'],
        ];
    }

    public function testSftpReportsAnUnreadableKeyWithoutCrashing(): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'user' => 'u', 'auth' => 'key', 'key' => 'this is not a key']);

        self::assertFalse($result['success']);
        self::assertStringStartsWith('SFTP authentication failed: ', $result['message']);
    }

    public function testSftpReportsALoginFailureRaisedByTheLibrary(): void
    {
        $this->tester->sftpFailure = new \RuntimeException('Connection closed by server');
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->tester->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'user' => 'u', 'auth' => 'password', 'password' => 'p']);

        self::assertSame('SFTP authentication failed: Connection closed by server', $result['message']);
    }

    public function testSftpFallsBackToExtSsh2ForPasswords(): void
    {
        $this->tester->phpseclib = false;
        $this->tester->ssh2 = true;
        $this->tester->ssh2Result = true;
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'user' => 'u', 'auth' => 'password', 'password' => 'p']);

        self::assertTrue($result['success']);
        self::assertSame('Authenticated as "u".', $result['steps'][1]['message']);
    }

    /**
     * @dataProvider ssh2OutcomeProvider
     */
    public function testExtSsh2Outcomes(bool|null|\Throwable $outcome, string $expected): void
    {
        $this->tester->phpseclib = false;
        $this->tester->ssh2 = true;
        $this->tester->ssh2Result = $outcome;
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'user' => 'u', 'auth' => 'password', 'password' => 'p']);

        self::assertFalse($result['success']);
        self::assertSame($expected, $result['message']);
    }

    /**
     * @return array<string, array{bool|null|\Throwable, string}>
     */
    public static function ssh2OutcomeProvider(): array
    {
        return [
            'rejected' => [false, 'The server rejected the password for user "u".'],
            'no session' => [null, 'Could not open an SSH session (ext-ssh2).'],
            'library error' => [new \RuntimeException('boom'), 'SFTP authentication failed: boom'],
        ];
    }

    public function testExtSsh2CannotAuthenticateWithAKey(): void
    {
        $this->tester->phpseclib = false;
        $this->tester->ssh2 = true;
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'user' => 'u', 'auth' => 'key', 'key' => self::TEST_KEY]);

        self::assertSame(
            'No SSH client is available on the server — install the phpseclib/phpseclib package to test authentication.',
            $result['message']
        );
    }

    public function testWithoutAnySshClientThePasswordMessageMentionsBothOptions(): void
    {
        $this->tester->phpseclib = false;
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_SFTP, ['server' => 'h', 'user' => 'u', 'auth' => 'password', 'password' => 'p']);

        self::assertSame(
            'No SSH client is available on the server — install the phpseclib/phpseclib package or ext-ssh2 to test authentication.',
            $result['message']
        );
    }

    // ---------------------------------------------------------------------------------------------
    // rest_api

    public function testRestApiNeedsAServerAndAValidPort(): void
    {
        self::assertSame('No server configured.', $this->test(OntologyConnector::TYPE_REST_API, [])['message']);
        self::assertSame(
            'The port must be between 1 and 65535.',
            $this->test(OntologyConnector::TYPE_REST_API, ['server' => 'api.example.com', 'port' => 70000])['message']
        );
        self::assertSame([], $this->tester->addresses);
    }

    /**
     * @dataProvider restEndpointProvider
     *
     * @param array<string, mixed> $config
     */
    public function testRestApiResolvesSchemeHostAndPort(array $config, string $expectedAddress): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_REST_API, $config);

        self::assertSame([$expectedAddress], $this->tester->addresses);
        self::assertTrue($result['success']);
        self::assertSame('Only socket connectivity is tested for this authentication mode.', $result['steps'][1]['message']);
        self::assertSame(['Socket:ok', 'Authentication:ok'], self::outline($result));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function restEndpointProvider(): array
    {
        return [
            'bare host assumes https' => [['server' => 'api.example.com'], 'tcp://api.example.com:443'],
            'bare host with path' => [['server' => 'api.example.com/v1/items'], 'tcp://api.example.com:443'],
            'bare host with port' => [['server' => 'api.example.com:8443/v1'], 'tcp://api.example.com:8443'],
            'https url' => [['server' => 'https://api.example.com/v1'], 'tcp://api.example.com:443'],
            'http url' => [['server' => 'HTTP://api.example.com'], 'tcp://api.example.com:80'],
            'url with port' => [['server' => 'http://api.example.com:8080/x'], 'tcp://api.example.com:8080'],
            'explicit port wins' => [['server' => 'http://api.example.com:8080', 'port' => 9090], 'tcp://api.example.com:9090'],
            'blank port falls back' => [['server' => 'http://api.example.com', 'port' => ''], 'tcp://api.example.com:80'],
        ];
    }

    public function testRestApiUnreachableServerSkipsTheOAuthStep(): void
    {
        $this->tester->sockets = ['refused'];

        $result = $this->test(OntologyConnector::TYPE_REST_API, ['server' => 'api', 'auth' => 'oauth', 'oauth' => ['path' => '/t']]);

        self::assertSame(['Socket:fail', 'OAuth:fail'], self::outline($result));
        self::assertSame('Skipped — the server is unreachable.', $result['steps'][1]['message']);
        self::assertSame([], $this->tester->httpRequests);
    }

    public function testOauthNeedsAPath(): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_REST_API, ['server' => 'api', 'auth' => 'oauth', 'oauth' => ['path' => ' ']]);

        self::assertSame('No OAuth path configured.', $result['message']);
        self::assertSame([], $this->tester->httpRequests);
    }

    public function testOauthReportsAMissingHttpClient(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->httpAvailable = false;

        $result = $this->test(OntologyConnector::TYPE_REST_API, ['server' => 'api', 'auth' => 'oauth', 'oauth' => ['path' => '/t']]);

        self::assertSame('The Symfony HTTP client is not available on the server.', $result['message']);
    }

    public function testOauthPostsTheMergedHeadersAndFormBody(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->httpResponses = [new MockResponse('{}', ['http_code' => 200])];

        $result = $this->test(OntologyConnector::TYPE_REST_API, [
            'server' => 'https://api.example.com',
            'auth' => 'oauth',
            'headers' => ['Accept' => 'application/json', 'X-Trace' => 1, 'ignored' => ['nested'], 5 => 'numeric key'],
            'oauth' => [
                'path' => '/oauth/token',
                'headers' => ['Accept' => 'text/plain'],
                'body' => ['grant_type' => 'client_credentials', 'client_secret' => 's e', 'skip' => null],
            ],
        ]);

        self::assertTrue($result['success']);
        self::assertSame('HTTP 200 from https://api.example.com:443/oauth/token.', $result['steps'][1]['message']);
        self::assertCount(1, $this->tester->httpRequests);
        $request = $this->tester->httpRequests[0];
        self::assertSame('POST', $request['method']);
        // (the HTTP client drops the scheme's default port from the URL it actually requests)
        self::assertSame('https://api.example.com/oauth/token', $request['url']);
        // The OAuth headers override the shared ones; non-string keys and non-scalar values are dropped.
        self::assertSame('text/plain', $request['headers']['accept']);
        self::assertSame('1', $request['headers']['x-trace']);
        self::assertArrayNotHasKey('ignored', $request['headers']);
        self::assertSame('grant_type=client_credentials&client_secret=s+e', $request['body']);
    }

    public function testOauthWithoutABodySendsNone(): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $this->test(OntologyConnector::TYPE_REST_API, ['server' => 'http://api:8080', 'auth' => 'oauth', 'oauth' => ['path' => 'token', 'body' => 'not a map']]);

        self::assertSame('http://api:8080/token', $this->tester->httpRequests[0]['url']);
        self::assertContains($this->tester->httpRequests[0]['body'], [null, ''], 'no body may be sent');
    }

    public function testOauthErrorStatusesFailTheStep(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->httpResponses = [new MockResponse('', ['http_code' => 401])];

        $result = $this->test(OntologyConnector::TYPE_REST_API, ['server' => 'api', 'auth' => 'oauth', 'oauth' => ['path' => '/t']]);

        self::assertFalse($result['success']);
        self::assertSame('HTTP 401 from https://api:443/t.', $result['message']);
    }

    public function testOauthTransportErrorsFailTheStepWithTheirMessage(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->httpResponses = [new \RuntimeException('SSL certificate problem')];

        $result = $this->test(OntologyConnector::TYPE_REST_API, ['server' => 'api', 'auth' => 'oauth', 'oauth' => ['path' => '/t']]);

        self::assertSame('The OAuth request failed: SSL certificate problem', $result['message']);
    }

    // ---------------------------------------------------------------------------------------------
    // bucket

    /**
     * @return array<string, mixed>
     */
    private function bucketConfig(): array
    {
        return ['server' => 'https://s3.eu-west-2.amazonaws.com', 'bucket_name' => ' assets ', 'access_key' => ' AKID ', 'secret_key' => 'SECRET'];
    }

    public function testBucketNeedsAServerAndAValidPort(): void
    {
        self::assertSame('No server configured.', $this->test(OntologyConnector::TYPE_BUCKET, [])['message']);
        self::assertSame(
            'The port must be between 1 and 65535.',
            $this->test(OntologyConnector::TYPE_BUCKET, ['server' => 'h', 'port' => 'x'])['message']
        );
    }

    public function testBucketUnreachableServerSkipsTheBucketStep(): void
    {
        $this->tester->sockets = ['refused'];

        $result = $this->test(OntologyConnector::TYPE_BUCKET, $this->bucketConfig());

        self::assertSame(['Socket:fail', 'Bucket:fail'], self::outline($result));
        self::assertSame('Skipped — the server is unreachable.', $result['steps'][1]['message']);
    }

    /**
     * @dataProvider incompleteBucketProvider
     *
     * @param array<string, mixed> $override
     */
    public function testBucketCredentialsAreCheckedBeforeAnyRequest(array $override, string $expected): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_BUCKET, $override + $this->bucketConfig());

        self::assertFalse($result['success']);
        self::assertSame($expected, $result['message']);
        self::assertSame([], $this->tester->httpRequests);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function incompleteBucketProvider(): array
    {
        return [
            'no bucket' => [['bucket_name' => '  '], 'No bucket name configured.'],
            'no access key' => [['access_key' => ''], 'No access key configured.'],
            'no secret key' => [['secret_key' => ''], 'No secret key value available to test.'],
        ];
    }

    public function testBucketReportsAMissingHttpClient(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->httpAvailable = false;

        self::assertSame(
            'The Symfony HTTP client is not available on the server.',
            $this->test(OntologyConnector::TYPE_BUCKET, $this->bucketConfig())['message']
        );
    }

    public function testBucketListsOneObjectWithASignedPathStyleRequest(): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $result = $this->test(OntologyConnector::TYPE_BUCKET, $this->bucketConfig());

        self::assertTrue($result['success']);
        self::assertSame('Listed bucket "assets" (HTTP 200).', $result['steps'][1]['message']);
        self::assertSame(['tcp://s3.eu-west-2.amazonaws.com:443'], $this->tester->addresses);
        $request = $this->tester->httpRequests[0];
        self::assertSame('GET', $request['method']);
        self::assertSame('https://s3.eu-west-2.amazonaws.com/assets?list-type=2&max-keys=1', $request['url']);
        self::assertMatchesRegularExpression(
            '~^AWS4-HMAC-SHA256 Credential=AKID/\d{8}/eu-west-2/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$~',
            $request['headers']['authorization']
        );
        self::assertStringNotContainsString('SECRET', json_encode($request, JSON_THROW_ON_ERROR));
    }

    public function testBucketSignsTheHostWithTheNonDefaultPortItSends(): void
    {
        $this->tester->sockets = [self::socketSaying('')];

        $this->test(OntologyConnector::TYPE_BUCKET, ['server' => 'http://minio.local:9000'] + $this->bucketConfig());

        self::assertSame('http://minio.local:9000/assets?list-type=2&max-keys=1', $this->tester->httpRequests[0]['url']);
        self::assertSame(['tcp://minio.local:9000'], $this->tester->addresses);
    }

    /**
     * @dataProvider bucketStatusProvider
     */
    public function testBucketStatusMessages(int $status, bool $success, string $expected): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->httpResponses = [new MockResponse('', ['http_code' => $status])];

        $result = $this->test(OntologyConnector::TYPE_BUCKET, $this->bucketConfig());

        self::assertSame($success, $result['success']);
        self::assertSame($expected, $result['steps'][1]['message']);
    }

    /**
     * @return array<string, array{int, bool, string}>
     */
    public static function bucketStatusProvider(): array
    {
        return [
            '204 counts as listed' => [204, true, 'Listed bucket "assets" (HTTP 204).'],
            '403' => [403, false, 'Access denied (HTTP 403) — the access key/secret key pair was rejected, or it has no permission on bucket "assets". A key created moments ago can also need a few minutes to become active.'],
            '404' => [404, false, 'Bucket "assets" does not exist on this endpoint (HTTP 404).'],
            '500' => [500, false, 'HTTP 500 from https://s3.eu-west-2.amazonaws.com/assets.'],
        ];
    }

    public function testBucketTransportErrorsFailTheStep(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->httpResponses = [new \RuntimeException('Could not resolve host')];

        $result = $this->test(OntologyConnector::TYPE_BUCKET, $this->bucketConfig());

        self::assertSame('The bucket request failed: Could not resolve host', $result['message']);
    }

    // ---------------------------------------------------------------------------------------------
    // database

    /**
     * @return array<string, mixed>
     */
    private function dbConfig(): array
    {
        return ['server' => 'db.internal', 'port' => 5433, 'database' => 'erp', 'user' => 'svc', 'password' => 'pw'];
    }

    public function testDatabaseAcceptsOnlyPostgresql(): void
    {
        $result = $this->test(OntologyConnector::TYPE_DATABASE, ['engine' => 'mysql'] + $this->dbConfig());

        self::assertSame('Only PostgreSQL is supported for now — "mysql" cannot be tested.', $result['message']);
        self::assertSame(['Configuration:fail'], self::outline($result));
    }

    public function testDatabaseNeedsAServerAndAValidPort(): void
    {
        self::assertSame('No server configured.', $this->test(OntologyConnector::TYPE_DATABASE, [])['message']);
        self::assertSame(
            'The port must be between 1 and 65535.',
            $this->test(OntologyConnector::TYPE_DATABASE, ['server' => 'h', 'port' => 0])['message']
        );
    }

    public function testDatabaseSkipsTheConnectionWhenTheServerIsUnreachable(): void
    {
        $this->tester->sockets = ['refused'];

        $result = $this->test(OntologyConnector::TYPE_DATABASE, $this->dbConfig());

        self::assertSame(['Socket:fail', 'Connection:fail'], self::outline($result));
        self::assertSame('Skipped — the server is unreachable.', $result['steps'][1]['message']);
        self::assertSame([], $this->tester->pdoAttempts);
    }

    /**
     * @dataProvider databaseEndpointProvider
     *
     * @param array<string, mixed> $config
     */
    public function testDatabaseEndpointResolution(array $config, string $expectedAddress): void
    {
        $this->tester->sockets = ['refused'];

        $this->test(OntologyConnector::TYPE_DATABASE, $config);

        self::assertSame([$expectedAddress], $this->tester->addresses);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function databaseEndpointProvider(): array
    {
        return [
            'default port' => [['server' => 'db'], 'tcp://db:5432'],
            'port in the server' => [['server' => 'db:6543'], 'tcp://db:6543'],
            'explicit port wins' => [['server' => 'db:6543', 'port' => '7000'], 'tcp://db:7000'],
        ];
    }

    public function testDatabaseConnectsAndReportsTheServerVersionWithoutPuttingCredentialsInTheDsn(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->pdo = $this->pdoReporting('16.2');

        $result = $this->test(OntologyConnector::TYPE_DATABASE, $this->dbConfig());

        self::assertTrue($result['success']);
        self::assertSame(['Socket:ok', 'Connection:ok'], self::outline($result));
        self::assertSame('Connected to database "erp" as "svc" — PostgreSQL 16.2.', $result['steps'][1]['message']);
        [$dsn, $user, $password, $options] = $this->tester->pdoAttempts[0];
        self::assertSame('pgsql:host=db.internal;port=5433;dbname=erp;connect_timeout=5', $dsn);
        self::assertSame(['svc', 'pw'], [$user, $password]);
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $options[\PDO::ATTR_ERRMODE]);
        self::assertSame(5, $options[\PDO::ATTR_TIMEOUT]);
    }

    public function testDatabaseOmitsTheVersionWhenTheServerReportsNone(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->pdo = $this->pdoReporting('');

        $result = $this->test(OntologyConnector::TYPE_DATABASE, $this->dbConfig());

        self::assertSame('Connected to database "erp" as "svc".', $result['steps'][1]['message']);
    }

    /**
     * @dataProvider unusableDatabaseProvider
     *
     * @param array<string, mixed> $override
     * @param array<int, string>   $drivers
     */
    public function testDatabaseConnectionPreconditions(array $override, array $drivers, string $expected): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->drivers = $drivers;

        $result = $this->test(OntologyConnector::TYPE_DATABASE, $override + $this->dbConfig());

        self::assertFalse($result['success']);
        self::assertSame($expected, $result['message']);
        self::assertSame([], $this->tester->pdoAttempts);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<int, string>, string}>
     */
    public static function unusableDatabaseProvider(): array
    {
        return [
            'no database' => [['database' => ' '], ['pgsql'], 'No database configured.'],
            'no user' => [['user' => ''], ['pgsql'], 'No user configured.'],
            'no pgsql driver' => [[], ['sqlite'], 'The pdo_pgsql PHP extension is not installed on the server.'],
            'semicolon in the server' => [['server' => 'db;sslmode=disable'], ['pgsql'], 'The server must not contain ";".'],
            'semicolon in the database' => [['database' => 'erp;host=evil'], ['pgsql'], 'The database must not contain ";".'],
        ];
    }

    public function testDatabaseConnectionFailuresNeverLeakThePassword(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->connectFailure = new \PDOException('FATAL: password authentication failed for user "svc" (password: pw)');

        $result = $this->test(OntologyConnector::TYPE_DATABASE, $this->dbConfig());

        self::assertFalse($result['success']);
        self::assertSame('Connection failed: FATAL: password authentication failed for user "svc" (password: ********)', $result['message']);
    }

    public function testDatabaseConnectionFailureWithoutAPasswordKeepsTheMessage(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->connectFailure = new \PDOException('connection refused');

        $result = $this->test(OntologyConnector::TYPE_DATABASE, ['password' => ''] + $this->dbConfig());

        self::assertSame('Connection failed: connection refused', $result['message']);
    }

    /**
     * @dataProvider schemaOutcomeProvider
     */
    public function testDatabaseSchemaCheck(mixed $fetched, bool $success, string $expected): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['schema' => 'sales'])->willReturn(true);
        $statement->method('fetchColumn')->willReturn($fetched);
        $this->tester->pdo = $this->pdoReporting('16.2', $statement);

        $result = $this->test(OntologyConnector::TYPE_DATABASE, ['schema' => ' sales '] + $this->dbConfig());

        self::assertSame(['Socket:ok', 'Connection:ok', 'Schema:' . ($success ? 'ok' : 'fail')], self::outline($result));
        self::assertSame($expected, $result['steps'][2]['message']);
    }

    /**
     * @return array<string, array{mixed, bool, string}>
     */
    public static function schemaOutcomeProvider(): array
    {
        return [
            'usable (int)' => [1, true, 'Schema "sales" exists and is accessible.'],
            'usable (string)' => ['1', true, 'Schema "sales" exists and is accessible.'],
            'missing' => [false, false, 'Schema "sales" does not exist in this database.'],
            'no usage' => [0, false, 'Schema "sales" exists but the user has no USAGE permission on it.'],
        ];
    }

    public function testDatabaseSchemaCheckErrorsAreReported(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('16.2');
        $pdo->method('prepare')->willThrowException(new \PDOException('permission denied for pg_namespace'));
        $this->tester->pdo = $pdo;

        $result = $this->test(OntologyConnector::TYPE_DATABASE, ['schema' => 'sales'] + $this->dbConfig());

        self::assertSame('The schema check failed: permission denied for pg_namespace', $result['message']);
    }

    public function testDatabaseSchemaIsSkippedWhenThereIsNoConnection(): void
    {
        $this->tester->sockets = [self::socketSaying('')];
        $this->tester->connectFailure = new \PDOException('nope');

        $result = $this->test(OntologyConnector::TYPE_DATABASE, ['schema' => 'sales'] + $this->dbConfig());

        self::assertSame(['Socket:ok', 'Connection:fail', 'Schema:fail'], self::outline($result));
        self::assertSame('Skipped — no connection to the database.', $result['steps'][2]['message']);
        self::assertSame('Connection failed: nope', $result['message'], 'the FIRST failing step names the overall failure');
    }

    private function pdoReporting(string $version, ?\PDOStatement $statement = null): \PDO
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->with(\PDO::ATTR_SERVER_VERSION)->willReturn($version);
        if ($statement !== null) {
            $pdo->method('prepare')->willReturn($statement);
        }

        return $pdo;
    }
}

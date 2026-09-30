<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\ConnectorConfigSecrets;
use PHPUnit\Framework\TestCase;

class ConnectorConfigSecretsTest extends TestCase
{
    private ConnectorConfigSecrets $secrets;

    protected function setUp(): void
    {
        $this->secrets = new ConnectorConfigSecrets();
    }

    public function testNullPassesThroughBothWays(): void
    {
        self::assertNull($this->secrets->mask(null));
        self::assertNull($this->secrets->merge(null, ['password' => 'x']));
    }

    public function testMaskReplacesNonEmptySecretStringsAtEveryDepth(): void
    {
        $masked = $this->secrets->mask([
            'server' => 'db.example.com',
            'port' => 5432,
            'password' => 'hunter2',
            'auth_headers' => ['X-Api-Key' => 'abc', 'Accept' => 'json'],
            'oauth' => ['body' => ['client_secret' => 's3cr3t', 'grant_type' => 'client_credentials']],
        ]);

        self::assertSame([
            'server' => 'db.example.com',
            'port' => 5432,
            'password' => ConnectorConfigSecrets::SENTINEL,
            'auth_headers' => ['X-Api-Key' => ConnectorConfigSecrets::SENTINEL, 'Accept' => 'json'],
            'oauth' => ['body' => ['client_secret' => ConnectorConfigSecrets::SENTINEL, 'grant_type' => 'client_credentials']],
        ], $masked);
    }

    public function testMaskLeavesEmptyOrNonStringSecretsAlone(): void
    {
        // An empty secret carries no information; a non-string (e.g. a number) cannot be a stored secret.
        self::assertSame(
            ['password' => '', 'token' => 123, 'secret' => null],
            $this->secrets->mask(['password' => '', 'token' => 123, 'secret' => null])
        );
    }

    public function testMaskNeverTreatsListIndexesAsSecretKeys(): void
    {
        self::assertSame(['a', 'b'], $this->secrets->mask(['a', 'b']));
    }

    /**
     * @dataProvider secretKeyProvider
     */
    public function testKeyNamesThatCountAsSecret(string $key, bool $secret): void
    {
        $masked = $this->secrets->mask([$key => 'value']);

        self::assertSame($secret ? ConnectorConfigSecrets::SENTINEL : 'value', $masked[$key]);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function secretKeyProvider(): array
    {
        return [
            'password' => ['password', true],
            'upper case' => ['PASSWORD', true],
            'passphrase' => ['passphrase', true],
            'plain key' => ['key', true],
            'private key' => ['private_key', true],
            'access key suffix' => ['access_key', true],
            'secret key suffix' => ['secret_key', true],
            'authorization header' => ['Authorization', true],
            'hyphenated header' => ['X-Auth-Token', true],
            'spaced header' => ['x api key', true],
            'refresh token' => ['refresh_token', true],
            'suffix password' => ['db_password', true],
            'suffix secret' => ['app_secret', true],
            'username is not secret' => ['user', false],
            'server' => ['server', false],
            'keyboard is not a suffix match' => ['keyboard', false],
            'key inside a word' => ['monkey_business', false],
            'bucket name' => ['bucket_name', false],
        ];
    }

    public function testMergeRestoresSentinelsFromTheStoredConfig(): void
    {
        $stored = [
            'password' => 'old-pass',
            'auth_headers' => ['X-Api-Key' => 'old-key'],
            'oauth' => ['body' => ['client_secret' => 'old-secret']],
        ];
        $submitted = [
            'password' => ConnectorConfigSecrets::SENTINEL,
            'auth_headers' => ['X-Api-Key' => ConnectorConfigSecrets::SENTINEL],
            'oauth' => ['body' => ['client_secret' => 'brand-new']],
        ];

        self::assertSame([
            'password' => 'old-pass',
            'auth_headers' => ['X-Api-Key' => 'old-key'],
            'oauth' => ['body' => ['client_secret' => 'brand-new']],
        ], $this->secrets->merge($submitted, $stored));
    }

    public function testMergeCollapsesASentinelWithoutAStoredCounterpartToEmpty(): void
    {
        $submitted = [
            'password' => ConnectorConfigSecrets::SENTINEL,
            'token' => ConnectorConfigSecrets::SENTINEL,
            'secret' => ConnectorConfigSecrets::SENTINEL,
            'nested' => ['api_key' => ConnectorConfigSecrets::SENTINEL],
        ];
        // stored: token empty, secret not a string, nested branch is not an array, password missing.
        $stored = ['token' => '', 'secret' => ['x'], 'nested' => 'scalar'];

        self::assertSame(
            ['password' => '', 'token' => '', 'secret' => '', 'nested' => ['api_key' => '']],
            $this->secrets->merge($submitted, $stored)
        );
        self::assertSame(['password' => ''], $this->secrets->merge(['password' => ConnectorConfigSecrets::SENTINEL], null));
    }

    public function testMergeKeepsSentinelLookalikesInNonSecretFields(): void
    {
        self::assertSame(
            ['note' => ConnectorConfigSecrets::SENTINEL, 'server' => 'a'],
            $this->secrets->merge(['note' => ConnectorConfigSecrets::SENTINEL, 'server' => 'a'], ['note' => 'stored'])
        );
    }

    public function testMergeKeepsFreshlyTypedSecrets(): void
    {
        self::assertSame(
            ['password' => 'typed'],
            $this->secrets->merge(['password' => 'typed'], ['password' => 'old'])
        );
    }

    public function testMaskThenMergeRoundTripsTheStoredConfig(): void
    {
        $stored = ['server' => 's', 'password' => 'p', 'headers' => ['X-Token' => 't', 'Accept' => 'a']];

        $roundTrip = $this->secrets->merge($this->secrets->mask($stored), $stored);

        self::assertSame($stored, $roundTrip);
    }
}

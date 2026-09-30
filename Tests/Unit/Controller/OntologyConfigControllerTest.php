<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller;

use Aaxis\Bundle\OntologyBundle\Controller\OntologyConfigController;
use Aaxis\Bundle\OntologyBundle\Manager\ConnectorTester;
use Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures\ControllerKit;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Encoder\SymmetricCrypterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class OntologyConfigControllerTest extends TestCase
{
    private ConnectorTester&MockObject $tester;
    private OntologyConfigController $controller;

    /** @var array<string, mixed> */
    private array $stored = [];

    protected function setUp(): void
    {
        $kit = new ControllerKit(fn (string $class) => $this->createMock($class));
        $this->tester = $this->createMock(ConnectorTester::class);
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnCallback(fn (string $key) => $this->stored[$key] ?? null);
        $crypter = $this->createMock(SymmetricCrypterInterface::class);
        $crypter->method('decryptData')->willReturnCallback(static fn (string $value): string => 'decrypted(' . $value . ')');

        $kit->set(ConnectorTester::class, $this->tester);
        $kit->set(ConfigManager::class, $config);
        $kit->set(SymmetricCrypterInterface::class, $crypter);
        $this->controller = $kit->wire(new OntologyConfigController());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function post(array $config): Request
    {
        return Request::create('/', 'POST', [], [], [], [], json_encode(['config' => $config], JSON_THROW_ON_ERROR));
    }

    public function testItProbesTheCurrentFormValuesThroughTheBucketArmOfTheTester(): void
    {
        $result = ['success' => true, 'message' => 'All checks passed.', 'steps' => []];
        $this->tester->expects(self::once())->method('test')->with('bucket', [
            'server' => 'https://s3.example.com',
            'access_key' => 'AKID',
            'secret_key' => 'SECRET',
            'bucket_name' => 'data',
        ])->willReturn($result);

        $response = $this->controller->bucketTestAction($this->post([
            'endpoint_url' => '  https://s3.example.com ',
            'access_key' => 'AKID',
            'secret_key' => 'SECRET',
            'bucket_name' => ' data ',
        ]));

        self::assertSame($result, ControllerKit::decode($response));
    }

    public function testUntouchedKeyPlaceholdersAreResolvedFromTheSavedEncryptedValues(): void
    {
        $this->stored = [
            'aaxis_ontology.bucket_access_key' => 'enc-access',
            'aaxis_ontology.bucket_secret_key' => 'enc-secret',
        ];
        $this->tester->expects(self::once())->method('test')->with('bucket', self::callback(static function (array $config): bool {
            return $config['access_key'] === 'decrypted(enc-access)' && $config['secret_key'] === 'decrypted(enc-secret)';
        }))->willReturn(['success' => true, 'message' => 'ok', 'steps' => []]);

        $this->controller->bucketTestAction($this->post(['endpoint_url' => 'h', 'access_key' => '********', 'secret_key' => '***', 'bucket_name' => 'b']));
    }

    public function testAPlaceholderWithNothingSavedBecomesEmpty(): void
    {
        $this->tester->expects(self::once())->method('test')->with('bucket', self::callback(
            static fn (array $config): bool => $config['access_key'] === '' && $config['secret_key'] === ''
        ))->willReturn(['success' => false, 'message' => 'x', 'steps' => []]);

        $this->controller->bucketTestAction($this->post(['access_key' => '****', 'secret_key' => '****']));
    }

    public function testRealTypedKeysAreNeverDecrypted(): void
    {
        $this->stored = ['aaxis_ontology.bucket_access_key' => 'enc-access'];
        $this->tester->expects(self::once())->method('test')->with('bucket', self::callback(
            static fn (array $config): bool => $config['access_key'] === 'AK*ID' && $config['secret_key'] === ''
        ))->willReturn(['success' => true, 'message' => 'ok', 'steps' => []]);

        // A key that merely CONTAINS asterisks is a typed value, not the placeholder.
        $this->controller->bucketTestAction($this->post(['access_key' => 'AK*ID']));
    }

    /**
     * @dataProvider brokenBodyProvider
     */
    public function testABrokenBodyProbesAnEmptyConfiguration(string $body): void
    {
        $this->tester->expects(self::once())->method('test')->with('bucket', [
            'server' => '',
            'access_key' => '',
            'secret_key' => '',
            'bucket_name' => '',
        ])->willReturn(['success' => false, 'message' => 'No server configured.', 'steps' => []]);

        $response = $this->controller->bucketTestAction(Request::create('/', 'POST', [], [], [], [], $body));

        self::assertSame('No server configured.', ControllerKit::decode($response)['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenBodyProvider(): array
    {
        return ['empty' => [''], 'not json' => ['{oops'], 'config is not an object' => ['{"config":"x"}'], 'json scalar' => ['5']];
    }

    public function testItSubscribesToTheServicesItUses(): void
    {
        $services = OntologyConfigController::getSubscribedServices();

        foreach ([ConnectorTester::class, ConfigManager::class, SymmetricCrypterInterface::class] as $service) {
            self::assertContains($service, $services);
        }
    }
}

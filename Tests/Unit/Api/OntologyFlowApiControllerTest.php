<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Api;

use Aaxis\Bundle\OntologyBundle\Api\OntologyFlowApiController;
use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use Aaxis\Bundle\OntologyBundle\Exception\EndpointResponseFailure;
use Aaxis\Bundle\OntologyBundle\Exception\FlowStepFailure;
use Aaxis\Bundle\OntologyBundle\Manager\EndpointFlowRunner;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class OntologyFlowApiControllerTest extends TestCase
{
    private EndpointFlowRunner&MockObject $runner;
    private TokenInterface&MockObject $token;
    private OntologyFlowApiController $controller;
    private ?UserInterface $user = null;
    private bool $hasToken = true;

    /** @var array<string, bool> */
    private array $config = [
        'aaxis_ontology.flow_api_secure_enabled' => true,
        'aaxis_ontology.flow_api_public_enabled' => true,
    ];

    /** @var array<int, string> */
    private array $granted = [];

    protected function setUp(): void
    {
        $this->runner = $this->createMock(EndpointFlowRunner::class);
        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturnCallback(fn (string $key) => $this->config[$key] ?? null);
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(fn (string $attribute): bool => \in_array($attribute, $this->granted, true));

        $this->token = $this->createMock(TokenInterface::class);
        $this->token->method('getUser')->willReturnCallback(fn (): ?UserInterface => $this->user);
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturnCallback(fn (): ?TokenInterface => $this->hasToken ? $this->token : null);

        $container = new Container();
        $container->set(EndpointFlowRunner::class, $this->runner);
        $container->set(ConfigManager::class, $config);
        $container->set('security.authorization_checker', $checker);
        $container->set('security.token_storage', $storage);
        $this->controller = new OntologyFlowApiController();
        $this->controller->setContainer($container);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function match(array $config = ['public' => false], array $params = []): array
    {
        $flow = (new OntologyFlow())->setName('Orders API');

        return ['flow' => $flow, 'steps' => [['id' => 's1']], 'links' => [], 'config' => $config, 'params' => $params];
    }

    private function user(string $first = '', string $last = '', string $email = '', string $identifier = 'jdoe'): UserInterface
    {
        return new class ($first, $last, $email, $identifier) implements UserInterface {
            public function __construct(private readonly string $first, private readonly string $last, private readonly string $email, private readonly string $identifier)
            {
            }

            public function getFirstName(): string
            {
                return $this->first;
            }

            public function getLastName(): string
            {
                return $this->last;
            }

            public function getEmail(): string
            {
                return $this->email;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return $this->identifier;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function request(string $method = 'GET', string $body = '', string $uri = '/'): Request
    {
        return Request::create($uri, $method, [], [], [], [], $body);
    }

    // --- resolution + kill switches -----------------------------------------------------------

    public function testAnUnmatchedRequestIsA404(): void
    {
        $this->runner->expects(self::once())->method('match')->with('POST', 'orders/1')->willReturn(null);
        $this->runner->expects(self::never())->method('run');

        $response = $this->controller->endpointAction($this->request('POST'), 'orders/1');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            ['error' => 'No enabled flow endpoint matches this method and path.', 'code' => 'endpoint_not_found'],
            self::decode($response)
        );
    }

    /**
     * @dataProvider switchProvider
     *
     * @param array<string, mixed> $trigger
     */
    public function testASwitchedOffKindIsA404BeforeAnyAuthCheck(array $trigger, string $switch): void
    {
        $this->config[$switch] = false;
        $this->runner->method('match')->willReturn($this->match($trigger));
        $this->runner->expects(self::never())->method('run');

        $response = $this->controller->endpointAction($this->request(), 'x');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'Not found.', 'code' => 'endpoint_disabled'], self::decode($response));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function switchProvider(): array
    {
        return [
            'secure (public flag false)' => [['public' => false], 'aaxis_ontology.flow_api_secure_enabled'],
            'secure (public flag absent)' => [[], 'aaxis_ontology.flow_api_secure_enabled'],
            'secure (public flag not a real true)' => [['public' => 'yes'], 'aaxis_ontology.flow_api_secure_enabled'],
            'public' => [['public' => true], 'aaxis_ontology.flow_api_public_enabled'],
        ];
    }

    public function testTheOtherKindsSwitchDoesNotAffectAnEndpoint(): void
    {
        $this->config['aaxis_ontology.flow_api_public_enabled'] = false;
        $this->user = $this->user();
        $this->granted = ['aaxis_ontology_api_access_flow'];
        $this->runner->method('match')->willReturn($this->match(['public' => false]));
        $this->runner->method('run')->willReturn(['flowUuid' => 'u']);

        self::assertSame(200, $this->controller->endpointAction($this->request(), 'x')->getStatusCode());
    }

    // --- authorization ------------------------------------------------------------------------

    public function testANonPublicEndpointDemandsAuthentication(): void
    {
        $this->user = null;
        $this->runner->method('match')->willReturn($this->match());
        $this->runner->expects(self::never())->method('run');

        $response = $this->controller->endpointAction($this->request(), 'x');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Authentication required.', 'code' => 'unauthenticated'], self::decode($response));
    }

    public function testNoTokenAtAllCountsAsAnonymous(): void
    {
        $this->hasToken = false;
        $this->runner->method('match')->willReturn($this->match());

        self::assertSame(401, $this->controller->endpointAction($this->request(), 'x')->getStatusCode());
    }

    public function testAnAuthenticatedCallerNeedsTheFlowOrTheAllCapability(): void
    {
        $this->user = $this->user();
        $this->runner->method('match')->willReturn($this->match());
        $this->runner->expects(self::never())->method('run');

        $response = $this->controller->endpointAction($this->request(), 'x');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'Access denied.', 'code' => 'forbidden'], self::decode($response));
    }

    /**
     * @dataProvider grantProvider
     */
    public function testEitherCapabilityOpensANonPublicEndpoint(string $grant): void
    {
        $this->user = $this->user();
        $this->granted = [$grant];
        $this->runner->method('match')->willReturn($this->match());
        $this->runner->method('run')->willReturn(['flowUuid' => 'u']);

        self::assertSame(200, $this->controller->endpointAction($this->request(), 'x')->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function grantProvider(): array
    {
        return ['flow' => ['aaxis_ontology_api_access_flow'], 'all' => ['aaxis_ontology_api_access_all']];
    }

    public function testAPublicEndpointRunsForAnonymousCallersWithoutOauthVariables(): void
    {
        $this->user = null;
        $match = $this->match(['public' => true], ['id' => '7']);
        $this->runner->method('match')->willReturn($match);
        $this->runner->expects(self::once())->method('run')
            ->with($match, ['a' => 1], self::anything(), ['q' => 'x'], [], ['trigger' => 'endpoint'])
            ->willReturn(['flowUuid' => 'u-1', 'payload' => 5]);

        $response = $this->controller->endpointAction(
            $this->request('POST', '{"a":1}', '/?q=x'),
            'orders/7'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'flowUuid' => 'u-1', 'context' => ['flowUuid' => 'u-1', 'payload' => 5]], self::decode($response));
    }

    // --- caller identity ----------------------------------------------------------------------

    public function testAnAuthenticatedCallerIsIdentifiedToTheFlowAndItsEvents(): void
    {
        $this->user = $this->user('Jane', 'Doe', 'jane@example.com');
        $this->granted = ['aaxis_ontology_api_access_all'];
        $client = new class () {
            public function getName(): string
            {
                return 'Partner Portal';
            }
        };
        $this->token->method('hasAttribute')->with('client')->willReturn(true);
        $this->token->method('getAttribute')->with('client')->willReturn($client);
        $this->runner->method('match')->willReturn($this->match());
        $this->runner->expects(self::once())->method('run')->with(
            self::anything(),
            null,
            self::anything(),
            [],
            ['OAuthApplication' => 'Partner Portal'],
            ['trigger' => 'endpoint', 'user' => ['name' => 'Jane Doe', 'email' => 'jane@example.com']]
        )->willReturn([]);

        $this->controller->endpointAction($this->request(), 'x');
    }

    public function testTheUserIdentifierIsTheFallbackNameAndAPublicEndpointStillExposesTheApplication(): void
    {
        $this->user = $this->user('', '', '', 'svc-account');
        $client = new class () {
            public function getName(): string
            {
                return 'Batch Job';
            }
        };
        $this->token->method('hasAttribute')->willReturn(true);
        $this->token->method('getAttribute')->willReturn($client);
        $this->runner->method('match')->willReturn($this->match(['public' => true]));
        $this->runner->expects(self::once())->method('run')->with(
            self::anything(),
            null,
            self::anything(),
            [],
            ['OAuthApplication' => 'Batch Job'],
            ['trigger' => 'endpoint', 'user' => ['name' => 'svc-account', 'email' => '']]
        )->willReturn([]);

        $this->controller->endpointAction($this->request(), 'x');
    }

    /**
     * @dataProvider unnamedClientProvider
     */
    public function testTheApplicationIsNullWhenTheTokenCannotNameIt(bool $hasAttribute, mixed $client): void
    {
        $this->user = $this->user();
        $this->token->method('hasAttribute')->willReturn($hasAttribute);
        $this->token->method('getAttribute')->willReturn($client);
        $this->runner->method('match')->willReturn($this->match(['public' => true]));
        $this->runner->expects(self::once())->method('run')->with(
            self::anything(),
            null,
            self::anything(),
            [],
            ['OAuthApplication' => null],
            self::anything()
        )->willReturn([]);

        $this->controller->endpointAction($this->request(), 'x');
    }

    /**
     * @return array<string, array{bool, mixed}>
     */
    public static function unnamedClientProvider(): array
    {
        return [
            'no client attribute' => [false, null],
            'client is a string' => [true, 'portal'],
            'client cannot be named' => [true, new \stdClass()],
        ];
    }

    public function testAUserWithoutNameAccessorsFallsBackToTheIdentifier(): void
    {
        $this->user = new class () implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'plain-user';
            }
        };
        $this->granted = ['aaxis_ontology_api_access_all'];
        $this->runner->method('match')->willReturn($this->match());
        $this->runner->expects(self::once())->method('run')->with(
            self::anything(),
            null,
            self::anything(),
            [],
            self::anything(),
            ['trigger' => 'endpoint', 'user' => ['name' => 'plain-user', 'email' => '']]
        )->willReturn([]);

        $this->controller->endpointAction($this->request(), 'x');
    }

    // --- request -> flow input ----------------------------------------------------------------

    /**
     * @dataProvider bodyProvider
     */
    public function testTheBodyIsJsonDecodedWhenItParsesRawWhenItDoesNotAndNullWhenEmpty(string $raw, mixed $expected): void
    {
        $this->runner->method('match')->willReturn($this->match(['public' => true]));
        $this->runner->expects(self::once())->method('run')->with(self::anything(), $expected, self::anything(), [], [], self::anything())->willReturn([]);

        $this->controller->endpointAction($this->request('POST', $raw), 'x');
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function bodyProvider(): array
    {
        return [
            'object' => ['{"a":{"b":[1,2]}}', ['a' => ['b' => [1, 2]]]],
            'list' => ['[1,2]', [1, 2]],
            'json scalar' => ['42', 42],
            'json null literal' => ['null', null],
            'plain text' => ['hello world', 'hello world'],
            'broken json stays raw' => ['{"a":', '{"a":'],
            'empty' => ['', null],
        ];
    }

    public function testHeadersAreLowercasedAndCredentialsNeverReachTheFlow(): void
    {
        $this->runner->method('match')->willReturn($this->match(['public' => true]));
        $seen = null;
        $this->runner->method('run')->willReturnCallback(function (array $match, mixed $body, array $headers) use (&$seen): array {
            $seen = $headers;

            return [];
        });
        $request = $this->request();
        $request->headers->add([
            'X-Trace-Id' => 'abc',
            'Authorization' => 'Bearer secret',
            'Cookie' => 'SESSION=1',
            'PHP_AUTH_USER' => 'u',
            'PHP_AUTH_PW' => 'p',
            'Accept' => ['application/json', 'text/plain'],
        ]);

        $this->controller->endpointAction($request, 'x');

        self::assertSame('abc', $seen['x-trace-id']);
        self::assertSame('application/json', $seen['accept'], 'the first value of a multi-valued header');
        foreach (['authorization', 'cookie', 'php-auth-user', 'php-auth-pw'] as $hidden) {
            self::assertArrayNotHasKey($hidden, $seen);
        }
    }

    // --- outcomes -----------------------------------------------------------------------------

    public function testAnEmptyFinalContextRendersAsAnEmptyObject(): void
    {
        $this->runner->method('match')->willReturn($this->match(['public' => true]));
        $this->runner->method('run')->willReturn([]);

        $response = $this->controller->endpointAction($this->request(), 'x');

        self::assertSame('{"success":true,"flowUuid":null,"context":{}}', $response->getContent());
    }

    public function testAResponseBindingShapesTheAnswer(): void
    {
        $config = ['public' => true, 'response' => '{statusCode: 201, body: payload}'];
        $this->runner->method('match')->willReturn($this->match($config));
        $this->runner->method('run')->willReturn(['payload' => ['id' => 9]]);
        $this->runner->expects(self::once())->method('respond')->with($config, ['payload' => ['id' => 9]])->willReturn(['statusCode' => 201, 'body' => ['id' => 9]]);

        $response = $this->controller->endpointAction($this->request(), 'x');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['id' => 9], self::decode($response));
    }

    public function testAStepFailureNamesTheFailingStep(): void
    {
        $this->runner->method('match')->willReturn($this->match(['public' => true]));
        $this->runner->method('run')->willThrowException(new FlowStepFailure('Step "x": boom', 's3', ['s1']));

        $response = $this->controller->endpointAction($this->request(), 'x');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'Step "x": boom', 'code' => 'flow_failed', 'failedStepId' => 's3'], self::decode($response));
    }

    public function testAnyOtherRuntimeFailureIsAlsoFlowFailed(): void
    {
        $this->runner->method('match')->willReturn($this->match(['public' => true]));
        $this->runner->method('run')->willReturn([]);
        $this->runner->method('respond')->willThrowException(new EndpointResponseFailure('the response binding failed: nope'));

        $response = $this->controller->endpointAction($this->request(), 'x');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'the response binding failed: nope', 'code' => 'flow_failed'], self::decode($response));
    }

    public function testTheRouteHandlesEveryRunnerMethod(): void
    {
        $route = (new \ReflectionMethod(OntologyFlowApiController::class, 'endpointAction'))
            ->getAttributes(\Symfony\Component\Routing\Attribute\Route::class)[0]->newInstance();

        self::assertSame(EndpointFlowRunner::METHODS, $route->methods);
        self::assertSame('aaxis_ontology_api_flow_endpoint', $route->name);
    }

    public function testItSubscribesToTheServicesItUses(): void
    {
        $services = OntologyFlowApiController::getSubscribedServices();

        self::assertContains(EndpointFlowRunner::class, $services);
        self::assertContains(ConfigManager::class, $services);
    }
}

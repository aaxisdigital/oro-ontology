<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Controller\Fixtures;

use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wires a controller to a service container of mocks: the Doctrine stub, a translator that echoes
 * its key (plus the parameters when there are any), and a security layer whose grants and current
 * user the test sets.
 */
final class ControllerKit
{
    public Container $container;
    public OrmStub $orm;

    /** @var array<int, string> "attribute" or "attribute|subject" strings the current user holds */
    public array $granted = [];

    public ?UserInterface $user = null;

    /**
     * @param callable(class-string): MockObject $createMock
     */
    public function __construct(callable $createMock)
    {
        $this->orm = new OrmStub($createMock);
        $this->container = new Container();
        $this->container->set('doctrine', $this->orm->registry);
        $this->container->set(\Doctrine\Persistence\ManagerRegistry::class, $this->orm->registry);

        $translator = $createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $parameters === [] ? $id : $id . '|' . json_encode($parameters)
        );
        $this->container->set(TranslatorInterface::class, $translator);

        $checker = $createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(function (mixed $attribute, mixed $subject = null): bool {
            $needle = \is_string($attribute) ? $attribute . ($subject !== null && \is_string($subject) ? '|' . $subject : '') : '';

            return \in_array($needle, $this->granted, true);
        });
        $this->container->set('security.authorization_checker', $checker);

        $token = $createMock(TokenInterface::class);
        $token->method('getUser')->willReturnCallback(fn (): ?UserInterface => $this->user);
        $storage = $createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturnCallback(fn (): ?TokenInterface => $this->user === null ? null : $token);
        $this->container->set('security.token_storage', $storage);
    }

    public function set(string $id, object $service): void
    {
        $this->container->set($id, $service);
    }

    /**
     * @template T of AbstractController
     *
     * @param T $controller
     *
     * @return T
     */
    public function wire(AbstractController $controller): AbstractController
    {
        $controller->setContainer($this->container);

        return $controller;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

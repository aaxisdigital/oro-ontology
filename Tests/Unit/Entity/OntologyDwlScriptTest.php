<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Entity;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyDwlScript;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\TestCase;

final class OntologyDwlScriptTest extends TestCase
{
    private static function user(?int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    public function testANewScriptIsPrivateWithTheDefaultMetadata(): void
    {
        $script = new OntologyDwlScript();

        self::assertNull($script->getId());
        self::assertNull($script->getOwner());
        self::assertSame(['', '', false, [], 100], [
            $script->getName(), $script->getScript(), $script->isPublic(), $script->getInputs(), $script->getRowLimit(),
        ]);
        self::assertSame('UTC', $script->getCreatedAt()->getTimezone()->getName());
        self::assertEquals($script->getCreatedAt(), $script->getUpdatedAt());
        self::assertNotSame($script->getCreatedAt(), $script->getUpdatedAt());
    }

    public function testItKeepsWhatItIsGiven(): void
    {
        $owner = self::user(3);
        $inputs = [['entityId' => 10, 'name' => 'orders'], ['entityId' => 11, 'name' => 'lines']];
        $script = (new OntologyDwlScript())
            ->setOwner($owner)->setName('totals')->setScript('orders')->setPublic(true)
            ->setInputs($inputs)->setRowLimit(null);

        self::assertSame([$owner, 'totals', 'orders', true, $inputs, null], [
            $script->getOwner(), $script->getName(), $script->getScript(), $script->isPublic(), $script->getInputs(), $script->getRowLimit(),
        ]);
    }

    public function testTouchStampsTheLastChange(): void
    {
        $script = new OntologyDwlScript();
        $created = $script->getCreatedAt();
        $before = $script->getUpdatedAt();
        usleep(1000);

        $script->touch();

        self::assertGreaterThan($before, $script->getUpdatedAt());
        self::assertSame($created, $script->getCreatedAt());
    }

    public function testOnlyTheOwnerOwnsIt(): void
    {
        $owner = self::user(3);
        $script = (new OntologyDwlScript())->setOwner($owner);

        self::assertTrue($script->isOwnedBy($owner));
        self::assertTrue($script->isOwnedBy(self::user(3)));
        self::assertFalse($script->isOwnedBy(self::user(4)));
        self::assertFalse($script->isOwnedBy(null));
        // Two unsaved users (no ids) are only the same user when they are the same object.
        $fresh = self::user(null);
        self::assertTrue((new OntologyDwlScript())->setOwner($fresh)->isOwnedBy($fresh));
        self::assertFalse((new OntologyDwlScript())->setOwner($fresh)->isOwnedBy(self::user(null)));
        self::assertFalse((new OntologyDwlScript())->isOwnedBy($owner));
    }
}

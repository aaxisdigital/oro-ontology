<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Dwl\Runtime;

use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Environment;
use Aaxis\Bundle\OntologyBundle\Dwl\Runtime\Value;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase
{
    public function testChildrenSeeTheirParentsAndShadowThem(): void
    {
        $parent = new Environment();
        $parent->define('a', Value::number(1));
        $child = $parent->child();
        $child->define('b', Value::number(2));

        self::assertSame(1, $child->get('a')->data);
        self::assertTrue($child->has('a'));
        self::assertSame(2, $child->get('b')->data);
        self::assertFalse($parent->has('b'));
        self::assertNull($parent->get('b'));

        $child->define('a', Value::number(3));
        self::assertSame(3, $child->get('a')->data);
        self::assertSame(1, $parent->get('a')->data);
    }

    public function testScopesStackAndUnwind(): void
    {
        $env = new Environment();
        $env->define('outer', Value::string('o'));

        $env->pushScope();
        $env->define('inner', Value::string('i'));
        $env->pushScope();
        $env->define('outer', Value::string('shadow'));

        self::assertSame('shadow', $env->get('outer')->data);
        self::assertSame('i', $env->get('inner')->data);
        self::assertTrue($env->has('inner'));

        $env->popScope();
        self::assertSame('o', $env->get('outer')->data);

        $env->popScope();
        self::assertFalse($env->has('inner'));
        self::assertNull($env->get('inner'));
        self::assertTrue($env->has('outer'));
    }

    public function testStackedBindingsAreFoundBeforeTheParent(): void
    {
        $parent = new Environment();
        $parent->define('x', Value::number(1));
        $env = new Environment($parent);
        $env->define('x', Value::number(2));
        $env->pushScope();

        self::assertSame(2, $env->get('x')->data);
        self::assertTrue($env->has('x'));
        self::assertFalse($env->has('y'));
    }
}

<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\DebugContextPreview;
use PHPUnit\Framework\TestCase;

class DebugContextPreviewTest extends TestCase
{
    public function testLeavesASmallContextAlone(): void
    {
        $context = ['payload' => ['a' => 1, 'list' => [1, 2, 3]], 'flowUuid' => 'u'];

        self::assertSame(['context' => $context, 'truncated' => []], DebugContextPreview::of($context));
    }

    public function testCutsEveryBigObjectOrListAtAnyDepthAndSaysWhere(): void
    {
        $object = [];
        for ($i = 0; $i < 5; $i++) {
            $object['k/' . $i] = ['~deep' => range(1, 4)];
        }

        $preview = DebugContextPreview::of(['rows' => range(1, 7), 'by key' => $object, 'small' => [1, 2]], 3);

        self::assertSame([1, 2, 3], $preview['context']['rows']);
        self::assertSame(['k/0', 'k/1', 'k/2'], array_keys($preview['context']['by key']));
        self::assertSame([1, 2, 3], $preview['context']['by key']['k/1']['~deep']);
        self::assertSame([
            '/rows' => 7,
            '/by key' => 5,
            '/by key/k~10/~0deep' => 4,
            '/by key/k~11/~0deep' => 4,
            '/by key/k~12/~0deep' => 4,
        ], $preview['truncated']);
    }

    public function testCutsTheTopLevelToo(): void
    {
        $context = array_combine(array_map(static fn (int $i) => 'v' . $i, range(1, 150)), range(1, 150));

        $preview = DebugContextPreview::of($context);

        self::assertCount(DebugContextPreview::LIMIT, $preview['context']);
        self::assertSame(['' => 150], $preview['truncated']);
    }
}

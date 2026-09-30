<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager;

use Aaxis\Bundle\OntologyBundle\Manager\FlowDesignParserTrait;
use PHPUnit\Framework\TestCase;

class FlowDesignParserTraitTest extends TestCase
{
    private object $parser;

    protected function setUp(): void
    {
        $this->parser = new class () {
            use FlowDesignParserTrait {
                parseDesign as public;
            }
        };
    }

    public function testNormalizesStepsAndLinksAndPicksTheFirstTriggerOfTheType(): void
    {
        $design = [
            'version' => 2,
            'steps' => [
                ['id' => 's1', 'type' => 'cron', 'name' => 'Nightly', 'config' => ['expression' => '* * * * *'], 'x' => 1],
                ['id' => 's2', 'type' => 'logger', 'config' => 'not-an-array'],
                ['id' => 's3', 'type' => 'cron', 'name' => 'Second'],
            ],
            'links' => [
                ['from' => 's1', 'to' => 's2'],
                ['from' => 's2', 'fromPort' => '1', 'to' => 's3'],
                ['from' => 's1'],
                'junk',
            ],
        ];

        [$steps, $links, $trigger] = $this->parser->parseDesign($design, 'cron');

        self::assertSame([
            ['id' => 's1', 'type' => 'cron', 'name' => 'Nightly', 'config' => ['expression' => '* * * * *']],
            ['id' => 's2', 'type' => 'logger', 'name' => 's2', 'config' => null],
            ['id' => 's3', 'type' => 'cron', 'name' => 'Second', 'config' => null],
        ], $steps);
        self::assertSame([
            ['from' => 's1', 'fromPort' => 0, 'to' => 's2'],
            ['from' => 's2', 'fromPort' => 1, 'to' => 's3'],
        ], $links);
        self::assertSame($steps[0], $trigger);
    }

    public function testMissingLinksAreAnEmptyList(): void
    {
        $parsed = $this->parser->parseDesign(['steps' => [['id' => 'a', 'type' => 'endpoint']]], 'endpoint');

        self::assertSame([], $parsed[1]);
    }

    /**
     * @dataProvider unusableDesigns
     */
    public function testRejectsUnusableDesigns(mixed $design): void
    {
        self::assertNull($this->parser->parseDesign($design, 'cron'));
    }

    public static function unusableDesigns(): array
    {
        return [
            'null' => [null],
            'not an array' => ['{}'],
            'no steps' => [['links' => []]],
            'steps not a list' => [['steps' => 'x']],
            'malformed step' => [['steps' => [['id' => 'a', 'type' => 'cron'], 'junk']]],
            'step without id' => [['steps' => [['type' => 'cron']]]],
            'step with a non-string type' => [['steps' => [['id' => 'a', 'type' => 5]]]],
            'no trigger of that type' => [['steps' => [['id' => 'a', 'type' => 'endpoint']]]],
        ];
    }
}

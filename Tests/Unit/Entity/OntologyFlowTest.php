<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Entity;

use Aaxis\Bundle\OntologyBundle\Entity\OntologyFlow;
use PHPUnit\Framework\TestCase;

class OntologyFlowTest extends TestCase
{
    public function testANewFlowIsAnEnabledSubflowStampedWithTheCreationTimeInUtc(): void
    {
        $before = new \DateTimeImmutable('-2 seconds');
        $flow = new OntologyFlow();

        self::assertNull($flow->getId());
        self::assertNull($flow->getName());
        self::assertTrue($flow->isEnabled());
        self::assertSame(OntologyFlow::TYPE_SUBFLOW, $flow->getType());
        self::assertFalse($flow->isNative());
        self::assertNull($flow->getSteps());
        self::assertNull($flow->getDesign());
        self::assertNull($flow->getTriggerType());
        self::assertNull($flow->getLastExecuted());
        self::assertNull($flow->getLastFinished());
        self::assertSame('UTC', $flow->getLastModified()->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $flow->getLastModified()->getTimestamp());
        self::assertSame('', (string) $flow);
    }

    public function testSettersAreFluent(): void
    {
        $flow = new OntologyFlow();
        $stamp = new \DateTimeImmutable('2026-01-02 03:04:05');

        $result = $flow->setName('Nightly')
            ->setEnabled(false)
            ->setType(OntologyFlow::TYPE_NATIVE)
            ->setSteps([['type' => 'cron']])
            ->setDesign(['version' => 2])
            ->setTriggerType('cron')
            ->setLastExecuted($stamp)
            ->setLastFinished($stamp)
            ->setLastModified($stamp);

        self::assertSame($flow, $result);
        self::assertSame('Nightly', $flow->getName());
        self::assertFalse($flow->isEnabled());
        self::assertTrue($flow->isNative());
        self::assertSame([['type' => 'cron']], $flow->getSteps());
        self::assertSame(['version' => 2], $flow->getDesign());
        self::assertSame('cron', $flow->getTriggerType());
        self::assertSame($stamp, $flow->getLastExecuted());
        self::assertSame($stamp, $flow->getLastFinished());
        self::assertSame($stamp, $flow->getLastModified());
        self::assertSame('Nightly', (string) $flow);
    }

    /**
     * @dataProvider stepsProvider
     *
     * @param array<int, mixed>|null $steps
     */
    public function testTypeTriggerAndEnabledAreDerivedFromTheSteps(?array $steps, ?string $trigger, string $type, bool $enabled): void
    {
        self::assertSame($trigger, OntologyFlow::computeTriggerType($steps));
        self::assertSame($type, OntologyFlow::computeType($steps));
        self::assertSame($enabled, OntologyFlow::computeEnabled($steps));
    }

    /**
     * @return array<string, array{array<int, mixed>|null, string|null, string, bool}>
     */
    public static function stepsProvider(): array
    {
        return [
            'null steps' => [null, null, 'subflow', false],
            'no steps' => [[], null, 'subflow', false],
            'no trigger (legacy)' => [[['type' => 'dwl_transform']], null, 'subflow', false],
            'enabled cron' => [[['type' => 'cron', 'config' => ['enabled' => true]]], 'cron', 'flow', true],
            'disabled endpoint' => [[['type' => 'endpoint', 'config' => ['enabled' => false]]], 'endpoint', 'flow', false],
            'flag missing' => [[['type' => 'entity_change', 'config' => []]], 'entity_change', 'flow', false],
            'no config at all' => [[['type' => 'cron']], 'cron', 'flow', false],
            'truthy is not true' => [[['type' => 'cron', 'config' => ['enabled' => 1]]], 'cron', 'flow', false],
            'config is not an array' => [[['type' => 'cron', 'config' => 'yes']], 'cron', 'flow', false],
            'subflow trigger' => [[['type' => 'subflow', 'config' => ['enabled' => true]]], 'subflow', 'subflow', true],
            'the first trigger wins' => [
                [['type' => 'dwl_transform'], ['type' => 'cron', 'config' => ['enabled' => true]], ['type' => 'endpoint']],
                'cron',
                'flow',
                true,
            ],
            'junk entries are skipped' => [['nope', 7, ['type' => 'cron', 'config' => ['enabled' => true]]], 'cron', 'flow', true],
        ];
    }

    /**
     * @dataProvider runningProvider
     */
    public function testRunningMeansStartedAndNotYetFinished(?string $executed, ?string $finished, bool $running): void
    {
        $flow = (new OntologyFlow())
            ->setLastExecuted($executed === null ? null : new \DateTimeImmutable($executed))
            ->setLastFinished($finished === null ? null : new \DateTimeImmutable($finished));

        self::assertSame($running, $flow->isRunning());
    }

    /**
     * @return array<string, array{string|null, string|null, bool}>
     */
    public static function runningProvider(): array
    {
        return [
            'never ran' => [null, null, false],
            'never ran but has a finish stamp' => [null, '2026-01-01 10:00:00', false],
            'started, not finished' => ['2026-01-01 10:00:00', null, true],
            'finished before the latest start' => ['2026-01-01 10:00:05', '2026-01-01 10:00:00', true],
            'finished at the start second' => ['2026-01-01 10:00:00', '2026-01-01 10:00:00', false],
            'finished after the start' => ['2026-01-01 10:00:00', '2026-01-01 10:00:09', false],
        ];
    }

    public function testStepTypesCoverEveryTriggerType(): void
    {
        foreach (OntologyFlow::TRIGGER_STEP_TYPES as $trigger) {
            self::assertContains($trigger, OntologyFlow::STEP_TYPES);
        }
        self::assertSame(2, OntologyFlow::DESIGN_VERSION);
    }
}

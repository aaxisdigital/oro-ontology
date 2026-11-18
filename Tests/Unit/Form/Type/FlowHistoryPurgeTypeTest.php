<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Form\Type;

use Aaxis\Bundle\OntologyBundle\Form\Type\FlowHistoryPurgeType;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

class FlowHistoryPurgeTypeTest extends TestCase
{
    public function testItExposesTheLastPurgeAndTheWindow(): void
    {
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->method('lastPurge')->willReturn(['at' => 'a', 'days' => 30, 'cutoff' => 'c', 'events' => 5, 'runs' => 2, 'bucketConfigured' => false, 'bucketObjects' => null]);
        $maintenance->method('historyDays')->willReturn(30);
        $type = new FlowHistoryPurgeType($maintenance);
        $view = new FormView();

        $type->buildView($view, $this->createMock(FormInterface::class), []);

        self::assertSame(5, $view->vars['last_purge']['events']);
        self::assertSame(30, $view->vars['history_days']);
        self::assertSame(TextType::class, $type->getParent());
        self::assertSame('aaxis_ontology_flow_history_purge', $type->getBlockPrefix());
        self::assertNull($view->vars['last_compact']);
    }

    public function testItFormatsTheLastCompactionPerTable(): void
    {
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->method('lastCompact')->willReturn(['at' => '2026-11-18 19:00:00.000000', 'tables' => [
            ['table' => 'aaxis_ontology_flow_events', 'before' => 16 * 1024 * 1024, 'after' => '9576448'],
            ['table' => 'aaxis_ontology_flow_runs', 'before' => null, 'after' => null, 'error' => 'canceling statement due to lock timeout'],
            'garbage',
        ]]);
        $view = new FormView();

        (new FlowHistoryPurgeType($maintenance))->buildView($view, $this->createMock(FormInterface::class), []);

        self::assertSame(['at' => '2026-11-18 19:00:00.000000', 'tables' => [
            ['table' => 'aaxis_ontology_flow_events', 'before' => '16.0 mb', 'after' => '9.1 mb', 'error' => null],
            ['table' => 'aaxis_ontology_flow_runs', 'before' => '—', 'after' => '—', 'error' => 'canceling statement due to lock timeout'],
        ]], $view->vars['last_compact']);
    }
}

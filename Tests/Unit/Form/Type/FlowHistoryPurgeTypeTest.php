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
    }
}

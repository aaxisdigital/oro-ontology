<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Form\Type;

use Aaxis\Bundle\OntologyBundle\Form\Type\FlowRunIndexStatusType;
use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

class FlowRunIndexStatusTypeTest extends TestCase
{
    public function testItExposesTheStatusAndTheLastRebuild(): void
    {
        $maintenance = $this->createMock(FlowRunMaintenance::class);
        $maintenance->method('staleSince')->willReturn('2026-03-04 05:06:07.000000');
        $maintenance->method('lastRebuild')->willReturn(['at' => '2026-03-01 00:00:00.000000', 'storage' => 'db', 'runs' => 9, 'days' => null]);
        $type = new FlowRunIndexStatusType($maintenance);
        $view = new FormView();

        $type->buildView($view, $this->createMock(FormInterface::class), []);

        self::assertSame('2026-03-04 05:06:07.000000', $view->vars['stale_since']);
        self::assertSame(9, $view->vars['last_rebuild']['runs']);
        self::assertSame(TextType::class, $type->getParent());
        self::assertSame('aaxis_ontology_flow_runs_index', $type->getBlockPrefix());
    }
}

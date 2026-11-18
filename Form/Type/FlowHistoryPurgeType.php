<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Form\Type;

use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * System Configuration → Settings: the flow-execution history CLEANUP — the last purge's outcome
 * (daily job or button alike) and the "Clean up now" button
 * (flow-history-maintenance-component → OntologyConfigController::flowHistoryPurgeAction).
 * Declared `ui_only`: renders no input and persists nothing; the value it shows is written only
 * by {@see FlowRunMaintenance::purge()}.
 */
class FlowHistoryPurgeType extends AbstractType
{
    public function __construct(private readonly FlowRunMaintenance $maintenance)
    {
    }

    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['last_purge'] = $this->maintenance->lastPurge();
        $view->vars['history_days'] = $this->maintenance->historyDays();
    }

    #[\Override]
    public function getParent(): string
    {
        return TextType::class;
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'aaxis_ontology_flow_history_purge';
    }
}

<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Form\Type;

use Aaxis\Bundle\OntologyBundle\Manager\FlowRunMaintenance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * System Configuration → Settings: the run index's READ-ONLY status (in sync, or out of sync
 * since the first event it failed to take in) and last rebuild, plus the "Rebuild run index"
 * button (flow-runs-index-component → OntologyConfigController::flowRunsRebuildAction). Declared
 * `ui_only`: renders no input and persists nothing; the two values it shows are written only by
 * {@see FlowRunMaintenance}.
 */
class FlowRunIndexStatusType extends AbstractType
{
    public function __construct(private readonly FlowRunMaintenance $maintenance)
    {
    }

    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['stale_since'] = $this->maintenance->staleSince();
        $view->vars['last_rebuild'] = $this->maintenance->lastRebuild();
    }

    #[\Override]
    public function getParent(): string
    {
        return TextType::class;
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'aaxis_ontology_flow_runs_index';
    }
}

<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * A Call Subflow / Foreach Loop failed because a step INSIDE the inner flow it invoked failed. An
 * inner flow is drawn on the same canvas as its caller, so the executor reports the inner step
 * that failed (`$stepId`, light red in the editor) instead of the invoking step. The message is the
 * invoker's ('Step "<caller>": <inner detail>').
 *
 * @see \Aaxis\Bundle\OntologyBundle\Manager\FlowDebugExecutor
 */
class InnerFlowFailure extends FlowExecutionFailure
{
    public function __construct(string $message, public readonly string $stepId, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

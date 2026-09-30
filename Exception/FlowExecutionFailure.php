<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * A flow that cannot run, or a step of it that cannot execute: the run gate's first problem, a
 * missing trigger, an incomplete step config, a vanished connector/subflow/entity, a failing DWL
 * expression, an HTTP or transfer error. The message is user-readable (step errors name their
 * step); the executor wraps step failures into {@see FlowStepFailure} with the failing step's id.
 * It extends \RuntimeException so existing catch blocks keep working.
 *
 * @see \Aaxis\Bundle\OntologyBundle\Manager\FlowDebugExecutor
 */
class FlowExecutionFailure extends \RuntimeException
{
}

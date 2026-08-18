<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * A flow run that failed INSIDE a step, carrying what the editor needs to paint the canvas: the
 * failing step's id (reddish tile) and the ids of the steps that had already executed (amber
 * tiles). Message and semantics are the wrapped step error's — this only adds addressing, so
 * callers that catch plain \RuntimeException keep working unchanged.
 */
class FlowStepFailure extends \RuntimeException
{
    /**
     * @param array<int, string> $executedIds
     */
    public function __construct(
        string $message,
        public readonly ?string $stepId,
        public readonly array $executedIds,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

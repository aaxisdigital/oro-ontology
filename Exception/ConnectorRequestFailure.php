<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * A request through a rest_api connector failed: the connector has no server, no OAuth token could
 * be obtained, the transport failed or the API answered HTTP >= 400. The message is step-agnostic;
 * whoever sent the request prefixes the failing step's name (the flow executor for synchronous
 * HTTP Request steps, the HTTP-request consumer for ASYNC ones).
 *
 * @see \Aaxis\Bundle\OntologyBundle\Manager\RestConnectorClient
 */
class ConnectorRequestFailure extends \RuntimeException
{
    /** Takes the cause as the SECOND argument — see {@see ConnectorTransferFailure::__construct()}. */
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

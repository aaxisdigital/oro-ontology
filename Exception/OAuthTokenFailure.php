<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * No OAuth access token could be obtained for a rest_api connector — no token path configured, the
 * token request failed, or the response carried no access_token. The message is step-agnostic;
 * the flow executor prefixes the failing step's name.
 *
 * @see \Aaxis\Bundle\OntologyBundle\Manager\OAuthTokenProvider
 */
class OAuthTokenFailure extends \RuntimeException
{
    /** Takes the cause as the SECOND argument — see {@see ConnectorTransferFailure::__construct()}. */
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

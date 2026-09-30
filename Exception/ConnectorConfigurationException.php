<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * A file-based connector whose DEFINITION cannot be used — no server / base path / bucket name /
 * credentials configured, or the SSH library missing on the server. Unlike
 * {@see ConnectorTransferFailure} (a failed operation, returned to the flow as an `isError`
 * payload), this one is deliberately NOT caught by the transfer class: it escapes so the caller
 * aborts the step. It extends \RuntimeException, so existing catch blocks keep working.
 *
 * @see \Aaxis\Bundle\OntologyBundle\Manager\FileConnectorTransfer
 */
class ConnectorConfigurationException extends \RuntimeException
{
}

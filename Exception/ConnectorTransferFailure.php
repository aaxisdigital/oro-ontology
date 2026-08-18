<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * An I/O failure of a file-based connector transfer — a path that does not exist, a permission the
 * storage refused, a rejected bucket request.
 *
 * It exists purely to separate the SOFT failures from the hard ones inside
 * {@see \Aaxis\Bundle\OntologyBundle\Manager\FileConnectorTransfer}: this one is caught at the
 * public boundary and turned into the `{isError: true, ...}` payload the flow can branch on, while
 * a plain \RuntimeException (a connector with no server, no credentials, a missing SFTP library —
 * i.e. a broken DEFINITION rather than a failed operation) escapes and aborts the step.
 *
 * The distinction is the reason the transfer class throws internally instead of returning payloads
 * from a dozen places: the failure shape is built once, at one boundary.
 */
class ConnectorTransferFailure extends \RuntimeException
{
    /**
     * Takes the cause as the SECOND argument, unlike \Exception (message, code, previous). Every
     * throw site here has a cause and no error code, and passing one positionally to the inherited
     * constructor lands it in `$code` — a TypeError, at the worst possible moment: inside the error
     * handling of a failed transfer.
     */
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * An Endpoint trigger's Response binding that could not produce a response: the DWL expression
 * failed, or its result is not a `{statusCode, body}` object with a valid HTTP status. The message
 * is user-readable; it extends \RuntimeException so existing catch blocks keep working.
 *
 * @see \Aaxis\Bundle\OntologyBundle\Manager\EndpointFlowRunner::respond()
 */
class EndpointResponseFailure extends \RuntimeException
{
}

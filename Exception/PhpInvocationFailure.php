<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * An "Invoke PHP" flow step that could not call its service method: not configured, a class
 * outside the allowed namespaces, parameters that do not bind (missing, unknown, wrongly typed),
 * or the method itself failing. The message names the step and is user-readable; it extends
 * \RuntimeException so existing catch blocks keep working.
 *
 * @see \Aaxis\Bundle\OntologyBundle\Manager\PhpMethodInvoker
 */
class PhpInvocationFailure extends \RuntimeException
{
}

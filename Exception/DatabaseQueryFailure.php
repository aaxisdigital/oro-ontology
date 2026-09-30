<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * A "SQL Query" flow step that could not run against its DATABASE connector: a wrong connector
 * type, a bad binding, a connection or statement the driver refused. The message is user-readable
 * (the executor prefixes the step name); it extends \RuntimeException so existing catch blocks
 * keep working.
 *
 * @see \Aaxis\Bundle\OntologyBundle\Manager\DatabaseQueryRunner
 */
class DatabaseQueryFailure extends \RuntimeException
{
}

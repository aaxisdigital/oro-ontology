<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * A request to the system-configuration ontology bucket that could not be built or completed: the
 * bucket is not (fully) configured, the endpoint URL is unusable, the transport failed, or the
 * store answered with an error status / an unreadable listing. Messages never carry credentials.
 * It extends \RuntimeException so existing catch blocks keep working.
 *
 * @see \Aaxis\Bundle\OntologyBundle\Manager\OntologyBucketClient
 */
class BucketRequestFailure extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

/** An entity with a STRING identifier, to exercise associations whose id is not an integer. */
class InternalOwner
{
    public function __construct(public ?string $code = null)
    {
    }
}

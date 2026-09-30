<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Tests\Unit\Manager\Fixtures;

class InternalCategory
{
    public function __construct(public ?int $id = null, public ?string $name = null)
    {
    }
}

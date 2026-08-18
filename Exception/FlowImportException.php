<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * Carries EVERY problem found while importing a flow document, not just the first — the user
 * should see the full list (bad format, duplicate name, every unmatched connector/entity) in one
 * go instead of fixing them one round trip at a time.
 */
class FlowImportException extends \RuntimeException
{
    /**
     * @param array<int, string> $errors already-translated, user-facing messages
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

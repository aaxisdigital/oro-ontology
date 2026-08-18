<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Exception;

/**
 * Business error raised by {@see \Aaxis\Bundle\OntologyBundle\Manager\OntologyDataApiManager}.
 *
 * Carries the HTTP status the API controller should return together with a short machine-readable
 * code, so the manager stays free of HTTP concerns while callers (the controller, or any other PHP
 * code reusing the manager) can react precisely.
 */
class OntologyApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function unknownSystem(string $systemName): self
    {
        return new self(sprintf('Unknown system "%s".', $systemName), 404, 'unknown_system');
    }

    public static function unknownEntity(string $systemName, string $entityName): self
    {
        return new self(
            sprintf('Unknown entity "%s" for system "%s".', $entityName, $systemName),
            404,
            'unknown_entity'
        );
    }

    public static function disabledSystem(string $systemName): self
    {
        return new self(sprintf('System "%s" is disabled.', $systemName), 409, 'disabled_system');
    }

    public static function disabledEntity(string $systemName, string $entityName): self
    {
        return new self(
            sprintf('Entity "%s" of system "%s" is disabled.', $entityName, $systemName),
            409,
            'disabled_entity'
        );
    }

    public static function recordNotFound(string $uniqueId): self
    {
        return new self(sprintf('No record found for unique id "%s".', $uniqueId), 404, 'record_not_found');
    }

    public static function internalEntityUnreadable(string $entityName, string $reason): self
    {
        return new self(
            sprintf('Entity "%s" cannot be read from OroCommerce: %s.', $entityName, $reason),
            422,
            'internal_entity_unreadable'
        );
    }

    public static function internalEntityUnwritable(string $entityName, string $reason): self
    {
        return new self(
            sprintf('Entity "%s" cannot be written to OroCommerce: %s.', $entityName, $reason),
            422,
            'internal_entity_unwritable'
        );
    }

    public static function invalidPayload(string $reason): self
    {
        return new self($reason, 400, 'invalid_payload');
    }

    public static function invalidQuery(string $reason): self
    {
        return new self($reason, 400, 'invalid_query');
    }

    public static function flowDisabled(string $flowName): self
    {
        return new self(sprintf('The "%s" flow is disabled.', $flowName), 409, 'flow_disabled');
    }

    public static function flowMisconfigured(string $flowName): self
    {
        return new self(sprintf('The "%s" flow is not configured.', $flowName), 500, 'flow_misconfigured');
    }
}

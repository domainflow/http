<?php

declare(strict_types=1);

namespace DomainFlow\Http\ErrorHandling\Exception;

use RuntimeException;

final class RequestValidationException extends RuntimeException
{
    /** @param array<string, non-empty-list<string>> $violations */
    private function __construct(private readonly array $violations)
    {
        parent::__construct('The request contains invalid input.');
    }

    /** @param array<string, non-empty-list<string>> $violations */
    public static function fromViolations(array $violations): self
    {
        return new self($violations);
    }

    /** @return array<string, non-empty-list<string>> */
    public function violations(): array
    {
        return $this->violations;
    }
}

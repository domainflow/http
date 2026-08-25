<?php

declare(strict_types=1);

namespace DomainFlow\Http\ErrorHandling;

use Throwable;

final readonly class CompositeProblemDetailsMapper implements ProblemDetailsMapper
{
    /** @param list<ProblemDetailsMapper> $mappers */
    public function __construct(private array $mappers)
    {
    }

    public function map(Throwable $exception): ProblemDetails
    {
        foreach ($this->mappers as $mapper) {
            $problem = $mapper->map($exception);
            if ($problem !== null) {
                return $problem;
            }
        }

        return new ProblemDetails(500, 'Internal Server Error');
    }
}

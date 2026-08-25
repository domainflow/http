<?php

declare(strict_types=1);

namespace DomainFlow\Http\ErrorHandling;

use Throwable;

interface ProblemDetailsMapper
{
    public function map(Throwable $exception): ?ProblemDetails;
}

<?php

declare(strict_types=1);

namespace DomainFlow\Http\ErrorHandling;

use DomainFlow\Http\ErrorHandling\Exception\MethodNotAllowedException;
use DomainFlow\Http\ErrorHandling\Exception\RequestValidationException;
use DomainFlow\Http\ErrorHandling\Exception\RouteNotFoundException;
use DomainFlow\Http\ErrorHandling\Exception\UnsupportedMediaTypeException;
use Throwable;

final class DefaultProblemDetailsMapper implements ProblemDetailsMapper
{
    public function map(Throwable $exception): ?ProblemDetails
    {
        return match (true) {
            $exception instanceof RouteNotFoundException => new ProblemDetails(404, 'Not Found'),
            $exception instanceof MethodNotAllowedException => new ProblemDetails(
                405,
                'Method Not Allowed',
                headers: ['Allow' => [implode(', ', $exception->allowedMethods())]],
            ),
            $exception instanceof RequestValidationException => new ProblemDetails(
                400,
                'Validation Failed',
                extensions: ['errors' => $exception->violations()],
            ),
            $exception instanceof UnsupportedMediaTypeException => new ProblemDetails(415, 'Unsupported Media Type'),
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace DomainFlow\Http\Routing\Exception;

use RuntimeException;
use Throwable;

final class RouteDefinitionException extends RuntimeException
{
    public static function forEndpoint(
        string $endpointClass,
        string $reason,
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('Invalid route definition for %s: %s', $endpointClass, $reason),
            previous: $previous,
        );
    }
}

<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint\Exception;

use RuntimeException;
use Throwable;

final class EndpointResolutionException extends RuntimeException
{
    /** @param class-string $endpointClass */
    public static function forEndpoint(string $endpointClass, ?Throwable $previous = null): self
    {
        return new self(sprintf('Unable to resolve invokable endpoint %s.', $endpointClass), previous: $previous);
    }
}

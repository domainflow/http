<?php

declare(strict_types=1);

namespace DomainFlow\Http\ErrorHandling\Exception;

use RuntimeException;
use Throwable;

final class RouteNotFoundException extends RuntimeException
{
    private function __construct(
        string $path,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('No route found for path "%s".', $path), previous: $previous);
    }

    public static function forPath(string $path, ?Throwable $previous = null): self
    {
        return new self($path, $previous);
    }
}

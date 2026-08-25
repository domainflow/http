<?php

declare(strict_types=1);

namespace DomainFlow\Http\Routing\Cache\Exception;

use RuntimeException;
use Throwable;

final class RouteCacheException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('The route cache file is missing.');
    }

    public static function invalid(?Throwable $previous = null): self
    {
        return new self('The route cache file is invalid.', previous: $previous);
    }

    public static function cannotWarm(?Throwable $previous = null): self
    {
        return new self('The route cache could not be warmed.', previous: $previous);
    }
}

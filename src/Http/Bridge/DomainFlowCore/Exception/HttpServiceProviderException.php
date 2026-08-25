<?php

declare(strict_types=1);

namespace DomainFlow\Http\Bridge\DomainFlowCore\Exception;

use RuntimeException;
use Throwable;

final class HttpServiceProviderException extends RuntimeException
{
    public static function forIncompatibleService(): self
    {
        return new self('The application service has an incompatible type.');
    }

    public static function forRouteCompilation(Throwable $previous): self
    {
        return new self('The HTTP routes could not be compiled.', previous: $previous);
    }
}

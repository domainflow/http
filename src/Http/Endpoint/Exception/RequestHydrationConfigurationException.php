<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint\Exception;

use RuntimeException;
use Throwable;

final class RequestHydrationConfigurationException extends RuntimeException
{
    public static function forRequestClass(
        string $requestClass,
        string $reason = 'no hydration plan is available.',
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('Cannot hydrate request %s: %s', $requestClass, $reason),
            previous: $previous,
        );
    }

    public static function forParameter(string $requestClass, string $parameter, string $reason): self
    {
        return self::forRequestClass(
            $requestClass,
            sprintf('parameter "%s" %s', $parameter, $reason),
        );
    }
}

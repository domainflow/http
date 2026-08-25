<?php

declare(strict_types=1);

namespace DomainFlow\Http\Routing\Exception;

use RuntimeException;

final class InvalidRouteMatchException extends RuntimeException
{
    public static function fromMatcherData(): self
    {
        return new self('The route matcher returned invalid runtime metadata.');
    }
}

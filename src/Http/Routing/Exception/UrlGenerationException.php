<?php

declare(strict_types=1);

namespace DomainFlow\Http\Routing\Exception;

use RuntimeException;
use Throwable;

final class UrlGenerationException extends RuntimeException
{
    private function __construct(
        private readonly string $routeName,
        Throwable $previous,
    ) {
        parent::__construct(sprintf('Unable to generate URL for route %s.', $routeName), previous: $previous);
    }

    public static function forRoute(string $routeName, Throwable $previous): self
    {
        return new self($routeName, $previous);
    }

    public function routeName(): string
    {
        return $this->routeName;
    }
}

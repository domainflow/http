<?php

declare(strict_types=1);

namespace DomainFlow\Http\ErrorHandling\Exception;

use RuntimeException;
use Throwable;

final class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param list<string> $allowedMethods
     */
    private function __construct(
        private readonly string $path,
        private readonly array $allowedMethods,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Method not allowed for path "%s".', $path),
            previous: $previous,
        );
    }

    /** @param list<string> $allowedMethods */
    public static function forPath(
        string $path,
        array $allowedMethods,
        ?Throwable $previous = null,
    ): self {
        $normalizedMethods = array_values(array_unique(array_map(strtoupper(...), $allowedMethods)));

        return new self($path, $normalizedMethods, $previous);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return list<string> */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }
}

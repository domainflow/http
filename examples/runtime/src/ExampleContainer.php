<?php

declare(strict_types=1);

namespace DomainFlow\Http\Example;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ExampleContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new ExampleNotFoundException($id);
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}

final class ExampleNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}

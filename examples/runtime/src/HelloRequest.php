<?php

declare(strict_types=1);

namespace DomainFlow\Http\Example;

final readonly class HelloRequest
{
    public function __construct(public string $name)
    {
    }
}

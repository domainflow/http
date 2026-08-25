<?php

declare(strict_types=1);

namespace DomainFlow\Http\Example;

use DomainFlow\Http\Attribute\Route;

#[Route(method: 'GET', path: '/hello/{name}', name: 'hello')]
final class HelloEndpoint
{
    public function __invoke(HelloRequest $request): array
    {
        return ['message' => sprintf('Hello, %s!', $request->name)];
    }
}

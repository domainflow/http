<?php

declare(strict_types=1);

namespace DomainFlow\Http;

use Psr\Http\Message\ServerRequestInterface;

interface Router
{
    public function match(ServerRequestInterface $request): RouteMatch;

    /** @param array<string, mixed> $parameters */
    public function generate(string $routeName, array $parameters = []): string;
}

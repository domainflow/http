<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint;

use Psr\Http\Message\ServerRequestInterface;

interface EndpointContextProvider
{
    public function context(ServerRequestInterface $request): ?object;
}

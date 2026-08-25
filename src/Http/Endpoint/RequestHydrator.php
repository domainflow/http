<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint;

use DomainFlow\Http\RouteMatch;
use Psr\Http\Message\ServerRequestInterface;

interface RequestHydrator
{
    public function hydrate(ServerRequestInterface $request, RouteMatch $match): ?object;
}

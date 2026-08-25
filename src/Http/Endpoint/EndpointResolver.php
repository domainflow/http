<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint;

interface EndpointResolver
{
    /** @param class-string $endpointClass */
    public function resolve(string $endpointClass): object;
}

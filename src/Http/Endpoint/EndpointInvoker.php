<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint;

interface EndpointInvoker
{
    /** @param class-string|null $requestClass */
    public function invoke(object $endpoint, ?object $request, ?string $requestClass): mixed;
}

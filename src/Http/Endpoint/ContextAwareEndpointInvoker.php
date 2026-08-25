<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint;

interface ContextAwareEndpointInvoker extends EndpointInvoker
{
    /** @param class-string|null $requestClass */
    public function invokeWithContext(
        object $endpoint,
        ?object $request,
        ?string $requestClass,
        object $context,
    ): mixed;
}

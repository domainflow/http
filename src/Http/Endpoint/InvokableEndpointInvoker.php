<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint;

use DomainFlow\Http\Endpoint\Exception\EndpointInvocationException;

final class InvokableEndpointInvoker implements EndpointInvoker
{
    /** @param class-string|null $requestClass */
    public function invoke(object $endpoint, ?object $request, ?string $requestClass): mixed
    {
        if (!is_callable($endpoint)) {
            throw EndpointInvocationException::notInvokable($endpoint);
        }

        if ($requestClass === null) {
            if ($request !== null) {
                throw EndpointInvocationException::unexpectedRequest($endpoint, $request);
            }

            return $endpoint();
        }

        if ($request === null) {
            throw EndpointInvocationException::missingRequest($endpoint, $requestClass);
        }

        if (!$request instanceof $requestClass) {
            throw EndpointInvocationException::incompatibleRequest($endpoint, $request, $requestClass);
        }

        return $endpoint($request);
    }
}

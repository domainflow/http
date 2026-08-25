<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint;

use DomainFlow\Http\Endpoint\Exception\EndpointResolutionException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

final readonly class Psr11EndpointResolver implements EndpointResolver
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function resolve(string $endpointClass): object
    {
        try {
            $endpoint = $this->container->get($endpointClass);
        } catch (ContainerExceptionInterface $exception) {
            throw EndpointResolutionException::forEndpoint($endpointClass, $exception);
        }

        if (!is_object($endpoint) || !is_callable($endpoint)) {
            throw EndpointResolutionException::forEndpoint($endpointClass);
        }

        return $endpoint;
    }
}

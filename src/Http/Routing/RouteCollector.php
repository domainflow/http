<?php

declare(strict_types=1);

namespace DomainFlow\Http\Routing;

use DomainFlow\Http\Attribute\Route as RouteAttribute;
use DomainFlow\Http\Routing\Exception\RouteDefinitionException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection as SymfonyRouteCollection;

final class RouteCollector
{
    /**
     * @param list<string> $endpointClasses
     */
    public function collect(array $endpointClasses): SymfonyRouteCollection
    {
        $routes = new SymfonyRouteCollection();

        foreach ($endpointClasses as $configuredEndpointClass) {
            if (!class_exists($configuredEndpointClass)) {
                $exception = new ReflectionException(sprintf(
                    'Class "%s" does not exist.',
                    $configuredEndpointClass,
                ));

                throw RouteDefinitionException::forEndpoint(
                    $configuredEndpointClass,
                    'endpoint class cannot be reflected.',
                    $exception,
                );
            }

            $reflection = new ReflectionClass($configuredEndpointClass);
            $endpointClass = $reflection->getName();
            $attributes = $reflection->getAttributes(RouteAttribute::class);

            if ($attributes === []) {
                throw RouteDefinitionException::forEndpoint($endpointClass, 'route attribute is missing.');
            }

            $definition = $attributes[0]->newInstance();
            $requestClass = $this->requestClass($reflection);
            $routeName = $definition->name ?? $endpointClass;

            if ($routes->get($routeName) !== null) {
                throw RouteDefinitionException::forEndpoint(
                    $endpointClass,
                    sprintf('duplicate route name "%s".', $routeName),
                );
            }

            try {
                $route = new Route(
                    $definition->path,
                    [
                        '_endpoint' => $endpointClass,
                        '_request' => $requestClass,
                    ],
                    $definition->requirements,
                    host: $definition->host,
                    schemes: $definition->schemes,
                    methods: [strtoupper($definition->method)],
                );
            } catch (InvalidArgumentException $exception) {
                throw RouteDefinitionException::forEndpoint(
                    $endpointClass,
                    'Symfony rejected the route metadata.',
                    $exception,
                );
            }

            $routes->add($routeName, $route);
        }

        return $routes;
    }

    /**
     * @param ReflectionClass<object> $endpoint
     *
     * @return class-string|null
     */
    private function requestClass(ReflectionClass $endpoint): ?string
    {
        if (!$endpoint->hasMethod('__invoke')) {
            throw RouteDefinitionException::forEndpoint($endpoint->getName(), 'endpoint is not invokable.');
        }

        $invoke = $endpoint->getMethod('__invoke');
        $parameters = $invoke->getParameters();
        $type = isset($parameters[0]) ? $parameters[0]->getType() : null;

        if (
            !$invoke->isPublic()
            || (
                $parameters !== []
                && (
                    count($parameters) !== 1
                    || !$type instanceof ReflectionNamedType
                    || $type->isBuiltin()
                )
            )
        ) {
            throw $this->invalidSignature($endpoint, $invoke);
        }

        if ($parameters === []) {
            return null;
        }

        $requestClass = $type->getName();

        if (!class_exists($requestClass)) {
            throw RouteDefinitionException::forEndpoint(
                $endpoint->getName(),
                sprintf('request DTO class %s does not exist.', $requestClass),
            );
        }

        $requestReflection = new ReflectionClass($requestClass);

        if (!$requestReflection->isInstantiable()) {
            throw RouteDefinitionException::forEndpoint(
                $endpoint->getName(),
                sprintf('request DTO class %s is not instantiable.', $requestClass),
            );
        }

        /** @var class-string */
        return $requestClass;
    }

    /** @param ReflectionClass<object> $endpoint */
    private function invalidSignature(ReflectionClass $endpoint, ReflectionMethod $invoke): RouteDefinitionException
    {
        return RouteDefinitionException::forEndpoint(
            $endpoint->getName(),
            sprintf('%s() must declare zero arguments or one request DTO.', $invoke->getName()),
        );
    }
}

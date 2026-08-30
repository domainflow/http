<?php

declare(strict_types=1);

namespace DomainFlow\Http\Routing\Adapter;

use DomainFlow\Http\ErrorHandling\Exception\MethodNotAllowedException;
use DomainFlow\Http\ErrorHandling\Exception\RouteNotFoundException;
use DomainFlow\Http\RouteMatch;
use DomainFlow\Http\Router;
use DomainFlow\Http\Routing\Exception\InvalidRouteMatchException;
use DomainFlow\Http\Routing\Exception\UrlGenerationException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as SymfonyRoutingException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException as SymfonyMethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;

final readonly class SymfonyRouter implements Router
{
    public function __construct(
        private UrlMatcherInterface $matcher,
        private UrlGeneratorInterface $generator,
        private RequestContext $context,
    ) {
    }

    public function match(ServerRequestInterface $request): RouteMatch
    {
        $this->updateContext($request);
        $path = $request->getUri()->getPath();

        try {
            $parameters = $this->matcher->match($path);
        } catch (SymfonyMethodNotAllowedException $exception) {
            throw MethodNotAllowedException::forPath(
                $path,
                array_values($exception->getAllowedMethods()),
                $exception,
            );
        } catch (ResourceNotFoundException $exception) {
            throw RouteNotFoundException::forPath($path, $exception);
        }

        return $this->routeMatch($parameters);
    }

    public function generate(string $routeName, array $parameters = []): string
    {
        try {
            $url = $this->generator->generate($routeName, $parameters);
        } catch (SymfonyRoutingException $exception) {
            throw UrlGenerationException::forRoute($routeName, $exception);
        }

        return preg_replace('#^(?:[a-z][a-z0-9+.-]*:)?//[^/]*#i', '', $url) ?? $url;
    }

    /** @param array<string|int, mixed> $parameters */
    private function routeMatch(array $parameters): RouteMatch
    {
        $routeName = $parameters['_route'] ?? null;
        $endpointClass = $parameters['_endpoint'] ?? null;
        $requestClass = $parameters['_request'] ?? null;

        if (
            !is_string($routeName)
            || $routeName === ''
            || !is_string($endpointClass)
            || !class_exists($endpointClass)
            || ($requestClass !== null && (!is_string($requestClass) || !class_exists($requestClass)))
        ) {
            throw InvalidRouteMatchException::fromMatcherData();
        }

        $routeParameters = [];
        foreach ($parameters as $name => $value) {
            if (is_string($name) && str_starts_with($name, '_')) {
                continue;
            }

            if (!is_string($name) || !is_string($value)) {
                throw InvalidRouteMatchException::fromMatcherData();
            }

            $routeParameters[$name] = $value;
        }

        return new RouteMatch($routeName, $endpointClass, $requestClass, $routeParameters);
    }

    private function updateContext(ServerRequestInterface $request): void
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme() === '' ? 'http' : $uri->getScheme();
        $port = $uri->getPort();

        $this->context
            ->setMethod($request->getMethod())
            ->setHost($uri->getHost())
            ->setScheme($scheme)
            ->setHttpPort($scheme === 'http' ? ($port ?? 80) : 80)
            ->setHttpsPort($scheme === 'https' ? ($port ?? 443) : 443)
            ->setPathInfo($uri->getPath())
            ->setQueryString($uri->getQuery());
    }
}

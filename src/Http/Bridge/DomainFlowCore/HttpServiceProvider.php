<?php

declare(strict_types=1);

namespace DomainFlow\Http\Bridge\DomainFlowCore;

use DomainFlow\Application;
use DomainFlow\Http\Bridge\DomainFlowCore\Exception\HttpServiceProviderException;
use DomainFlow\Http\Endpoint\EndpointContextProvider;
use DomainFlow\Http\Endpoint\EndpointInvoker;
use DomainFlow\Http\Endpoint\EndpointResolver;
use DomainFlow\Http\Endpoint\InvokableEndpointInvoker;
use DomainFlow\Http\Endpoint\Psr11EndpointResolver;
use DomainFlow\Http\Endpoint\ReflectionRequestHydrator;
use DomainFlow\Http\Endpoint\RequestHydrator;
use DomainFlow\Http\ErrorHandling\CompositeProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\DefaultProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\ProblemDetailsMapper;
use DomainFlow\Http\Kernel;
use DomainFlow\Http\Response\JsonResponseFactory;
use DomainFlow\Http\Router;
use DomainFlow\Http\Routing\Adapter\SymfonyRouter;
use DomainFlow\Http\Routing\RouteCollector;
use DomainFlow\Service\ServiceProviderInterface;
use Exception;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Routing\Generator\CompiledUrlGenerator;
use Symfony\Component\Routing\Generator\Dumper\CompiledUrlGeneratorDumper;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

final readonly class HttpServiceProvider implements ServiceProviderInterface
{
    /** @param list<class-string> $endpointClasses */
    public function __construct(
        private array $endpointClasses,
        private ?EndpointContextProvider $endpointContextProvider = null,
    ) {
    }

    public function register(Application $app): void
    {
        $routes = (new RouteCollector())->collect($this->endpointClasses);
        if (!$app->has(Router::class)) {
            $app->instance(Router::class, $this->defaultRouter($routes));
        }

        $this->registerDefault(
            $app,
            EndpointResolver::class,
            new Psr11EndpointResolver($app),
        );
        $this->registerDefault(
            $app,
            RequestHydrator::class,
            new ReflectionRequestHydrator($this->requestClasses($routes)),
        );
        $this->registerDefault($app, EndpointInvoker::class, new InvokableEndpointInvoker());
        $this->registerDefault(
            $app,
            ProblemDetailsMapper::class,
            new CompositeProblemDetailsMapper([new DefaultProblemDetailsMapper()]),
        );

        $responseFactory = new JsonResponseFactory(
            $this->service($app, ResponseFactoryInterface::class),
            $this->service($app, StreamFactoryInterface::class),
        );
        $app->instance(JsonResponseFactory::class, $responseFactory);
        $app->instance(Kernel::class, new Kernel(
            $this->service($app, Router::class),
            $this->service($app, EndpointResolver::class),
            $this->service($app, RequestHydrator::class),
            $this->service($app, EndpointInvoker::class),
            $this->service($app, ProblemDetailsMapper::class),
            $responseFactory,
            endpointContextProvider: $this->endpointContextProvider,
        ));
    }

    public function boot(Application $app): void
    {
    }

    public function provides(): array
    {
        return [
            Kernel::class,
            Router::class,
            EndpointResolver::class,
            RequestHydrator::class,
            EndpointInvoker::class,
            ProblemDetailsMapper::class,
            JsonResponseFactory::class,
        ];
    }

    public function isDeferred(): bool
    {
        return false;
    }

    private function registerDefault(Application $app, string $serviceId, object $service): void
    {
        if (!$app->has($serviceId)) {
            $app->instance($serviceId, $service);
        }
    }

    private function defaultRouter(RouteCollection $routes): Router
    {
        try {
            $matcherData = (new CompiledUrlMatcherDumper($routes))->getCompiledRoutes();
            $generatorDumper = new CompiledUrlGeneratorDumper($routes);
            $generatorData = [
                ...$generatorDumper->getCompiledRoutes(),
                ...$generatorDumper->getCompiledAliases(),
            ];
        } catch (Exception $exception) {
            throw HttpServiceProviderException::forRouteCompilation($exception);
        }

        $context = new RequestContext();

        return new SymfonyRouter(
            new CompiledUrlMatcher($matcherData, $context),
            new CompiledUrlGenerator($generatorData, $context),
            $context,
        );
    }

    /** @return list<class-string> */
    private function requestClasses(RouteCollection $routes): array
    {
        $requestClasses = [];

        foreach ($routes as $route) {
            $requestClass = $route->getDefault('_request');
            if (is_string($requestClass) && class_exists($requestClass)) {
                $requestClasses[$requestClass] = $requestClass;
            }
        }

        return array_values($requestClasses);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $serviceId
     *
     * @return T
     */
    private function service(Application $app, string $serviceId): object
    {
        $service = $app->get($serviceId);
        if (!$service instanceof $serviceId) {
            throw HttpServiceProviderException::forIncompatibleService();
        }

        return $service;
    }
}

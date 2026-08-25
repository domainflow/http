<?php

declare(strict_types=1);

use DomainFlow\Http\Endpoint\InvokableEndpointInvoker;
use DomainFlow\Http\Endpoint\Psr11EndpointResolver;
use DomainFlow\Http\Endpoint\ReflectionRequestHydrator;
use DomainFlow\Http\ErrorHandling\CompositeProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\DefaultProblemDetailsMapper;
use DomainFlow\Http\Example\ExampleContainer;
use DomainFlow\Http\Example\HelloEndpoint;
use DomainFlow\Http\Example\HelloRequest;
use DomainFlow\Http\Kernel;
use DomainFlow\Http\Response\JsonResponseFactory;
use DomainFlow\Http\Routing\Adapter\SymfonyRouter;
use DomainFlow\Http\Routing\RouteCollector;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;

require dirname(__DIR__) . '/vendor/autoload.php';

$routes = (new RouteCollector())->collect([HelloEndpoint::class]);
$context = new RequestContext();
$router = new SymfonyRouter(
    new UrlMatcher($routes, $context),
    new UrlGenerator($routes, $context),
    $context,
);
$psr17 = new Psr17Factory();
$kernel = new Kernel(
    $router,
    new Psr11EndpointResolver(new ExampleContainer([
        HelloEndpoint::class => new HelloEndpoint(),
    ])),
    new ReflectionRequestHydrator([HelloRequest::class]),
    new InvokableEndpointInvoker(),
    new CompositeProblemDetailsMapper([new DefaultProblemDetailsMapper()]),
    new JsonResponseFactory($psr17, $psr17),
);
$requestCreator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$response = $kernel->handle($requestCreator->fromGlobals());

(new SapiEmitter())->emit($response);

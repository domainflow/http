<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Integration;

use DomainFlow\Container;
use DomainFlow\Http\Endpoint\InvokableEndpointInvoker;
use DomainFlow\Http\Endpoint\Psr11EndpointResolver;
use DomainFlow\Http\Endpoint\ReflectionRequestHydrator;
use DomainFlow\Http\ErrorHandling\CompositeProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\DefaultProblemDetailsMapper;
use DomainFlow\Http\Kernel;
use DomainFlow\Http\Response\JsonResponseFactory;
use DomainFlow\Http\Routing\Adapter\SymfonyRouter;
use DomainFlow\Http\Routing\RouteCollector;
use DomainFlow\Http\Tests\Fixture\CreateOrderEndpoint;
use DomainFlow\Http\Tests\Fixture\CreateOrderRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;

#[CoversNothing]
final class KernelIntegrationTest extends TestCase
{
    public function testHandlesARealJsonRequestEndToEnd(): void
    {
        $kernel = $this->kernel();
        $request = new ServerRequest(
            'POST',
            '/orders',
            ['Content-Type' => 'application/json'],
            json_encode([
                'customerId' => 'customer-1',
                'items' => [['sku' => 'sku-1', 'quantity' => 2]],
                'shippingAddress' => ['city' => 'Berlin', 'country' => 'DE'],
            ], JSON_THROW_ON_ERROR),
        );

        $response = $kernel->handle($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('/orders/created-customer-1', $response->getHeaderLine('Location'));
        $this->assertSame(
            ['orderId' => 'created-customer-1'],
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testReturnsProblemDetailsForRoutingAndValidationFailures(): void
    {
        $kernel = $this->kernel();

        $missing = $kernel->handle(new ServerRequest('GET', '/missing'));
        $wrongMethod = $kernel->handle(new ServerRequest('GET', '/orders'));
        $invalid = $kernel->handle(new ServerRequest(
            'POST',
            '/orders',
            ['Content-Type' => 'application/json'],
            '{}',
        ));

        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame('application/problem+json', $missing->getHeaderLine('Content-Type'));
        $this->assertSame(405, $wrongMethod->getStatusCode());
        $this->assertSame('POST', $wrongMethod->getHeaderLine('Allow'));
        $this->assertSame(400, $invalid->getStatusCode());
    }

    private function kernel(): Kernel
    {
        $routes = (new RouteCollector())->collect([CreateOrderEndpoint::class]);
        $context = new RequestContext();
        $router = new SymfonyRouter(
            new UrlMatcher($routes, $context),
            new UrlGenerator($routes, $context),
            $context,
        );
        $container = new Container();
        $container->instance(CreateOrderEndpoint::class, new CreateOrderEndpoint());
        $psr17 = new Psr17Factory();

        return new Kernel(
            $router,
            new Psr11EndpointResolver($container),
            new ReflectionRequestHydrator([CreateOrderRequest::class]),
            new InvokableEndpointInvoker(),
            new CompositeProblemDetailsMapper([new DefaultProblemDetailsMapper()]),
            new JsonResponseFactory($psr17, $psr17),
        );
    }
}

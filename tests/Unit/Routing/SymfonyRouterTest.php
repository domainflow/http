<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit\Routing;

use DomainFlow\Http\ErrorHandling\Exception\MethodNotAllowedException;
use DomainFlow\Http\ErrorHandling\Exception\RouteNotFoundException;
use DomainFlow\Http\RouteMatch;
use DomainFlow\Http\Routing\Adapter\SymfonyRouter;
use DomainFlow\Http\Routing\Exception\InvalidRouteMatchException;
use DomainFlow\Http\Routing\Exception\UrlGenerationException;
use DomainFlow\Http\Tests\Fixture\CreateOrderEndpoint;
use DomainFlow\Http\Tests\Fixture\CreateOrderRequest;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException as SymfonyRouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(SymfonyRouter::class)]
#[CoversClass(RouteMatch::class)]
#[CoversClass(RouteNotFoundException::class)]
#[CoversClass(MethodNotAllowedException::class)]
#[CoversClass(InvalidRouteMatchException::class)]
#[CoversClass(UrlGenerationException::class)]
final class SymfonyRouterTest extends TestCase
{
    private RequestContext $context;
    private SymfonyRouter $router;

    protected function setUp(): void
    {
        $routes = new RouteCollection();
        $routes->add('orders.show', new Route(
            '/orders/{orderId}',
            [
                '_endpoint' => CreateOrderEndpoint::class,
                '_request' => CreateOrderRequest::class,
            ],
            ['orderId' => '[0-9a-f-]{36}'],
            host: 'api.example.test',
            schemes: ['https'],
            methods: ['GET'],
        ));

        $this->context = new RequestContext();
        $this->router = new SymfonyRouter(
            new UrlMatcher($routes, $this->context),
            new UrlGenerator($routes, $this->context),
            $this->context,
        );
    }

    public function testMatchesARequestAndKeepsOnlyApplicationPathParameters(): void
    {
        $id = '018f4f7c-41f0-7c1a-9e66-31d1786b9471';
        $request = new ServerRequest('GET', 'https://api.example.test:8443/orders/' . $id . '?expand=items');

        $match = $this->router->match($request);

        $this->assertSame('orders.show', $match->routeName);
        $this->assertSame(CreateOrderEndpoint::class, $match->endpointClass);
        $this->assertSame(CreateOrderRequest::class, $match->requestClass);
        $this->assertSame(['orderId' => $id], $match->pathParameters);
    }

    public function testUpdatesTheCompleteSharedContextFromEveryRequest(): void
    {
        $id = '018f4f7c-41f0-7c1a-9e66-31d1786b9471';

        $this->router->match(new ServerRequest(
            'get',
            'https://api.example.test:8443/orders/' . $id . '?expand=items',
        ));

        $this->assertSame('GET', $this->context->getMethod());
        $this->assertSame('api.example.test', $this->context->getHost());
        $this->assertSame('https', $this->context->getScheme());
        $this->assertSame(8443, $this->context->getHttpsPort());
        $this->assertSame('/orders/' . $id, $this->context->getPathInfo());
        $this->assertSame('expand=items', $this->context->getQueryString());
    }

    public function testTranslatesResourceNotFoundWithoutLeakingSymfonyException(): void
    {
        $this->expectException(RouteNotFoundException::class);

        $this->router->match(new ServerRequest('GET', 'https://api.example.test/missing'));
    }

    public function testTranslatesMethodNotAllowedAndPreservesAllowedMethods(): void
    {
        $id = '018f4f7c-41f0-7c1a-9e66-31d1786b9471';

        try {
            $this->router->match(new ServerRequest('POST', 'https://api.example.test/orders/' . $id));
            $this->fail('Expected a method-not-allowed exception.');
        } catch (MethodNotAllowedException $exception) {
            $this->assertSame(['GET'], $exception->allowedMethods());
            $this->assertSame('/orders/' . $id, $exception->path());
        }
    }

    public function testGeneratesAUrlWithParameters(): void
    {
        $id = '018f4f7c-41f0-7c1a-9e66-31d1786b9471';

        $this->assertSame('/orders/' . $id, $this->router->generate('orders.show', ['orderId' => $id]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidMatcherResults(): iterable
    {
        yield 'missing route name' => [[
            '_endpoint' => CreateOrderEndpoint::class,
        ]];
        yield 'non-string route name' => [[
            '_route' => 42,
            '_endpoint' => CreateOrderEndpoint::class,
        ]];
        yield 'missing endpoint class' => [[
            '_route' => 'orders.show',
        ]];
        yield 'unknown endpoint class' => [[
            '_route' => 'orders.show',
            '_endpoint' => 'Missing\\Endpoint',
        ]];
        yield 'unknown request class' => [[
            '_route' => 'orders.show',
            '_endpoint' => CreateOrderEndpoint::class,
            '_request' => 'Missing\\Request',
        ]];
        yield 'non-string path parameter' => [[
            '_route' => 'orders.show',
            '_endpoint' => CreateOrderEndpoint::class,
            '_request' => CreateOrderRequest::class,
            'orderId' => 42,
        ]];
    }

    /** @param array<string, mixed> $matcherResult */
    #[DataProvider('invalidMatcherResults')]
    public function testRejectsInvalidMatcherMetadataWithAPackageException(array $matcherResult): void
    {
        $matcher = $this->createStub(UrlMatcherInterface::class);
        $matcher->method('match')->willReturn($matcherResult);

        $router = new SymfonyRouter(
            $matcher,
            $this->createStub(UrlGeneratorInterface::class),
            new RequestContext(),
        );

        $this->expectException(InvalidRouteMatchException::class);

        $router->match(new ServerRequest('GET', '/orders/42'));
    }

    public function testTranslatesUrlGeneratorFailuresAndPreservesTheCause(): void
    {
        $cause = new SymfonyRouteNotFoundException('missing route');
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willThrowException($cause);
        $router = new SymfonyRouter(
            $this->createStub(UrlMatcherInterface::class),
            $generator,
            new RequestContext(),
        );

        try {
            $router->generate('missing.route');
            $this->fail('Expected URL generation to fail.');
        } catch (UrlGenerationException $exception) {
            $this->assertSame('missing.route', $exception->routeName());
            $this->assertSame($cause, $exception->getPrevious());
        }
    }
}

<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit;

use DomainFlow\Http\Endpoint\EndpointInvoker;
use DomainFlow\Http\Endpoint\EndpointResolver;
use DomainFlow\Http\Endpoint\RequestHydrator;
use DomainFlow\Http\ErrorHandling\CompositeProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\DefaultProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\ProblemDetails;
use DomainFlow\Http\ErrorHandling\ProblemDetailsMapper;
use DomainFlow\Http\Internal\HttpMetadataValidator;
use DomainFlow\Http\Kernel;
use DomainFlow\Http\Response\HttpResult;
use DomainFlow\Http\Response\JsonResponseFactory;
use DomainFlow\Http\RouteMatch;
use DomainFlow\Http\Router;
use DomainFlow\Http\Tests\Fixture\CreateOrderEndpoint;
use DomainFlow\Http\Tests\Fixture\CreateOrderRequest;
use DomainFlow\Http\Tests\Fixture\CreateOrderResponse;
use DomainFlow\Http\Tests\Fixture\DeliverySpeed;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(Kernel::class)]
#[UsesClass(CompositeProblemDetailsMapper::class)]
#[UsesClass(DefaultProblemDetailsMapper::class)]
#[UsesClass(HttpMetadataValidator::class)]
#[UsesClass(HttpResult::class)]
#[UsesClass(JsonResponseFactory::class)]
#[UsesClass(ProblemDetails::class)]
#[UsesClass(RouteMatch::class)]
final class KernelTest extends TestCase
{
    public function testDispatchesThroughEveryPortAndBuildsTheEndpointResult(): void
    {
        $match = $this->match();
        $dto = $this->requestDto();
        $endpoint = new CreateOrderEndpoint();
        $result = new HttpResult(new CreateOrderResponse('order-1'), 201);

        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('match')->willReturn($match);
        $hydrator = $this->createMock(RequestHydrator::class);
        $hydrator->expects($this->once())->method('hydrate')->with($this->isInstanceOf(ServerRequestInterface::class), $match)->willReturn($dto);
        $resolver = $this->createMock(EndpointResolver::class);
        $resolver->expects($this->once())->method('resolve')->with(CreateOrderEndpoint::class)->willReturn($endpoint);
        $invoker = $this->createMock(EndpointInvoker::class);
        $invoker->expects($this->once())
            ->method('invoke')
            ->with($endpoint, $dto, CreateOrderRequest::class)
            ->willReturn($result);

        $response = $this->kernel($router, $resolver, $hydrator, $invoker)->handle(
            new ServerRequest('POST', '/orders'),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(['orderId' => 'order-1'], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testMiddlewareUsesOnionOrderAroundDispatch(): void
    {
        $events = [];
        $middleware = [
            new RecordingMiddleware('first', $events),
            new RecordingMiddleware('second', $events),
        ];

        $kernel = $this->successfulKernel($middleware, $events);
        $response = $kernel->handle(new ServerRequest('GET', '/health'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'first.before',
            'second.before',
            'dispatch',
            'second.after',
            'first.after',
        ], $events);
    }

    public function testExceptionsFromMiddlewareAreInsideTheProblemDetailsBoundary(): void
    {
        $router = $this->createStub(Router::class);
        $resolver = $this->createStub(EndpointResolver::class);
        $hydrator = $this->createStub(RequestHydrator::class);
        $invoker = $this->createStub(EndpointInvoker::class);

        $response = $this->kernel(
            $router,
            $resolver,
            $hydrator,
            $invoker,
            [new ThrowingMiddleware()],
        )->handle(new ServerRequest('GET', '/'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('secret middleware detail', (string) $response->getBody());
    }

    public function testUsesTheGenericProblemWhenNoMapperRecognizesAnException(): void
    {
        $mapper = $this->createStub(ProblemDetailsMapper::class);
        $mapper->method('map')->willReturn(null);

        $response = $this->kernel(
            $this->createStub(Router::class),
            $this->createStub(EndpointResolver::class),
            $this->createStub(RequestHydrator::class),
            $this->createStub(EndpointInvoker::class),
            [new ThrowingMiddleware()],
            $mapper,
        )->handle(new ServerRequest('GET', '/'));

        $this->assertSame(500, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame('Internal Server Error', $payload['title']);
    }

    public function testKernelCanBeSafelyReusedAcrossRequests(): void
    {
        $events = [];
        $kernel = $this->successfulKernel([], $events);

        $first = $kernel->handle(new ServerRequest('GET', '/one'));
        $second = $kernel->handle(new ServerRequest('GET', '/two'));

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(['dispatch', 'dispatch'], $events);
    }

    /**
     * @param list<MiddlewareInterface> $middleware
     * @param list<string> $events
     */
    private function successfulKernel(array $middleware, array &$events): Kernel
    {
        $router = $this->createStub(Router::class);
        $router->method('match')->willReturn($this->match());
        $hydrator = $this->createStub(RequestHydrator::class);
        $hydrator->method('hydrate')->willReturn($this->requestDto());
        $resolver = $this->createStub(EndpointResolver::class);
        $resolver->method('resolve')->willReturn(new CreateOrderEndpoint());
        $invoker = $this->createStub(EndpointInvoker::class);
        $invoker->method('invoke')->willReturnCallback(
            static function () use (&$events): CreateOrderResponse {
                $events[] = 'dispatch';

                return new CreateOrderResponse('order-1');
            },
        );

        return $this->kernel($router, $resolver, $hydrator, $invoker, $middleware);
    }

    /** @param list<MiddlewareInterface> $middleware */
    private function kernel(
        Router $router,
        EndpointResolver $resolver,
        RequestHydrator $hydrator,
        EndpointInvoker $invoker,
        array $middleware = [],
        ?ProblemDetailsMapper $problemDetailsMapper = null,
    ): Kernel {
        $psr17 = new Psr17Factory();

        return new Kernel(
            $router,
            $resolver,
            $hydrator,
            $invoker,
            $problemDetailsMapper ?? new CompositeProblemDetailsMapper([new DefaultProblemDetailsMapper()]),
            new JsonResponseFactory($psr17, $psr17),
            $middleware,
        );
    }

    private function match(): RouteMatch
    {
        return new RouteMatch(
            'orders.create',
            CreateOrderEndpoint::class,
            CreateOrderRequest::class,
            [],
        );
    }

    private function requestDto(): CreateOrderRequest
    {
        return new CreateOrderRequest(
            'customer-1',
            [],
            new \DomainFlow\Http\Tests\Fixture\AddressRequest('Berlin', 'DE'),
            DeliverySpeed::Standard,
        );
    }
}

final class RecordingMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $events;

    /** @param list<string> $events */
    public function __construct(private readonly string $name, array &$events)
    {
        $this->events = &$events;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        array_push($this->events, $this->name . '.before');
        $response = $handler->handle($request);
        array_push($this->events, $this->name . '.after');

        return $response;
    }
}

final class ThrowingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw new RuntimeException('secret middleware detail');
    }
}

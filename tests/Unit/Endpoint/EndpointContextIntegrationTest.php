<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit\Endpoint;

use DomainFlow\Http\Endpoint\ContextAwareEndpointInvoker;
use DomainFlow\Http\Endpoint\EndpointContextProvider;
use DomainFlow\Http\Endpoint\EndpointInvoker;
use DomainFlow\Http\Endpoint\EndpointResolver;
use DomainFlow\Http\Endpoint\Exception\EndpointInvocationException;
use DomainFlow\Http\Endpoint\RequestHydrator;
use DomainFlow\Http\ErrorHandling\CompositeProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\DefaultProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\ProblemDetails;
use DomainFlow\Http\Internal\HttpMetadataValidator;
use DomainFlow\Http\Kernel;
use DomainFlow\Http\Response\HttpResult;
use DomainFlow\Http\Response\JsonResponseFactory;
use DomainFlow\Http\RouteMatch;
use DomainFlow\Http\Router;
use DomainFlow\Http\Tests\Fixture\AddressRequest;
use DomainFlow\Http\Tests\Fixture\CreateOrderEndpoint;
use DomainFlow\Http\Tests\Fixture\CreateOrderRequest;
use DomainFlow\Http\Tests\Fixture\CreateOrderResponse;
use DomainFlow\Http\Tests\Fixture\DeliverySpeed;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

#[CoversClass(Kernel::class)]
#[CoversClass(EndpointInvocationException::class)]
#[UsesClass(CompositeProblemDetailsMapper::class)]
#[UsesClass(DefaultProblemDetailsMapper::class)]
#[UsesClass(HttpMetadataValidator::class)]
#[UsesClass(HttpResult::class)]
#[UsesClass(JsonResponseFactory::class)]
#[UsesClass(ProblemDetails::class)]
#[UsesClass(RouteMatch::class)]
final class EndpointContextIntegrationTest extends TestCase
{
    public function testKernelPassesProvidedContextToAContextAwareInvoker(): void
    {
        $context = new stdClass();
        $invoker = new RecordingContextAwareInvoker();
        $kernel = $this->kernel($invoker, new StubContextProvider($context));

        $response = $kernel->handle(new ServerRequest('GET', '/orders'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($context, $invoker->context);
    }

    public function testKernelRejectsAContextWhenInvokerCannotConsumeIt(): void
    {
        $kernel = $this->kernel(
            $this->createStub(EndpointInvoker::class),
            new StubContextProvider(new stdClass()),
        );

        $response = $kernel->handle(new ServerRequest('GET', '/orders'));

        $this->assertSame(500, $response->getStatusCode());
    }

    private function kernel(EndpointInvoker $invoker, EndpointContextProvider $provider): Kernel
    {
        $match = new RouteMatch('orders', CreateOrderEndpoint::class, CreateOrderRequest::class, []);
        $router = $this->createStub(Router::class);
        $router->method('match')->willReturn($match);
        $hydrator = $this->createStub(RequestHydrator::class);
        $hydrator->method('hydrate')->willReturn(new CreateOrderRequest(
            'customer-1',
            [],
            new AddressRequest('Berlin', 'DE'),
            DeliverySpeed::Standard,
        ));
        $resolver = $this->createStub(EndpointResolver::class);
        $resolver->method('resolve')->willReturn(new CreateOrderEndpoint());
        $factory = new Psr17Factory();

        return new Kernel(
            $router,
            $resolver,
            $hydrator,
            $invoker,
            new CompositeProblemDetailsMapper([new DefaultProblemDetailsMapper()]),
            new JsonResponseFactory($factory, $factory),
            endpointContextProvider: $provider,
        );
    }
}

final class StubContextProvider implements EndpointContextProvider
{
    public function __construct(private readonly object $value)
    {
    }

    public function context(ServerRequestInterface $request): object
    {
        return $this->value;
    }
}

final class RecordingContextAwareInvoker implements ContextAwareEndpointInvoker
{
    public ?object $context = null;

    public function invoke(object $endpoint, ?object $request, ?string $requestClass): mixed
    {
        return new CreateOrderResponse('regular');
    }

    public function invokeWithContext(
        object $endpoint,
        ?object $request,
        ?string $requestClass,
        object $context,
    ): mixed {
        $this->context = $context;

        return new CreateOrderResponse('context-aware');
    }
}

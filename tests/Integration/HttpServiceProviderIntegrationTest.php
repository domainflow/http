<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Http\Attribute\Route;
use DomainFlow\Http\Bridge\DomainFlowCore\Exception\HttpServiceProviderException;
use DomainFlow\Http\Bridge\DomainFlowCore\HttpServiceProvider;
use DomainFlow\Http\Endpoint\InvokableEndpointInvoker;
use DomainFlow\Http\Endpoint\Psr11EndpointResolver;
use DomainFlow\Http\Endpoint\ReflectionRequestHydrator;
use DomainFlow\Http\ErrorHandling\CompositeProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\DefaultProblemDetailsMapper;
use DomainFlow\Http\Kernel;
use DomainFlow\Http\Response\JsonResponseFactory;
use DomainFlow\Http\Router;
use DomainFlow\Http\Routing\Adapter\SymfonyRouter;
use DomainFlow\Http\Routing\RouteCollector;
use DomainFlow\Http\Tests\Fixture\CreateOrderEndpoint;
use DomainFlow\Http\Tests\Fixture\InvalidRouteCompilationEndpoint;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use stdClass;

#[CoversClass(HttpServiceProvider::class)]
#[CoversClass(HttpServiceProviderException::class)]
#[UsesClass(InvokableEndpointInvoker::class)]
#[UsesClass(Psr11EndpointResolver::class)]
#[UsesClass(ReflectionRequestHydrator::class)]
#[UsesClass(CompositeProblemDetailsMapper::class)]
#[UsesClass(DefaultProblemDetailsMapper::class)]
#[UsesClass(Kernel::class)]
#[UsesClass(JsonResponseFactory::class)]
#[UsesClass(SymfonyRouter::class)]
#[UsesClass(RouteCollector::class)]
#[UsesClass(Route::class)]
final class HttpServiceProviderIntegrationTest extends TestCase
{
    public function testRegistersAndBootsTheKernelInADomainFlowApplication(): void
    {
        $application = $this->applicationWithPsr17Factories();
        $application->instance(CreateOrderEndpoint::class, new CreateOrderEndpoint());
        $provider = new HttpServiceProvider([CreateOrderEndpoint::class]);

        $application->registerProvider($provider);
        $application->boot();

        $this->assertInstanceOf(Kernel::class, $application->get(Kernel::class));
        $this->assertContains(Kernel::class, $provider->provides());
        $this->assertFalse($provider->isDeferred());
    }

    public function testDoesNotOverwriteAnApplicationProvidedRouterPort(): void
    {
        $application = $this->applicationWithPsr17Factories();
        $customRouter = $this->createStub(Router::class);
        $application->instance(Router::class, $customRouter);

        $application->registerProvider(new HttpServiceProvider([CreateOrderEndpoint::class]));

        $this->assertSame($customRouter, $application->get(Router::class));
    }

    public function testTranslatesRouteCompilationFailuresDuringRegistration(): void
    {
        $application = $this->applicationWithPsr17Factories();

        try {
            (new HttpServiceProvider([InvalidRouteCompilationEndpoint::class]))->register($application);
            $this->fail('Expected route compilation to fail.');
        } catch (HttpServiceProviderException $exception) {
            $this->assertSame('The HTTP routes could not be compiled.', $exception->getMessage());
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function testRejectsAnApplicationServiceWithAnIncompatibleType(): void
    {
        $application = $this->applicationWithPsr17Factories();
        $application->instance(ResponseFactoryInterface::class, new stdClass());

        $this->expectException(HttpServiceProviderException::class);

        (new HttpServiceProvider([CreateOrderEndpoint::class]))->register($application);
    }

    private function applicationWithPsr17Factories(): Application
    {
        $application = new Application();
        $psr17 = new Psr17Factory();
        $application->instance(ResponseFactoryInterface::class, $psr17);
        $application->instance(StreamFactoryInterface::class, $psr17);

        return $application;
    }
}

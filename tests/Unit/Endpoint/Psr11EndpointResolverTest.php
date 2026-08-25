<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit\Endpoint;

use DomainFlow\Http\Endpoint\Exception\EndpointResolutionException;
use DomainFlow\Http\Endpoint\Psr11EndpointResolver;
use DomainFlow\Http\Tests\Fixture\CreateOrderEndpoint;
use DomainFlow\Http\Tests\Fixture\NotInvokableEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

#[CoversClass(Psr11EndpointResolver::class)]
#[CoversClass(EndpointResolutionException::class)]
final class Psr11EndpointResolverTest extends TestCase
{
    public function testResolvesAnInvokableEndpointFromAnyPsr11Container(): void
    {
        $endpoint = new CreateOrderEndpoint();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with(CreateOrderEndpoint::class)
            ->willReturn($endpoint);

        $this->assertSame($endpoint, (new Psr11EndpointResolver($container))->resolve(CreateOrderEndpoint::class));
    }

    public function testWrapsContainerFailuresAtTheAdapterBoundary(): void
    {
        $failure = new MissingService('not found');
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willThrowException($failure);

        try {
            (new Psr11EndpointResolver($container))->resolve(CreateOrderEndpoint::class);
            $this->fail('Expected endpoint resolution to fail.');
        } catch (EndpointResolutionException $exception) {
            $this->assertStringContainsString(CreateOrderEndpoint::class, $exception->getMessage());
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function testRejectsAResolvedObjectThatIsNotInvokable(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new NotInvokableEndpoint());

        $this->expectException(EndpointResolutionException::class);

        (new Psr11EndpointResolver($container))->resolve(NotInvokableEndpoint::class);
    }

    public function testRejectsNonObjectContainerValues(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn('not-an-endpoint');

        $this->expectException(EndpointResolutionException::class);

        (new Psr11EndpointResolver($container))->resolve(CreateOrderEndpoint::class);
    }
}

final class MissingService extends RuntimeException implements NotFoundExceptionInterface
{
}

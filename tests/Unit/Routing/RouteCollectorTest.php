<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit\Routing;

use DomainFlow\Http\Attribute\Route;
use DomainFlow\Http\Routing\Exception\RouteDefinitionException;
use DomainFlow\Http\Routing\RouteCollector;
use DomainFlow\Http\Tests\Fixture\AbstractRequestTypeEndpoint;
use DomainFlow\Http\Tests\Fixture\CreateOrderEndpoint;
use DomainFlow\Http\Tests\Fixture\CreateOrderRequest;
use DomainFlow\Http\Tests\Fixture\EnumRequestTypeEndpoint;
use DomainFlow\Http\Tests\Fixture\FirstDuplicateRouteEndpoint;
use DomainFlow\Http\Tests\Fixture\HealthEndpoint;
use DomainFlow\Http\Tests\Fixture\InterfaceRequestTypeEndpoint;
use DomainFlow\Http\Tests\Fixture\InvalidEndpointSignature;
use DomainFlow\Http\Tests\Fixture\InvalidRouteRequirementEndpoint;
use DomainFlow\Http\Tests\Fixture\NonInvokableRoutedEndpoint;
use DomainFlow\Http\Tests\Fixture\SecondDuplicateRouteEndpoint;
use DomainFlow\Http\Tests\Fixture\TenantReportEndpoint;
use DomainFlow\Http\Tests\Fixture\UnroutedEndpoint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionException;

#[CoversClass(Route::class)]
#[CoversClass(RouteCollector::class)]
#[CoversClass(RouteDefinitionException::class)]
final class RouteCollectorTest extends TestCase
{
    public function testCollectsExplicitEndpointClassesAndBuildsRuntimeMetadata(): void
    {
        $collection = (new RouteCollector())->collect([
            CreateOrderEndpoint::class,
            HealthEndpoint::class,
        ]);

        $create = $collection->get('orders.create');
        $this->assertNotNull($create);
        $this->assertSame('/orders', $create->getPath());
        $this->assertSame(['POST'], $create->getMethods());
        $this->assertSame(CreateOrderEndpoint::class, $create->getDefault('_endpoint'));
        $this->assertSame(CreateOrderRequest::class, $create->getDefault('_request'));

        $healthName = HealthEndpoint::class;
        $health = $collection->get($healthName);
        $this->assertNotNull($health);
        $this->assertNull($health->getDefault('_request'));
    }

    public function testRejectsDuplicateRouteNamesInsteadOfSilentlyOverwriting(): void
    {
        try {
            (new RouteCollector())->collect([
                FirstDuplicateRouteEndpoint::class,
                SecondDuplicateRouteEndpoint::class,
            ]);
            $this->fail('Expected route collection to fail.');
        } catch (RouteDefinitionException $exception) {
            $this->assertStringContainsString('duplicate', $exception->getMessage());
        }
    }

    public function testPreservesAdvancedRoutingOptionsAndNormalizesTheMethod(): void
    {
        $route = (new RouteCollector())->collect([TenantReportEndpoint::class])->get('reports.show');

        $this->assertNotNull($route);
        $this->assertSame(['GET'], $route->getMethods());
        $this->assertSame(['reportId' => '\\d+'], $route->getRequirements());
        $this->assertSame('{tenant}.api.example.test', $route->getHost());
        $this->assertSame(['https'], $route->getSchemes());
    }

    public function testRejectsConfiguredClassesWithoutRouteAttributes(): void
    {
        try {
            (new RouteCollector())->collect([UnroutedEndpoint::class]);
            $this->fail('Expected route collection to fail.');
        } catch (RouteDefinitionException $exception) {
            $this->assertStringContainsString(UnroutedEndpoint::class, $exception->getMessage());
        }
    }

    public function testRejectsAConfiguredClassThatIsNotInvokable(): void
    {
        $this->expectException(RouteDefinitionException::class);

        (new RouteCollector())->collect([NonInvokableRoutedEndpoint::class]);
    }

    public function testRejectsEndpointSignaturesThatCannotBeDispatched(): void
    {
        try {
            (new RouteCollector())->collect([InvalidEndpointSignature::class]);
            $this->fail('Expected route collection to fail.');
        } catch (RouteDefinitionException $exception) {
            $this->assertStringContainsString(InvalidEndpointSignature::class, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRequestTypeProvider(): iterable
    {
        yield 'unknown class' => ['DomainFlow\\Http\\Tests\\RuntimeFixture\\UnknownRequestTypeEndpoint'];
        yield 'abstract class' => [AbstractRequestTypeEndpoint::class];
        yield 'interface' => [InterfaceRequestTypeEndpoint::class];
        yield 'enum' => [EnumRequestTypeEndpoint::class];
    }

    #[DataProvider('invalidRequestTypeProvider')]
    public function testRejectsRequestTypesThatCannotBeHydratedAtBootstrap(string $endpointClass): void
    {
        if ($endpointClass === 'DomainFlow\\Http\\Tests\\RuntimeFixture\\UnknownRequestTypeEndpoint') {
            eval(<<<'PHP'
                namespace DomainFlowHttpTestsRuntimeFixture;

                #[\DomainFlow\Http\Attribute\Route(method: 'GET', path: '/unknown-request-type')]
                final class UnknownRequestTypeEndpoint
                {
                    public function __invoke(MissingRequestDto $request): void
                    {
                    }
                }
                PHP);
        }

        $this->expectException(RouteDefinitionException::class);

        (new RouteCollector())->collect([$endpointClass]);
    }

    public function testRejectsANonPublicZeroArgumentEndpoint(): void
    {
        $endpointClass = 'DomainFlow\\Http\\Tests\\RuntimeFixture\\NonPublicEndpoint';

        if (!class_exists($endpointClass, false)) {
            set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);

            try {
                eval(<<<'PHP'
                    namespace DomainFlow\Http\Tests\RuntimeFixture;

                    #[\DomainFlow\Http\Attribute\Route(method: 'GET', path: '/private')]
                    final class NonPublicEndpoint
                    {
                        protected function __invoke(): void
                        {
                        }
                    }
                    PHP);
            } finally {
                restore_error_handler();
            }
        }

        $this->expectException(RouteDefinitionException::class);

        (new RouteCollector())->collect([$endpointClass]);
    }

    public function testWrapsReflectionFailuresAtTheCollectorBoundary(): void
    {
        $missingEndpoint = 'Missing\\Endpoint';

        try {
            (new RouteCollector())->collect([$missingEndpoint]);
            $this->fail('Expected route collection to fail.');
        } catch (RouteDefinitionException $exception) {
            $this->assertInstanceOf(ReflectionException::class, $exception->getPrevious());
        }
    }

    public function testWrapsInvalidSymfonyRouteDefinitionsAtTheCollectorBoundary(): void
    {
        try {
            (new RouteCollector())->collect([InvalidRouteRequirementEndpoint::class]);
            $this->fail('Expected route collection to fail.');
        } catch (RouteDefinitionException $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }
}

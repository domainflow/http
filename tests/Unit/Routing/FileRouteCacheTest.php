<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit\Routing;

use DomainFlow\Http\Routing\Cache\CompiledRoutes;
use DomainFlow\Http\Routing\Cache\Exception\RouteCacheException;
use DomainFlow\Http\Routing\Cache\FileRouteCache;
use ParseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\CompiledUrlGenerator;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(FileRouteCache::class)]
#[CoversClass(CompiledRoutes::class)]
#[CoversClass(RouteCacheException::class)]
final class FileRouteCacheTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/domainflow-http-routes-' . bin2hex(random_bytes(8)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public function testWarmsAndLoadsMatcherAndGeneratorData(): void
    {
        $routes = new RouteCollection();
        $routes->add('orders.show', new Route('/orders/{id}', methods: ['GET']));

        $cache = new FileRouteCache($this->cacheFile);
        $cache->warm($routes);
        $compiled = $cache->load();

        $context = new RequestContext();
        $matcher = new CompiledUrlMatcher($compiled->matcherData, $context);
        $generator = new CompiledUrlGenerator($compiled->generatorData, $context);

        $this->assertSame('orders.show', $matcher->match('/orders/123')['_route']);
        $this->assertSame('/orders/123', $generator->generate('orders.show', ['id' => '123']));
    }

    public function testReplacesAnExistingCacheWithValidPhp(): void
    {
        file_put_contents($this->cacheFile, '<?php return "old";');
        $routes = new RouteCollection();
        $routes->add('health', new Route('/health'));

        (new FileRouteCache($this->cacheFile))->warm($routes);

        $this->assertInstanceOf(CompiledRoutes::class, (new FileRouteCache($this->cacheFile))->load());
    }

    public function testMissingCacheHasAPackageSpecificFailure(): void
    {
        $this->expectException(RouteCacheException::class);

        (new FileRouteCache($this->cacheFile))->load();
    }

    public function testCorruptCacheHasAPackageSpecificFailure(): void
    {
        file_put_contents($this->cacheFile, '<?php return "not compiled routes";');

        $this->expectException(RouteCacheException::class);

        (new FileRouteCache($this->cacheFile))->load();
    }

    public function testRejectsSemanticallyInvalidCompiledRouteData(): void
    {
        file_put_contents(
            $this->cacheFile,
            '<?php return new \\DomainFlow\\Http\\Routing\\Cache\\CompiledRoutes([], []);',
        );

        $this->expectException(RouteCacheException::class);

        (new FileRouteCache($this->cacheFile))->load();
    }

    public function testRejectsInvalidGeneratorRouteData(): void
    {
        file_put_contents(
            $this->cacheFile,
            '<?php return new \\DomainFlow\\Http\\Routing\\Cache\\CompiledRoutes([false, [], [], [], null], ["orders.show" => "invalid"]);',
        );

        $this->expectException(RouteCacheException::class);

        (new FileRouteCache($this->cacheFile))->load();
    }

    public function testRejectsNonScalarValuesInsideCompiledRouteData(): void
    {
        file_put_contents(
            $this->cacheFile,
            '<?php return new \\DomainFlow\\Http\\Routing\\Cache\\CompiledRoutes([false, [], [], [[new \\stdClass()]], null], []);',
        );

        $this->expectException(RouteCacheException::class);

        (new FileRouteCache($this->cacheFile))->load();
    }

    public function testCacheWarmTranslatesInvalidRouteCompilationFailures(): void
    {
        $routes = new RouteCollection();
        $routes->add('invalid', new Route('/orders/{id}', requirements: ['id' => '(']));

        try {
            (new FileRouteCache($this->cacheFile))->warm($routes);
            $this->fail('Expected route cache warming to fail.');
        } catch (RouteCacheException $exception) {
            $this->assertSame('The route cache could not be warmed.', $exception->getMessage());
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function testInvalidCachePhpIsTranslatedWithoutLeakingTheParseError(): void
    {
        file_put_contents($this->cacheFile, '<?php return (');

        try {
            (new FileRouteCache($this->cacheFile))->load();
            $this->fail('Expected invalid cache PHP to fail.');
        } catch (RouteCacheException $exception) {
            $this->assertSame('The route cache file is invalid.', $exception->getMessage());
            $this->assertInstanceOf(ParseError::class, $exception->getPrevious());
        }
    }

    public function testWarmRejectsAPathWhoseDirectoryDoesNotExist(): void
    {
        $cache = new FileRouteCache(sys_get_temp_dir() . '/domainflow-http-missing-directory/cache.php');
        $routes = new RouteCollection();
        $routes->add('health', new Route('/health'));

        $this->expectException(RouteCacheException::class);

        $cache->warm($routes);
    }

    public function testWarmRejectsWhenTheCacheTargetCannotBeReplaced(): void
    {
        $routes = new RouteCollection();
        $routes->add('health', new Route('/health'));
        $target = sys_get_temp_dir() . '/domainflow-http-cache-directory-' . bin2hex(random_bytes(4));
        mkdir($target);

        try {
            $this->expectException(RouteCacheException::class);

            (new FileRouteCache($target))->warm($routes);
        } finally {
            rmdir($target);
        }
    }

    public function testUnreadableCacheIsRejected(): void
    {
        file_put_contents($this->cacheFile, '<?php return null;');
        chmod($this->cacheFile, 0000);

        try {
            $this->expectException(RouteCacheException::class);
            (new FileRouteCache($this->cacheFile))->load();
        } finally {
            chmod($this->cacheFile, 0600);
        }
    }
}

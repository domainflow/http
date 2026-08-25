<?php

declare(strict_types=1);

namespace DomainFlow\Http\Routing\Cache;

use DomainFlow\Http\Routing\Cache\Exception\RouteCacheException;
use Exception;
use ParseError;
use Random\RandomException;
use Symfony\Component\Routing\Generator\Dumper\CompiledUrlGeneratorDumper;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RouteCollection;
use TypeError;

final readonly class FileRouteCache
{
    public function __construct(private string $cacheFile)
    {
    }

    public function warm(RouteCollection $routes): void
    {
        try {
            $matcherDump = (new CompiledUrlMatcherDumper($routes))->dump();
            $generatorDump = (new CompiledUrlGeneratorDumper($routes))->dump();
            $contents = $this->cacheContents($matcherDump, $generatorDump);
        } catch (Exception $exception) {
            throw RouteCacheException::cannotWarm($exception);
        }

        $this->replaceAtomically($contents);
    }

    public function load(): CompiledRoutes
    {
        if (!is_file($this->cacheFile)) {
            throw RouteCacheException::missing();
        }

        if (!is_readable($this->cacheFile)) {
            throw RouteCacheException::invalid();
        }

        try {
            $compiledRoutes = (static fn (string $file): mixed => require $file)($this->cacheFile);
        } catch (Exception|ParseError|TypeError $exception) {
            throw RouteCacheException::invalid($exception);
        }

        if (!$compiledRoutes instanceof CompiledRoutes) {
            throw RouteCacheException::invalid();
        }

        if (!$this->hasValidCompiledData($compiledRoutes)) {
            throw RouteCacheException::invalid();
        }

        return $compiledRoutes;
    }

    private function hasValidCompiledData(CompiledRoutes $compiledRoutes): bool
    {
        $matcherData = $compiledRoutes->matcherData;
        if (
            !array_key_exists(0, $matcherData)
            || !array_key_exists(1, $matcherData)
            || !array_key_exists(2, $matcherData)
            || !array_key_exists(3, $matcherData)
            || !array_key_exists(4, $matcherData)
            || !is_bool($matcherData[0])
            || !is_array($matcherData[1])
            || !is_array($matcherData[2])
            || !is_array($matcherData[3])
            || ($matcherData[4] !== null && !is_array($matcherData[4]))
            || !$this->isScalarTree($matcherData)
        ) {
            return false;
        }

        foreach ($compiledRoutes->generatorData as $routeName => $routeData) {
            if (!is_string($routeName) || !is_array($routeData)) {
                return false;
            }
        }

        return $this->isScalarTree($compiledRoutes->generatorData);
    }

    /** @param array<mixed> $data */
    private function isScalarTree(array $data): bool
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                if (!$this->isScalarTree($value)) {
                    return false;
                }

                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                return false;
            }
        }

        return true;
    }

    private function cacheContents(string $matcherDump, string $generatorDump): string
    {
        $matcherBody = $this->indentDump($this->withoutOpeningTag($matcherDump));
        $generatorBody = $this->indentDump($this->withoutOpeningTag($generatorDump));

        return <<<PHP
            <?php

            declare(strict_types=1);

            return new \\DomainFlow\\Http\\Routing\\Cache\\CompiledRoutes(
                (static function (): array {
            {$matcherBody}
                })(),
                (static function (): array {
            {$generatorBody}
                })(),
            );

            PHP;
    }

    private function withoutOpeningTag(string $dump): string
    {
        // Symfony's dumper contract always returns a PHP opening tag.
        // @codeCoverageIgnoreStart
        if (!str_starts_with($dump, '<?php')) {
            throw RouteCacheException::cannotWarm();
        }
        // @codeCoverageIgnoreEnd

        return ltrim(substr($dump, 5), "\r\n");
    }

    private function indentDump(string $dump): string
    {
        return preg_replace('/^/m', '        ', rtrim($dump)) ?? throw RouteCacheException::cannotWarm();
    }

    private function replaceAtomically(string $contents): void
    {
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw RouteCacheException::cannotWarm();
        }

        try {
            $temporaryFile = $directory
                . DIRECTORY_SEPARATOR
                . '.' . basename($this->cacheFile)
                . '.' . bin2hex(random_bytes(8))
                . '.tmp';
            // @codeCoverageIgnoreStart — random_bytes() only fails when the OS RNG fails.
        } catch (RandomException $exception) {
            throw RouteCacheException::cannotWarm($exception);
        }
        // @codeCoverageIgnoreEnd

        try {
            $bytesWritten = @file_put_contents($temporaryFile, $contents, LOCK_EX);
            if ($bytesWritten !== strlen($contents) || !@rename($temporaryFile, $this->cacheFile)) {
                throw RouteCacheException::cannotWarm();
            }
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }
}

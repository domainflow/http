<?php

declare(strict_types=1);

namespace DomainFlow\Http\Routing\Cache;

final readonly class CompiledRoutes
{
    /**
     * @param array<mixed>         $matcherData
     * @param array<string, mixed> $generatorData
     */
    public function __construct(
        public array $matcherData,
        public array $generatorData,
    ) {
    }
}

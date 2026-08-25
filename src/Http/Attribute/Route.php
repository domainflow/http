<?php

declare(strict_types=1);

namespace DomainFlow\Http\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Route
{
    /**
     * @param array<string, string> $requirements
     * @param list<string>          $schemes
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $name = null,
        public array $requirements = [],
        public ?string $host = null,
        public array $schemes = [],
    ) {
    }
}

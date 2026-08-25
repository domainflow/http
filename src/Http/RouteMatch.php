<?php

declare(strict_types=1);

namespace DomainFlow\Http;

final readonly class RouteMatch
{
    /**
     * @param class-string              $endpointClass
     * @param class-string|null         $requestClass
     * @param array<string, string>     $pathParameters
     */
    public function __construct(
        public string $routeName,
        public string $endpointClass,
        public ?string $requestClass,
        public array $pathParameters,
    ) {
    }
}

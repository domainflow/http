# DomainFlow HTTP

[![Tests](https://github.com/domainflow/http/actions/workflows/tests.yml/badge.svg)](https://github.com/domainflow/http/actions/workflows/tests.yml)
![Packagist Version](https://img.shields.io/packagist/v/domainflow/http)
![PHP Version](https://img.shields.io/packagist/php-v/domainflow/http)
![License](https://img.shields.io/github/license/domainflow/http)
![PHPStan](https://img.shields.io/badge/PHPStan-Level%210-brightgreen.svg)

DomainFlow HTTP is a small PSR-based HTTP entry point for DomainFlow
applications. It routes PSR-7 server requests to typed invokable endpoints and
provides request hydration, endpoint dispatch, JSON responses, Problem Details,
compiled routing, and an optional DomainFlow Core bootstrap adapter.

The package keeps application and domain code independent of Symfony
HttpFoundation, a concrete PSR-7 implementation, and a full-stack framework.

## Requirements

- PHP 8.4 or later; tested on PHP 8.4 and PHP 8.5
- PSR-7 message and PSR-17 factory implementations supplied by the application
- A PSR-11 container when using the included `Psr11EndpointResolver`

Symfony Routing and Relay are internal implementation dependencies. Concrete
PSR-7 implementations and SAPI emitters remain application choices.

## Installation

```sh
composer require domainflow/http
```

The optional Core bridge is available when `domainflow/core` is installed. The
library itself does not require DomainFlow Core at runtime.

## Basic composition

Declare an endpoint as an invokable class with a route attribute:

```php
use DomainFlow\Http\Attribute\Route;

#[Route(method: 'GET', path: '/hello/{name}', name: 'hello')]
final class HelloEndpoint
{
    public function __invoke(HelloRequest $request): array
    {
        return ['message' => 'Hello, ' . $request->name . '!'];
    }
}

final readonly class HelloRequest
{
    public function __construct(public string $name)
    {
    }
}
```

## Routing and request input

`Router::match()` returns a `RouteMatch` whose `routeParameters` contain every
application placeholder captured by the route. This includes placeholders from
both the URI path and the host; internal routing metadata is not exposed:

```php
#[Route(
    method: 'GET',
    path: '/reports/{reportId}',
    host: '{tenant}.api.example.test',
)]
final class TenantReportEndpoint
{
    public function __invoke(): void
    {
    }
}

// https://acme.api.example.test/reports/42
// routeParameters: ['reportId' => '42', 'tenant' => 'acme']
```

Request hydration combines JSON body fields, query parameters, and route
parameters. Query parameters override body fields, and route parameters have
the highest precedence so a client cannot replace a value captured by the
matched path or host.

`Router::generate()` always returns an origin-less URI reference such as
`/reports/42`. The scheme and host are omitted even when the route constrains
them; applications that need an absolute URI remain responsible for supplying
the origin.

## Runtime implementations

The package deliberately does not choose a concrete PSR implementation or SAPI
emitter. A minimal application-owned composition is available in
[`examples/runtime`](examples/runtime/). It uses Nyholm PSR-7 and Laminas'
HTTP handler runner:

```sh
cd examples/runtime
composer install
php -S 127.0.0.1:8080 -t public public/index.php
```

Then request the example endpoint:

```sh
curl http://127.0.0.1:8080/hello/Ada
# {"message":"Hello, Ada!"}
```

## DomainFlow Core

`DomainFlow\Http\Bridge\DomainFlowCore\HttpServiceProvider` registers the
HTTP kernel and default ports during Core bootstrap. Existing application
routers, hydrators, endpoint adapters, problem mappers, and PSR factories remain
application-owned. Core's callable middleware pipeline is not merged into the
PSR-15 request pipeline.

## Development

```sh
composer test-all
composer phpstan
composer lint
composer quality
```

The package targets PHP 8.4 and 8.5, PHPStan level 10, strict formatting,
dependency auditing, and full reachable-source line coverage.

## License

MIT license.

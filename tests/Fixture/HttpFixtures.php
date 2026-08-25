<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Fixture;

use DomainFlow\Http\Attribute\Route;
use DomainFlow\Http\Response\HttpResult;
use Psr\Http\Message\ResponseInterface;

enum DeliverySpeed: string
{
    case Standard = 'standard';
    case Express = 'express';
}

final readonly class AddressRequest
{
    public function __construct(
        public string $city,
        public string $country,
    ) {
    }
}

final readonly class OrderItemRequest
{
    public function __construct(
        public string $sku,
        public int $quantity,
    ) {
    }
}

final readonly class CreateOrderRequest
{
    /**
     * @param list<OrderItemRequest> $items
     */
    public function __construct(
        public string $customerId,
        public array $items,
        public AddressRequest $shippingAddress,
        public DeliverySpeed $deliverySpeed = DeliverySpeed::Standard,
        public ?string $note = null,
    ) {
    }
}

final readonly class CreateOrderResponse
{
    public function __construct(public string $orderId)
    {
    }
}

final readonly class SearchOrdersRequest
{
    public function __construct(
        public int $limit,
        public bool $includeArchived,
        public DeliverySpeed $deliverySpeed,
    ) {
    }
}

final readonly class RequiredNullableRequest
{
    public function __construct(public ?string $note)
    {
    }
}

final readonly class ScalarRequest
{
    public function __construct(
        public float $ratio,
        public bool $enabled,
    ) {
    }
}

abstract class AbstractRequest
{
}

final readonly class UnionRequest
{
    public function __construct(public int|string $value)
    {
    }
}

final readonly class NullRequest
{
    public function __construct(public null $value)
    {
    }
}

final readonly class UnknownNestedRequest
{
    public function __construct(public MissingNestedDto $value)
    {
    }
}

interface MissingNestedDto
{
}

interface RequestContract
{
}

final readonly class UndocumentedCollectionRequest
{
    /** @param array<array-key, mixed> $items */
    public function __construct(public array $items)
    {
    }
}

#[Route(method: 'GET', path: '/abstract-request-type')]
final class AbstractRequestTypeEndpoint
{
    public function __invoke(AbstractRequest $request): void
    {
    }
}

#[Route(method: 'GET', path: '/interface-request-type')]
final class InterfaceRequestTypeEndpoint
{
    public function __invoke(RequestContract $request): void
    {
    }
}

#[Route(method: 'GET', path: '/enum-request-type')]
final class EnumRequestTypeEndpoint
{
    public function __invoke(DeliverySpeed $request): void
    {
    }
}

#[Route(method: 'POST', path: '/orders', name: 'orders.create')]
final class CreateOrderEndpoint
{
    public function __invoke(CreateOrderRequest $request): HttpResult
    {
        return new HttpResult(
            new CreateOrderResponse('created-' . $request->customerId),
            201,
            ['Location' => ['/orders/created-' . $request->customerId]],
        );
    }
}

#[Route(method: 'GET', path: '/health')]
final class HealthEndpoint
{
    public function __invoke(): CreateOrderResponse
    {
        return new CreateOrderResponse('healthy');
    }
}

#[Route(method: 'GET', path: '/orders/search', name: 'orders.search')]
final class SearchOrdersEndpoint
{
    public function __invoke(SearchOrdersRequest $request): CreateOrderResponse
    {
        return new CreateOrderResponse((string) $request->limit);
    }
}

#[Route(
    method: 'get',
    path: '/reports/{reportId}',
    name: 'reports.show',
    requirements: ['reportId' => '\\d+'],
    host: '{tenant}.api.example.test',
    schemes: ['https'],
)]
final class TenantReportEndpoint
{
    public function __invoke(): CreateOrderResponse
    {
        return new CreateOrderResponse('report');
    }
}

#[Route(method: 'GET', path: '/duplicate-a', name: 'duplicate')]
final class FirstDuplicateRouteEndpoint
{
    public function __invoke(): CreateOrderResponse
    {
        return new CreateOrderResponse('a');
    }
}

#[Route(method: 'GET', path: '/duplicate-b', name: 'duplicate')]
final class SecondDuplicateRouteEndpoint
{
    public function __invoke(): CreateOrderResponse
    {
        return new CreateOrderResponse('b');
    }
}

#[Route(method: 'GET', path: '/invalid')]
final class InvalidEndpointSignature
{
    public function __invoke(string $one, string $two): void
    {
    }
}

final class UnroutedEndpoint
{
    public function __invoke(): void
    {
    }
}

#[Route(method: 'GET', path: '/not-invokable')]
final class NonInvokableRoutedEndpoint
{
}

#[Route(method: 'GET', path: '/invalid-requirement/{id}', requirements: ['id' => ''])]
final class InvalidRouteRequirementEndpoint
{
    public function __invoke(): void
    {
    }
}

#[Route(method: 'GET', path: '/invalid-compilation/{id}', requirements: ['id' => '('])]
final class InvalidRouteCompilationEndpoint
{
    public function __invoke(): void
    {
    }
}

final class ResponseEndpoint
{
    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function __invoke(): ResponseInterface
    {
        return $this->response;
    }
}

final class NotInvokableEndpoint
{
}

<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit\Endpoint;

use DomainFlow\Http\Endpoint\Exception\RequestHydrationConfigurationException;
use DomainFlow\Http\Endpoint\ReflectionRequestHydrator;
use DomainFlow\Http\ErrorHandling\Exception\RequestValidationException;
use DomainFlow\Http\ErrorHandling\Exception\UnsupportedMediaTypeException;
use DomainFlow\Http\RouteMatch;
use DomainFlow\Http\Tests\Fixture\AbstractRequest;
use DomainFlow\Http\Tests\Fixture\CreateOrderEndpoint;
use DomainFlow\Http\Tests\Fixture\CreateOrderRequest;
use DomainFlow\Http\Tests\Fixture\DeliverySpeed;
use DomainFlow\Http\Tests\Fixture\NullRequest;
use DomainFlow\Http\Tests\Fixture\RequiredNullableRequest;
use DomainFlow\Http\Tests\Fixture\ScalarRequest;
use DomainFlow\Http\Tests\Fixture\SearchOrdersEndpoint;
use DomainFlow\Http\Tests\Fixture\SearchOrdersRequest;
use DomainFlow\Http\Tests\Fixture\UndocumentedCollectionRequest;
use DomainFlow\Http\Tests\Fixture\UnionRequest;
use DomainFlow\Http\Tests\Fixture\UnknownNestedRequest;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ReflectionRequestHydrator::class)]
#[CoversClass(RequestHydrationConfigurationException::class)]
#[CoversClass(RequestValidationException::class)]
#[CoversClass(UnsupportedMediaTypeException::class)]
#[UsesClass(RouteMatch::class)]
final class ReflectionRequestHydratorTest extends TestCase
{
    public function testHydratesStrictJsonNestedDtosDtoListsEnumsDefaultsAndNullables(): void
    {
        $request = $this->jsonRequest([
            'customerId' => 'customer-1',
            'items' => [
                ['sku' => 'sku-1', 'quantity' => 2],
                ['sku' => 'sku-2', 'quantity' => 1],
            ],
            'shippingAddress' => ['city' => 'Berlin', 'country' => 'DE'],
            'deliverySpeed' => 'express',
        ]);

        $dto = $this->hydrator()->hydrate($request, $this->match());

        $this->assertInstanceOf(CreateOrderRequest::class, $dto);
        $this->assertSame('customer-1', $dto->customerId);
        $this->assertCount(2, $dto->items);
        $this->assertSame('sku-1', $dto->items[0]->sku);
        $this->assertSame(2, $dto->items[0]->quantity);
        $this->assertSame('Berlin', $dto->shippingAddress->city);
        $this->assertSame(DeliverySpeed::Express, $dto->deliverySpeed);
        $this->assertNull($dto->note);
    }

    public function testPathParametersOverrideBodyAndQueryValues(): void
    {
        $request = $this->jsonRequest([
            'customerId' => 'from-body',
            'items' => [],
            'shippingAddress' => ['city' => 'Berlin', 'country' => 'DE'],
        ])->withQueryParams(['customerId' => 'from-query']);
        $match = new RouteMatch(
            'orders.create',
            CreateOrderEndpoint::class,
            CreateOrderRequest::class,
            ['customerId' => 'from-path'],
        );

        $dto = $this->hydrator()->hydrate($request, $match);

        $this->assertInstanceOf(CreateOrderRequest::class, $dto);
        $this->assertSame('from-path', $dto->customerId);
    }

    public function testReturnsNullWhenTheRouteDeclaresNoRequestDto(): void
    {
        $match = new RouteMatch('health', CreateOrderEndpoint::class, null, []);

        $this->assertNull($this->hydrator()->hydrate(new ServerRequest('GET', '/health'), $match));
    }

    public function testConvertsUnambiguousQueryStringsToDeclaredScalarAndEnumTypes(): void
    {
        $request = (new ServerRequest('GET', '/orders/search?limit=25&includeArchived=true&deliverySpeed=standard'))
            ->withQueryParams([
                'limit' => '25',
                'includeArchived' => 'true',
                'deliverySpeed' => 'standard',
            ]);
        $match = new RouteMatch(
            'orders.search',
            SearchOrdersEndpoint::class,
            SearchOrdersRequest::class,
            [],
        );

        $dto = $this->hydrator()->hydrate($request, $match);

        $this->assertInstanceOf(SearchOrdersRequest::class, $dto);
        $this->assertSame(25, $dto->limit);
        $this->assertTrue($dto->includeArchived);
        $this->assertSame(DeliverySpeed::Standard, $dto->deliverySpeed);
    }

    public function testRejectsAmbiguousScalarAndUnknownEnumQueryValues(): void
    {
        $request = (new ServerRequest('GET', '/orders/search'))
            ->withQueryParams([
                'limit' => '2.5',
                'includeArchived' => 'yes',
                'deliverySpeed' => 'instant',
            ]);
        $match = new RouteMatch(
            'orders.search',
            SearchOrdersEndpoint::class,
            SearchOrdersRequest::class,
            [],
        );

        try {
            $this->hydrator()->hydrate($request, $match);
            $this->fail('Expected query hydration to fail.');
        } catch (RequestValidationException $exception) {
            $this->assertArrayHasKey('limit', $exception->violations());
            $this->assertArrayHasKey('includeArchived', $exception->violations());
            $this->assertArrayHasKey('deliverySpeed', $exception->violations());
        }
    }

    public function testRejectsMalformedJsonWithAStableBodyViolation(): void
    {
        $request = new ServerRequest(
            'POST',
            '/orders',
            ['Content-Type' => 'application/json'],
            '{broken',
        );

        try {
            $this->hydrator()->hydrate($request, $this->match());
            $this->fail('Expected request validation to fail.');
        } catch (RequestValidationException $exception) {
            $this->assertArrayHasKey('$body', $exception->violations());
        }
    }

    public function testRejectsAWhitespaceOnlyJsonBodyAsMalformed(): void
    {
        $request = new ServerRequest(
            'POST',
            '/orders',
            ['Content-Type' => 'application/json'],
            " \n\t",
        );

        try {
            $this->hydrator()->hydrate($request, $this->match());
            $this->fail('Expected whitespace-only JSON to fail validation.');
        } catch (RequestValidationException $exception) {
            $this->assertSame(['Malformed JSON body.'], $exception->violations()['$body']);
        }
    }

    public function testAccumulatesMissingUnknownAndWronglyTypedFields(): void
    {
        $request = $this->jsonRequest([
            'customerId' => 42,
            'items' => [['sku' => 'sku-1', 'quantity' => 'two']],
            'unexpected' => true,
        ]);

        try {
            $this->hydrator()->hydrate($request, $this->match());
            $this->fail('Expected request validation to fail.');
        } catch (RequestValidationException $exception) {
            $violations = $exception->violations();
            $this->assertArrayHasKey('customerId', $violations);
            $this->assertArrayHasKey('items.0.quantity', $violations);
            $this->assertArrayHasKey('shippingAddress', $violations);
            $this->assertArrayHasKey('unexpected', $violations);
        }
    }

    public function testRejectsNumericJsonObjectKeysAsUnknownFields(): void
    {
        $request = $this->jsonRequest([
            0 => 'unexpected',
            'customerId' => 'customer-1',
            'items' => [],
            'shippingAddress' => ['city' => 'Berlin', 'country' => 'DE'],
        ]);

        try {
            $this->hydrator()->hydrate($request, $this->match());
            $this->fail('Expected request validation to fail.');
        } catch (RequestValidationException $exception) {
            $this->assertArrayHasKey('$body.0', $exception->violations());
        }
    }

    public function testRejectsAJsonObjectWhereAListIsDeclared(): void
    {
        $request = new ServerRequest(
            'POST',
            '/orders',
            ['Content-Type' => 'application/json'],
            <<<'JSON'
                {
                    "customerId": "customer-1",
                    "items": {"0": {"sku": "sku-1", "quantity": 2}},
                    "shippingAddress": {"city": "Berlin", "country": "DE"}
                }
                JSON,
        );

        try {
            $this->hydrator()->hydrate($request, $this->match());
            $this->fail('Expected request validation to fail.');
        } catch (RequestValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->violations());
        }
    }

    public function testRejectsAJsonRequestWithAnUnsupportedMediaType(): void
    {
        $request = new ServerRequest('POST', '/orders', ['Content-Type' => 'text/plain'], '{}');

        $this->expectException(UnsupportedMediaTypeException::class);

        $this->hydrator()->hydrate($request, $this->match());
    }

    public function testAcceptsStructuredJsonMediaTypes(): void
    {
        $request = new ServerRequest(
            'POST',
            '/orders',
            ['Content-Type' => 'application/vnd.domainflow+json; charset=utf-8'],
            json_encode([
                'customerId' => 'customer-1',
                'items' => [],
                'shippingAddress' => ['city' => 'Berlin', 'country' => 'DE'],
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertInstanceOf(
            CreateOrderRequest::class,
            $this->hydrator()->hydrate($request, $this->match()),
        );
    }

    public function testRejectsAnInvalidStructuredJsonMediaType(): void
    {
        $request = new ServerRequest('POST', '/orders', ['Content-Type' => '+json'], '{}');

        $this->expectException(UnsupportedMediaTypeException::class);

        $this->hydrator()->hydrate($request, $this->match());
    }

    public function testRejectsARequestClassThatWasNotCompiledAtBootstrap(): void
    {
        $hydrator = new ReflectionRequestHydrator([SearchOrdersRequest::class]);
        $request = new ServerRequest('POST', '/orders', ['Content-Type' => 'text/plain'], 'invalid');

        $this->expectException(RequestHydrationConfigurationException::class);

        $hydrator->hydrate($request, $this->match());
    }

    public function testRejectsUnsupportedDtoMetadataDuringBootstrapCompilation(): void
    {
        $this->expectException(RequestHydrationConfigurationException::class);

        new ReflectionRequestHydrator([UndocumentedCollectionRequest::class]);
    }

    public function testCompilesEachRequestClassOnlyOnceWhenConfiguredRepeatedly(): void
    {
        $hydrator = new ReflectionRequestHydrator([
            CreateOrderRequest::class,
            CreateOrderRequest::class,
        ]);

        $this->assertInstanceOf(
            CreateOrderRequest::class,
            $hydrator->hydrate($this->jsonRequest([
                'customerId' => 'customer-1',
                'items' => [],
                'shippingAddress' => ['city' => 'Berlin', 'country' => 'DE'],
            ]), $this->match()),
        );
    }

    public function testRejectsAnAbstractRequestClass(): void
    {
        $this->expectException(RequestHydrationConfigurationException::class);

        new ReflectionRequestHydrator([AbstractRequest::class]);
    }

    public function testRejectsAnInvalidRequestClassAtTheBootstrapBoundary(): void
    {
        $this->expectException(RequestHydrationConfigurationException::class);

        (new ReflectionClass(ReflectionRequestHydrator::class))->newInstanceArgs([
            ['Missing\\Request'],
        ]);
    }

    public function testRejectsAnUntypedRequestParameterAtTheBootstrapBoundary(): void
    {
        $request = new class(null) {
            /** @phpstan-param mixed $value */
            public function __construct($value)
            {
                if ($value === null) {
                    return;
                }
            }
        };

        $this->expectException(RequestHydrationConfigurationException::class);

        new ReflectionRequestHydrator([get_class($request)]);
    }

    public function testRejectsRequestMetadataThatCannotBeResolved(): void
    {
        $this->expectException(RequestHydrationConfigurationException::class);

        new ReflectionRequestHydrator([UnionRequest::class]);
    }

    public function testRejectsAnUnknownNestedRequestClass(): void
    {
        $this->expectException(RequestHydrationConfigurationException::class);

        new ReflectionRequestHydrator([UnknownNestedRequest::class]);
    }

    public function testHydratesRequiredNullableValuesFromEmptyAndExplicitNullInput(): void
    {
        $hydrator = new ReflectionRequestHydrator([RequiredNullableRequest::class]);
        $match = new RouteMatch('nullable', CreateOrderEndpoint::class, RequiredNullableRequest::class, []);

        $empty = $hydrator->hydrate(new ServerRequest('POST', '/nullable'), $match);
        $this->assertInstanceOf(RequiredNullableRequest::class, $empty);
        $this->assertNull($empty->note);

        $explicit = $hydrator->hydrate(
            new ServerRequest('POST', '/nullable', ['Content-Type' => 'application/json'], '{"note":null}'),
            $match,
        );
        $this->assertInstanceOf(RequiredNullableRequest::class, $explicit);
        $this->assertNull($explicit->note);

        $value = $hydrator->hydrate(
            new ServerRequest('POST', '/nullable', ['Content-Type' => 'application/json'], '{"note":"provided"}'),
            $match,
        );
        $this->assertInstanceOf(RequiredNullableRequest::class, $value);
        $this->assertSame('provided', $value->note);
    }

    public function testReportsAnObjectTypeWhenAJsonValueIsNotAnObject(): void
    {
        $request = $this->jsonRequest([
            'customerId' => 'customer-1',
            'items' => [],
            'shippingAddress' => 'Berlin',
        ]);

        try {
            $this->hydrator()->hydrate($request, $this->match());
            $this->fail('Expected object validation to fail.');
        } catch (RequestValidationException $exception) {
            $this->assertArrayHasKey('shippingAddress', $exception->violations());
        }
    }

    public function testConvertsFloatAndFalseBooleanQueryValues(): void
    {
        $hydrator = new ReflectionRequestHydrator([ScalarRequest::class]);
        $match = new RouteMatch('scalar', CreateOrderEndpoint::class, ScalarRequest::class, []);
        $request = (new ServerRequest('GET', '/scalar'))->withQueryParams([
            'ratio' => '1.25',
            'enabled' => 'false',
        ]);

        $dto = $hydrator->hydrate($request, $match);

        $this->assertInstanceOf(ScalarRequest::class, $dto);
        $this->assertSame(1.25, $dto->ratio);
        $this->assertFalse($dto->enabled);
    }

    public function testRejectsAnInvalidFloatQueryValue(): void
    {
        $hydrator = new ReflectionRequestHydrator([ScalarRequest::class]);
        $match = new RouteMatch('scalar', CreateOrderEndpoint::class, ScalarRequest::class, []);
        $request = (new ServerRequest('GET', '/scalar'))->withQueryParams([
            'ratio' => 'not-a-number',
            'enabled' => 'false',
        ]);

        $this->expectException(RequestValidationException::class);

        $hydrator->hydrate($request, $match);
    }

    public function testRejectsAnEnumValueWithTheWrongBackingType(): void
    {
        $request = $this->jsonRequest([
            'customerId' => 'customer-1',
            'items' => [],
            'shippingAddress' => ['city' => 'Berlin', 'country' => 'DE'],
            'deliverySpeed' => 1,
        ]);

        $this->expectException(RequestValidationException::class);

        $this->hydrator()->hydrate($request, $this->match());
    }

    public function testRejectsAValueThatCannotBeCoercedToANullOnlyType(): void
    {
        $hydrator = new ReflectionRequestHydrator([NullRequest::class]);
        $match = new RouteMatch('null', CreateOrderEndpoint::class, NullRequest::class, []);
        $request = (new ServerRequest('GET', '/null'))->withQueryParams(['value' => 'not-null']);

        $this->expectException(RequestValidationException::class);

        $hydrator->hydrate($request, $match);
    }

    public function testRejectsAJsonArrayWhereAnObjectIsDeclared(): void
    {
        $request = new ServerRequest(
            'POST',
            '/orders',
            ['Content-Type' => 'application/json'],
            '[]',
        );

        $this->expectException(RequestValidationException::class);

        $this->hydrator()->hydrate($request, $this->match());
    }

    private function hydrator(): ReflectionRequestHydrator
    {
        return new ReflectionRequestHydrator([
            CreateOrderRequest::class,
            SearchOrdersRequest::class,
        ]);
    }

    private function match(): RouteMatch
    {
        return new RouteMatch(
            'orders.create',
            CreateOrderEndpoint::class,
            CreateOrderRequest::class,
            [],
        );
    }

    /** @param array<array-key, mixed> $body */
    private function jsonRequest(array $body): ServerRequest
    {
        return new ServerRequest(
            'POST',
            '/orders',
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}

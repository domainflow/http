<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit\Endpoint;

use DomainFlow\Http\Endpoint\Exception\EndpointInvocationException;
use DomainFlow\Http\Endpoint\InvokableEndpointInvoker;
use DomainFlow\Http\Internal\HttpMetadataValidator;
use DomainFlow\Http\Response\HttpResult;
use DomainFlow\Http\Tests\Fixture\AddressRequest;
use DomainFlow\Http\Tests\Fixture\CreateOrderEndpoint;
use DomainFlow\Http\Tests\Fixture\CreateOrderRequest;
use DomainFlow\Http\Tests\Fixture\CreateOrderResponse;
use DomainFlow\Http\Tests\Fixture\DeliverySpeed;
use DomainFlow\Http\Tests\Fixture\HealthEndpoint;
use DomainFlow\Http\Tests\Fixture\NotInvokableEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvokableEndpointInvoker::class)]
#[CoversClass(EndpointInvocationException::class)]
#[UsesClass(HttpMetadataValidator::class)]
#[UsesClass(HttpResult::class)]
final class InvokableEndpointInvokerTest extends TestCase
{
    public function testInvokesATypedEndpointWithPrecomputedRequestMetadata(): void
    {
        $request = new CreateOrderRequest(
            'customer-1',
            [],
            new AddressRequest('Berlin', 'DE'),
            DeliverySpeed::Express,
        );

        $result = (new InvokableEndpointInvoker())->invoke(
            new CreateOrderEndpoint(),
            $request,
            CreateOrderRequest::class,
        );

        $this->assertInstanceOf(HttpResult::class, $result);
        $this->assertSame(201, $result->status);
    }

    public function testInvokesAZeroArgumentEndpointWithoutInventingARequestDto(): void
    {
        $result = (new InvokableEndpointInvoker())->invoke(new HealthEndpoint(), null, null);

        $this->assertInstanceOf(CreateOrderResponse::class, $result);
        $this->assertSame('healthy', $result->orderId);
    }

    public function testRejectsAnObjectThatIsNotInvokable(): void
    {
        $this->expectException(EndpointInvocationException::class);

        (new InvokableEndpointInvoker())->invoke(new NotInvokableEndpoint(), null, null);
    }

    public function testRejectsMissingRequestForATypedEndpoint(): void
    {
        try {
            (new InvokableEndpointInvoker())->invoke(
                new CreateOrderEndpoint(),
                null,
                CreateOrderRequest::class,
            );
            $this->fail('Expected endpoint invocation to fail.');
        } catch (EndpointInvocationException $exception) {
            $this->assertStringContainsString(CreateOrderRequest::class, $exception->getMessage());
        }
    }

    public function testRejectsARequestWithAnIncompatibleType(): void
    {
        $this->expectException(EndpointInvocationException::class);

        (new InvokableEndpointInvoker())->invoke(
            new CreateOrderEndpoint(),
            new AddressRequest('Berlin', 'DE'),
            CreateOrderRequest::class,
        );
    }

    public function testRejectsAnUnexpectedRequestForAZeroArgumentEndpoint(): void
    {
        $this->expectException(EndpointInvocationException::class);

        (new InvokableEndpointInvoker())->invoke(
            new HealthEndpoint(),
            new AddressRequest('Berlin', 'DE'),
            null,
        );
    }
}

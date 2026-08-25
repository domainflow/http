<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit\Response;

use DomainFlow\Http\ErrorHandling\ProblemDetails;
use DomainFlow\Http\Exception\InvalidHttpMetadataException;
use DomainFlow\Http\Internal\HttpMetadataValidator;
use DomainFlow\Http\Response\Exception\JsonEncodingException;
use DomainFlow\Http\Response\HttpResult;
use DomainFlow\Http\Response\JsonResponseFactory;
use DomainFlow\Http\Tests\Fixture\CreateOrderResponse;
use InvalidArgumentException;
use JsonSerializable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(HttpResult::class)]
#[CoversClass(HttpMetadataValidator::class)]
#[CoversClass(InvalidHttpMetadataException::class)]
#[CoversClass(JsonResponseFactory::class)]
#[CoversClass(JsonEncodingException::class)]
#[CoversClass(ProblemDetails::class)]
final class JsonResponseFactoryTest extends TestCase
{
    private JsonResponseFactory $factory;

    protected function setUp(): void
    {
        $psr17 = new Psr17Factory();
        $this->factory = new JsonResponseFactory($psr17, $psr17);
    }

    public function testSerializesObjectsAsJsonWithA200Default(): void
    {
        $response = $this->factory->fromResult(new CreateOrderResponse('order-1'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['orderId' => 'order-1'], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testSerializesArraysAsJson(): void
    {
        $response = $this->factory->fromResult(['orderId' => 'order-1']);

        $this->assertSame('{"orderId":"order-1"}', (string) $response->getBody());
    }

    public function testHttpResultControlsStatusAndHeadersExplicitly(): void
    {
        $response = $this->factory->fromResult(new HttpResult(
            new CreateOrderResponse('order-1'),
            201,
            ['Location' => ['/orders/order-1'], 'X-Trace' => ['one', 'two']],
        ));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('/orders/order-1', $response->getHeaderLine('Location'));
        $this->assertSame(['one', 'two'], $response->getHeader('X-Trace'));
    }

    public function testRejectsAResponseBodyForNoContentStatus(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        $this->factory->fromResult(new HttpResult(['ignored' => true], 204));
    }

    public function testHttpResultRejectsInvalidHttpMetadataAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpResult(null, 99, ['Bad Header' => ["unsafe\r\nvalue"]]);
    }

    public function testHttpResultRejectsAnInvalidHeaderName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpResult(null, headers: ['Bad Header' => ['value']]);
    }

    public function testHttpResultRejectsAnUnsafeHeaderValue(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new HttpResult(null, headers: ['X-Safe' => ["unsafe\r\nvalue"]]);
    }

    public function testHttpResultRejectsOtherForbiddenHeaderControlBytes(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new HttpResult(null, headers: ['X-Safe' => ["unsafe\0value"]]);
    }

    public function testNullProducesAnEmpty204Response(): void
    {
        $response = $this->factory->fromResult(null);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertFalse($response->hasHeader('Content-Type'));
    }

    public function testRemovesContentHeadersFromAnExplicitNoContentResult(): void
    {
        $response = $this->factory->fromResult(new HttpResult(
            null,
            204,
            ['Content-Type' => ['application/json'], 'Content-Length' => ['12']],
        ));

        $this->assertSame('', (string) $response->getBody());
        $this->assertFalse($response->hasHeader('Content-Type'));
        $this->assertFalse($response->hasHeader('Content-Length'));
    }

    public function testHttpResultWithANullBodyKeepsItsStatusAndHeadersWithoutAContentType(): void
    {
        $response = $this->factory->fromResult(new HttpResult(
            null,
            202,
            ['X-Status' => ['accepted']],
        ));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('accepted', $response->getHeaderLine('X-Status'));
        $this->assertSame('', (string) $response->getBody());
        $this->assertFalse($response->hasHeader('Content-Type'));
    }

    public function testAllowsBodylessInformationalAndNotModifiedResults(): void
    {
        $this->assertSame(101, $this->factory->fromResult(new HttpResult(null, 101))->getStatusCode());
        $this->assertSame(304, $this->factory->fromResult(new HttpResult(null, 304))->getStatusCode());
    }

    public function testRejectsNonListHeaderValuesAtTheRuntimeBoundary(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        (new ReflectionClass(HttpResult::class))->newInstanceArgs([
            null,
            200,
            ['X-Trace' => 'not-a-header-list'],
        ]);
    }

    public function testPsrResponseIsPassedThroughUnchanged(): void
    {
        $expected = new Response(202, ['X-Pass-Through' => 'yes'], 'already built');

        $this->assertSame($expected, $this->factory->fromResult($expected));
    }

    public function testWrapsJsonEncodingFailuresAtTheResponseBoundary(): void
    {
        $this->expectException(JsonEncodingException::class);

        $this->factory->fromResult(NAN);
    }

    public function testWrapsExceptionsThrownByJsonSerializableValues(): void
    {
        $failure = new RuntimeException('sensitive serializer detail');
        $value = new class($failure) implements JsonSerializable {
            public function __construct(private readonly RuntimeException $failure)
            {
            }

            public function jsonSerialize(): mixed
            {
                throw $this->failure;
            }
        };

        try {
            $this->factory->fromResult($value);
            $this->fail('Expected JSON encoding to fail.');
        } catch (JsonEncodingException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
            $this->assertStringNotContainsString('sensitive', $exception->getMessage());
        }
    }

    public function testBuildsAProblemJsonResponseWithStandardAndExtensionMembers(): void
    {
        $problem = new ProblemDetails(
            status: 422,
            title: 'Invalid order',
            detail: 'Two fields are invalid.',
            type: 'https://domainflow.dev/problems/invalid-order',
            instance: '/orders/request-1',
            extensions: ['errors' => ['items' => ['Must not be empty.']]],
            headers: ['Retry-After' => ['10']],
        );

        $response = $this->factory->fromProblem($problem);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('10', $response->getHeaderLine('Retry-After'));
        $this->assertSame('https://domainflow.dev/problems/invalid-order', $payload['type']);
        $this->assertSame('/orders/request-1', $payload['instance']);
        $this->assertSame(['items' => ['Must not be empty.']], $payload['errors']);
    }

    public function testProblemExtensionsCannotOverwriteReservedMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProblemDetails(400, 'Bad Request', extensions: ['status' => 200]);
    }

    public function testProblemDetailsRejectsAnInvalidHttpStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProblemDetails(700, 'Invalid');
    }

    public function testProblemDetailsRejectsAnInvalidTypeUriReference(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new ProblemDetails(400, 'Invalid', type: 'not a URI');
    }

    public function testProblemDetailsRejectsAnInvalidInstanceUriReference(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new ProblemDetails(400, 'Invalid', instance: '/requests/bad percent%');
    }

    public function testProblemDetailsRejectsBracketsInAPath(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new ProblemDetails(400, 'Invalid', type: '/orders/[bad]');
    }

    public function testProblemDetailsRejectsMultipleFragmentDelimiters(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new ProblemDetails(400, 'Invalid', instance: '/orders#one#two');
    }

    public function testProblemDetailsRejectsBracketsOutsideAnIpLiteral(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new ProblemDetails(400, 'Invalid', type: 'http://foo[bar]');
    }

    public function testProblemDetailsRejectsMultipleUserInfoDelimiters(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new ProblemDetails(400, 'Invalid', type: 'http://user@@host');
    }
}

<?php

declare(strict_types=1);

namespace DomainFlow\Http\Tests\Unit\ErrorHandling;

use DomainFlow\Http\ErrorHandling\CompositeProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\DefaultProblemDetailsMapper;
use DomainFlow\Http\ErrorHandling\Exception\MethodNotAllowedException;
use DomainFlow\Http\ErrorHandling\Exception\RequestValidationException;
use DomainFlow\Http\ErrorHandling\Exception\RouteNotFoundException;
use DomainFlow\Http\ErrorHandling\Exception\UnsupportedMediaTypeException;
use DomainFlow\Http\ErrorHandling\ProblemDetails;
use DomainFlow\Http\ErrorHandling\ProblemDetailsMapper;
use DomainFlow\Http\Exception\InvalidHttpMetadataException;
use DomainFlow\Http\Internal\HttpMetadataValidator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ProblemDetails::class)]
#[CoversClass(HttpMetadataValidator::class)]
#[CoversClass(InvalidHttpMetadataException::class)]
#[CoversClass(DefaultProblemDetailsMapper::class)]
#[CoversClass(CompositeProblemDetailsMapper::class)]
#[UsesClass(MethodNotAllowedException::class)]
#[UsesClass(RequestValidationException::class)]
#[UsesClass(RouteNotFoundException::class)]
#[UsesClass(UnsupportedMediaTypeException::class)]
final class ProblemDetailsMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function infrastructureProblems(): iterable
    {
        yield 'not found' => ['not_found', 404, 'Not Found'];
        yield 'method' => ['method', 405, 'Method Not Allowed'];
        yield 'validation' => ['validation', 400, 'Validation Failed'];
        yield 'media type' => ['media_type', 415, 'Unsupported Media Type'];
    }

    #[DataProvider('infrastructureProblems')]
    public function testDefaultMapperMapsOnlyKnownInfrastructureFailures(
        string $exceptionKind,
        int $status,
        string $title,
    ): void {
        $exception = match ($exceptionKind) {
            'not_found' => RouteNotFoundException::forPath('/missing'),
            'method' => MethodNotAllowedException::forPath('/orders', ['GET']),
            'validation' => RequestValidationException::fromViolations(['name' => ['Required.']]),
            'media_type' => UnsupportedMediaTypeException::forMediaType('text/plain'),
            default => throw new LogicException('Unknown infrastructure problem fixture.'),
        };
        $problem = (new DefaultProblemDetailsMapper())->map($exception);

        $this->assertNotNull($problem);
        $this->assertSame($status, $problem->status);
        $this->assertSame($title, $problem->title);
    }

    public function testMethodNotAllowedProblemCarriesTheRequiredAllowHeader(): void
    {
        $problem = (new DefaultProblemDetailsMapper())->map(
            MethodNotAllowedException::forPath('/orders', ['GET', 'HEAD']),
        );

        $this->assertNotNull($problem);
        $this->assertSame(['GET, HEAD'], $problem->headers['Allow']);
    }

    public function testValidationViolationsAreExposedAsAnExtension(): void
    {
        $problem = (new DefaultProblemDetailsMapper())->map(
            RequestValidationException::fromViolations(['name' => ['Required.']]),
        );

        $this->assertNotNull($problem);
        $this->assertSame(['name' => ['Required.']], $problem->extensions['errors']);
    }

    public function testDefaultMapperDeclinesUnknownDomainExceptions(): void
    {
        $this->assertNull((new DefaultProblemDetailsMapper())->map(new RuntimeException('domain failure')));
    }

    public function testProblemDetailsAcceptsQueriesAndFragmentsWithValidPercentEscapes(): void
    {
        $problem = new ProblemDetails(
            400,
            'Invalid',
            type: '/orders?filter=one%20two#section',
            instance: 'http://example.test/orders?query=ok#fragment',
        );

        $this->assertSame('/orders?filter=one%20two#section', $problem->type);
        $this->assertSame('http://example.test/orders?query=ok#fragment', $problem->instance);
    }

    public function testProblemDetailsRejectsInvalidFragmentAndQueryEscapes(): void
    {
        foreach (['/orders#bad%ZZ', '/orders?bad%ZZ'] as $uri) {
            try {
                new ProblemDetails(400, 'Invalid', type: $uri);
                $this->fail('Expected the URI reference to be rejected.');
            } catch (InvalidHttpMetadataException $exception) {
                $this->assertSame('Problem type must be a valid URI-reference.', $exception->getMessage());
            }
        }
    }

    public function testProblemDetailsRejectsAColonInTheFirstRelativePathSegment(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new ProblemDetails(400, 'Invalid', type: '1orders:show');
    }

    public function testProblemDetailsAcceptsUserInfoAndPortsInAnAuthority(): void
    {
        $problem = new ProblemDetails(400, 'Invalid', type: 'http://user:pass@example.test:8443/orders');

        $this->assertSame('http://user:pass@example.test:8443/orders', $problem->type);
    }

    public function testProblemDetailsRejectsInvalidUserInfoEscapes(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new ProblemDetails(400, 'Invalid', type: 'http://user%ZZ@example.test/orders');
    }

    public function testProblemDetailsAcceptsIpv6AndIpvFutureAuthorities(): void
    {
        $ipv6 = new ProblemDetails(400, 'Invalid', type: 'http://[::1]:443/orders');
        $ipvFuture = new ProblemDetails(400, 'Invalid', type: 'http://[v1.fe]/orders');

        $this->assertSame('http://[::1]:443/orders', $ipv6->type);
        $this->assertSame('http://[v1.fe]/orders', $ipvFuture->type);
    }

    public function testProblemDetailsRejectsMalformedIpLiteralAuthorities(): void
    {
        foreach (['http://[::1', 'http://[::1]x', 'http://[::1]:abc'] as $uri) {
            try {
                new ProblemDetails(400, 'Invalid', type: $uri);
                $this->fail('Expected the IP literal authority to be rejected.');
            } catch (InvalidHttpMetadataException $exception) {
                $this->assertSame('Problem type must be a valid URI-reference.', $exception->getMessage());
            }
        }
    }

    public function testProblemDetailsRejectsInvalidPercentEscapesInAPath(): void
    {
        $this->expectException(InvalidHttpMetadataException::class);

        new ProblemDetails(400, 'Invalid', type: '/orders/bad%ZZ');
    }

    public function testCompositeUsesTheFirstMapperThatRecognizesTheException(): void
    {
        $custom = $this->createStub(ProblemDetailsMapper::class);
        $custom->method('map')->willReturn(new ProblemDetails(409, 'Order conflict'));

        $problem = (new CompositeProblemDetailsMapper([
            $custom,
            new DefaultProblemDetailsMapper(),
        ]))->map(new RuntimeException('conflict'));

        $this->assertSame(409, $problem->status);
        $this->assertSame('Order conflict', $problem->title);
    }

    public function testCompositeFallbackNeverLeaksAnUnknownExceptionMessage(): void
    {
        $problem = (new CompositeProblemDetailsMapper([
            new DefaultProblemDetailsMapper(),
        ]))->map(new RuntimeException('database password and internal path'));

        $this->assertSame(500, $problem->status);
        $this->assertSame('Internal Server Error', $problem->title);
        $this->assertNull($problem->detail);
    }
}

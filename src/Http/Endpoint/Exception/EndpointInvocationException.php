<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint\Exception;

use RuntimeException;

final class EndpointInvocationException extends RuntimeException
{
    public static function notInvokable(object $endpoint): self
    {
        return new self(sprintf('Resolved endpoint %s is not invokable.', $endpoint::class));
    }

    /** @param class-string $requestClass */
    public static function missingRequest(object $endpoint, string $requestClass): self
    {
        return new self(sprintf('Endpoint %s requires request %s.', $endpoint::class, $requestClass));
    }

    public static function unexpectedRequest(object $endpoint, object $request): self
    {
        return new self(sprintf(
            'Endpoint %s does not accept request %s.',
            $endpoint::class,
            $request::class,
        ));
    }

    /** @param class-string $requestClass */
    public static function incompatibleRequest(object $endpoint, object $request, string $requestClass): self
    {
        return new self(sprintf(
            'Endpoint %s requires request %s, %s given.',
            $endpoint::class,
            $requestClass,
            $request::class,
        ));
    }

    public static function contextUnsupported(object $endpoint): self
    {
        return new self(sprintf(
            'Endpoint %s requires a context-aware endpoint invoker.',
            $endpoint::class,
        ));
    }
}

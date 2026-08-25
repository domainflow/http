<?php

declare(strict_types=1);

namespace DomainFlow\Http\Exception;

use InvalidArgumentException;

final class InvalidHttpMetadataException extends InvalidArgumentException
{
    public static function forStatus(string $kind): self
    {
        return new self(sprintf('%s status must be between 100 and 599.', $kind));
    }

    public static function forBody(string $kind, int $status): self
    {
        return new self(sprintf('%s status %d must not contain a body.', $kind, $status));
    }

    public static function forHeaderName(string $kind): self
    {
        return new self(sprintf('%s header name is invalid.', $kind));
    }

    public static function forHeaderValues(string $kind): self
    {
        return new self(sprintf('%s header values must be a list of valid field values.', $kind));
    }

    public static function forProblemExtension(): self
    {
        return new self('Problem extension name is reserved or invalid.');
    }

    public static function forProblemUriReference(string $member): self
    {
        return new self(sprintf('Problem %s must be a valid URI-reference.', $member));
    }
}

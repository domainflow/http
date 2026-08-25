<?php

declare(strict_types=1);

namespace DomainFlow\Http\ErrorHandling\Exception;

use RuntimeException;

final class UnsupportedMediaTypeException extends RuntimeException
{
    public static function forMediaType(string $mediaType): self
    {
        return new self(sprintf('Unsupported request media type "%s".', $mediaType));
    }
}

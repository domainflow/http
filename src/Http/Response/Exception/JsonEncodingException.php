<?php

declare(strict_types=1);

namespace DomainFlow\Http\Response\Exception;

use RuntimeException;
use Throwable;

final class JsonEncodingException extends RuntimeException
{
    public static function fromEncoding(Throwable $previous): self
    {
        return new self('Unable to encode the HTTP response as JSON.', previous: $previous);
    }
}

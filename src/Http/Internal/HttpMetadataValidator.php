<?php

declare(strict_types=1);

namespace DomainFlow\Http\Internal;

use DomainFlow\Http\Exception\InvalidHttpMetadataException;

/** @internal */
final class HttpMetadataValidator
{
    public static function assertStatus(int $status, string $kind): void
    {
        if ($status < 100 || $status > 599) {
            throw InvalidHttpMetadataException::forStatus($kind);
        }
    }

    public static function assertBodyAllowed(mixed $body, int $status, string $kind): void
    {
        if ($body !== null && (($status >= 100 && $status < 200) || $status === 204 || $status === 304)) {
            throw InvalidHttpMetadataException::forBody($kind, $status);
        }
    }

    /** @param array<array-key, mixed> $headers */
    public static function assertHeaders(array $headers, string $kind): void
    {
        foreach ($headers as $name => $values) {
            if (!is_string($name) || !self::isHeaderName($name)) {
                throw InvalidHttpMetadataException::forHeaderName($kind);
            }

            if (!is_array($values) || !array_is_list($values)) {
                throw InvalidHttpMetadataException::forHeaderValues($kind);
            }

            foreach ($values as $value) {
                if (!is_string($value) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                    throw InvalidHttpMetadataException::forHeaderValues($kind);
                }
            }
        }
    }

    private static function isHeaderName(string $name): bool
    {
        $allowed = "!#$%&'*+-.^_`|~0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";

        return $name !== '' && strspn($name, $allowed) === strlen($name);
    }
}

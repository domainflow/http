<?php

declare(strict_types=1);

namespace DomainFlow\Http\ErrorHandling;

use DomainFlow\Http\Exception\InvalidHttpMetadataException;
use DomainFlow\Http\Internal\HttpMetadataValidator;

final readonly class ProblemDetails
{
    private const array RESERVED_MEMBERS = ['type', 'title', 'status', 'detail', 'instance'];

    /**
     * @param array<string, mixed> $extensions
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $status,
        public string $title,
        public ?string $detail = null,
        public string $type = 'about:blank',
        public ?string $instance = null,
        public array $extensions = [],
        public array $headers = [],
    ) {
        HttpMetadataValidator::assertStatus($status, 'Problem');

        if (!$this->isUriReference($type)) {
            throw InvalidHttpMetadataException::forProblemUriReference('type');
        }

        if ($instance !== null && !$this->isUriReference($instance)) {
            throw InvalidHttpMetadataException::forProblemUriReference('instance');
        }

        foreach (array_keys($extensions) as $name) {
            if (!is_string($name) || in_array($name, self::RESERVED_MEMBERS, true)) {
                throw InvalidHttpMetadataException::forProblemExtension();
            }
        }

        HttpMetadataValidator::assertHeaders($headers, 'Problem');
    }

    private function isUriReference(string $value): bool
    {
        if ($value === '' || !$this->containsOnlyUriAscii($value) || substr_count($value, '#') > 1) {
            return false;
        }

        [$withoutFragment, $fragment] = $this->splitOnce($value, '#');
        if ($fragment !== null && !$this->isUriComponent($fragment, '/?')) {
            return false;
        }

        [$hierarchy, $query] = $this->splitOnce($withoutFragment, '?');
        if ($query !== null && !$this->isUriComponent($query, '/?')) {
            return false;
        }

        $hasScheme = preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $hierarchy) === 1;
        if ($hasScheme) {
            $hierarchy = substr($hierarchy, strpos($hierarchy, ':') + 1);
        } else {
            $firstSegment = strstr($hierarchy, '/', true);
            $firstSegment = $firstSegment === false ? $hierarchy : $firstSegment;
            if (str_contains($firstSegment, ':')) {
                return false;
            }
        }

        if (!str_starts_with($hierarchy, '//')) {
            return $this->isUriComponent($hierarchy, '/');
        }

        $authorityAndPath = substr($hierarchy, 2);
        $pathOffset = strpos($authorityAndPath, '/');
        $authority = $pathOffset === false ? $authorityAndPath : substr($authorityAndPath, 0, $pathOffset);
        $path = $pathOffset === false ? '' : substr($authorityAndPath, $pathOffset);

        return $this->isAuthority($authority) && $this->isUriComponent($path, '/');
    }

    /** @return array{string, string|null} */
    private function splitOnce(string $value, string $delimiter): array
    {
        $offset = strpos($value, $delimiter);
        if ($offset === false) {
            return [$value, null];
        }

        return [substr($value, 0, $offset), substr($value, $offset + 1)];
    }

    private function containsOnlyUriAscii(string $value): bool
    {
        foreach (str_split($value) as $character) {
            $code = ord($character);
            if ($code < 0x21 || $code > 0x7E) {
                return false;
            }
        }

        return true;
    }

    private function isAuthority(string $authority): bool
    {
        if (substr_count($authority, '@') > 1) {
            return false;
        }

        $hostAndPort = $authority;
        $userInfoOffset = strpos($authority, '@');
        if ($userInfoOffset !== false) {
            $userInfo = substr($authority, 0, $userInfoOffset);
            if (!$this->containsOnlyUriCharacters($userInfo, "-._~!$&'()*+,;=:")) {
                return false;
            }

            $hostAndPort = substr($authority, $userInfoOffset + 1);
        }

        if (str_starts_with($hostAndPort, '[')) {
            return $this->isIpLiteralAndPort($hostAndPort);
        }

        if (str_contains($hostAndPort, '[') || str_contains($hostAndPort, ']') || substr_count($hostAndPort, ':') > 1) {
            return false;
        }

        [$host, $port] = $this->splitOnce($hostAndPort, ':');

        return $this->containsOnlyUriCharacters($host, "-._~!$&'()*+,;=")
            && ($port === null || $port === '' || ctype_digit($port));
    }

    private function isIpLiteralAndPort(string $hostAndPort): bool
    {
        $closingBracket = strpos($hostAndPort, ']');
        if ($closingBracket === false) {
            return false;
        }

        $literal = substr($hostAndPort, 1, $closingBracket - 1);
        $remainder = substr($hostAndPort, $closingBracket + 1);
        if ($remainder !== '') {
            if (!str_starts_with($remainder, ':')) {
                return false;
            }

            $port = substr($remainder, 1);
            if ($port !== '' && !ctype_digit($port)) {
                return false;
            }
        }

        if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return true;
        }

        return preg_match("/^[vV][0-9A-Fa-f]+\\.[A-Za-z0-9\\-._~!$&'()*+,;=:]+$/D", $literal) === 1;
    }

    private function isUriComponent(string $value, string $extraCharacters): bool
    {
        return $this->containsOnlyUriCharacters(
            $value,
            "-._~!$&'()*+,;=:@" . $extraCharacters,
        );
    }

    private function containsOnlyUriCharacters(string $value, string $allowed): bool
    {
        $length = strlen($value);

        for ($index = 0; $index < $length; ++$index) {
            $character = $value[$index];
            if (ctype_alnum($character) || str_contains($allowed, $character)) {
                continue;
            }

            if (
                $character !== '%'
                || $index + 2 >= $length
                || !ctype_xdigit($value[$index + 1] . $value[$index + 2])
            ) {
                return false;
            }

            $index += 2;
        }

        return true;
    }
}

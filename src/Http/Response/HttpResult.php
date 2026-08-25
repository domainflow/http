<?php

declare(strict_types=1);

namespace DomainFlow\Http\Response;

use DomainFlow\Http\Internal\HttpMetadataValidator;

final readonly class HttpResult
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public mixed $body,
        public int $status = 200,
        public array $headers = [],
    ) {
        HttpMetadataValidator::assertStatus($status, 'HTTP');
        HttpMetadataValidator::assertBodyAllowed($body, $status, 'HTTP');
        HttpMetadataValidator::assertHeaders($headers, 'HTTP');
    }
}

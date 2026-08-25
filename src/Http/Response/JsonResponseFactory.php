<?php

declare(strict_types=1);

namespace DomainFlow\Http\Response;

use DomainFlow\Http\ErrorHandling\ProblemDetails;
use DomainFlow\Http\Response\Exception\JsonEncodingException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

final readonly class JsonResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function fromResult(mixed $result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if ($result === null) {
            return $this->withoutContentHeaders($this->responseFactory->createResponse(204));
        }

        $status = 200;
        $headers = [];
        $body = $result;

        if ($result instanceof HttpResult) {
            $status = $result->status;
            $headers = $result->headers;
            $body = $result->body;
        }

        $response = $this->withHeaders($this->responseFactory->createResponse($status), $headers);
        if ($this->isBodylessStatus($status)) {
            return $this->withoutContentHeaders($response);
        }

        if ($body === null) {
            return $response;
        }

        if (!$response->hasHeader('Content-Type')) {
            $response = $response->withHeader('Content-Type', 'application/json');
        }

        return $response->withBody($this->streamFactory->createStream($this->encode($body)));
    }

    public function fromProblem(ProblemDetails $problem): ResponseInterface
    {
        $payload = [
            'type' => $problem->type,
            'title' => $problem->title,
            'status' => $problem->status,
        ];

        if ($problem->detail !== null) {
            $payload['detail'] = $problem->detail;
        }

        if ($problem->instance !== null) {
            $payload['instance'] = $problem->instance;
        }

        $payload = array_replace($payload, $problem->extensions);
        $response = $this->withHeaders(
            $this->responseFactory->createResponse($problem->status),
            $problem->headers,
        )->withHeader('Content-Type', 'application/problem+json');

        return $response->withBody($this->streamFactory->createStream($this->encode($payload)));
    }

    /** @param array<string, list<string>> $headers */
    private function withHeaders(ResponseInterface $response, array $headers): ResponseInterface
    {
        foreach ($headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }

    private function withoutContentHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length');
    }

    private function isBodylessStatus(int $status): bool
    {
        return ($status >= 100 && $status < 200) || $status === 204 || $status === 304;
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw JsonEncodingException::fromEncoding($exception);
        }
    }
}

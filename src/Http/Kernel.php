<?php

declare(strict_types=1);

namespace DomainFlow\Http;

use DomainFlow\Http\Endpoint\ContextAwareEndpointInvoker;
use DomainFlow\Http\Endpoint\EndpointContextProvider;
use DomainFlow\Http\Endpoint\EndpointInvoker;
use DomainFlow\Http\Endpoint\EndpointResolver;
use DomainFlow\Http\Endpoint\Exception\EndpointInvocationException;
use DomainFlow\Http\Endpoint\RequestHydrator;
use DomainFlow\Http\ErrorHandling\ProblemDetails;
use DomainFlow\Http\ErrorHandling\ProblemDetailsMapper;
use DomainFlow\Http\Response\JsonResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;
use Throwable;

final readonly class Kernel implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(
        private Router $router,
        private EndpointResolver $endpointResolver,
        private RequestHydrator $requestHydrator,
        private EndpointInvoker $endpointInvoker,
        private ProblemDetailsMapper $problemDetailsMapper,
        private JsonResponseFactory $responseFactory,
        private array $middleware = [],
        private ?EndpointContextProvider $endpointContextProvider = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $dispatch = fn (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface => $this->dispatch($request);
            $pipeline = new Relay([...$this->middleware, $dispatch]);

            return $pipeline->handle($request);
        } catch (Throwable $exception) {
            $problem = $this->problemDetailsMapper->map($exception)
                ?? new ProblemDetails(500, 'Internal Server Error');

            return $this->responseFactory->fromProblem($problem);
        }
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $match = $this->router->match($request);
        $requestDto = $this->requestHydrator->hydrate($request, $match);
        $endpoint = $this->endpointResolver->resolve($match->endpointClass);
        $context = $this->endpointContextProvider?->context($request);
        if ($context !== null) {
            if (!$this->endpointInvoker instanceof ContextAwareEndpointInvoker) {
                throw EndpointInvocationException::contextUnsupported($endpoint);
            }

            $result = $this->endpointInvoker->invokeWithContext(
                $endpoint,
                $requestDto,
                $match->requestClass,
                $context,
            );
        } else {
            $result = $this->endpointInvoker->invoke($endpoint, $requestDto, $match->requestClass);
        }

        return $this->responseFactory->fromResult($result);
    }
}

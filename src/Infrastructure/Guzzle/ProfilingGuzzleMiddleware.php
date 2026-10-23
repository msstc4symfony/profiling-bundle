<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\Guzzle;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Infrastructure\OutgoingRequestSpan;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Gives every Guzzle request an "http <METHOD> <host>" span through a handler-stack middleware. The span is detached
 * and ends when the promise settles, so synchronous send() and asynchronous pools behave alike. The middleware is
 * the outermost one of the stack: one span covers redirects and retries.
 *
 * `ProfileGuzzlePass` wires it into container clients, through the client's `handler` config (`decorateHandler()`)
 * or as a service configurator (`addHandler()`).
 *
 * @phpstan-type GuzzleOptions array<array-key, mixed>
 * @phpstan-type GuzzleHandler callable(RequestInterface, GuzzleOptions): PromiseInterface
 *
 * @internal
 */
final readonly class ProfilingGuzzleMiddleware
{
    public const MIDDLEWARE_NAME = 'msstc4symfony_profiling';

    public function __construct(
        private DetachedSpanFactoryInterface $profiling,
    ) {
    }

    /**
     * The given stack (or Guzzle's default one) with the profiling middleware outermost. A bare handler is returned
     * unchanged: wrapping it into a stack would change what the client exposes as its handler.
     *
     * @param GuzzleHandler|null $handler
     *
     * @return GuzzleHandler
     */
    public function decorateHandler(?callable $handler = null): callable
    {
        if ($handler !== null && !$handler instanceof HandlerStack) {
            return $handler;
        }

        $stack = $handler ?? HandlerStack::create();
        $stack->remove(self::MIDDLEWARE_NAME);
        $stack->unshift($this->middleware(), self::MIDDLEWARE_NAME);

        return $stack;
    }

    /**
     * Service configurator for clients that expose their handler through `getConfig()` (every Guzzle 7 client,
     * `GuzzleHttp\Client` on Guzzle 8). A client without it, or whose handler is not a `HandlerStack`, is left as it
     * is: failing here would break the service creation, and ProfileGuzzlePass already logs the clients it cannot
     * reach.
     */
    public function addHandler(ClientInterface $client): void
    {
        $stack = method_exists($client, 'getConfig') ? $client->getConfig('handler') : null;

        if ($stack instanceof HandlerStack) {
            $this->decorateHandler($stack);
        }
    }

    /**
     * @return Closure(GuzzleHandler): (Closure(RequestInterface, GuzzleOptions): PromiseInterface)
     */
    public function middleware(): Closure
    {
        return $this->wrap(...);
    }

    /**
     * @param GuzzleHandler $handler
     *
     * @return Closure(RequestInterface, GuzzleOptions): PromiseInterface
     */
    private function wrap(callable $handler): Closure
    {
        return fn (RequestInterface $request, array $options): PromiseInterface => $this->send($handler, $request, $options);
    }

    /**
     * @param GuzzleHandler $handler
     * @param GuzzleOptions $options
     */
    private function send(callable $handler, RequestInterface $request, array $options): PromiseInterface
    {
        try {
            $uri = $request->getUri();
            $span = $this->profiling->createDetachedSpan(
                OutgoingRequestSpan::message($request->getMethod(), $uri->getHost()),
                OutgoingRequestSpan::context($uri->getPath()),
            );
        } catch (Throwable) {
            return $handler($request, $options);
        }

        try {
            $promise = $handler($request, $options);
        } catch (Throwable $exception) {
            $this->end($span, ['exception' => get_debug_type($exception)]);

            throw $exception;
        }

        return $promise->then(
            function (mixed $response) use ($span): mixed {
                $this->end($span, $response instanceof ResponseInterface ? ['status' => $response->getStatusCode()] : []);

                return $response;
            },
            function (mixed $reason) use ($span): PromiseInterface {
                $this->end($span, $this->failure($reason));

                return Create::rejectionFor($reason);
            },
        );
    }

    /**
     * A failing factory decorator must neither reject a fulfilled promise nor replace the request's own failure.
     *
     * @param array<string, mixed> $context
     */
    private function end(SpanInterface $span, array $context): void
    {
        try {
            $this->profiling->endSpan($span, $context);
        } catch (Throwable) {
            // Profiling stays out of the request's outcome.
        }
    }

    /**
     * @return array{exception: string, status?: int}
     */
    private function failure(mixed $reason): array
    {
        $context = ['exception' => get_debug_type($reason)];

        // Guzzle 7 declares getResponse() on RequestException (nullable), Guzzle 8 on ResponseException.
        $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
        if ($response instanceof ResponseInterface) {
            $context['status'] = $response->getStatusCode();
        }

        return $context;
    }
}

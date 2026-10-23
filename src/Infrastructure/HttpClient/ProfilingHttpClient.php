<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient;

use Generator;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Msstc4Symfony\ProfilingBundle\Infrastructure\OutgoingRequestSpan;
use Override;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use SplObjectStorage;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Decorates http_client.transport: every client, default and scoped, ends there with an absolute URL, so each
 * request gets exactly one "http <METHOD> <host>" span. The span is detached because the response completes after
 * request() returns; nothing is read in request(), which would serialize concurrent requests.
 *
 * @internal registered by ProfileHttpClientPass
 */
final class ProfilingHttpClient implements HttpClientInterface, ResetInterface, LoggerAwareInterface
{
    use DecoratorTrait;

    public function __construct(
        HttpClientInterface $inner,
        private readonly DetachedSpanFactoryInterface $profiling,
    ) {
        $this->client = $inner;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        try {
            $response = $this->client->request($method, $url, $options);
        } catch (Throwable $exception) {
            $this->recordFailedRequest($method, $url, $exception);

            throw $exception;
        }

        try {
            $span = $this->openSpan($method, $url, $response);
        } catch (Throwable) {
            return $response;
        }

        return new ProfiledResponse($response, $this->client, $span, $this->profiling);
    }

    #[Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        /** @var SplObjectStorage<ResponseInterface, ProfiledResponse> $profiled */
        $profiled = new SplObjectStorage();
        $inner = [];

        foreach ($responses as $response) {
            if ($response instanceof ProfiledResponse) {
                $profiled[$response->inner()] = $response;
                $response = $response->inner();
            }

            $inner[] = $response;
        }

        return new ResponseStream($this->observe($this->client->stream($inner, $timeout), $profiled));
    }

    #[Override]
    public function setLogger(LoggerInterface $logger): void
    {
        if ($this->client instanceof LoggerAwareInterface) {
            $this->client->setLogger($logger);
        }
    }

    private function openSpan(string $method, string $url, ResponseInterface $response): SpanInterface
    {
        // The inner response knows its resolved URL, including a base_uri this decorator never sees.
        $resolvedUrl = $response->getInfo('url');

        return $this->createSpan($method, is_string($resolvedUrl) && $resolvedUrl !== '' ? $resolvedUrl : $url);
    }

    /**
     * Invalid options or a failing inner decorator throw before there is a response; the attempt still gets a span.
     */
    private function recordFailedRequest(string $method, string $url, Throwable $exception): void
    {
        try {
            $this->profiling->endSpan($this->createSpan($method, $url), ['exception' => get_debug_type($exception)]);
        } catch (Throwable) {
            // The caller gets the request's exception, not one from profiling.
        }
    }

    private function createSpan(string $method, string $url): SpanInterface
    {
        $parts = parse_url($url);

        return $this->profiling->createDetachedSpan(
            OutgoingRequestSpan::message($method, is_array($parts) ? $parts['host'] ?? '' : ''),
            OutgoingRequestSpan::context(is_array($parts) ? $parts['path'] ?? '' : ''),
        );
    }

    /**
     * @param SplObjectStorage<ResponseInterface, ProfiledResponse> $profiled
     *
     * @return Generator<ResponseInterface, ChunkInterface, mixed, void>
     */
    private function observe(ResponseStreamInterface $stream, SplObjectStorage $profiled): Generator
    {
        foreach ($stream as $response => $chunk) {
            $wrapper = $profiled->offsetExists($response) ? $profiled[$response] : null;
            // Error and timeout chunks throw from isFirst()/isLast() and from their destructor unless the caller
            // touched them: they reach the caller untouched.
            if ($wrapper instanceof ProfiledResponse) {
                if ($chunk->getError() === null) {
                    if ($chunk->isLast()) {
                        $wrapper->complete();
                    }
                } else {
                    $wrapper->failIfTransportError();
                }
            }

            yield $wrapper ?? $response => $chunk;
        }
    }
}

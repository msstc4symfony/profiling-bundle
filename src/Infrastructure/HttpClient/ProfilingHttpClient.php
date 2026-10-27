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
 * Decorates http_client.transport: every client, default and scoped, ends there, so each request gets exactly one
 * "http <METHOD> <host>" span. The span is detached because the response completes after request() returns; nothing is
 * read in request(), which would serialize concurrent requests.
 *
 * @internal registered by ProfileHttpClientPass
 */
final class ProfilingHttpClient implements HttpClientInterface, ResetInterface, LoggerAwareInterface
{
    use DecoratorTrait;

    /**
     * @param string|array<string, string|null>|null $baseUri the transport's own base_uri default, which
     *                                                        requests do not carry
     */
    public function __construct(
        HttpClientInterface $inner,
        private readonly DetachedSpanFactoryInterface $profiling,
        private string|array|null $baseUri = null,
    ) {
        $this->client = $inner;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // Opened before the request goes out: a create processor may put the span's id into the request's headers.
        try {
            // As the transport merges its defaults: a base_uri option, even null, wins over the default one.
            $baseUri = array_key_exists('base_uri', $options) ? $options['base_uri'] : $this->baseUri;
            $span = $this->createSpan($method, RequestedUrl::resolve($url, $this->baseUri($baseUri)));
        } catch (Throwable) {
            return $this->client->request($method, $url, $options);
        }

        try {
            $response = $this->client->request($method, $url, $options);
        } catch (Throwable $exception) {
            $this->endFailedRequest($span, $exception);

            throw $exception;
        }

        return new ProfiledResponse($response, $this->client, $span, $this->profiling);
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        if (array_key_exists('base_uri', $options)) {
            $clone->baseUri = $this->baseUri($options['base_uri']);
        }

        return $clone;
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

    /**
     * @return string|array<string, string|null>|null
     */
    private function baseUri(mixed $value): string|array|null
    {
        if (is_string($value) || $value === null) {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $parts = [];
        foreach ($value as $key => $part) {
            if (!is_string($key) || ($part !== null && !is_string($part))) {
                return null;
            }

            $parts[$key] = $part;
        }

        return $parts;
    }

    /**
     * Invalid options or a failing inner decorator throw before there is a response.
     */
    private function endFailedRequest(SpanInterface $span, Throwable $exception): void
    {
        try {
            $this->profiling->endSpan($span, ['exception' => get_debug_type($exception)]);
        } catch (Throwable) {
            // The caller gets the request's exception, not one from profiling.
        }
    }

    private function createSpan(string $method, RequestedUrl $url): SpanInterface
    {
        return $this->profiling->createDetachedSpan(
            OutgoingRequestSpan::message($method, $url->host),
            OutgoingRequestSpan::context($url->path),
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

<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient;

use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\SpanInterface;
use Override;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Transparent wrapper in the spirit of TraceableResponse: every call is forwarded as-is, so exceptions keep their
 * class (TimeoutException stays TimeoutException), and the span ends when the caller reaches the end of the body,
 * a failure, cancel() or the destructor.
 *
 * @internal created by ProfilingHttpClient only
 */
final class ProfiledResponse implements ResponseInterface, StreamableInterface
{
    private bool $ended = false;

    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly HttpClientInterface $client,
        private readonly SpanInterface $span,
        private readonly ProfilingFactoryInterface $profiling,
    ) {
    }

    public function __destruct()
    {
        // The inner destructor waits for an unread response and throws on 4xx/5xx; run it first so the span
        // still gets the outcome, and keep its exception.
        try {
            if (method_exists($this->inner, '__destruct')) {
                $this->inner->__destruct();
            }
        } catch (Throwable $exception) {
            $this->fail($exception);

            throw $exception;
        }

        $this->end(['response_destroyed' => true]);
    }

    public function inner(): ResponseInterface
    {
        return $this->inner;
    }

    #[Override]
    public function getStatusCode(): int
    {
        try {
            return $this->inner->getStatusCode();
        } catch (Throwable $exception) {
            $this->fail($exception);

            throw $exception;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    #[Override]
    public function getHeaders(bool $throw = true): array
    {
        try {
            return $this->inner->getHeaders($throw);
        } catch (Throwable $exception) {
            $this->fail($exception);

            throw $exception;
        }
    }

    #[Override]
    public function getContent(bool $throw = true): string
    {
        try {
            $content = $this->inner->getContent($throw);
        } catch (Throwable $exception) {
            $this->fail($exception);

            throw $exception;
        }

        $this->complete();

        return $content;
    }

    /**
     * @return array<array-key, mixed>
     */
    #[Override]
    public function toArray(bool $throw = true): array
    {
        try {
            $data = $this->inner->toArray($throw);
        } catch (Throwable $exception) {
            $this->fail($exception);

            throw $exception;
        }

        $this->complete();

        return $data;
    }

    /**
     * After the headers, e.g. a RetryableHttpClient dropping a failed attempt, the status is known and the span keeps
     * it with "body_cancelled"; before them, nothing came back: "cancelled".
     */
    #[Override]
    public function cancel(): void
    {
        $context = $this->status() === null ? ['cancelled' => true] : ['body_cancelled' => true];
        $this->inner->cancel();
        $this->end($context);
    }

    #[Override]
    public function getInfo(?string $type = null): mixed
    {
        return $this->inner->getInfo($type);
    }

    /**
     * @return resource
     */
    #[Override]
    public function toStream(bool $throw = true)
    {
        if ($throw) {
            $this->getHeaders();
        }

        $stream = $this->inner instanceof StreamableInterface
            ? $this->inner->toStream(false)
            : StreamWrapper::createResource($this->inner, $this->client);

        // The inner stream stays buffered and rewindable (Psr18Client seeks it); the filter reports the end of the
        // body and the closing of the stream, which bypass this wrapper otherwise.
        // A transport error ends the stream early (StreamWrapper returns false): both callbacks report it first.
        CompletionStreamFilter::attach(
            $stream,
            function (): void {
                $this->failIfTransportError();
                $this->complete();
            },
            function (): void {
                $this->failIfTransportError();
                $this->end(['stream_closed' => true]);
            },
        );

        return $stream;
    }

    /**
     * The whole body was received.
     */
    public function complete(): void
    {
        $this->end([]);
    }

    /**
     * Called for an error chunk of stream(): a transport error sets the "error" info, a timeout does not.
     */
    public function failIfTransportError(): void
    {
        try {
            $error = $this->inner->getInfo('error');
        } catch (Throwable) {
            return;
        }

        if (is_string($error) && $error !== '') {
            $this->end(['exception' => TransportException::class]);
        }
    }

    private function fail(Throwable $exception): void
    {
        $this->end(['exception' => get_debug_type($exception)]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function end(array $context): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;

        $status = $this->status();
        if ($status !== null) {
            $context['status'] = $status;
        }

        try {
            $this->profiling->endSpan($this->span, $context);
        } catch (Throwable) {
            // Reached from getContent(), fread() or a destructor: a factory decorator's failure must not surface.
        }
    }

    /**
     * @return positive-int|null
     */
    private function status(): ?int
    {
        try {
            $status = $this->inner->getInfo('http_code');
        } catch (Throwable) {
            return null;
        }

        return is_int($status) && $status > 0 ? $status : null;
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\HttpClient;

use Override;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * An inner client that remembers resets and the logger it was given.
 */
final class ResettableLoggingClient implements HttpClientInterface, ResetInterface, LoggerAwareInterface
{
    public int $resets = 0;

    public ?LoggerInterface $logger = null;

    private readonly MockHttpClient $client;

    public function __construct()
    {
        $this->client = new MockHttpClient();
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $url, $options);
    }

    #[Override]
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function withOptions(array $options): static
    {
        return $this;
    }

    #[Override]
    public function reset(): void
    {
        $this->resets++;
    }

    #[Override]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}

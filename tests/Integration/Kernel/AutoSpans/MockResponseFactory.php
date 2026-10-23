<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\AutoSpans;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FrameworkBundle's mock_response_factory: answers every request of the test kernel. "/flaky" fails once with a 503,
 * for the retry_failed client.
 */
final class MockResponseFactory
{
    private bool $flakyFailed = false;

    /**
     * @param array<array-key, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        if (str_ends_with($url, '/flaky') && !$this->flakyFailed) {
            $this->flakyFailed = true;

            return new MockResponse('down', ['http_code' => 503]);
        }

        return new MockResponse('pong', ['http_code' => 202]);
    }
}

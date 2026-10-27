<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient;

use Throwable;

/**
 * Host and path of a request before it is sent, resolved as the Symfony transports resolve it (same parser, base_uri
 * rules, punycode hosts).
 *
 * @internal
 */
final readonly class RequestedUrl
{
    private function __construct(
        public string $host,
        public string $path,
    ) {
    }

    /**
     * A URL the transport would reject keeps the path parse_url() finds in it and no host.
     *
     * @param string|array<string, string|null>|null $baseUri as the base_uri option
     */
    public static function resolve(string $url, string|array|null $baseUri): self
    {
        try {
            $parts = SymfonyUrlResolver::resolve($url, $baseUri);
        } catch (Throwable) {
            $path = parse_url($url, PHP_URL_PATH);

            return new self('', is_string($path) ? $path : '');
        }

        $authority = $parts['authority'] ?? '';
        // "//user:pass@host:port"; the user part ends at the last "@", as the transports read it.
        $at = strrpos($authority, '@');
        $hostAndPort = $at === false ? substr($authority, 2) : substr($authority, $at + 1);
        $host = preg_match('/^(?:\[[^]]*]|[^:]*)/', $hostAndPort, $match) === 1 ? $match[0] : '';

        return new self($host, $parts['path'] ?? '');
    }
}

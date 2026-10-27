<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient;

use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClientTrait;

/**
 * The URL parsing and base_uri resolution of the Symfony transports, kept apart from the value it feeds.
 *
 * @internal
 */
final class SymfonyUrlResolver
{
    use HttpClientTrait;

    private function __construct()
    {
    }

    /**
     * @param string|array<string, string|null>|null $baseUri as the base_uri option: a URL, or parts already parsed
     *
     * @return array<string, string|null>
     *
     * @throws InvalidArgumentException for a URL the transports reject
     */
    public static function resolve(string $url, string|array|null $baseUri): array
    {
        $parts = [];
        $base = is_string($baseUri) ? self::parseUrl($baseUri) : $baseUri;
        foreach (self::resolveUrl(self::parseUrl($url), $base) as $name => $part) {
            if (is_string($name) && (is_string($part) || $part === null)) {
                $parts[$name] = $part;
            }
        }

        return $parts;
    }
}

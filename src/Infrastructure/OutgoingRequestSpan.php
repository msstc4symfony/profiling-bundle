<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure;

use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;

/**
 * Name and context of outgoing HTTP request spans, shared by the HttpClient and Guzzle integrations.
 *
 * @internal
 */
final class OutgoingRequestSpan
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const LONG_HEX = '/^[0-9a-f]{24,}$/i';

    private const DIGITS = '/^\d+$/';

    /**
     * @return non-empty-string
     */
    public static function message(string $method, string $host): string
    {
        return 'http ' . strtoupper($method) . ' ' . ($host === '' ? 'unknown' : $host);
    }

    /**
     * @return array{url_path: non-empty-string, profiling_automatic: true}
     */
    public static function context(string $path): array
    {
        return ['url_path' => self::sanitize($path), ...DetachedSpanFactoryInterface::AUTOMATIC];
    }

    /**
     * Ids and tokens in a path end up in logs: UUIDs, numbers and long hex strings become ":uuid", ":id", ":hash"
     * (the rules of metrics-bundle's PathSanitizer).
     *
     * @return non-empty-string
     */
    private static function sanitize(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            $segments[$i] = match (true) {
                preg_match(self::UUID, $segment) === 1 => ':uuid',
                preg_match(self::DIGITS, $segment) === 1 => ':id',
                preg_match(self::LONG_HEX, $segment) === 1 => ':hash',
                default => $segment,
            };
        }

        return implode('/', $segments);
    }
}

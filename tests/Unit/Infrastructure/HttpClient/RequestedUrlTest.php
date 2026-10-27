<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Infrastructure\HttpClient;

use Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient\RequestedUrl;
use Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient\SymfonyUrlResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(RequestedUrl::class)]
#[CoversClass(SymfonyUrlResolver::class)]
#[RequiresMethod(MockHttpClient::class, 'request')]
final class RequestedUrlTest extends TestCase
{
    /**
     * @param string|array<string, string|null>|null $baseUri
     */
    #[DataProvider('urls')]
    public function testResolvesHostAndPathAsTheTransport(string $url, string|array|null $baseUri, string $host, string $path): void
    {
        $resolved = RequestedUrl::resolve($url, $baseUri);

        self::assertSame([$host, $path], [$resolved->host, $resolved->path]);
    }

    /**
     * @return iterable<string, array{string, string|array<string, string|null>|null, string, string}>
     */
    public static function urls(): iterable
    {
        yield 'absolute' => ['https://api.example.com/v1/orders?x=1', null, 'api.example.com', '/v1/orders'];
        yield 'user info with @ in the password' => ['https://user:p@ss@api.example.com/a', null, 'api.example.com', '/a'];
        yield 'port' => ['http://api.example.com:8080/a', null, 'api.example.com', '/a'];
        yield 'ipv6 with port' => ['http://[::1]:8080/a', null, '[::1]', '/a'];
        yield 'upper-case host' => ['https://API.Example.COM/a', null, 'api.example.com', '/a'];
        yield 'international host' => ['https://bücher.example/a', null, 'xn--bcher-kva.example', '/a'];
        yield 'dot segments' => ['https://api.example.com/a/../b/./c', null, 'api.example.com', '/b/c'];
        yield 'relative to a base' => ['v2/items', 'https://api.example.com/shop/', 'api.example.com', '/shop/v2/items'];
        yield 'absolute path on a base' => ['/v2', 'https://api.example.com/shop/', 'api.example.com', '/v2'];
        yield 'absolute url ignores the base' => ['https://other.example.com/x', 'https://api.example.com', 'other.example.com', '/x'];
        yield 'base as parts' => ['x', ['scheme' => 'https:', 'authority' => '//parts.example.com', 'path' => '/'], 'parts.example.com', '/x'];
        yield 'relative without a base' => ['/v1/orders', null, '', '/v1/orders'];
        yield 'base without a scheme' => ['/a', 'api.example.com', '', '/a'];
        yield 'unsupported scheme' => ['ftp://files.example.com/a', null, '', '/a'];
        yield 'no path' => ['https://api.example.com', null, 'api.example.com', '/'];
    }
}

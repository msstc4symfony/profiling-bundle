<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Infrastructure;

use Msstc4Symfony\ProfilingBundle\Infrastructure\OutgoingRequestSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutgoingRequestSpan::class)]
final class OutgoingRequestSpanTest extends TestCase
{
    #[TestWith(['get', 'api.example.com', 'http GET api.example.com'])]
    #[TestWith(['POST', '', 'http POST unknown'])]
    public function testTheMessageNamesTheMethodAndHost(string $method, string $host, string $message): void
    {
        self::assertSame($message, OutgoingRequestSpan::message($method, $host));
    }

    #[TestWith(['', '/'])]
    #[TestWith(['/', '/'])]
    #[TestWith(['/orders', '/orders'])]
    #[TestWith(['/orders/42/', '/orders/:id/'])]
    #[TestWith(['/v1/users/550E8400-E29B-41D4-A716-446655440000', '/v1/users/:uuid'])]
    #[TestWith(['/files/0123456789abcdef01234567', '/files/:hash'])]
    #[TestWith(['/files/0123456789abcdef0123456', '/files/0123456789abcdef0123456'])]
    #[TestWith(['/a1/12b/550e8400-e29b-41d4-a716-44665544000', '/a1/12b/550e8400-e29b-41d4-a716-44665544000'])]
    #[TestWith(['42', ':id'])]
    public function testIdsInThePathBecomePlaceholders(string $path, string $sanitized): void
    {
        self::assertSame(['url_path' => $sanitized, 'profiling_automatic' => true, 'profiling_outgoing_request' => true], OutgoingRequestSpan::context($path));
    }
}

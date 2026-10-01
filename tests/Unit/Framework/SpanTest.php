<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullableSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractSpan::class)]
#[CoversClass(Span::class)]
#[CoversClass(NullableSpan::class)]
final class SpanTest extends TestCase
{
    public function testEndRunsHandlersWithTheContext(): void
    {
        $calls = [];
        $span = new Span('db', ['table' => 'users'])->addEndHandler(static function (SpanInterface $span, array $context) use (&$calls): void {
            $calls[] = [$span->getMessage(), $context];
        });

        $span->end(['rows' => 3]);

        self::assertSame([['db', ['rows' => 3]]], $calls);
        self::assertSame(['table' => 'users'], $span->getContext());
    }

    public function testRemoveEndHandlerByCallableOrIndex(): void
    {
        $first = static function (): void {};
        $second = static function (): void {};
        $span = new Span('x')->addEndHandler($first)->addEndHandler($second);

        $span->removeEndHandler($first);
        self::assertSame([$second], $span->getEndHandlers());

        $span->removeEndHandler(0);
        self::assertSame([], $span->getEndHandlers());
    }

    public function testOnlyRealSpansAreRecorded(): void
    {
        $before = microtime(true);

        self::assertTrue(new Span('a')->isRecorded());
        self::assertFalse(new NullableSpan('b')->isRecorded());
        self::assertGreaterThanOrEqual($before, new NullableSpan('c')->getStartTime());
    }
}

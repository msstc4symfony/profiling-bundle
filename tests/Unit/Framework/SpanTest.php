<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Framework;

use Msstc4Symfony\ProfilingBundle\Framework\Span\AbstractSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\NullSpan;
use Msstc4Symfony\ProfilingBundle\Framework\Span\Span;
use Msstc4Symfony\ProfilingBundle\Framework\Span\SpanInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractSpan::class)]
#[CoversClass(Span::class)]
#[CoversClass(NullSpan::class)]
final class SpanTest extends TestCase
{
    public function testEndRunsHandlersOnceWithTheContext(): void
    {
        $calls = [];
        $span = new Span('db', ['table' => 'users'])->addEndHandler(static function (SpanInterface $span, array $context) use (&$calls): void {
            $calls[] = [$span->getMessage(), $context];
        });

        $span->end(['rows' => 3]);
        $span->end(['rows' => 4]);

        self::assertSame([['db', ['rows' => 3]]], $calls);
        self::assertTrue($span->isEnded());
        self::assertSame(['table' => 'users'], $span->getContext());
    }

    public function testDurationIsFixedAtEnd(): void
    {
        $span = new Span('x');
        self::assertFalse($span->isEnded());
        $running = $span->getDuration();

        $span->end();
        $ended = $span->getDuration();
        usleep(1000);

        self::assertGreaterThanOrEqual($running, $ended);
        self::assertSame($ended, $span->getDuration());
    }

    public function testRemoveEndHandlerByCallableOrIndex(): void
    {
        $first = static function (): void {};
        $second = static function (): void {};
        $span = new Span('x')->addEndHandler($first)->addEndHandler($second);

        $span->removeEndHandler($first);
        self::assertSame([$second], $span->getEndHandlers());

        $span->removeEndHandler(5);
        self::assertCount(1, $span->getEndHandlers());

        $span->removeEndHandler(0);
        self::assertSame([], $span->getEndHandlers());
    }

    public function testOnlyRealSpansAreRecorded(): void
    {
        $before = microtime(true);

        self::assertTrue(new Span('a')->isRecorded());
        self::assertFalse(new NullSpan('b')->isRecorded());
        self::assertGreaterThanOrEqual($before, new NullSpan('c')->getStartTime());
    }
}
